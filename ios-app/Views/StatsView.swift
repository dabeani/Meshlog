/// Statistics View - Show general network statistics
import SwiftUI
import Charts

struct StatsView: View {
    @EnvironmentObject var apiClient: APIClient
    @State private var stats: StatisticsResponse?
    @State private var selectedWindow: Int = 24
    @State private var isLoading = false
    @State private var lastUpdatedAt: Date?

    private let windowOptions = [1, 24, 36]

    private func normalizedChannelName(_ raw: String) -> String {
        let trimmed = raw.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmed.isEmpty else { return "Unknown" }
        if trimmed.caseInsensitiveCompare("Public") == .orderedSame {
            return "Public"
        }
        return trimmed.hasPrefix("#") ? trimmed : "#\(trimmed)"
    }

    var body: some View {
        NavigationStack {
            ZStack {
                Color(red: 0.15, green: 0.2, blue: 0.3)
                    .ignoresSafeArea()

                VStack(spacing: 14) {
                    Picker("Time Window", selection: $selectedWindow) {
                        ForEach(windowOptions, id: \.self) { window in
                            Text("\(window)h").tag(window)
                        }
                    }
                    .pickerStyle(.segmented)
                    .padding(.horizontal, 16)
                    .onChange(of: selectedWindow) { _, _ in
                        Task { await loadStats() }
                    }

                    if isLoading {
                        ProgressView()
                            .tint(.cyan)
                            .frame(maxHeight: .infinity)
                    } else if let stats {
                        ScrollView {
                            VStack(alignment: .leading, spacing: 14) {
                                HStack {
                                    Text("Window: \(stats.windowHours)h")
                                        .font(.system(size: 11, weight: .semibold, design: .monospaced))
                                        .foregroundColor(Color(red: 0.72, green: 0.78, blue: 0.84))

                                    Spacer()

                                    Text(lastUpdatedText)
                                        .font(.system(size: 10, design: .monospaced))
                                        .foregroundColor(.gray)
                                }

                                LazyVGrid(columns: [GridItem(.flexible()), GridItem(.flexible())], spacing: 12) {
                                    StatCardView(title: "Reports", value: "\(stats.totalReports)", icon: "scope")
                                    StatCardView(title: "Unique Devices", value: "\(stats.uniqueDevices)", icon: "antenna.radiowaves.left.and.right")
                                    StatCardView(title: "Unique Collectors", value: "\(stats.uniqueCollectors)", icon: "dot.radiowaves.left.and.right")
                                    StatCardView(title: "Advertisements", value: "\(stats.totalAdvertisements)", icon: "megaphone.fill")
                                }

                                if !stats.chartBuckets.isEmpty {
                                    VStack(alignment: .leading, spacing: 8) {
                                        Text("Reports Timeline")
                                            .font(.system(size: 14, weight: .semibold))
                                            .foregroundColor(.white)

                                        Chart {
                                            ForEach(Array(stats.chartBuckets.enumerated()), id: \.offset) { idx, value in
                                                BarMark(
                                                    x: .value("Bucket", idx),
                                                    y: .value("Reports", value)
                                                )
                                                .foregroundStyle(Color.cyan.opacity(0.85))
                                            }
                                        }
                                        .chartXAxis(.hidden)
                                        .chartYAxis { AxisMarks(position: .leading) }
                                        .frame(height: 160)
                                        .padding(10)
                                        .background(Color(red: 0.12, green: 0.17, blue: 0.27))
                                        .cornerRadius(8)
                                    }
                                }

                                if !stats.uniqueDeviceBuckets.isEmpty {
                                    VStack(alignment: .leading, spacing: 8) {
                                        Text("Unique Devices Timeline")
                                            .font(.system(size: 14, weight: .semibold))
                                            .foregroundColor(.white)

                                        Chart {
                                            ForEach(Array(stats.uniqueDeviceBuckets.enumerated()), id: \.offset) { idx, value in
                                                LineMark(
                                                    x: .value("Bucket", idx),
                                                    y: .value("Devices", value)
                                                )
                                                .interpolationMethod(.monotone)
                                                .foregroundStyle(Color.green.opacity(0.9))

                                                AreaMark(
                                                    x: .value("Bucket", idx),
                                                    y: .value("Devices", value)
                                                )
                                                .interpolationMethod(.monotone)
                                                .foregroundStyle(Color.green.opacity(0.18))
                                            }
                                        }
                                        .chartXAxis(.hidden)
                                        .chartYAxis { AxisMarks(position: .leading) }
                                        .frame(height: 160)
                                        .padding(10)
                                        .background(Color(red: 0.12, green: 0.17, blue: 0.27))
                                        .cornerRadius(8)
                                    }
                                }

                                VStack(alignment: .leading, spacing: 8) {
                                    Text("Route Breakdown")
                                        .font(.system(size: 14, weight: .semibold))
                                        .foregroundColor(.white)

                                    StatLine(title: "Direct", value: stats.directReports)
                                    StatLine(title: "Flood", value: stats.floodReports)
                                    StatLine(title: "Relayed", value: stats.relayedReports)
                                    StatLine(title: "No Hop", value: stats.noHopReports)
                                    StatLine(title: "Unknown Route", value: stats.unknownRouteReports)

                                    Divider().padding(.vertical, 2)

                                    RouteLegendLine(title: "Direct", detail: "Point-to-point delivery")
                                    RouteLegendLine(title: "Flood", detail: "Broadcast/flood routing")
                                    RouteLegendLine(title: "Relayed", detail: "Packet had hop path")
                                    RouteLegendLine(title: "No Hop", detail: "No relay path recorded")
                                    RouteLegendLine(title: "Unknown", detail: "Legacy/missing route type")
                                }
                                .padding(12)
                                .background(Color(red: 0.12, green: 0.17, blue: 0.27))
                                .cornerRadius(8)

                                if !stats.collectorTotals.isEmpty {
                                    VStack(alignment: .leading, spacing: 8) {
                                        Text("Top Collectors")
                                            .font(.system(size: 14, weight: .semibold))
                                            .foregroundColor(.white)

                                        ForEach(stats.collectorTotals.prefix(8)) { collector in
                                            VStack(alignment: .leading, spacing: 4) {
                                                HStack {
                                                    Text(collector.reporterName.isEmpty ? "Collector \(collector.reporterId)" : collector.reporterName)
                                                        .font(.system(size: 12, weight: .medium))
                                                        .foregroundColor(.white)

                                                    Spacer()

                                                    Text("\(collector.totalPackets)")
                                                        .font(.system(size: 12, weight: .semibold, design: .monospaced))
                                                        .foregroundColor(.cyan)
                                                }

                                                Text("ADV \(collector.advPackets) · DIR \(collector.dirPackets) · PUB \(collector.pubPackets) · TEL \(collector.telPackets) · SYS \(collector.sysPackets) · CTRL \(collector.ctrlPackets) · RAW \(collector.rawPackets)")
                                                    .font(.system(size: 10, design: .monospaced))
                                                    .foregroundColor(.gray)
                                            }
                                            Divider()
                                        }
                                    }
                                    .padding(12)
                                    .background(Color(red: 0.12, green: 0.17, blue: 0.27))
                                    .cornerRadius(8)
                                }

                                if !stats.channelTotals.isEmpty {
                                    let channelItems = Array(stats.channelTotals.prefix(10))
                                    let channelMax = max(1, channelItems.map { $0.totalMessages }.max() ?? 1)

                                    VStack(alignment: .leading, spacing: 8) {
                                        Text("Channel Activity")
                                            .font(.system(size: 14, weight: .semibold))
                                            .foregroundColor(.white)

                                        ForEach(channelItems) { channel in
                                            VStack(alignment: .leading, spacing: 6) {
                                                HStack(spacing: 8) {
                                                    Text(normalizedChannelName(channel.channelName))
                                                        .font(.system(size: 12, weight: .medium))
                                                        .foregroundColor(.white)

                                                    Spacer()

                                                    Text("\(channel.totalMessages)")
                                                        .font(.system(size: 12, weight: .semibold, design: .monospaced))
                                                        .foregroundColor(.orange)
                                                }

                                                GeometryReader { proxy in
                                                    ZStack(alignment: .leading) {
                                                        Capsule()
                                                            .fill(Color.white.opacity(0.08))
                                                        Capsule()
                                                            .fill(Color.orange.opacity(0.82))
                                                            .frame(width: max(10, proxy.size.width * CGFloat(channel.totalMessages) / CGFloat(channelMax)))
                                                    }
                                                }
                                                .frame(height: 7)

                                                Text("Unique senders: \(channel.uniqueSenders)")
                                                    .font(.system(size: 10, design: .monospaced))
                                                    .foregroundColor(.gray)
                                            }

                                            if channel.id != channelItems.last?.id {
                                                Divider()
                                            }
                                        }
                                    }
                                    .padding(12)
                                    .background(Color(red: 0.12, green: 0.17, blue: 0.27))
                                    .cornerRadius(8)
                                }

                                if let note = stats.note, !note.isEmpty {
                                    Text(note)
                                        .font(.system(size: 10))
                                        .foregroundColor(.gray)
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
                Task { await loadStats() }
            }
        }
    }

    private func loadStats() async {
        await MainActor.run { isLoading = true }
        defer {
            Task { @MainActor in
                isLoading = false
            }
        }

        do {
            let response = try await apiClient.fetchGeneralStats(windowHours: selectedWindow)
            await MainActor.run {
                stats = response
                lastUpdatedAt = Date()
            }
        } catch {
            print("Error loading stats: \(error)")
            await MainActor.run {
                stats = nil
            }
        }
    }

    private var lastUpdatedText: String {
        guard let lastUpdatedAt else { return "Updated: --" }
        let formatter = DateFormatter()
        formatter.locale = Locale(identifier: "en_US_POSIX")
        formatter.dateFormat = "HH:mm:ss"
        return "Updated: \(formatter.string(from: lastUpdatedAt))"
    }
}

private struct StatLine: View {
    let title: String
    let value: Int

    var body: some View {
        HStack {
            Text(title)
                .font(.system(size: 12))
                .foregroundColor(.gray)

            Spacer()

            Text("\(value)")
                .font(.system(size: 12, weight: .semibold))
                .foregroundColor(.cyan)
        }
    }
}

private struct RouteLegendLine: View {
    let title: String
    let detail: String

    var body: some View {
        HStack(alignment: .top, spacing: 6) {
            Text("\(title):")
                .font(.system(size: 10, weight: .semibold, design: .monospaced))
                .foregroundColor(Color(red: 0.72, green: 0.78, blue: 0.84))
                .frame(width: 64, alignment: .leading)

            Text(detail)
                .font(.system(size: 10))
                .foregroundColor(.gray)
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
