/// Live Feed View - Real-time packet stream
import SwiftUI
import UIKit
import UserNotifications
import CoreLocation

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
    @AppStorage("live_feed_items_limit") private var liveFeedItemsLimit = 100

    @State private var knownContactIds: Set<Int> = []
    @State private var knownContactKeys: Set<String> = []
    @State private var knownMessagePacketKeys: Set<String> = []
    @State private var notificationsPrimed = false
    @State private var renderedPacketCount = 40
    
    let reconnectDelay: TimeInterval = 1.0
    let fallbackPollingDelay: TimeInterval = 3.0

    private var maxVisiblePackets: Int {
        min(max(liveFeedItemsLimit, 20), 200)
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
                                BrandLogoMark(height: 24)

                                Text(subTab == .feed ? "Live Feed" : "Channels")
                                    .font(.system(size: 16, weight: .bold, design: .monospaced))
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
                                ForEach(Array(packets.prefix(renderedPacketCount))) { packet in
                                    PacketRowView(packet: packet, userCoordinate: liveLocationManager.currentCoordinate) {
                                        navigationState.focusOnMap(
                                            contactId: packet.contactId,
                                            publicKey: packet.contactPublicKey
                                        )
                                    }
                                        .onAppear {
                                            guard let index = packets.firstIndex(where: { $0.id == packet.id }) else { return }
                                            if index >= max(0, renderedPacketCount - 5) {
                                                loadMoreVisiblePackets()
                                            }
                                        }
                                        .listRowBackground(Color.clear)
                                        .listRowSeparator(.visible)
                                        .listRowSeparatorTint(Color(red: 0.20, green: 0.21, blue: 0.23))
                                        .listRowInsets(EdgeInsets(top: 0, leading: 0, bottom: 0, trailing: 0))
                                }
                            }
                            .listStyle(.plain)
                            .scrollContentBackground(.hidden)
                            .refreshable {
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
            .navigationBarTitleDisplayMode(.inline)
            .onAppear {
                hasAppeared = true
                liveLocationManager.requestCurrentLocation()
                Task { await primeKnownDevices() }
                if scenePhase == .active {
                    requestLiveStreamRestart(force: true)
                }
            }
            .onDisappear {
                hasAppeared = false
                updateTask?.cancel()
                restartTask?.cancel()
            }
            .onChange(of: scenePhase) { _, phase in
                guard hasAppeared else { return }

                if phase == .active {
                    requestLiveStreamRestart(force: true)
                } else {
                    updateTask?.cancel()
                    restartTask?.cancel()
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

    private func handleFilterChange() {
        applyVisibleFilters()
        requestLiveStreamRestart()
    }

    private func loadMoreVisiblePackets() {
        let target = min(packets.count, renderedPacketCount + 40)
        if target > renderedPacketCount {
            renderedPacketCount = target
        }
    }

    private func streamConfigKey() -> String {
        selectedTypes.joined(separator: ",") + "|" + collectorFilterRaw
    }

    private func requestLiveStreamRestart(force: Bool = false) {
        guard scenePhase == .active else {
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
                        applyIncoming(response)
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
                types: selectedTypes
            )
            
            applyIncoming(response)
        } catch let urlError as URLError where urlError.code == .cancelled {
            // Expected when refresh tasks overlap due to view lifecycle changes.
            return
        } catch {
            print("Error fetching live feed: \(error)")
        }
    }

    @MainActor
    private func applyIncoming(_ response: LiveFeedResponse) {
        var merged: [Packet] = []
        var seen = Set<String>()
        let existingPacketKeys = Set(packetBuffer.map { "\($0.type)-\($0.id)" })
        var freshPackets: [Packet] = []

        for packet in response.packets + packetBuffer {
            let key = "\(packet.type)-\(packet.id)"
            if seen.insert(key).inserted {
                merged.append(packet)

                if !existingPacketKeys.contains(key) {
                    freshPackets.append(packet)
                }
            }
        }

        packetBuffer = Array(merged.prefix(maxVisiblePackets))
        applyVisibleFilters()
        lastFetchTimeMs = response.timestampMs

        handleNotifications(for: freshPackets)
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

        packets = Array(visible.prefix(maxVisiblePackets))
        renderedPacketCount = min(max(40, renderedPacketCount), packets.count)
        if renderedPacketCount == 0 && !packets.isEmpty {
            renderedPacketCount = min(40, packets.count)
        }
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
    }
}

struct PacketRowView: View {
    let packet: Packet
    let userCoordinate: CLLocationCoordinate2D?
    let onOpenMap: (() -> Void)?

    @AppStorage("distance_unit") private var distanceUnit = "km"
    @AppStorage("snr_color_mode") private var snrColorMode = "webui"

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
                        SnrBadge(snr: snr, mode: snrColorMode)
                    }
                    if let distance = resolvedDistanceText {
                        InlineMetaBadge(text: distance, style: .metric)
                    }
                    if !packet.reports.isEmpty {
                        InlineMetaBadge(text: "\(packet.reports.count)rx", style: .metric)
                    }
                    if packet.type == "PUB", let ch = packet.channelName, !ch.isEmpty {
                        InlineMetaBadge(text: displayChannelName(ch), style: .scope)
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

    var body: some View {
        Text(type)
            .font(.system(size: 10, weight: .bold, design: .monospaced))
            .foregroundColor(color)
            .padding(.horizontal, 8)
            .padding(.vertical, 4)
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
            .font(.system(size: 9, weight: .medium, design: .monospaced))
            .foregroundColor(colors.text)
            .padding(.horizontal, 6)
            .padding(.vertical, 3)
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
    let mode: String

    private var label: String {
        "\(String(format: "%.0f", snr))dB"
    }

    private var colors: (text: Color, background: Color, border: Color) {
        if mode != "webui" {
            return (
                Color(red: 0.89, green: 0.91, blue: 0.94),
                Color(red: 0.27, green: 0.29, blue: 0.33),
                Color(red: 0.38, green: 0.41, blue: 0.45)
            )
        }

        if snr >= 9 {
            return (Color(red: 0.93, green: 1.0, blue: 0.96), Color(red: 0.13, green: 0.35, blue: 0.23), Color(red: 0.24, green: 0.66, blue: 0.42))
        }
        if snr >= 3 {
            return (Color(red: 0.91, green: 1.0, blue: 0.99), Color(red: 0.14, green: 0.34, blue: 0.39), Color(red: 0.27, green: 0.66, blue: 0.76))
        }
        if snr >= -3 {
            return (Color(red: 1.0, green: 0.97, blue: 0.89), Color(red: 0.43, green: 0.33, blue: 0.13), Color(red: 0.78, green: 0.60, blue: 0.23))
        }
        if snr >= -10 {
            return (Color(red: 1.0, green: 0.94, blue: 0.89), Color(red: 0.48, green: 0.27, blue: 0.13), Color(red: 0.83, green: 0.49, blue: 0.22))
        }

        return (Color(red: 1.0, green: 0.93, blue: 0.92), Color(red: 0.43, green: 0.15, blue: 0.19), Color(red: 0.83, green: 0.35, blue: 0.41))
    }

    var body: some View {
        Text(label)
            .font(.system(size: 9, weight: .medium, design: .monospaced))
            .foregroundColor(colors.text)
            .padding(.horizontal, 6)
            .padding(.vertical, 3)
            .background(colors.background)
            .overlay(
                RoundedRectangle(cornerRadius: 999)
                    .stroke(colors.border, lineWidth: 0.7)
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

    var body: some View {
        Text(type)
            .font(.system(size: 11, weight: .semibold, design: .monospaced))
            .foregroundColor(isActive ? Color.white : Color(red: 0.77, green: 0.82, blue: 0.87))
            .padding(.horizontal, 10)
            .padding(.vertical, 6)
            .background(isActive ? tint.opacity(0.24) : Color.white.opacity(0.05))
            .overlay(
                RoundedRectangle(cornerRadius: 999)
                    .stroke(isActive ? tint.opacity(0.55) : Color.white.opacity(0.15), lineWidth: 0.8)
            )
            .clipShape(Capsule())
    }
}

private struct StatBadge: View {
    let label: String
    let value: String

    var body: some View {
        HStack(spacing: 4) {
            Text(label)
                .foregroundColor(Color(red: 0.66, green: 0.7, blue: 0.76))
            Text(value)
                .foregroundColor(.white)
        }
        .font(.system(size: 9, weight: .semibold, design: .monospaced))
        .padding(.horizontal, 7)
        .padding(.vertical, 5)
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

    var body: some View {
        Button(action: action) {
            VStack(spacing: 0) {
                Text(label)
                    .font(.system(size: 10, weight: .bold, design: .monospaced))
                    .foregroundColor(active ? .white : Color(red: 0.52, green: 0.56, blue: 0.62))
                    .padding(.horizontal, 14)
                    .padding(.vertical, 8)

                Rectangle()
                    .fill(active ? Color.cyan : Color.clear)
                    .frame(height: 2)
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
    /// Nil = channel list; non-nil = single channel detail.
    @State private var selectedChannelKey: String?
    @State private var unreadByChannelKey: [String: Int] = [:]
    @AppStorage("channels_last_read_packet_by_key") private var lastReadPacketRaw = ""

    private let maxPackets = 300

    private var lastReadPacketByChannelKey: [String: Int] {
        decodeIntMap(lastReadPacketRaw)
    }

    // PUB packets grouped by channel
    private var grouped: [(key: String, channel: String, id: Int?, packets: [Packet])] {
        var order: [String] = []
        var seen = Set<String>()
        var map: [String: (channel: String, id: Int?, packets: [Packet])] = [:]

        func keyFor(id: Int?, name: String?) -> String {
            if let id { return "id:\(id)" }
            return "name:\(normalizedChannelName(name).lowercased())"
        }

        for packet in packets {
            let channelName = normalizedChannelName(packet.channelName ?? "\(packet.channelId.map(String.init) ?? "?")")
            let key = keyFor(id: packet.channelId, name: channelName)
            if seen.insert(key).inserted { order.append(key) }
            if map[key] == nil {
                map[key] = (channel: channelName, id: packet.channelId, packets: [])
            }
            map[key, default: (channelName, packet.channelId, [])].packets.append(packet)
        }

        // Merge known channels that may not have messages yet
        for ch in channels {
            let key = keyFor(id: ch.id, name: ch.name)
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
            } else {
                List {
                    if let selectedKey = selectedChannelKey,
                       let selectedGroup = grouped.first(where: { $0.key == selectedKey }) {
                        Section {
                            if selectedGroup.packets.isEmpty {
                                Text("No messages yet")
                                    .font(.system(size: 11, design: .monospaced))
                                    .foregroundColor(Color(red: 0.35, green: 0.38, blue: 0.42))
                                    .listRowBackground(Color.clear)
                                    .listRowSeparator(.hidden)
                            } else {
                                let detailPackets = packetsForDetail(selectedGroup)
                                ForEach(detailPackets, id: \.id) { packet in
                                    ChannelMessageRow(packet: packet) {
                                        navigationState.focusOnMap(
                                            contactId: packet.contactId,
                                            publicKey: packet.contactPublicKey
                                        )
                                    }
                                    .id(packet.id)
                                    .listRowBackground(Color.clear)
                                    .listRowSeparator(.visible)
                                    .listRowSeparatorTint(Color(red: 0.18, green: 0.20, blue: 0.22))
                                    .listRowInsets(EdgeInsets(top: 0, leading: 0, bottom: 0, trailing: 0))
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
                        ForEach(grouped, id: \.key) { group in
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
                            .listRowSeparator(.hidden)
                        }
                    }
                }
                .listStyle(.plain)
                .scrollContentBackground(.hidden)
                .refreshable { await reload() }
            }
        }
        .onAppear {
            hasAppeared = true
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
                        limit: maxPackets
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
        packets = Array((newOnes + packets)
            .sorted { $0.receivedAt > $1.receivedAt }
            .prefix(maxPackets))
        lastFetchTimeMs = max(lastFetchTimeMs, response.timestampMs)

        recalculateUnreadCounts()
    }

    @MainActor
    private func packetsForDetail(_ group: (key: String, channel: String, id: Int?, packets: [Packet])) -> [Packet] {
        let detail = Array(group.packets.sorted { $0.id < $1.id }.suffix(160))
        guard let target = scrollTarget(for: group),
              let startIndex = detail.firstIndex(where: { $0.id == target }) else {
            return detail
        }
                let contextStart = max(0, startIndex - 5)
                return Array(detail[contextStart...])
    }

    @MainActor
    private func scrollTarget(for group: (key: String, channel: String, id: Int?, packets: [Packet])) -> Int? {
        guard let lastRead = lastReadPacketByChannelKey[group.key],
              group.packets.contains(where: { $0.id == lastRead }) else {
            return nil
        }
        return lastRead
    }

    @MainActor
    private func markChannelRead(_ group: (key: String, channel: String, id: Int?, packets: [Packet])) {
        unreadByChannelKey[group.key] = 0

        guard let newest = group.packets.first else { return }
        var map = lastReadPacketByChannelKey
        map[group.key] = newest.id
        lastReadPacketRaw = encodeIntMap(map)
    }

    @MainActor
    private func recalculateUnreadCounts() {
        let readMap = lastReadPacketByChannelKey
        var updated: [String: Int] = [:]

        for group in grouped {
            guard !group.packets.isEmpty else {
                updated[group.key] = 0
                continue
            }

            if selectedChannelKey == group.key {
                updated[group.key] = 0
                continue
            }

            let lastReadId = readMap[group.key] ?? 0
            let unread = group.packets.reduce(into: 0) { partial, pkt in
                if pkt.id > lastReadId {
                    partial += 1
                }
            }
            updated[group.key] = unread
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

    private func encodeIntMap(_ value: [String: Int]) -> String {
        guard let data = try? JSONEncoder().encode(value),
              let text = String(data: data, encoding: .utf8) else {
            return "{}"
        }
        return text
    }

    private func reload() async {
        streamTask?.cancel()
        await MainActor.run {
            packets = []
            lastFetchTimeMs = 0
            unreadByChannelKey = [:]
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
                        .foregroundColor(Color(red: 0.52, green: 0.55, blue: 0.60))
                }

                Image(systemName: isSelected ? "chevron.left" : "chevron.right")
                    .font(.system(size: 10, weight: .semibold))
                    .foregroundColor(Color(red: 0.40, green: 0.44, blue: 0.50))
            }
            .padding(.horizontal, 12)
            .padding(.vertical, 8)
            .background(Color(red: 0.10, green: 0.12, blue: 0.16))
        }
        .buttonStyle(.plain)
        .listRowInsets(EdgeInsets())
    }
}

// MARK: - ChannelMessageRow

private struct ChannelMessageRow: View {
    let packet: Packet
    let onOpenMap: () -> Void
    @AppStorage("snr_color_mode") private var snrColorMode = "webui"

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
                        SnrBadge(snr: snr, mode: snrColorMode)
                    }
                    if let scope = resolvedScope {
                        InlineMetaBadge(text: scope <= 0 ? "*" : "#\(scope)", style: .scope)
                    }
                    Text(formatTime(packet.receivedAt))
                        .font(.system(size: 9, design: .monospaced))
                        .foregroundColor(Color(red: 0.44, green: 0.47, blue: 0.52))
                }

                // Line 2: message body
                if let msg = packet.message, !msg.isEmpty {
                    Text(msg)
                        .font(.system(size: 12))
                        .foregroundColor(Color(red: 0.88, green: 0.91, blue: 0.96))
                        .lineLimit(4)
                }
            }
            .padding(.vertical, 7)
            .padding(.trailing, 12)
        }
        .contentShape(Rectangle())
        .onTapGesture { onOpenMap() }
    }
}

#Preview {
    LiveFeedView()
        .environmentObject(APIClient())
        .environmentObject(AuthenticationManager())
    .environmentObject(AppNavigationState())
}
