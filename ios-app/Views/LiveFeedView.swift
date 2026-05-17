/// Live Feed View - Real-time packet stream
import SwiftUI
#if os(iOS)
import UIKit
#endif
#if canImport(UserNotifications)
import UserNotifications
#endif
#if canImport(CoreLocation)
import CoreLocation
#endif

private func normalizedChannelName(_ raw: String?) -> String {
    let trimmed = (raw ?? "").trimmingCharacters(in: .whitespacesAndNewlines)
    guard !trimmed.isEmpty else { return "Unknown" }
    return trimmed.trimmingCharacters(in: CharacterSet(charactersIn: "#"))
}

private func displayChannelName(_ raw: String?) -> String {
    let normalized = normalizedChannelName(raw)
    if normalized.caseInsensitiveCompare("public") == .orderedSame {
        return "Public"
    }
    if normalized == "Unknown" {
        return "#Unknown"
    }
    return "#\(normalized)"
}

#if canImport(CoreLocation)

private final class LiveDeviceLocationManager: NSObject, ObservableObject, CLLocationManagerDelegate {
    @Published var currentCoordinate: CLLocationCoordinate2D?

    private let manager = CLLocationManager()
    private var didRequest = false

    override init() {
        super.init()
        manager.delegate = self
        manager.desiredAccuracy = kCLLocationAccuracyHundredMeters
    }

    func requestCurrentLocation() {
        guard !didRequest else { return }
        didRequest = true

        switch manager.authorizationStatus {
        case .notDetermined:
            manager.requestWhenInUseAuthorization()
        case .authorizedWhenInUse, .authorizedAlways:
            manager.requestLocation()
        default:
            break
        }
    }

    func locationManagerDidChangeAuthorization(_ manager: CLLocationManager) {
        switch manager.authorizationStatus {
        case .authorizedWhenInUse, .authorizedAlways:
            manager.requestLocation()
        default:
            break
        }
    }

    func locationManager(_ manager: CLLocationManager, didUpdateLocations locations: [CLLocation]) {
        currentCoordinate = locations.last?.coordinate
    }

    func locationManager(_ manager: CLLocationManager, didFailWithError error: Error) {
        // Ignore transient failures; distance badge remains hidden without a fix.
    }
}

#else

private final class LiveDeviceLocationManager: ObservableObject {
    @Published var currentCoordinate: CLLocationCoordinate2D?

    func requestCurrentLocation() {
        // tvOS: no runtime location request flow in this app.
    }
}

#endif

struct LiveFeedView: View {
    @EnvironmentObject var apiClient: APIClient
    @EnvironmentObject var authManager: AuthenticationManager
    @EnvironmentObject var navigationState: AppNavigationState
    @Environment(\.scenePhase) private var scenePhase

    enum SubTab { case feed, channels }
    @State private var subTab: SubTab = .feed

    @State private var packets: [Packet] = []
    @State private var packetBuffer: [Packet] = []
    @State private var isLoading = false
    @State private var lastFetchTimeMs: Int = 0
    @State private var updateTask: Task<Void, Never>?
    @State private var restartTask: Task<Void, Never>?
    @State private var lastStreamConfigKey: String = ""
    @State private var hasAppeared = false
#if os(iOS)
    @State private var backgroundTaskId: UIBackgroundTaskIdentifier = .invalid
#endif
    @StateObject private var liveLocationManager = LiveDeviceLocationManager()

    @AppStorage("live_type_adv") private var showADV = true
    @AppStorage("live_type_msg") private var showMSG = true
    @AppStorage("live_type_pub") private var showPUB = true
    @AppStorage("live_type_raw") private var showRAW = true
    @AppStorage("live_type_tel") private var showTEL = true
    @AppStorage("live_type_sys") private var showSYS = true
    @AppStorage("collector_filter_selected_ids") private var collectorFilterRaw = ""
    @AppStorage("notify_new_device") private var notifyNewDevice = false
    @AppStorage("notify_new_message") private var notifyNewMessage = false
    @AppStorage("live_feed_items_limit") private var liveFeedItemsLimit = 300

    private var isCurrentTab: Bool {
        navigationState.selectedTab == 0
    }

    @State private var knownContactIds: Set<Int> = []
    @State private var knownContactKeys: Set<String> = []
    @State private var knownMessagePacketKeys: Set<String> = []
    @State private var notificationsPrimed = false
    @State private var renderedPacketCount = 40
    @State private var isLoadingOlder = false
    @State private var hasMoreHistory = true
    @State private var oldestLoadedTimestampMs: Int?
    @State private var feedScrollPositionKey: String?
    
    let reconnectDelay: TimeInterval = 1.0
    let fallbackPollingDelay: TimeInterval = 3.0

    private var maxVisiblePackets: Int {
        min(max(liveFeedItemsLimit, 20), 500)
    }

    private var pageSize: Int {
        maxVisiblePackets
    }

    private var selectedTypeList: [String] {
        ["ADV", "MSG", "PUB", "RAW", "TEL", "SYS"]
    }

    private var selectedTypes: [String] {
        var result: [String] = []
        if showADV { result.append("ADV") }
        if showMSG { result.append("MSG") }
        if showPUB { result.append("PUB") }
        if showRAW { result.append("RAW") }
        if showTEL { result.append("TEL") }
        if showSYS { result.append("SYS") }
        return result
    }

    private var selectedCollectorIds: Set<Int> {
        Set(
            collectorFilterRaw
                .split(separator: ",")
                .compactMap { Int($0.trimmingCharacters(in: .whitespaces)) }
        )
    }

    private var shouldKeepNotificationStream: Bool {
        notifyNewDevice || notifyNewMessage
    }

    private func isTypeEnabled(_ type: String) -> Bool {
        switch type {
        case "ADV": return showADV
        case "MSG": return showMSG
        case "PUB": return showPUB
        case "RAW": return showRAW
        case "TEL": return showTEL
        case "SYS": return showSYS
        default: return false
        }
    }

    private func setTypeEnabled(_ type: String, enabled: Bool) {
        switch type {
        case "ADV": showADV = enabled
        case "MSG": showMSG = enabled
        case "PUB": showPUB = enabled
        case "RAW": showRAW = enabled
        case "TEL": showTEL = enabled
        case "SYS": showSYS = enabled
        default: break
        }
    }

    private func packetStableKey(_ packet: Packet) -> String {
        "\(packet.type)-\(packet.id)"
    }
    
    var body: some View {
        NavigationStack {
            ZStack {
                LinearGradient(
                    colors: [
                        Color(red: 0.08, green: 0.1, blue: 0.13),
                        Color(red: 0.12, green: 0.16, blue: 0.21)
                    ],
                    startPoint: .topLeading,
                    endPoint: .bottomTrailing
                )
                    .ignoresSafeArea()
                
                VStack(spacing: 0) {
                    VStack(spacing: 10) {
                        HStack(spacing: 8) {
                            HStack(spacing: 10) {
                                BrandLogoMark(height: liveFeedHeaderLogoHeight)

                                Text(subTab == .feed ? "Live Feed" : "Channels")
                                    .font(.system(size: liveFeedHeaderTitleSize, weight: .bold, design: .monospaced))
                                    .foregroundColor(.white)
                            }

                            Spacer()

                            if subTab == .feed {
                                StatBadge(label: "ITEMS", value: "\(packets.count)")
                                StatBadge(label: "MODE", value: "LIVE")
                            }
                        }
                        .padding(.horizontal, 14)

                        // Sub-tab switcher: Feed | Channels
                        HStack(spacing: 0) {
                            SubTabButton(label: "FEED", active: subTab == .feed) {
                                withAnimation(.easeInOut(duration: 0.18)) { subTab = .feed }
                            }
                            SubTabButton(label: "CHANNELS", active: subTab == .channels) {
                                withAnimation(.easeInOut(duration: 0.18)) { subTab = .channels }
                            }
                            Spacer()
                        }
                        .padding(.horizontal, 12)
                        .padding(.top, 4)

                        if isLoading && subTab == .feed {
                            ProgressView()
                                .tint(.cyan)
                        }
                    }
                    .padding(.top, 10)

                    if subTab == .feed {
                        ScrollView(.horizontal, showsIndicators: false) {
                            HStack(spacing: 8) {
                                ForEach(selectedTypeList, id: \.self) { type in
                                    Button(action: {
                                        setTypeEnabled(type, enabled: !isTypeEnabled(type))
                                    }) {
                                        TypeFilterBadge(type: type, isActive: isTypeEnabled(type))
                                    }
                                }
                            }
                            .padding(.horizontal, 12)
                        }
                        .padding(.vertical, 10)
                        .background(Color.black.opacity(0.18))
                    }

                    if subTab == .feed {
                        if packets.isEmpty {
                            VStack(spacing: 12) {
                                Image(systemName: "wifi.slash")
                                    .font(.system(size: 32))
                                    .foregroundColor(.gray)

                                Text("No packets yet")
                                    .font(.system(size: 14, weight: .semibold))
                                    .foregroundColor(.white)

                                Text("Waiting for network activity...")
                                    .font(.system(size: 12))
                                    .foregroundColor(.gray)
                            }
                            .frame(maxHeight: .infinity)
                            .padding()
                        } else {
                            List {
                                ForEach(Array(packets.prefix(renderedPacketCount)).map { (key: packetStableKey($0), packet: $0) }, id: \.key) { row in
                                    let packet = row.packet
                                    PacketRowView(packet: packet, userCoordinate: liveLocationManager.currentCoordinate) {
                                        navigationState.focusOnMap(
                                            contactId: packet.contactId,
                                            publicKey: packet.contactPublicKey
                                        )
                                    }
                                        .onAppear {
                                            let rowKey = packetStableKey(packet)
                                            guard let index = packets.firstIndex(where: { packetStableKey($0) == rowKey }) else { return }
                                            if index >= max(0, renderedPacketCount - 5) {
                                                // Preserve current viewport row when appending more items.
                                                feedScrollPositionKey = rowKey

                                                if renderedPacketCount < packets.count {
                                                    loadMoreVisiblePackets(anchorKey: rowKey)
                                                } else {
                                                    Task {
                                                        await loadOlderHistoryIfNeeded(triggerIndex: index, anchorKey: rowKey)
                                                    }
                                                }
                                            }
                                        }
                                        .listRowBackground(Color.clear)
                                        .meshListRowSeparator(.visible)
                                        .meshListRowSeparatorTint(Color(red: 0.20, green: 0.21, blue: 0.23))
                                        .listRowInsets(EdgeInsets(top: 0, leading: 0, bottom: 0, trailing: 0))
                                }

                                if isLoadingOlder {
                                    HStack {
                                        Spacer()
                                        ProgressView()
                                            .tint(.cyan)
                                        Text("Loading older packets...")
                                            .font(.system(size: 11, design: .monospaced))
                                            .foregroundColor(.gray)
                                        Spacer()
                                    }
                                    .padding(.vertical, 8)
                                    .listRowBackground(Color.clear)
                                    .meshListRowSeparator(.hidden)
                                } else if !hasMoreHistory && !packets.isEmpty {
                                    HStack {
                                        Spacer()
                                        Text("Reached oldest packet in database")
                                            .font(.system(size: 11, design: .monospaced))
                                            .foregroundColor(.gray)
                                        Spacer()
                                    }
                                    .padding(.vertical, 8)
                                    .listRowBackground(Color.clear)
                                    .meshListRowSeparator(.hidden)
                                }
                            }
                            .listStyle(.plain)
                            .meshScrollContentBackgroundHidden()
                            .scrollPosition(id: $feedScrollPositionKey)
                            .meshRefreshable {
                                await refreshFeed()
                            }
                        }
                    } else {
                        ChannelsFeedView()
                            .frame(maxWidth: .infinity, maxHeight: .infinity)
                    }
                }
            }
            .navigationTitle("Live Feed")
            .meshNavigationBarInline()
            .onAppear {
                hasAppeared = true
                liveLocationManager.requestCurrentLocation()
                Task { await primeKnownDevices() }
                if scenePhase == .active && isCurrentTab {
                    requestLiveStreamRestart(force: true)
                }
            }
            .onDisappear {
                hasAppeared = false
                if !shouldKeepNotificationStream {
                    updateTask?.cancel()
                    restartTask?.cancel()
                }
                endBackgroundNotificationTask()
            }
            .onChange(of: scenePhase) { _, phase in
                guard hasAppeared else { return }

                if phase == .active {
                    endBackgroundNotificationTask()
                    if isCurrentTab {
                        requestLiveStreamRestart(force: true)
                    }
                } else {
                    if shouldKeepNotificationStream {
                        beginBackgroundNotificationTask()
                    } else {
                        updateTask?.cancel()
                        restartTask?.cancel()
                    }
                }
            }
            .onChange(of: navigationState.selectedTab) { _, newTab in
                guard hasAppeared else { return }

                if newTab == 0 {
                    if scenePhase == .active {
                        requestLiveStreamRestart(force: true)
                    }
                } else if !shouldKeepNotificationStream {
                    updateTask?.cancel()
                    restartTask?.cancel()
                    endBackgroundNotificationTask()
                }
            }
            .onChange(of: showADV) { _, _ in handleFilterChange() }
            .onChange(of: showMSG) { _, _ in handleFilterChange() }
            .onChange(of: showPUB) { _, _ in handleFilterChange() }
            .onChange(of: showRAW) { _, _ in handleFilterChange() }
            .onChange(of: showTEL) { _, _ in handleFilterChange() }
            .onChange(of: showSYS) { _, _ in handleFilterChange() }
            .onChange(of: collectorFilterRaw) { _, _ in handleFilterChange() }
        }
    }

        private var liveFeedHeaderLogoHeight: CGFloat {
    #if os(tvOS)
        return 34
    #else
        return 24
    #endif
        }

        private var liveFeedHeaderTitleSize: CGFloat {
    #if os(tvOS)
        return 24
    #else
        return 16
    #endif
        }

    private func handleFilterChange() {
        applyVisibleFilters()
        hasMoreHistory = true
        oldestLoadedTimestampMs = packetBuffer.compactMap { packetTimestampMs($0) }.min()
        requestLiveStreamRestart()
    }

    private func loadMoreVisiblePackets(anchorKey: String? = nil) {
        let target = min(packets.count, renderedPacketCount + pageSize)
        if target > renderedPacketCount {
            renderedPacketCount = target
            if let anchorKey {
                Task { @MainActor in
                    // Re-anchor after list expansion so the user stays where they were.
                    feedScrollPositionKey = anchorKey
                }
            }
        }
    }

    private func loadOlderHistoryIfNeeded(triggerIndex: Int, anchorKey: String? = nil) async {
        guard triggerIndex >= max(0, renderedPacketCount - 5) else { return }
        guard renderedPacketCount >= packets.count else { return }
        guard hasMoreHistory else { return }
        guard !isLoadingOlder else { return }
        guard !selectedTypes.isEmpty else { return }

        // Need an oldest timestamp cursor to page backwards.
        if oldestLoadedTimestampMs == nil {
            oldestLoadedTimestampMs = packets.compactMap { packetTimestampMs($0) }.min()
        }
        guard let beforeMs = oldestLoadedTimestampMs, beforeMs > 0 else {
            hasMoreHistory = false
            return
        }

        isLoadingOlder = true
        defer { isLoadingOlder = false }

        let previousOldest = beforeMs

        do {
            let response = try await apiClient.fetchLiveFeed(
                beforeMs: beforeMs,
                types: selectedTypes,
                limit: pageSize
            )

            await MainActor.run {
                let previousRendered = renderedPacketCount
                applyIncoming(response, advanceCursor: false, allowNotifications: false)

                // Reveal one additional settings-sized page of newly loaded history,
                // while keeping the previous row anchored in place.
                renderedPacketCount = min(packets.count, previousRendered + pageSize)
                if let anchorKey {
                    feedScrollPositionKey = anchorKey
                }

                if let oldest = response.oldestTimestampMs {
                    oldestLoadedTimestampMs = oldest
                } else {
                    oldestLoadedTimestampMs = packets.compactMap { packetTimestampMs($0) }.min()
                }

                if let hasMore = response.hasMore {
                    hasMoreHistory = hasMore
                } else {
                    hasMoreHistory = !response.packets.isEmpty && response.packets.count >= pageSize
                }

                // Stop if the cursor did not move backwards to avoid refetch loops.
                if let currentOldest = oldestLoadedTimestampMs,
                   currentOldest >= previousOldest {
                    hasMoreHistory = false
                }
            }
        } catch {
            print("Error loading older live feed history: \(error)")
        }
    }

    private func streamConfigKey() -> String {
        selectedTypes.joined(separator: ",") + "|" + collectorFilterRaw
    }

    private func requestLiveStreamRestart(force: Bool = false) {
        guard (scenePhase == .active && isCurrentTab) || shouldKeepNotificationStream else {
            updateTask?.cancel()
            restartTask?.cancel()
            return
        }

        let key = streamConfigKey()
        if !force && key == lastStreamConfigKey {
            return
        }
        lastStreamConfigKey = key

        restartTask?.cancel()
        restartTask = Task {
            // Debounce startup and rapid toggle changes to avoid churn.
            try? await Task.sleep(nanoseconds: 250_000_000)
            if Task.isCancelled { return }
            await MainActor.run {
                startLiveStream()
            }
        }
    }

    private func startLiveStream() {
        updateTask?.cancel()
        updateTask = Task {
            // If user disables all packet types, stop loading immediately.
            if selectedTypes.isEmpty {
                await MainActor.run {
                    isLoading = false
                    packets = []
                }
                return
            }

            while !Task.isCancelled {
                do {
                    await MainActor.run {
                        isLoading = true
                    }

                    let stream = apiClient.streamLiveFeed(
                        sinceMs: lastFetchTimeMs,
                        types: selectedTypes,
                        limit: maxVisiblePackets
                    )

                    // Stream is connected; hide spinner even if no packet arrives yet.
                    await MainActor.run {
                        isLoading = false
                    }

                    for try await response in stream {
                        applyIncoming(response, advanceCursor: true, allowNotifications: true)
                        isLoading = false
                    }
                } catch let urlError as URLError where urlError.code == .cancelled {
                    await MainActor.run {
                        isLoading = false
                    }
                    return
                } catch {
                    print("Live stream error: \(error)")

                    await MainActor.run {
                        isLoading = false
                    }

                    // Fallback path for servers that do not yet expose SSE endpoint.
                    await refreshFeed()

                    if Task.isCancelled {
                        return
                    }

                    try? await Task.sleep(nanoseconds: UInt64(fallbackPollingDelay * 1_000_000_000))
                    continue
                }

                if Task.isCancelled {
                    return
                }

                // Server intentionally closes streams periodically; reconnect quickly.
                try? await Task.sleep(nanoseconds: UInt64(reconnectDelay * 1_000_000_000))
            }
        }
    }

    private func refreshFeed() async {
        defer { isLoading = false }

        if selectedTypes.isEmpty {
            await MainActor.run {
                applyVisibleFilters()
                isLoading = false
            }
            return
        }

        do {
            isLoading = true
            let response = try await apiClient.fetchLiveFeed(
                sinceMs: lastFetchTimeMs,
                types: selectedTypes,
                limit: pageSize
            )
            
            applyIncoming(response, advanceCursor: true, allowNotifications: true)
        } catch let urlError as URLError where urlError.code == .cancelled {
            // Expected when refresh tasks overlap due to view lifecycle changes.
            return
        } catch {
            print("Error fetching live feed: \(error)")
        }
    }

    @MainActor
    private func applyIncoming(_ response: LiveFeedResponse, advanceCursor: Bool, allowNotifications: Bool) {
        var merged: [Packet] = []
        var seen = Set<String>()
        let existingPacketKeys = Set(packetBuffer.map { packetStableKey($0) })
        var freshPackets: [Packet] = []

        for packet in response.packets + packetBuffer {
            let key = packetStableKey(packet)
            if seen.insert(key).inserted {
                merged.append(packet)

                if !existingPacketKeys.contains(key) {
                    freshPackets.append(packet)
                }
            }
        }

        packetBuffer = merged.sorted { lhs, rhs in
            let lts = packetTimestampMs(lhs) ?? 0
            let rts = packetTimestampMs(rhs) ?? 0
            if lts == rts {
                return lhs.id > rhs.id
            }
            return lts > rts
        }
        applyVisibleFilters()
        oldestLoadedTimestampMs = packetBuffer.compactMap { packetTimestampMs($0) }.min()

        if advanceCursor {
            lastFetchTimeMs = response.timestampMs
        }

        if let hasMore = response.hasMore {
            hasMoreHistory = hasMore || !packetBuffer.isEmpty
        }

        if allowNotifications {
            handleNotifications(for: freshPackets)
        }
    }

    @MainActor
    private func applyVisibleFilters() {
        let enabledTypes = Set(selectedTypes)
        let collectorIds = selectedCollectorIds

        let visible = packetBuffer.filter { packet in
            guard enabledTypes.contains(packet.type) else { return false }

            if collectorIds.isEmpty {
                return true
            }

            guard let rid = packet.reporterId else { return false }
            return collectorIds.contains(rid)
        }

        packets = visible
        renderedPacketCount = min(max(40, renderedPacketCount), packets.count)
        if renderedPacketCount == 0 && !packets.isEmpty {
            renderedPacketCount = min(40, packets.count)
        }
    }

    private func packetTimestampMs(_ packet: Packet) -> Int? {
        if let ts = parseTimestampMs(packet.receivedAt) { return ts }
        if let ts = parseTimestampMs(packet.sentAt) { return ts }
        if let created = packet.createdAt, let ts = parseTimestampMs(created) { return ts }
        return nil
    }

    private func parseTimestampMs(_ value: String) -> Int? {
        let text = value.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !text.isEmpty else { return nil }

        let iso = ISO8601DateFormatter()
        iso.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        if let date = iso.date(from: text) {
            return Int(date.timeIntervalSince1970 * 1000)
        }
        iso.formatOptions = [.withInternetDateTime]
        if let date = iso.date(from: text) {
            return Int(date.timeIntervalSince1970 * 1000)
        }

        let parser = DateFormatter()
        parser.locale = Locale(identifier: "en_US_POSIX")
        parser.timeZone = TimeZone(secondsFromGMT: 0)
        let formats = [
            "yyyy-MM-dd HH:mm:ss.SSS",
            "yyyy-MM-dd HH:mm:ss"
        ]
        for format in formats {
            parser.dateFormat = format
            if let date = parser.date(from: text) {
                return Int(date.timeIntervalSince1970 * 1000)
            }
        }

        return nil
    }

    private func primeKnownDevices() async {
        do {
            let response = try await apiClient.fetchContacts()
            await MainActor.run {
                knownContactIds.formUnion(response.contacts.map { $0.id })
                let keys = response.contacts
                    .map { $0.publicKey.uppercased() }
                    .filter { !$0.isEmpty }
                knownContactKeys.formUnion(keys)
            }
        } catch {
            // Keep silent: notification baseline can also be established from feed packets.
        }
    }

    @MainActor
    private func handleNotifications(for freshPackets: [Packet]) {
        guard !freshPackets.isEmpty else { return }

        if !notificationsPrimed {
            notificationsPrimed = true

            for packet in packets {
                let key = "\(packet.type)-\(packet.id)"
                if packet.type == "MSG" || packet.type == "PUB" {
                    knownMessagePacketKeys.insert(key)
                }
                if let cid = packet.contactId {
                    knownContactIds.insert(cid)
                }
                if let pubKey = packet.contactPublicKey, !pubKey.isEmpty {
                    knownContactKeys.insert(pubKey.uppercased())
                }
            }
            return
        }

        for packet in freshPackets {
            if packet.type == "MSG" || packet.type == "PUB" {
                let key = "\(packet.type)-\(packet.id)"
                if !knownMessagePacketKeys.contains(key) {
                    if notifyNewMessage {
                        let sender = packet.contactName ?? String(packet.contactPublicKey?.prefix(10) ?? "Unknown")
                        let text = packet.message ?? "(no text)"
                        postLocalNotification(
                            id: "msg-\(key)",
                            title: "New Message",
                            body: "\(sender): \(text)"
                        )
                    }
                    knownMessagePacketKeys.insert(key)
                }
            }

            var isUnknownDevice = false
            if let cid = packet.contactId {
                if !knownContactIds.contains(cid) {
                    isUnknownDevice = true
                    knownContactIds.insert(cid)
                }
            } else if let pubKey = packet.contactPublicKey, !pubKey.isEmpty {
                let normalized = pubKey.uppercased()
                if !knownContactKeys.contains(normalized) {
                    isUnknownDevice = true
                    knownContactKeys.insert(normalized)
                }
            }

            if isUnknownDevice && notifyNewDevice {
                let name = packet.contactName ?? "Unknown Device"
                let key = packet.contactPublicKey.map { String($0.prefix(12)) } ?? "no-key"
                postLocalNotification(
                    id: "dev-\(packet.id)-\(key)",
                    title: "New Device Detected",
                    body: "\(name) [\(key)]"
                )
            }
        }
    }

    private func postLocalNotification(id: String, title: String, body: String) {
#if os(iOS)
        let center = UNUserNotificationCenter.current()

        center.getNotificationSettings { settings in
            guard settings.authorizationStatus == .authorized ||
                    settings.authorizationStatus == .provisional ||
                    settings.authorizationStatus == .ephemeral else {
                return
            }

            let content = UNMutableNotificationContent()
            content.title = title
            content.body = body
            content.sound = .default

            let trigger = UNTimeIntervalNotificationTrigger(timeInterval: 0.2, repeats: false)
            let request = UNNotificationRequest(identifier: id, content: content, trigger: trigger)
            center.add(request)
        }
#else
    _ = (id, title, body)
#endif
    }

    private func beginBackgroundNotificationTask() {
#if os(iOS)
        guard backgroundTaskId == .invalid else { return }
        backgroundTaskId = UIApplication.shared.beginBackgroundTask(withName: "meshlog-live-notifications") {
            endBackgroundNotificationTask()
        }
#endif
    }

    private func endBackgroundNotificationTask() {
#if os(iOS)
        guard backgroundTaskId != .invalid else { return }
        UIApplication.shared.endBackgroundTask(backgroundTaskId)
        backgroundTaskId = .invalid
#endif
    }
}

struct PacketRowView: View {
    let packet: Packet
    let userCoordinate: CLLocationCoordinate2D?
    let onOpenMap: (() -> Void)?

    @AppStorage("distance_unit") private var distanceUnit = "km"

    private func channelTint(_ rawName: String) -> Color {
        let normalized = normalizedChannelName(rawName)
        let hue = Double(abs(normalized.hashValue) % 360) / 360.0
        return Color(hue: hue, saturation: 0.55, brightness: 0.75)
    }

    private var resolvedScope: Int? {
        if let value = packet.scope { return value }
        return packet.reports.compactMap { $0.scope }.first
    }

    private var resolvedRouteType: Int? {
        if let value = packet.routeType { return value }
        return packet.reports.compactMap { $0.routeType }.first
    }

    private var resolvedSenderAt: String {
        if let sender = packet.senderAt, !sender.isEmpty { return sender }
        if let sender = packet.reports.compactMap({ $0.senderAt }).first, !sender.isEmpty { return sender }
        return packet.sentAt
    }

    private var resolvedPath: String? {
        if let p = packet.path, !p.isEmpty { return p }
        return packet.reports.compactMap { $0.path }.filter { !$0.isEmpty }.first
    }

    private var resolvedSnr: Double? {
        if let v = packet.snr { return v }
        return packet.reports.compactMap { $0.snr }.first
    }

    private var hopCountText: String? {
        guard let path = resolvedPath, !path.isEmpty else { return "dir" }
        if path.contains("->") {
            let parts = path.components(separatedBy: "->").filter { !$0.isEmpty }
            let hops = max(0, parts.count - 1)
            return hops > 0 ? "\(hops)h" : "dir"
        }
        let hops = path.components(separatedBy: ",").filter { !$0.isEmpty }.count
        return hops > 0 ? "\(hops)h" : "dir"
    }

    private var resolvedDistanceText: String? {
        guard let lat = packet.latitude,
              let lon = packet.longitude,
              let userCoordinate else {
            return nil
        }

        let packetLocation = CLLocation(latitude: lat, longitude: lon)
        let userLocation = CLLocation(latitude: userCoordinate.latitude, longitude: userCoordinate.longitude)
        let meters = userLocation.distance(from: packetLocation)
        guard meters.isFinite else { return nil }

        if distanceUnit == "mi" {
            let miles = meters / 1609.344
            if miles < 0.1 {
                let feet = max(1, Int((miles * 5280).rounded()))
                return "\(feet)ft"
            }
            return miles >= 10 ? String(format: "%.0fmi", miles) : String(format: "%.1fmi", miles)
        }

        if meters < 1000 {
            return "\(Int(meters.rounded()))m"
        }

        let km = meters / 1000.0
        return km >= 10 ? String(format: "%.0fkm", km) : String(format: "%.1fkm", km)
    }

    private var byteHashText: String? {
        guard let hash = packet.messageHash, !hash.isEmpty else { return nil }
        let size = max(1, min(packet.hashSize ?? 1, 3))
        let chars = min(hash.count, size * 2)
        return String(hash.prefix(chars))
    }

    private var regionScopeBadgeText: String? {
        if packet.type == "MSG" || packet.type == "PUB" {
            guard let scope = resolvedScope else { return "*" }
            return scope <= 0 ? "*" : "#\(scope)"
        }

        guard let scope = resolvedScope else { return nil }
        return scope <= 0 ? "*" : "#\(scope)"
    }
    
    var packetColor: Color {
        switch packet.type {
        case "ADV": return Color(red: 0.42, green: 0.78, blue: 0.72)
        case "MSG": return Color(red: 0.46, green: 0.67, blue: 0.93)
        case "PUB": return Color(red: 0.82, green: 0.55, blue: 0.94)
        case "RAW": return Color(red: 0.9, green: 0.7, blue: 0.43)
        case "TEL": return Color(red: 0.82, green: 0.76, blue: 0.34)
        case "SYS": return Color(red: 0.91, green: 0.43, blue: 0.43)
        default: return .gray
        }
    }

    private var primaryLabel: String {
        switch packet.type {
        case "SYS":
            if let r = packet.reporterName, !r.isEmpty { return r }
            if let k = packet.contactPublicKey, !k.isEmpty { return String(k.prefix(14)) + "..." }
            return "Unknown Reporter"
        default:
            if let name = packet.contactName, !name.isEmpty { return name }
            if let key = packet.contactPublicKey, !key.isEmpty { return String(key.prefix(14)) + "..." }
            return "Unknown"
        }
    }
    
    var body: some View {
#if os(tvOS)
        tvPacketRow
#else
        iosPacketRow
#endif
    }

    @ViewBuilder
    private var iosPacketRow: some View {
        HStack(alignment: .top, spacing: 0) {
            // Left accent stripe — type colour at a glance, mirrors WebUI row tinting
            packetColor
                .frame(width: 3)
                .padding(.trailing, 8)

            VStack(alignment: .leading, spacing: 2) {
                // Line 1: [type] [HH:mm:ss] [Xb] [Nh] [SNRdB] [Nrx] [channel/#scope] — device name
                HStack(spacing: 4) {
                    Text(packet.rawSubtypeLabel ?? packet.type)
                        .font(.system(size: 9, weight: .bold, design: .monospaced))
                        .foregroundColor(packetColor)
                        .frame(minWidth: 26, alignment: .leading)

                    Text(formatTime(packet.receivedAt))
                        .font(.system(size: 10, design: .monospaced))
                        .foregroundColor(Color(red: 0.52, green: 0.55, blue: 0.60))

                    if let hs = packet.hashSize {
                        InlineMetaBadge(text: "\(hs)b", style: .hashSize)
                    }
                    if let hops = hopCountText {
                        InlineMetaBadge(text: hops, style: .metric)
                    }
                    if let snr = resolvedSnr {
                        SnrBadge(snr: snr)
                    }
                    if let distance = resolvedDistanceText {
                        InlineMetaBadge(text: distance, style: .metric)
                    }
                    if !packet.reports.isEmpty {
                        InlineMetaBadge(text: "\(packet.reports.count)rx", style: .metric)
                    }
                    if packet.type == "PUB", let ch = packet.channelName, !ch.isEmpty {
                        ChannelInlineBadge(text: displayChannelName(ch), tint: channelTint(ch))
                    }
                    if let scopeText = regionScopeBadgeText {
                        InlineMetaBadge(text: scopeText, style: .scope)
                    }

                    Spacer(minLength: 4)

                    Text(primaryLabel)
                        .font(.system(size: 11, weight: .semibold, design: .monospaced))
                        .foregroundColor(.white)
                        .lineLimit(1)
                        .truncationMode(.middle)
                }

                // Line 2: message body (MSG / PUB)
                if let msg = packet.message, !msg.isEmpty {
                    Text(msg)
                        .font(.system(size: 11))
                        .foregroundColor(Color(red: 0.88, green: 0.91, blue: 0.96))
                        .lineLimit(2)
                        .padding(.leading, 34)
                }

                // Telemetry / SYS summary
                if let tel = packet.telemetrySummary {
                    Text(tel)
                        .font(.system(size: 9, design: .monospaced))
                        .foregroundColor(Color(red: 0.82, green: 0.76, blue: 0.34).opacity(0.9))
                        .lineLimit(1)
                        .padding(.leading, 34)
                }
                if let sys = packet.sysSummary {
                    Text(sys)
                        .font(.system(size: 9, design: .monospaced))
                        .foregroundColor(Color(red: 0.91, green: 0.43, blue: 0.43).opacity(0.9))
                        .lineLimit(1)
                        .padding(.leading, 34)
                }

                // GPS location
                if let lat = packet.latitude, let lon = packet.longitude {
                    HStack(spacing: 3) {
                        Image(systemName: "location.fill")
                            .font(.system(size: 8))
                        Text(String(format: "%.4f, %.4f", lat, lon))
                            .font(.system(size: 9, design: .monospaced))
                    }
                    .foregroundColor(.cyan.opacity(0.8))
                    .padding(.leading, 34)
                }
            }
            .padding(.vertical, 5)
            .padding(.trailing, 10)
        }
        .contentShape(Rectangle())
        .onTapGesture {
            guard let onOpenMap,
                  (packet.latitude != nil && packet.longitude != nil) ||
                  packet.contactId != nil ||
                  (packet.contactPublicKey?.isEmpty == false) else { return }
            onOpenMap()
        }
    }

    @ViewBuilder
    private var tvPacketRow: some View {
        HStack(alignment: .top, spacing: 0) {
            packetColor
                .frame(width: 6)

            VStack(alignment: .leading, spacing: 9) {
                HStack(alignment: .firstTextBaseline, spacing: 10) {
                    Text(packet.rawSubtypeLabel ?? packet.type)
                        .font(.system(size: 14, weight: .bold, design: .monospaced))
                        .foregroundColor(packetColor)

                    Text(formatTime(packet.receivedAt))
                        .font(.system(size: 14, design: .monospaced))
                        .foregroundColor(Color(red: 0.64, green: 0.68, blue: 0.74))

                    Spacer(minLength: 6)

                    Text(primaryLabel)
                        .font(.system(size: 19, weight: .semibold, design: .rounded))
                        .foregroundColor(.white)
                        .lineLimit(1)
                        .truncationMode(.tail)
                }

                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(spacing: 8) {
                        if let hs = packet.hashSize {
                            InlineMetaBadge(text: "\(hs)b", style: .hashSize)
                        }
                        if let hops = hopCountText {
                            InlineMetaBadge(text: hops, style: .metric)
                        }
                        if let snr = resolvedSnr {
                            SnrBadge(snr: snr)
                        }
                        if let distance = resolvedDistanceText {
                            InlineMetaBadge(text: distance, style: .metric)
                        }
                        if !packet.reports.isEmpty {
                            InlineMetaBadge(text: "\(packet.reports.count)rx", style: .metric)
                        }
                        if packet.type == "PUB", let ch = packet.channelName, !ch.isEmpty {
                            ChannelInlineBadge(text: displayChannelName(ch), tint: channelTint(ch))
                        }
                        if let scopeText = regionScopeBadgeText {
                            InlineMetaBadge(text: scopeText, style: .scope)
                        }
                    }
                }

                if let msg = packet.message, !msg.isEmpty {
                    Text(msg)
                        .font(.system(size: 17, weight: .regular))
                        .foregroundColor(Color(red: 0.92, green: 0.94, blue: 0.97))
                        .lineLimit(3)
                }

                if let tel = packet.telemetrySummary {
                    Text(tel)
                        .font(.system(size: 13, design: .monospaced))
                        .foregroundColor(Color(red: 0.82, green: 0.76, blue: 0.34).opacity(0.92))
                        .lineLimit(2)
                }

                if let sys = packet.sysSummary {
                    Text(sys)
                        .font(.system(size: 13, design: .monospaced))
                        .foregroundColor(Color(red: 0.91, green: 0.43, blue: 0.43).opacity(0.92))
                        .lineLimit(2)
                }

                if let lat = packet.latitude, let lon = packet.longitude {
                    HStack(spacing: 6) {
                        Image(systemName: "location.fill")
                            .font(.system(size: 12))
                        Text(String(format: "%.4f, %.4f", lat, lon))
                            .font(.system(size: 13, design: .monospaced))
                    }
                    .foregroundColor(.cyan.opacity(0.86))
                }
            }
            .padding(.horizontal, 14)
            .padding(.vertical, 11)
        }
        .background(Color(red: 0.10, green: 0.12, blue: 0.16).opacity(0.95))
        .overlay(
            RoundedRectangle(cornerRadius: 9, style: .continuous)
                .stroke(Color.white.opacity(0.10), lineWidth: 0.8)
        )
        .clipShape(RoundedRectangle(cornerRadius: 9, style: .continuous))
        .padding(.horizontal, 8)
        .padding(.vertical, 4)
        .contentShape(Rectangle())
        .onTapGesture {
            guard let onOpenMap,
                  (packet.latitude != nil && packet.longitude != nil) ||
                  packet.contactId != nil ||
                  (packet.contactPublicKey?.isEmpty == false) else { return }
            onOpenMap()
        }
    }
    
    private func formatTime(_ dateString: String) -> String {
        if dateString.isEmpty { return "--:--:--" }
        let parser = DateFormatter()
        parser.locale = Locale(identifier: "en_US_POSIX")
        let formats = [
            "yyyy-MM-dd HH:mm:ss.SSS",
            "yyyy-MM-dd HH:mm:ss",
            "yyyy-MM-dd'T'HH:mm:ss.SSSXXXXX",
            "yyyy-MM-dd'T'HH:mm:ssXXXXX"
        ]
        for format in formats {
            parser.dateFormat = format
            if let date = parser.date(from: dateString) {
                let display = DateFormatter()
                display.locale = Locale(identifier: "en_US_POSIX")
                display.dateFormat = "HH:mm:ss"
                return display.string(from: date)
            }
        }
        // Fallback: extract HH:mm:ss from "yyyy-MM-dd HH:mm:ss" strings directly
        let parts = dateString.components(separatedBy: " ")
        if parts.count >= 2 { return String(parts[1].prefix(8)) }
        return String(dateString.prefix(8))
    }

    private func formatDateTime(_ dateString: String) -> String {
        if dateString.isEmpty { return "-" }

        let parser = DateFormatter()
        parser.locale = Locale(identifier: "en_US_POSIX")

        let formats = [
            "yyyy-MM-dd HH:mm:ss.SSS",
            "yyyy-MM-dd HH:mm:ss",
            "yyyy-MM-dd'T'HH:mm:ss.SSSXXXXX",
            "yyyy-MM-dd'T'HH:mm:ssXXXXX"
        ]

        for format in formats {
            parser.dateFormat = format
            if let date = parser.date(from: dateString) {
                let display = DateFormatter()
                display.locale = Locale.current
                display.dateFormat = "yyyy-MM-dd HH:mm:ss"
                return display.string(from: date)
            }
        }

        return dateString
    }
}

private struct PacketTypeBadge: View {
    let type: String
    let color: Color

    private var fontSize: CGFloat {
#if os(tvOS)
    return 14
#else
    return 10
#endif
    }

    private var horizontalPadding: CGFloat {
#if os(tvOS)
    return 12
#else
    return 8
#endif
    }

    private var verticalPadding: CGFloat {
#if os(tvOS)
    return 6
#else
    return 4
#endif
    }

    var body: some View {
        Text(type)
        .font(.system(size: fontSize, weight: .bold, design: .monospaced))
            .foregroundColor(color)
        .padding(.horizontal, horizontalPadding)
        .padding(.vertical, verticalPadding)
            .background(color.opacity(0.14))
            .overlay(
                RoundedRectangle(cornerRadius: 999)
                    .stroke(color.opacity(0.35), lineWidth: 0.8)
            )
            .clipShape(Capsule())
    }
}

private struct InlineMetaBadge: View {
    enum Style {
        case `default`
        case hashSize
        case scope
        case metric
    }

    let text: String
    let style: Style

        private var fontSize: CGFloat {
    #if os(tvOS)
        return 13
    #else
        return 9
    #endif
        }

        private var horizontalPadding: CGFloat {
    #if os(tvOS)
        return 9
    #else
        return 6
    #endif
        }

        private var verticalPadding: CGFloat {
    #if os(tvOS)
        return 5
    #else
        return 3
    #endif
        }

    private var colors: (text: Color, background: Color, border: Color) {
        switch style {
        case .hashSize:
            return (
                Color(red: 0.79, green: 0.80, blue: 0.82),
                Color(red: 0.29, green: 0.31, blue: 0.35),
                Color(red: 0.40, green: 0.43, blue: 0.47)
            )
        case .scope:
            return (
                Color(red: 0.86, green: 0.94, blue: 0.90),
                Color(red: 0.21, green: 0.32, blue: 0.29),
                Color(red: 0.31, green: 0.48, blue: 0.42)
            )
        case .metric:
            return (
                Color(red: 0.89, green: 0.91, blue: 0.94),
                Color(red: 0.27, green: 0.29, blue: 0.33),
                Color(red: 0.38, green: 0.41, blue: 0.45)
            )
        case .default:
            return (
                Color(red: 0.82, green: 0.86, blue: 0.91),
                Color.white.opacity(0.06),
                Color.white.opacity(0.12)
            )
        }
    }

    var body: some View {
        Text(text)
            .font(.system(size: fontSize, weight: .medium, design: .monospaced))
            .foregroundColor(colors.text)
            .padding(.horizontal, horizontalPadding)
            .padding(.vertical, verticalPadding)
            .background(colors.background)
            .overlay(
                RoundedRectangle(cornerRadius: 999)
                    .stroke(colors.border, lineWidth: 0.7)
            )
            .clipShape(Capsule())
    }
}

private struct SnrBadge: View {
    let snr: Double

    @AppStorage("snr_excellent_threshold") private var snrExcellentThreshold = 9
    @AppStorage("snr_good_threshold") private var snrGoodThreshold = 3
    @AppStorage("snr_fair_threshold") private var snrFairThreshold = -3
    @AppStorage("snr_weak_threshold") private var snrWeakThreshold = -10

    private var label: String {
        "\(String(format: "%.0f", snr))dB"
    }

    private var colors: (text: Color, background: Color, border: Color) {
        let excellent = max(snrExcellentThreshold, snrGoodThreshold + 1)
        let good = min(snrGoodThreshold, excellent - 1)
        let fair = min(snrFairThreshold, good - 1)
        let weak = min(snrWeakThreshold, fair - 1)

        if snr >= Double(excellent) {
            return (Color(red: 0.93, green: 1.0, blue: 0.96), Color(red: 0.13, green: 0.35, blue: 0.23), Color(red: 0.24, green: 0.66, blue: 0.42))
        }
        if snr >= Double(good) {
            return (Color(red: 0.91, green: 1.0, blue: 0.99), Color(red: 0.14, green: 0.34, blue: 0.39), Color(red: 0.27, green: 0.66, blue: 0.76))
        }
        if snr >= Double(fair) {
            return (Color(red: 1.0, green: 0.97, blue: 0.89), Color(red: 0.43, green: 0.33, blue: 0.13), Color(red: 0.78, green: 0.60, blue: 0.23))
        }
        if snr >= Double(weak) {
            return (Color(red: 1.0, green: 0.94, blue: 0.89), Color(red: 0.48, green: 0.27, blue: 0.13), Color(red: 0.83, green: 0.49, blue: 0.22))
        }

        return (Color(red: 1.0, green: 0.93, blue: 0.92), Color(red: 0.43, green: 0.15, blue: 0.19), Color(red: 0.83, green: 0.35, blue: 0.41))
    }

        private var fontSize: CGFloat {
    #if os(tvOS)
        return 13
    #else
        return 9
    #endif
        }

        private var horizontalPadding: CGFloat {
    #if os(tvOS)
        return 9
    #else
        return 6
    #endif
        }

        private var verticalPadding: CGFloat {
    #if os(tvOS)
        return 5
    #else
        return 3
    #endif
        }

    var body: some View {
        Text(label)
            .font(.system(size: fontSize, weight: .medium, design: .monospaced))
            .foregroundColor(colors.text)
            .padding(.horizontal, horizontalPadding)
            .padding(.vertical, verticalPadding)
            .background(colors.background)
            .overlay(
                RoundedRectangle(cornerRadius: 999)
                    .stroke(colors.border, lineWidth: 0.7)
            )
            .clipShape(Capsule())
    }
}

private struct ChannelInlineBadge: View {
    let text: String
    let tint: Color

    private var fontSize: CGFloat {
#if os(tvOS)
    return 13
#else
    return 9
#endif
    }

    private var horizontalPadding: CGFloat {
#if os(tvOS)
    return 9
#else
    return 6
#endif
    }

    private var verticalPadding: CGFloat {
#if os(tvOS)
    return 5
#else
    return 3
#endif
    }

    var body: some View {
        Text(text)
        .font(.system(size: fontSize, weight: .medium, design: .monospaced))
            .foregroundColor(tint)
        .padding(.horizontal, horizontalPadding)
        .padding(.vertical, verticalPadding)
            .background(tint.opacity(0.14))
            .overlay(
                RoundedRectangle(cornerRadius: 999)
                    .stroke(tint.opacity(0.36), lineWidth: 0.8)
            )
            .clipShape(Capsule())
    }
}

private struct TypeFilterBadge: View {
    let type: String
    let isActive: Bool

    private var tint: Color {
        switch type {
        case "ADV": return Color(red: 0.42, green: 0.78, blue: 0.72)
        case "MSG": return Color(red: 0.46, green: 0.67, blue: 0.93)
        case "PUB": return Color(red: 0.82, green: 0.55, blue: 0.94)
        case "RAW": return Color(red: 0.9, green: 0.7, blue: 0.43)
        case "TEL": return Color(red: 0.82, green: 0.76, blue: 0.34)
        case "SYS": return Color(red: 0.91, green: 0.43, blue: 0.43)
        default: return .cyan
        }
    }

        private var activeBackground: Color {
    #if os(tvOS)
        return tint.opacity(0.36)
    #else
        return tint.opacity(0.24)
    #endif
        }

        private var inactiveBackground: Color {
    #if os(tvOS)
        return Color.white.opacity(0.12)
    #else
        return Color.white.opacity(0.05)
    #endif
        }

        private var activeBorder: Color {
    #if os(tvOS)
        return tint.opacity(0.82)
    #else
        return tint.opacity(0.55)
    #endif
        }

        private var inactiveBorder: Color {
    #if os(tvOS)
        return Color.white.opacity(0.30)
    #else
        return Color.white.opacity(0.15)
    #endif
        }

        private var inactiveText: Color {
    #if os(tvOS)
        return Color(red: 0.87, green: 0.91, blue: 0.95)
    #else
        return Color(red: 0.77, green: 0.82, blue: 0.87)
    #endif
        }

        private var fontSize: CGFloat {
    #if os(tvOS)
        return 16
    #else
        return 11
    #endif
        }

        private var horizontalPadding: CGFloat {
    #if os(tvOS)
        return 16
    #else
        return 10
    #endif
        }

        private var verticalPadding: CGFloat {
    #if os(tvOS)
        return 9
    #else
        return 6
    #endif
        }

    var body: some View {
        Text(type)
            .font(.system(size: fontSize, weight: .semibold, design: .monospaced))
            .foregroundColor(isActive ? Color.white : inactiveText)
            .padding(.horizontal, horizontalPadding)
            .padding(.vertical, verticalPadding)
            .background(isActive ? activeBackground : inactiveBackground)
            .overlay(
                RoundedRectangle(cornerRadius: 999)
                    .stroke(isActive ? activeBorder : inactiveBorder, lineWidth: 0.8)
            )
            .clipShape(Capsule())
    }
}

private struct StatBadge: View {
    let label: String
    let value: String

        private var fontSize: CGFloat {
    #if os(tvOS)
        return 12
    #else
        return 9
    #endif
        }

        private var horizontalPadding: CGFloat {
    #if os(tvOS)
        return 10
    #else
        return 7
    #endif
        }

        private var verticalPadding: CGFloat {
    #if os(tvOS)
        return 7
    #else
        return 5
    #endif
        }

    var body: some View {
        HStack(spacing: 4) {
            Text(label)
                .foregroundColor(Color(red: 0.66, green: 0.7, blue: 0.76))
            Text(value)
                .foregroundColor(.white)
        }
        .font(.system(size: fontSize, weight: .semibold, design: .monospaced))
        .padding(.horizontal, horizontalPadding)
        .padding(.vertical, verticalPadding)
        .background(Color.white.opacity(0.05))
        .overlay(
            RoundedRectangle(cornerRadius: 999)
                .stroke(Color.white.opacity(0.14), lineWidth: 0.8)
        )
        .clipShape(Capsule())
    }
}

private struct BrandLogoMark: View {
    let height: CGFloat

    private var image: UIImage? {
        if let named = UIImage(named: "meshcore-austria_logo") {
            return named
        }
        guard let url = Bundle.main.url(forResource: "meshcore-austria_logo", withExtension: "jpg"),
              let data = try? Data(contentsOf: url),
              let decoded = UIImage(data: data) else {
            return nil
        }
        return decoded
    }

    var body: some View {
        Group {
            if let image {
                Image(uiImage: image)
                    .resizable()
                    .scaledToFit()
            } else {
                Image(systemName: "antenna.radiowaves.left.and.right.circle.fill")
                    .resizable()
                    .scaledToFit()
                    .foregroundColor(.cyan)
                    .padding(2)
            }
        }
        .frame(height: height)
    }
}

// MARK: - SubTabButton

private struct SubTabButton: View {
    let label: String
    let active: Bool
    let action: () -> Void

    private var fontSize: CGFloat {
#if os(tvOS)
        return 14
#else
        return 10
#endif
    }

    private var horizontalPadding: CGFloat {
#if os(tvOS)
        return 18
#else
        return 14
#endif
    }

    private var verticalPadding: CGFloat {
#if os(tvOS)
        return 10
#else
        return 8
#endif
    }

    private var activeTextColor: Color {
#if os(tvOS)
    return .white
#else
    return .white
#endif
    }

    private var inactiveTextColor: Color {
#if os(tvOS)
    return Color(red: 0.84, green: 0.88, blue: 0.93)
#else
    return Color(red: 0.52, green: 0.56, blue: 0.62)
#endif
    }

    private var activeBackgroundColor: Color {
#if os(tvOS)
    return Color.cyan.opacity(0.32)
#else
    return Color.clear
#endif
    }

    private var inactiveBackgroundColor: Color {
#if os(tvOS)
    return Color.white.opacity(0.10)
#else
    return Color.clear
#endif
    }

    private var activeBorderColor: Color {
#if os(tvOS)
    return Color.cyan.opacity(0.82)
#else
    return Color.clear
#endif
    }

    private var inactiveBorderColor: Color {
#if os(tvOS)
    return Color.white.opacity(0.24)
#else
    return Color.clear
#endif
    }

    var body: some View {
        Button(action: action) {
            VStack(spacing: 0) {
                Text(label)
                    .font(.system(size: fontSize, weight: .bold, design: .monospaced))
            .foregroundColor(active ? activeTextColor : inactiveTextColor)
                    .padding(.horizontal, horizontalPadding)
                    .padding(.vertical, verticalPadding)

#if os(tvOS)
            .background(
            RoundedRectangle(cornerRadius: 10, style: .continuous)
                .fill(active ? activeBackgroundColor : inactiveBackgroundColor)
            )
            .overlay(
            RoundedRectangle(cornerRadius: 10, style: .continuous)
                .stroke(active ? activeBorderColor : inactiveBorderColor, lineWidth: 1.0)
            )
#endif

#if !os(tvOS)
                Rectangle()
                    .fill(active ? Color.cyan : Color.clear)
                    .frame(height: 2)
#endif
            }
        }
        .buttonStyle(.plain)
    }
}

// MARK: - ChannelsFeedView

/// Live channel messages grouped by channel name — mirrors the WebUI "Channels" concept.
struct ChannelsFeedView: View {
    @EnvironmentObject var apiClient: APIClient
    @EnvironmentObject var navigationState: AppNavigationState
    @Environment(\.scenePhase) private var scenePhase

    /// All know channels from the server (names + hash).
    @State private var channels: [Channel] = []
    /// Buffered PUB packets, newest first.
    @State private var packets: [Packet] = []
    @State private var isLoading = false
    @State private var lastFetchTimeMs: Int = 0
    @State private var streamTask: Task<Void, Never>?
    @State private var hasAppeared = false
    @State private var isLoadingOlder = false
    @State private var hasMoreHistory = true
    @State private var oldestLoadedTimestampMs: Int?
    /// Nil = channel list; non-nil = single channel detail.
    @State private var selectedChannelKey: String?
    @State private var unreadByChannelKey: [String: Int] = [:]
    @AppStorage("channels_last_read_packet_by_key") private var lastReadPacketRaw = ""
    @AppStorage("channels_filter_mode") private var channelFilterModeRaw = "all"
    @AppStorage("channels_filter_selected_keys") private var selectedChannelFilterRaw = "[]"

    private let pageSize = 500

    private enum ChannelFilterMode: String {
        case all
        case selected
    }

    private var channelFilterMode: ChannelFilterMode {
        ChannelFilterMode(rawValue: channelFilterModeRaw) ?? .all
    }

    private var selectedChannelFilterKeys: Set<String> {
        decodeStringSet(selectedChannelFilterRaw)
    }

    private var lastReadPacketByChannelKey: [String: Int] {
        decodeIntMap(lastReadPacketRaw)
    }

    private func keyForChannel(id: Int?, name: String?) -> String {
        if let id { return "id:\(id)" }
        return "name:\(normalizedChannelName(name).lowercased())"
    }

    private func allKeysForGroup(_ group: (key: String, channel: String, id: Int?, packets: [Packet])) -> [String] {
        var keys: [String] = [group.key]
        let nameKey = keyForChannel(id: nil, name: group.channel)
        if !keys.contains(nameKey) {
            keys.append(nameKey)
        }
        if let id = group.id {
            let idKey = keyForChannel(id: id, name: nil)
            if !keys.contains(idKey) {
                keys.append(idKey)
            }
        }
        return keys
    }

    private func readCursor(for group: (key: String, channel: String, id: Int?, packets: [Packet]), map: [String: Int]) -> Int {
        allKeysForGroup(group)
            .compactMap { map[$0] }
            .max() ?? 0
    }

    private func persistReadCursor(_ id: Int, for group: (key: String, channel: String, id: Int?, packets: [Packet]), into map: inout [String: Int]) {
        for key in allKeysForGroup(group) {
            if (map[key] ?? 0) < id {
                map[key] = id
            }
        }
    }

    // PUB packets grouped by channel
    private var grouped: [(key: String, channel: String, id: Int?, packets: [Packet])] {
        var order: [String] = []
        var seen = Set<String>()
        var map: [String: (channel: String, id: Int?, packets: [Packet])] = [:]

        for packet in packets {
            let channelName = normalizedChannelName(packet.channelName ?? "\(packet.channelId.map(String.init) ?? "?")")
            let key = keyForChannel(id: packet.channelId, name: channelName)
            if seen.insert(key).inserted { order.append(key) }
            if map[key] == nil {
                map[key] = (channel: channelName, id: packet.channelId, packets: [])
            }
            map[key, default: (channelName, packet.channelId, [])].packets.append(packet)
        }

        // Merge known channels that may not have messages yet
        for ch in channels {
            let key = keyForChannel(id: ch.id, name: ch.name)
            if seen.insert(key).inserted {
                order.append(key)
                map[key] = (channel: ch.name, id: ch.id, packets: [])
            }
        }

        return order.map { key in
            let entry = map[key]!
            return (key: key, channel: entry.channel, id: entry.id, packets: entry.packets)
        }
    }

    private var displayedGroups: [(key: String, channel: String, id: Int?, packets: [Packet])] {
        if channelFilterMode == .all {
            return grouped
        }

        let selected = selectedChannelFilterKeys
        guard !selected.isEmpty else { return [] }

        return grouped.filter { group in
            !Set(allKeysForGroup(group)).isDisjoint(with: selected)
        }
    }

    var body: some View {
        ZStack {
            if isLoading && packets.isEmpty {
                VStack(spacing: 10) {
                    ProgressView().tint(.cyan)
                    Text("Loading channels…")
                        .font(.system(size: 12, design: .monospaced))
                        .foregroundColor(.gray)
                }
                .frame(maxWidth: .infinity, maxHeight: .infinity)
            } else if grouped.isEmpty {
                VStack(spacing: 12) {
                    Image(systemName: "bubble.left.and.bubble.right")
                        .font(.system(size: 32))
                        .foregroundColor(.gray)
                    Text("No channels yet")
                        .font(.system(size: 14, weight: .semibold))
                        .foregroundColor(.white)
                    Text("Waiting for channel messages…")
                        .font(.system(size: 12))
                        .foregroundColor(.gray)
                }
                .frame(maxWidth: .infinity, maxHeight: .infinity)
            } else if displayedGroups.isEmpty {
                VStack(spacing: 12) {
                    Image(systemName: "line.3.horizontal.decrease.circle")
                        .font(.system(size: 32))
                        .foregroundColor(.gray)
                    Text("No selected channels")
                        .font(.system(size: 14, weight: .semibold))
                        .foregroundColor(.white)
                    Text("Switch to All, or pick channels below the filter bar.")
                        .font(.system(size: 12))
                        .foregroundColor(.gray)
                }
                .frame(maxWidth: .infinity, maxHeight: .infinity)
            } else {
                VStack(spacing: 8) {
                    channelFilterControls
                        .padding(.horizontal, 12)
                        .padding(.top, 6)

                    List {
                    if let selectedKey = selectedChannelKey,
                       let selectedGroup = displayedGroups.first(where: { $0.key == selectedKey }) {
                        Section {
                            if selectedGroup.packets.isEmpty {
                                Text("No messages yet")
                                    .font(.system(size: 11, design: .monospaced))
                                    .foregroundColor(Color(red: 0.35, green: 0.38, blue: 0.42))
                                    .listRowBackground(Color.clear)
                                        .meshListRowSeparator(.hidden)
                            } else {
                                let detailPackets = packetsForDetail(selectedGroup)
                                ForEach(detailPackets, id: \.id) { packet in
                                    ChannelMessageRow(packet: packet) {
                                        navigationState.focusOnMap(
                                            contactId: packet.contactId,
                                            publicKey: packet.contactPublicKey
                                        )
                                    }
                                    .onAppear {
                                        guard packet.id == detailPackets.first?.id else { return }
                                        Task { await loadOlderHistoryIfNeeded() }
                                    }
                                    .id(packet.id)
                                    .listRowBackground(Color.clear)
                                    .meshListRowSeparator(.visible)
                                    .meshListRowSeparatorTint(Color(red: 0.18, green: 0.20, blue: 0.22))
                                    .listRowInsets(EdgeInsets(top: 0, leading: 0, bottom: 0, trailing: 0))
                                }

                                if isLoadingOlder {
                                    HStack(spacing: 8) {
                                        ProgressView().tint(.cyan)
                                        Text("Loading older channel messages…")
                                            .font(.system(size: 11, design: .monospaced))
                                            .foregroundColor(.gray)
                                    }
                                    .frame(maxWidth: .infinity, alignment: .center)
                                    .padding(.vertical, 8)
                                    .listRowBackground(Color.clear)
                                    .meshListRowSeparator(.hidden)
                                } else if !hasMoreHistory {
                                    Text("Reached oldest channel messages in database")
                                        .font(.system(size: 11, design: .monospaced))
                                        .foregroundColor(Color(red: 0.35, green: 0.38, blue: 0.42))
                                        .frame(maxWidth: .infinity, alignment: .center)
                                        .padding(.vertical, 8)
                                        .listRowBackground(Color.clear)
                                        .meshListRowSeparator(.hidden)
                                }
                            }
                        } header: {
                            ChannelHeaderRow(
                                name: selectedGroup.channel,
                                messageCount: selectedGroup.packets.count,
                                unreadCount: unreadByChannelKey[selectedGroup.key] ?? 0,
                                isSelected: true,
                                onTap: {
                                    withAnimation(.easeInOut(duration: 0.18)) {
                                        selectedChannelKey = nil
                                    }
                                }
                            )
                        }
                    } else {
                        ForEach(displayedGroups, id: \.key) { group in
                            ChannelHeaderRow(
                                name: group.channel,
                                messageCount: group.packets.count,
                                unreadCount: unreadByChannelKey[group.key] ?? 0,
                                isSelected: false,
                                onTap: {
                                    withAnimation(.easeInOut(duration: 0.18)) {
                                        selectedChannelKey = group.key
                                        markChannelRead(group)
                                    }
                                }
                            )
                            .listRowInsets(EdgeInsets())
                            .listRowBackground(Color.clear)
                            .meshListRowSeparator(.hidden)
                        }
                    }
                }
                .listStyle(.plain)
                .meshScrollContentBackgroundHidden()
                .meshRefreshable { await reload() }
                }
            }
        }
        .onAppear {
            hasAppeared = true
            normalizeSelectedChannelFilterKeys()
            recalculateUnreadCounts()
            if scenePhase == .active { startStream() }
        }
        .onDisappear {
            hasAppeared = false
            streamTask?.cancel()
        }
        .onChange(of: scenePhase) { _, phase in
            guard hasAppeared else { return }
            if phase == .active { startStream() }
            else { streamTask?.cancel() }
        }
        .onChange(of: channelFilterModeRaw) { _, _ in
            if channelFilterMode == .all {
                normalizeSelectedChannelFilterKeys()
            }
            ensureSelectedDetailStillVisible()
        }
        .onChange(of: selectedChannelFilterRaw) { _, _ in
            ensureSelectedDetailStillVisible()
        }
    }

    @ViewBuilder
    private var channelFilterControls: some View {
        VStack(alignment: .leading, spacing: 8) {
            Picker("Channel Filter", selection: $channelFilterModeRaw) {
                Text("All").tag(ChannelFilterMode.all.rawValue)
                Text("Selected").tag(ChannelFilterMode.selected.rawValue)
            }
            .pickerStyle(.segmented)

            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 8) {
                    ForEach(grouped, id: \.key) { group in
                        let isSelected = selectedChannelFilterKeys.intersection(Set(allKeysForGroup(group))).isEmpty == false

                        Button {
                            toggleChannelFilterSelection(group)
                        } label: {
                            Text(displayChannelName(group.channel))
                                .font(.system(size: 10, weight: .semibold, design: .monospaced))
                                .foregroundColor(
                                    isSelected
                                    ? .white
                                    : Color(red: 0.86, green: 0.90, blue: 0.95)
                                )
                                .padding(.horizontal, 10)
                                .padding(.vertical, 6)
                                .background(isSelected ? Color.cyan.opacity(0.36) : Color.white.opacity(0.12))
                                .overlay(
                                    Capsule().stroke(isSelected ? Color.cyan.opacity(0.82) : Color.white.opacity(0.30), lineWidth: 0.8)
                                )
                                .clipShape(Capsule())
                        }
                        .buttonStyle(.plain)
                    }
                }
            }
        }
    }

    private func startStream() {
        streamTask?.cancel()
        streamTask = Task {
            while !Task.isCancelled {
                await MainActor.run { isLoading = true }
                do {
                    // Also load channel definitions on (re-)connect.
                    let fetchedChannels = try await apiClient.fetchChannels()
                    await MainActor.run {
                        channels = fetchedChannels.filter { $0.enabled }
                        isLoading = false
                    }
                } catch {
                    await MainActor.run { isLoading = false }
                }
                guard !Task.isCancelled else { return }
                do {
                    let stream = apiClient.streamLiveFeed(
                        sinceMs: lastFetchTimeMs,
                        types: ["PUB"],
                        limit: pageSize
                    )
                    await MainActor.run { isLoading = false }
                    for try await response in stream {
                        if Task.isCancelled { return }
                        await MainActor.run { merge(response) }
                    }
                } catch let e as URLError where e.code == .cancelled {
                    return
                } catch {
                    await MainActor.run { isLoading = false }
                    try? await Task.sleep(nanoseconds: 2_000_000_000)
                }
            }
        }
    }

    @MainActor
    private func merge(_ response: LiveFeedResponse) {
        var seen = Set(packets.map { "\($0.id)" })
        var newOnes: [Packet] = []

        for pkt in response.packets where seen.insert("\(pkt.id)").inserted {
            newOnes.append(pkt)
        }
        packets = (newOnes + packets)
            .sorted { $0.receivedAt > $1.receivedAt }
        lastFetchTimeMs = max(lastFetchTimeMs, response.timestampMs)

        if let oldest = response.oldestTimestampMs {
            if let current = oldestLoadedTimestampMs {
                oldestLoadedTimestampMs = min(current, oldest)
            } else {
                oldestLoadedTimestampMs = oldest
            }
        } else if oldestLoadedTimestampMs == nil {
            oldestLoadedTimestampMs = packets.compactMap { packetTimestampMs($0) }.min()
        }

        recalculateUnreadCounts()
    }

    private func packetTimestampMs(_ packet: Packet) -> Int? {
        if let value = parseTimestampMs(packet.receivedAt) { return value }
        if let value = parseTimestampMs(packet.sentAt) { return value }
        if let value = parseTimestampMs(packet.createdAt) { return value }
        return nil
    }

    private func parseTimestampMs(_ value: String?) -> Int? {
        guard let raw = value?.trimmingCharacters(in: .whitespacesAndNewlines), !raw.isEmpty else {
            return nil
        }

        let withMs = DateFormatter()
        withMs.locale = Locale(identifier: "en_US_POSIX")
        withMs.timeZone = TimeZone.current
        withMs.dateFormat = "yyyy-MM-dd HH:mm:ss.SSS"
        if let date = withMs.date(from: raw) {
            return Int(date.timeIntervalSince1970 * 1000)
        }

        let withoutMs = DateFormatter()
        withoutMs.locale = Locale(identifier: "en_US_POSIX")
        withoutMs.timeZone = TimeZone.current
        withoutMs.dateFormat = "yyyy-MM-dd HH:mm:ss"
        if let date = withoutMs.date(from: raw) {
            return Int(date.timeIntervalSince1970 * 1000)
        }

        return nil
    }

    private func loadOlderHistoryIfNeeded() async {
        await MainActor.run {
            if isLoadingOlder || !hasMoreHistory { return }
            isLoadingOlder = true
        }

        defer {
            Task { @MainActor in
                isLoadingOlder = false
            }
        }

        let beforeMs = await MainActor.run {
            oldestLoadedTimestampMs ?? packets.compactMap { packetTimestampMs($0) }.min()
        }

        guard let beforeMs, beforeMs > 0 else {
            await MainActor.run {
                hasMoreHistory = false
            }
            return
        }

        do {
            let response = try await apiClient.fetchLiveFeed(
                beforeMs: beforeMs,
                types: ["PUB"],
                limit: pageSize
            )

            await MainActor.run {
                merge(response)
                if let hasMore = response.hasMore {
                    hasMoreHistory = hasMore
                } else {
                    hasMoreHistory = !response.packets.isEmpty && response.packets.count >= pageSize
                }

                if response.packets.isEmpty {
                    hasMoreHistory = false
                }

                if let oldest = response.oldestTimestampMs {
                    if let current = oldestLoadedTimestampMs {
                        oldestLoadedTimestampMs = min(current, oldest)
                    } else {
                        oldestLoadedTimestampMs = oldest
                    }
                }
            }
        } catch {
            // Keep existing state; user can retry by scrolling to top again.
        }
    }

    @MainActor
    private func packetsForDetail(_ group: (key: String, channel: String, id: Int?, packets: [Packet])) -> [Packet] {
        group.packets.sorted { $0.id < $1.id }
    }

    @MainActor
    private func markChannelRead(_ group: (key: String, channel: String, id: Int?, packets: [Packet])) {
        unreadByChannelKey[group.key] = 0

        guard let newest = group.packets.first else { return }
        var map = lastReadPacketByChannelKey
        persistReadCursor(newest.id, for: group, into: &map)
        lastReadPacketRaw = encodeIntMap(map)
    }

    @MainActor
    private func recalculateUnreadCounts() {
        var readMap = lastReadPacketByChannelKey
        var readMapChanged = false
        var updated: [String: Int] = [:]

        for group in grouped {
            guard !group.packets.isEmpty else {
                updated[group.key] = 0
                continue
            }

            if selectedChannelKey == group.key {
                if let newestId = group.packets.first?.id {
                    let previousCursor = readCursor(for: group, map: readMap)
                    if newestId > previousCursor {
                        persistReadCursor(newestId, for: group, into: &readMap)
                        readMapChanged = true
                    }
                }
                updated[group.key] = 0
                continue
            }

            let lastReadId = readCursor(for: group, map: readMap)
            let unread = group.packets.reduce(into: 0) { partial, pkt in
                if pkt.id > lastReadId {
                    partial += 1
                }
            }
            updated[group.key] = unread
        }

        if readMapChanged {
            lastReadPacketRaw = encodeIntMap(readMap)
        }
        unreadByChannelKey = updated
    }

    private func decodeIntMap(_ raw: String) -> [String: Int] {
        guard let data = raw.data(using: .utf8),
              let map = try? JSONDecoder().decode([String: Int].self, from: data) else {
            return [:]
        }
        return map
    }

    private func decodeStringSet(_ raw: String) -> Set<String> {
        guard let data = raw.data(using: .utf8),
              let values = try? JSONDecoder().decode([String].self, from: data) else {
            return []
        }
        return Set(values)
    }

    private func encodeIntMap(_ value: [String: Int]) -> String {
        guard let data = try? JSONEncoder().encode(value),
              let text = String(data: data, encoding: .utf8) else {
            return "{}"
        }
        return text
    }

    private func encodeStringSet(_ value: Set<String>) -> String {
        guard let data = try? JSONEncoder().encode(Array(value).sorted()),
              let text = String(data: data, encoding: .utf8) else {
            return "[]"
        }
        return text
    }

    @MainActor
    private func normalizeSelectedChannelFilterKeys() {
        let validKeys = Set(grouped.flatMap { allKeysForGroup($0) })
        let normalized = selectedChannelFilterKeys.intersection(validKeys)

        if normalized != selectedChannelFilterKeys {
            selectedChannelFilterRaw = encodeStringSet(normalized)
        }
    }

    @MainActor
    private func ensureSelectedDetailStillVisible() {
        guard let selectedKey = selectedChannelKey else { return }
        if displayedGroups.contains(where: { $0.key == selectedKey }) { return }
        self.selectedChannelKey = nil
    }

    @MainActor
    private func toggleChannelFilterSelection(_ group: (key: String, channel: String, id: Int?, packets: [Packet])) {
        var set = selectedChannelFilterKeys
        let keys = Set(allKeysForGroup(group))

        if set.isDisjoint(with: keys) {
            set.formUnion(keys)
        } else {
            set.subtract(keys)
        }

        selectedChannelFilterRaw = encodeStringSet(set)
    }

    private func reload() async {
        streamTask?.cancel()
        await MainActor.run {
            packets = []
            lastFetchTimeMs = 0
            unreadByChannelKey = [:]
            hasMoreHistory = true
            oldestLoadedTimestampMs = nil
        }
        startStream()
    }
}

// MARK: - ChannelHeaderRow

private struct ChannelHeaderRow: View {
    let name: String
    let messageCount: Int
    let unreadCount: Int
    let isSelected: Bool
    let onTap: () -> Void

    private var channelColor: Color {
        // Deterministic hue from channel name
        let hue = Double(abs(name.hashValue) % 360) / 360.0
        return Color(hue: hue, saturation: 0.55, brightness: 0.75)
    }

    private var rowBackground: Color {
        isSelected
        ? Color.cyan.opacity(0.18)
        : Color(red: 0.10, green: 0.12, blue: 0.16)
    }

    private var rowBorder: Color {
        isSelected
        ? Color.cyan.opacity(0.48)
        : Color.white.opacity(0.10)
    }

    private var messageCountColor: Color {
#if os(tvOS)
        return Color(red: 0.76, green: 0.81, blue: 0.88)
#else
        return Color(red: 0.52, green: 0.55, blue: 0.60)
#endif
    }

    private var chevronColor: Color {
#if os(tvOS)
        return Color(red: 0.72, green: 0.77, blue: 0.85)
#else
        return Color(red: 0.40, green: 0.44, blue: 0.50)
#endif
    }

    var body: some View {
        Button(action: onTap) {
            HStack(spacing: 10) {
                // Coloured pill
                Text(displayChannelName(name))
                    .font(.system(size: 11, weight: .bold, design: .monospaced))
                    .foregroundColor(channelColor)
                    .padding(.horizontal, 9)
                    .padding(.vertical, 5)
                    .background(channelColor.opacity(0.14))
                    .overlay(RoundedRectangle(cornerRadius: 999).stroke(channelColor.opacity(0.36), lineWidth: 0.8))
                    .clipShape(Capsule())

                Spacer()

                if unreadCount > 0 {
                    Text("\(unreadCount)")
                        .font(.system(size: 10, weight: .bold, design: .monospaced))
                        .foregroundColor(.white)
                        .padding(.horizontal, 7)
                        .padding(.vertical, 3)
                        .background(Color(red: 0.83, green: 0.13, blue: 0.20))
                        .clipShape(Capsule())
                }

                if messageCount > 0 {
                    Text("\(messageCount)")
                        .font(.system(size: 10, design: .monospaced))
                        .foregroundColor(messageCountColor)
                }

                Image(systemName: isSelected ? "chevron.left" : "chevron.right")
                    .font(.system(size: 10, weight: .semibold))
                    .foregroundColor(chevronColor)
            }
            .padding(.horizontal, 12)
            .padding(.vertical, 8)
            .background(
                RoundedRectangle(cornerRadius: 10, style: .continuous)
                    .fill(rowBackground)
            )
            .overlay(
                RoundedRectangle(cornerRadius: 10, style: .continuous)
                    .stroke(rowBorder, lineWidth: 0.8)
            )
        }
        .buttonStyle(.plain)
        .listRowInsets(EdgeInsets())
    }
}

// MARK: - ChannelMessageRow

private struct ChannelMessageRow: View {
    let packet: Packet
    let onOpenMap: () -> Void

    private var senderName: String {
        if let n = packet.contactName, !n.isEmpty { return n }
        if let k = packet.contactPublicKey, !k.isEmpty { return String(k.prefix(12)) + "…" }
        return "Unknown"
    }

    private var resolvedSnr: Double? {
        packet.snr ?? packet.reports.compactMap({ $0.snr }).first
    }

    private var resolvedScope: Int? {
        if let value = packet.scope { return value }
        return packet.reports.compactMap { $0.scope }.first
    }

    private var hopCountText: String? {
        let path = packet.path ?? packet.reports.compactMap({ $0.path }).filter({ !$0.isEmpty }).first
        guard let path else { return nil }
        if path.isEmpty { return "dir" }
        if path.contains("->") {
            let parts = path.components(separatedBy: "->").filter { !$0.isEmpty }
            let hops = max(0, parts.count - 1)
            return hops > 0 ? "\(hops)h" : "dir"
        }
        return "dir"
    }

    private func formatTime(_ s: String) -> String {
        guard !s.isEmpty else { return "--:--" }
        let parts = s.components(separatedBy: " ")
        if parts.count >= 2 { return String(parts[1].prefix(8)) }
        return String(s.prefix(8))
    }

    private func linkedMessage(_ text: String) -> AttributedString {
        let baseColor = UIColor(red: 0.88, green: 0.91, blue: 0.96, alpha: 1.0)
        let attr = NSMutableAttributedString(string: text, attributes: [.foregroundColor: baseColor])

        if let detector = try? NSDataDetector(types: NSTextCheckingResult.CheckingType.link.rawValue) {
            let nsRange = NSRange(text.startIndex..<text.endIndex, in: text)
            let matches = detector.matches(in: text, options: [], range: nsRange)

            for match in matches {
                guard let detectedUrl = match.url else { continue }
                let url: URL
                if detectedUrl.scheme == nil {
                    url = URL(string: "https://\(detectedUrl.absoluteString)") ?? detectedUrl
                } else {
                    url = detectedUrl
                }

                attr.addAttribute(.link, value: url, range: match.range)
                attr.addAttribute(.underlineStyle, value: NSUnderlineStyle.single.rawValue, range: match.range)
                attr.addAttribute(.foregroundColor, value: UIColor.systemBlue, range: match.range)
            }
        }

        return AttributedString(attr)
    }

    var body: some View {
        HStack(alignment: .top, spacing: 0) {
            Color(red: 0.82, green: 0.55, blue: 0.94)
                .frame(width: 3)
                .padding(.trailing, 8)

            VStack(alignment: .leading, spacing: 2) {
                // Line 1: sender name · time · hop · SNR
                HStack(spacing: 6) {
                    Text(senderName)
                        .font(.system(size: 11, weight: .semibold, design: .monospaced))
                        .foregroundColor(.white)
                        .lineLimit(1)

                    Spacer()

                    if let h = hopCountText {
                        InlineMetaBadge(text: h, style: .metric)
                    }
                    if let snr = resolvedSnr {
                        SnrBadge(snr: snr)
                    }
                    if let scope = resolvedScope {
                        InlineMetaBadge(text: scope <= 0 ? "*" : "#\(scope)", style: .scope)
                    }
                    Text(formatTime(packet.receivedAt))
                        .font(.system(size: 9, design: .monospaced))
                        .foregroundColor(Color(red: 0.44, green: 0.47, blue: 0.52))
                }
                .contentShape(Rectangle())
                .onTapGesture { onOpenMap() }

                // Line 2: message body
                if let msg = packet.message, !msg.isEmpty {
                    Text(linkedMessage(msg))
                        .font(.system(size: 12))
                        .lineLimit(4)
                        .tint(.blue)
                }
            }
            .padding(.vertical, 7)
            .padding(.trailing, 12)
        }
        .contentShape(Rectangle())
    }
}

#Preview {
    LiveFeedView()
        .environmentObject(APIClient())
        .environmentObject(AuthenticationManager())
    .environmentObject(AppNavigationState())
}
