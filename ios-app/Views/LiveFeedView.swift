/// Live Feed View - Real-time packet stream
import SwiftUI
import UIKit

struct LiveFeedView: View {
    @EnvironmentObject var apiClient: APIClient
    @EnvironmentObject var authManager: AuthenticationManager
    
    @State private var packets: [Packet] = []
    @State private var isLoading = false
    @State private var lastFetchTimeMs: Int = 0
    @State private var updateTask: Task<Void, Never>?

    @AppStorage("live_type_adv") private var showADV = true
    @AppStorage("live_type_msg") private var showMSG = true
    @AppStorage("live_type_pub") private var showPUB = true
    @AppStorage("live_type_raw") private var showRAW = true
    @AppStorage("live_type_tel") private var showTEL = true
    @AppStorage("live_type_sys") private var showSYS = true
    @AppStorage("collector_filter_selected_ids") private var collectorFilterRaw = ""
    
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
                                PacketRowView(packet: packet)
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
                startLiveStream()
            }
            .onDisappear {
                updateTask?.cancel()
            }
            .onChange(of: showADV) { _, _ in startLiveStream() }
            .onChange(of: showMSG) { _, _ in startLiveStream() }
            .onChange(of: showPUB) { _, _ in startLiveStream() }
            .onChange(of: showRAW) { _, _ in startLiveStream() }
            .onChange(of: showTEL) { _, _ in startLiveStream() }
            .onChange(of: showSYS) { _, _ in startLiveStream() }
            .onChange(of: collectorFilterRaw) { _, _ in startLiveStream() }
        }
    }

    private func startLiveStream() {
        updateTask?.cancel()
        updateTask = Task {
            while !Task.isCancelled {
                do {
                    isLoading = true

                    let stream = apiClient.streamLiveFeed(
                        sinceMs: lastFetchTimeMs,
                        types: selectedTypes,
                        limit: maxVisiblePackets
                    )

                    for try await response in stream {
                        applyIncoming(response)
                        isLoading = false
                    }
                } catch let urlError as URLError where urlError.code == .cancelled {
                    return
                } catch {
                    print("Live stream error: \(error)")

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
        let collectorIds = selectedCollectorIds

        for packet in response.packets + packets {
            if !collectorIds.isEmpty,
               let rid = packet.reporterId,
               !collectorIds.contains(rid) {
                continue
            }

            let key = "\(packet.type)-\(packet.id)"
            if seen.insert(key).inserted {
                merged.append(packet)
            }
        }

        packets = Array(merged.prefix(maxVisiblePackets))
        lastFetchTimeMs = response.timestampMs
    }
}

struct PacketRowView: View {
    let packet: Packet
    
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
        if let name = packet.contactName, !name.isEmpty { return name }
        if let key = packet.contactPublicKey, !key.isEmpty { return String(key.prefix(14)) + "..." }
        return "Unknown"
    }
    
    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack(alignment: .firstTextBaseline, spacing: 8) {
                PacketTypeBadge(type: packet.type, color: packetColor)

                Text(primaryLabel)
                    .font(.system(size: 12, weight: .semibold, design: .monospaced))
                    .foregroundColor(.white)
                    .lineLimit(1)

                Spacer(minLength: 8)

                Text(formatTime(packet.receivedAt))
                    .font(.system(size: 10, weight: .medium, design: .monospaced))
                    .foregroundColor(.gray)
            }

            HStack(spacing: 6) {
                if let hash = packet.messageHash, !hash.isEmpty {
                    InlineMetaBadge(text: "#\(hash)")
                }

                if let reporter = packet.reporterName, !reporter.isEmpty {
                    InlineMetaBadge(text: "via \(reporter)")
                }

                if let snr = packet.snr {
                    InlineMetaBadge(text: "SNR \(String(format: "%.1f", snr))")
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

            if let lat = packet.latitude, let lon = packet.longitude {
                HStack(spacing: 5) {
                    Image(systemName: "location.fill")
                        .font(.system(size: 9, weight: .semibold))
                    Text(String(format: "%.4f, %.4f", lat, lon))
                        .font(.system(size: 9, design: .monospaced))
                }
                .foregroundColor(.cyan)
            }

            if let path = packet.path, !path.isEmpty {
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
    }
    
    private func formatTime(_ dateString: String) -> String {
        let formatter = DateFormatter()
        formatter.dateFormat = "yyyy-MM-dd HH:mm:ss"
        if let date = formatter.date(from: dateString) {
            let timeFormatter = DateFormatter()
            timeFormatter.timeStyle = .medium
            return timeFormatter.string(from: date)
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
    let text: String

    var body: some View {
        Text(text)
            .font(.system(size: 9, weight: .medium, design: .monospaced))
            .foregroundColor(Color(red: 0.82, green: 0.86, blue: 0.91))
            .padding(.horizontal, 6)
            .padding(.vertical, 3)
            .background(Color.white.opacity(0.06))
            .overlay(
                RoundedRectangle(cornerRadius: 999)
                    .stroke(Color.white.opacity(0.12), lineWidth: 0.7)
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
}
