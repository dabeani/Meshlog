/// Live Feed View - Real-time packet stream
import SwiftUI

struct LiveFeedView: View {
    @EnvironmentObject var apiClient: APIClient
    @EnvironmentObject var authManager: AuthenticationManager
    
    @State private var packets: [Packet] = []
    @State private var isLoading = false
    @State private var selectedTypes: Set<String> = ["ADV", "MSG", "PUB", "RAW"]
    @State private var lastFetchTimeMs: Int = 0
    @State private var updateTask: Task<Void, Never>?
    
    let pollingInterval: TimeInterval = 3.0
    
    var body: some View {
        NavigationStack {
            ZStack {
                // Background
                Color(red: 0.15, green: 0.2, blue: 0.3)
                    .ignoresSafeArea()
                
                VStack(spacing: 0) {
                    // Filter controls
                    ScrollView(.horizontal, showsIndicators: false) {
                        HStack(spacing: 8) {
                            ForEach(["ADV", "MSG", "PUB", "RAW"], id: \.self) { type in
                                Button(action: {
                                    if selectedTypes.contains(type) {
                                        selectedTypes.remove(type)
                                    } else {
                                        selectedTypes.insert(type)
                                    }
                                }) {
                                    Text(type)
                                        .font(.system(size: 12, weight: .semibold))
                                        .padding(.horizontal, 12)
                                        .padding(.vertical, 6)
                                        .background(selectedTypes.contains(type) ? Color.cyan : Color(red: 0.2, green: 0.25, blue: 0.35))
                                        .foregroundColor(selectedTypes.contains(type) ? .black : .white)
                                        .cornerRadius(6)
                                }
                            }
                        }
                        .padding(.horizontal, 16)
                    }
                    .padding(.vertical, 12)
                    .background(Color(red: 0.1, green: 0.15, blue: 0.25))
                    
                    // Live feed list
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
                                    .listRowBackground(Color(red: 0.12, green: 0.17, blue: 0.27))
                                    .listRowSeparator(.hidden)
                            }
                        }
                        .listStyle(.plain)
                        .refreshable {
                            await refreshFeed()
                        }
                    }
                }
            }
            .navigationTitle("Live Feed")
            .navigationBarTitleDisplayMode(.inline)
            .onAppear {
                startAutoRefresh()
            }
            .onDisappear {
                updateTask?.cancel()
            }
        }
    }
    
    private func startAutoRefresh() {
        updateTask?.cancel()
        updateTask = Task {
            while !Task.isCancelled {
                try? await Task.sleep(nanoseconds: UInt64(pollingInterval * 1_000_000_000))
                await refreshFeed()
            }
        }
    }
    
    private func refreshFeed() async {
        do {
            isLoading = true
            let response = try await apiClient.fetchLiveFeed(
                sinceMs: lastFetchTimeMs,
                types: Array(selectedTypes)
            )
            
            DispatchQueue.main.async {
                self.packets = response.packets
                self.lastFetchTimeMs = response.timestampMs
                isLoading = false
            }
        } catch {
            print("Error fetching live feed: \(error)")
        }
    }
}

struct PacketRowView: View {
    let packet: Packet
    
    var packetColor: Color {
        switch packet.type {
        case "ADV": return .green
        case "MSG": return .blue
        case "PUB": return .purple
        case "RAW": return .orange
        case "TEL": return .yellow
        default: return .gray
        }
    }
    
    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack(spacing: 8) {
                // Type badge
                Text(packet.type)
                    .font(.system(size: 11, weight: .bold))
                    .foregroundColor(.white)
                    .padding(.horizontal, 8)
                    .padding(.vertical, 4)
                    .background(packetColor)
                    .cornerRadius(4)
                
                // Contact/Reporter info
                VStack(alignment: .leading, spacing: 2) {
                    if let name = packet.contactName {
                        Text(name)
                            .font(.system(size: 13, weight: .semibold))
                            .foregroundColor(.white)
                    } else if let key = packet.contactPublicKey {
                        Text(key.prefix(8) + "...")
                            .font(.system(size: 11, design: .monospaced))
                            .foregroundColor(.cyan)
                    }
                    
                    if let reporter = packet.reporterName {
                        Text("← \(reporter)")
                            .font(.system(size: 11))
                            .foregroundColor(.gray)
                    }
                }
                
                Spacer()
                
                // Time
                Text(formatTime(packet.receivedAt))
                    .font(.system(size: 11))
                    .foregroundColor(.gray)
            }
            
            // Message content (if present)
            if let message = packet.message {
                Text(message)
                    .font(.system(size: 12))
                    .foregroundColor(.white)
                    .lineLimit(2)
            }
            
            // Location (if present)
            if let lat = packet.latitude, let lon = packet.longitude {
                HStack(spacing: 4) {
                    Image(systemName: "location.fill")
                        .font(.system(size: 10))
                    Text(String(format: "%.4f, %.4f", lat, lon))
                        .font(.system(size: 10, design: .monospaced))
                }
                .foregroundColor(.cyan)
            }
        }
        .padding(.vertical, 8)
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

#Preview {
    LiveFeedView()
        .environmentObject(APIClient())
        .environmentObject(AuthenticationManager())
}
