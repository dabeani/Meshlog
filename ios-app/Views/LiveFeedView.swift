/// Live Feed View - Real-time packet stream
import SwiftUI
import UIKit
import UserNotifications

struct LiveFeedView: View {
    @EnvironmentObject var apiClient: APIClient
    @EnvironmentObject var authManager: AuthenticationManager
    @EnvironmentObject var navigationState: AppNavigationState
    
    @State private var packets: [Packet] = []
    @State private var packetBuffer: [Packet] = []
    @State private var isLoading = false
    @State private var lastFetchTimeMs: Int = 0
    @State private var updateTask: Task<Void, Never>?
    @State private var restartTask: Task<Void, Never>?
    @State private var lastStreamConfigKey: String = ""

    @AppStorage("live_type_adv") private var showADV = true
    @AppStorage("live_type_msg") private var showMSG = true
    @AppStorage("live_type_pub") private var showPUB = true
    @AppStorage("live_type_raw") private var showRAW = true
    @AppStorage("live_type_tel") private var showTEL = true
    @AppStorage("live_type_sys") private var showSYS = true
    @AppStorage("collector_filter_selected_ids") private var collectorFilterRaw = ""
    @AppStorage("notify_new_device") private var notifyNewDevice = false
    @AppStorage("notify_new_message") private var notifyNewMessage = false

    @State private var knownContactIds: Set<Int> = []
    @State private var knownContactKeys: Set<String> = []
    @State private var knownMessagePacketKeys: Set<String> = []
    @State private var notificationsPrimed = false
    
    let reconnectDelay: TimeInterval = 1.0
    let fallbackPollingDelay: TimeInterval = 3.0
    let maxVisiblePackets = 250

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

                                Text("Live Feed")
                                    .font(.system(size: 16, weight: .bold, design: .monospaced))
                                    .foregroundColor(.white)
                            }

                            Spacer()

                            StatBadge(label: "ITEMS", value: "\(packets.count)")
                            StatBadge(label: "MODE", value: "LIVE")
                        }
                        .padding(.horizontal, 14)

                        if isLoading {
                            ProgressView()
                                .tint(.cyan)
                        }
                    }
                    .padding(.top, 10)

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
                            ForEach(packets) { packet in
                                PacketRowView(packet: packet) {
                                    navigationState.focusOnMap(
                                        contactId: packet.contactId,
                                        publicKey: packet.contactPublicKey
                                    )
                                }
                                    .listRowBackground(Color.clear)
                                    .listRowSeparator(.hidden)
                                    .listRowInsets(EdgeInsets(top: 3, leading: 10, bottom: 3, trailing: 10))
                            }
                        }
                        .listStyle(.plain)
                        .scrollContentBackground(.hidden)
                        .refreshable {
                            await refreshFeed()
                        }
                    }
                }
            }
            .navigationTitle("Live Feed")
            .navigationBarTitleDisplayMode(.inline)
            .onAppear {
                Task { await primeKnownDevices() }
                requestLiveStreamRestart(force: true)
            }
            .onDisappear {
                updateTask?.cancel()
                restartTask?.cancel()
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

    private func streamConfigKey() -> String {
        selectedTypes.joined(separator: ",") + "|" + collectorFilterRaw
    }

    private func requestLiveStreamRestart(force: Bool = false) {
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
    let onOpenMap: (() -> Void)?

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

    private var byteHashText: String? {
        guard let hash = packet.messageHash, !hash.isEmpty else { return nil }
        let size = max(1, min(packet.hashSize ?? 1, 3))
        let chars = min(hash.count, size * 2)
        return String(hash.prefix(chars))
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
        VStack(alignment: .leading, spacing: 6) {
            HStack(alignment: .firstTextBaseline, spacing: 8) {
                PacketTypeBadge(type: packet.rawSubtypeLabel ?? packet.type, color: packetColor)

                VStack(alignment: .leading, spacing: 2) {
                    HStack(spacing: 6) {
                        Text(primaryLabel)
                            .font(.system(size: 12, weight: .semibold, design: .monospaced))
                            .foregroundColor(.white)
                            .lineLimit(1)

                        if packet.type == "ADV", let nt = packet.nodeTypeLabel {
                            Text(nt)
                                .font(.system(size: 9, weight: .medium))
                                .foregroundColor(Color(red: 0.42, green: 0.78, blue: 0.72).opacity(0.8))
                        }
                    }

                    if packet.type == "PUB", let ch = packet.channelName, !ch.isEmpty {
                        Text("#\(ch)")
                            .font(.system(size: 9, weight: .medium, design: .monospaced))
                            .foregroundColor(Color(red: 0.82, green: 0.55, blue: 0.94).opacity(0.75))
                    }
                }

                Spacer(minLength: 8)

                Text(formatDateTime(packet.receivedAt))
                    .font(.system(size: 10, weight: .medium, design: .monospaced))
                    .foregroundColor(.gray)
            }

            HStack(spacing: 6) {
                if let byteHash = byteHashText {
                    InlineMetaBadge(text: "BH \(byteHash)", style: .hashSize)
                }

                if let hashSize = packet.hashSize {
                    InlineMetaBadge(text: "\(hashSize)b", style: .hashSize)
                }

                if let reporter = packet.reporterName, !reporter.isEmpty {
                    InlineMetaBadge(text: "via \(reporter)", style: .default)
                }

                if let scope = resolvedScope {
                    InlineMetaBadge(text: "SCP \(scope <= 0 ? "*" : String(scope))", style: .scope)
                }

                if let routeType = resolvedRouteType {
                    InlineMetaBadge(text: "RT \(routeType)", style: .metric)
                }

                if let hops = hopCountText {
                    InlineMetaBadge(text: hops, style: .metric)
                }

                if !packet.reports.isEmpty {
                    InlineMetaBadge(text: "\(packet.reports.count)rx", style: .metric)
                }

                if let snr = resolvedSnr {
                    InlineMetaBadge(text: "SNR \(String(format: "%.1f", snr))", style: .metric)
                }
            }

            HStack(spacing: 6) {
                InlineMetaBadge(text: "TX \(formatDateTime(resolvedSenderAt))", style: .metric)
                if !packet.receivedAt.isEmpty {
                    InlineMetaBadge(text: "RX \(formatDateTime(packet.receivedAt))", style: .metric)
                }
            }

            if let message = packet.message {
                Text(message)
                    .font(.system(size: 11))
                    .foregroundColor(Color(red: 0.93, green: 0.95, blue: 0.98))
                    .lineLimit(2)
                    .padding(.horizontal, 8)
                    .padding(.vertical, 6)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .background(Color.white.opacity(0.05))
                    .clipShape(RoundedRectangle(cornerRadius: 8, style: .continuous))
            }

            if let summary = packet.telemetrySummary {
                Text(summary)
                    .font(.system(size: 10, design: .monospaced))
                    .foregroundColor(Color(red: 0.82, green: 0.76, blue: 0.34).opacity(0.9))
                    .lineLimit(2)
                    .padding(.horizontal, 8)
                    .padding(.vertical, 5)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .background(Color.white.opacity(0.04))
                    .clipShape(RoundedRectangle(cornerRadius: 8, style: .continuous))
            }

            if let summary = packet.sysSummary {
                Text(summary)
                    .font(.system(size: 10, design: .monospaced))
                    .foregroundColor(Color(red: 0.91, green: 0.43, blue: 0.43).opacity(0.9))
                    .lineLimit(1)
                    .padding(.horizontal, 8)
                    .padding(.vertical, 5)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .background(Color.white.opacity(0.04))
                    .clipShape(RoundedRectangle(cornerRadius: 8, style: .continuous))
            }

            if let lat = packet.latitude, let lon = packet.longitude {
                HStack(spacing: 5) {
                    Image(systemName: "location.fill")
                        .font(.system(size: 9, weight: .semibold))
                    Text(String(format: "%.4f, %.4f", lat, lon))
                        .font(.system(size: 9, design: .monospaced))
                }
                .foregroundColor(.cyan)
            }

            if let path = resolvedPath, !path.isEmpty {
                Text(path)
                    .font(.system(size: 9, design: .monospaced))
                    .foregroundColor(Color(red: 0.62, green: 0.67, blue: 0.73))
                    .lineLimit(1)
            }
        }
        .padding(.horizontal, 10)
        .padding(.vertical, 8)
        .background(
            RoundedRectangle(cornerRadius: 10, style: .continuous)
                .fill(Color.white.opacity(0.045))
                .overlay(
                    RoundedRectangle(cornerRadius: 10, style: .continuous)
                        .stroke(Color.white.opacity(0.09), lineWidth: 0.8)
                )
        )
        .contentShape(RoundedRectangle(cornerRadius: 10, style: .continuous))
        .onTapGesture {
            guard let onOpenMap,
                  (packet.latitude != nil && packet.longitude != nil) ||
                  packet.contactId != nil ||
                  (packet.contactPublicKey?.isEmpty == false) else {
                return
            }
            onOpenMap()
        }
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

#Preview {
    LiveFeedView()
        .environmentObject(APIClient())
        .environmentObject(AuthenticationManager())
    .environmentObject(AppNavigationState())
}
