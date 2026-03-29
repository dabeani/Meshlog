/// Statistics View - Show general network statistics
import SwiftUI
import Charts

struct StatsView: View {
    @EnvironmentObject var apiClient: APIClient
    @State private var stats: StatisticsResponse?
    @State private var selectedWindow: Int = 24
    @State private var isLoading = false
    
    let windowOptions = [1, 6, 24, 36]
    
    var body: some View {
        NavigationStack {
            ZStack {
                Color(red: 0.15, green: 0.2, blue: 0.3)
                    .ignoresSafeArea()
                
                VStack(spacing: 16) {
                    // Window picker
                    Picker("Time Window", selection: $selectedWindow) {
                        ForEach(windowOptions, id: \.self) { window in
                            Text("\(window)h").tag(window)
                        }
                    }
                    .pickerStyle(.segmented)
                    .padding(.horizontal, 16)
                    .onChange(of: selectedWindow) { oldValue, newValue in
                        Task {
                            await loadStats()
                        }
                    }
                    
                    // Stats display
                    if isLoading {
                        ProgressView()
                            .tint(.cyan)
                            .frame(maxHeight: .infinity)
                    } else if let stats = stats {
                        ScrollView {
                            VStack(alignment: .leading, spacing: 12) {
                                // Summary cards
                                let sortedStats = stats.stats.sorted { $0.value > $1.value }
                                
                                LazyVGrid(columns: [GridItem(.flexible()), GridItem(.flexible())], spacing: 12) {
                                    ForEach(sortedStats.prefix(4), id: \.key) { key, value in
                                        StatCardView(
                                            title: key.capitalized,
                                            value: "\(value)",
                                            icon: getStatIcon(key)
                                        )
                                    }
                                }
                                
                                Divider()
                                    .padding(.vertical, 8)
                                
                                // All statistics
                                VStack(alignment: .leading, spacing: 8) {
                                    Text("All Statistics")
                                        .font(.system(size: 14, weight: .semibold))
                                        .foregroundColor(.white)
                                    
                                    ForEach(sortedStats, id: \.key) { key, value in
                                        HStack {
                                            Text(key.capitalized)
                                                .font(.system(size: 12))
                                                .foregroundColor(.gray)
                                            
                                            Spacer()
                                            
                                            Text("\(value)")
                                                .font(.system(size: 12, weight: .semibold))
                                                .foregroundColor(.cyan)
                                        }
                                        
                                        Divider()
                                    }
                                }
                            }
                            .padding(16)
                        }
                    } else {
                        VStack(spacing: 12) {
                            Image(systemName: "chart.bar.fill")
                                .font(.system(size: 32))
                                .foregroundColor(.gray)
                            
                            Text("No statistics available")
                                .font(.system(size: 14, weight: .semibold))
                                .foregroundColor(.white)
                        }
                        .frame(maxHeight: .infinity)
                    }
                }
                .padding(.vertical, 16)
            }
            .navigationTitle("Statistics")
            .navigationBarTitleDisplayMode(.inline)
            .onAppear {
                Task {
                    await loadStats()
                }
            }
        }
    }
    
    private func loadStats() async {
        do {
            isLoading = true
            let response = try await apiClient.fetchGeneralStats(windowHours: selectedWindow)
            DispatchQueue.main.async {
                self.stats = response
                isLoading = false
            }
        } catch {
            print("Error loading stats: \(error)")
        }
    }
    
    private func getStatIcon(_ key: String) -> String {
        switch key.lowercased() {
        case let k where k.contains("advertisement"):
            return "bullseye"
        case let k where k.contains("message"):
            return "bubble.left.fill"
        case let k where k.contains("packet"):
            return "square.stack.fill"
        case let k where k.contains("device"):
            return "antenna.radiowaves.left.and.right"
        default:
            return "chart.bar.fill"
        }
    }
}

struct StatCardView: View {
    let title: String
    let value: String
    let icon: String
    
    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            HStack {
                Image(systemName: icon)
                    .font(.system(size: 16, weight: .semibold))
                    .foregroundColor(.cyan)
                
                Spacer()
            }
            
            VStack(alignment: .leading, spacing: 2) {
                Text(value)
                    .font(.system(size: 20, weight: .bold))
                    .foregroundColor(.cyan)
                
                Text(title)
                    .font(.system(size: 11))
                    .foregroundColor(.gray)
            }
        }
        .padding(12)
        .background(Color(red: 0.12, green: 0.17, blue: 0.27))
        .cornerRadius(8)
    }
}

#Preview {
    StatsView()
        .environmentObject(APIClient())
        .environmentObject(AuthenticationManager())
}
