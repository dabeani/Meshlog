/// Devices View - List and details of mesh devices
import SwiftUI

struct DevicesView: View {
    @EnvironmentObject var apiClient: APIClient
    @EnvironmentObject var navigationState: AppNavigationState
    @State private var contacts: [Contact] = []
    @State private var isLoading = false
    @State private var selectedContact: Contact?
    @State private var searchText = ""
    @AppStorage("collector_filter_selected_ids") private var collectorFilterRaw = ""

    private var selectedCollectorIds: Set<Int> {
        Set(
            collectorFilterRaw
                .split(separator: ",")
                .compactMap { Int($0.trimmingCharacters(in: .whitespaces)) }
        )
    }
    
    var filteredContacts: [Contact] {
        let collectorFiltered: [Contact]
        if selectedCollectorIds.isEmpty {
            collectorFiltered = contacts
        } else {
            collectorFiltered = contacts.filter { !Set($0.reporterIds).isDisjoint(with: selectedCollectorIds) }
        }

        if searchText.isEmpty {
            return collectorFiltered
        }
        return collectorFiltered.filter {
            $0.name.localizedCaseInsensitiveContains(searchText) ||
            $0.publicKey.localizedCaseInsensitiveContains(searchText)
        }
    }
    
    var body: some View {
        NavigationStack {
            ZStack {
                Color(red: 0.15, green: 0.2, blue: 0.3)
                    .ignoresSafeArea()
                
                VStack(spacing: 0) {
                    // Search bar
                    HStack(spacing: 8) {
                        Image(systemName: "magnifyingglass")
                            .foregroundColor(.gray)
                        
                        TextField(
                            "Search devices...",
                            text: $searchText,
                            prompt: Text("Search devices...").foregroundColor(Color.white.opacity(0.65))
                        )
                            .textInputAutocapitalization(.never)
                            .meshTextInputStyle()
                    }
                    .padding(8)
                    .background(Color(red: 0.1, green: 0.15, blue: 0.25))
                    
                    // Device list
                    if isLoading {
                        ProgressView()
                            .tint(.cyan)
                            .frame(maxHeight: .infinity)
                    } else if filteredContacts.isEmpty {
                        VStack(spacing: 12) {
                            Image(systemName: "antenna.radiowaves.left.and.right.slash")
                                .font(.system(size: 32))
                                .foregroundColor(.gray)
                            
                            Text("No devices found")
                                .font(.system(size: 14, weight: .semibold))
                                .foregroundColor(.white)
                        }
                        .frame(maxHeight: .infinity)
                    } else {
                        List {
                            ForEach(filteredContacts) { contact in
                                Button {
                                    navigationState.focusOnMap(contactId: contact.id, publicKey: contact.publicKey)
                                } label: {
                                    DeviceRowView(contact: contact)
                                        .frame(maxWidth: .infinity, alignment: .leading)
                                }
                                .buttonStyle(.plain)
                                .listRowBackground(Color(red: 0.12, green: 0.17, blue: 0.27))
                                .meshListRowSeparator(.hidden)
#if os(tvOS)
                                .listRowInsets(EdgeInsets(top: 6, leading: 18, bottom: 6, trailing: 18))
#else
                                .listRowInsets(EdgeInsets(top: 2, leading: 10, bottom: 2, trailing: 10))
#endif
                            }
                        }
                        .listStyle(.plain)
                        .meshRefreshable {
                            await loadContacts()
                        }
                    }
                }
            }
            .navigationTitle("Devices")
            .meshNavigationBarInline()
            .onAppear {
                Task {
                    await loadContacts()
                }
            }
        }
    }
    
    private func loadContacts() async {
        defer { isLoading = false }

        do {
            isLoading = true
            let response = try await apiClient.fetchContacts()
            DispatchQueue.main.async {
                self.contacts = response.contacts.sorted { $0.lastHeardAt > $1.lastHeardAt }
            }
        } catch {
            print("Error loading contacts: \(error)")
        }
    }
}

struct DeviceRowView: View {
    let contact: Contact

    private var titleFont: Font {
#if os(tvOS)
        return .system(size: 20, weight: .semibold)
#else
        return .system(size: 12, weight: .semibold)
#endif
    }

    private var keyFont: Font {
#if os(tvOS)
        return .system(size: 14, design: .monospaced)
#else
        return .system(size: 9, design: .monospaced)
#endif
    }

    @ViewBuilder
    private func metaChip(icon: String, text: String) -> some View {
        HStack(spacing: 6) {
            Image(systemName: icon)
#if os(tvOS)
                .font(.system(size: 13, weight: .semibold))
#else
                .font(.system(size: 9, weight: .semibold))
#endif

            Text(text)
#if os(tvOS)
                .font(.system(size: 13, weight: .medium, design: .monospaced))
#else
                .font(.system(size: 9))
#endif
                .lineLimit(1)
        }
        .foregroundColor(Color(red: 0.78, green: 0.83, blue: 0.89))
#if os(tvOS)
        .padding(.horizontal, 10)
        .padding(.vertical, 6)
        .background(Color.white.opacity(0.10))
        .overlay(
            Capsule()
                .stroke(Color.white.opacity(0.24), lineWidth: 0.8)
        )
        .clipShape(Capsule())
#endif
    }

    @ViewBuilder
    private var tvRow: some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack(alignment: .firstTextBaseline, spacing: 10) {
                VStack(alignment: .leading, spacing: 4) {
                    Text(contact.name)
                        .font(titleFont)
                        .foregroundColor(.white)
                        .lineLimit(1)

                    Text(String(contact.publicKey.prefix(18)) + "...")
                        .font(keyFont)
                        .foregroundColor(.cyan)
                        .lineLimit(1)
                }

                Spacer(minLength: 12)

                Image(systemName: "location.fill")
                    .font(.system(size: 18, weight: .semibold))
                    .foregroundColor(Color(red: 0.70, green: 0.75, blue: 0.82))
            }

            HStack(spacing: 10) {
                if let type = contact.nodeType {
                    metaChip(icon: getNodeTypeIcon(type), text: type)
                }

                metaChip(icon: "dot.radiowaves.left.and.right", text: "\(contact.reporterIds.count) collectors")

                Spacer(minLength: 10)

                metaChip(icon: "clock", text: formatTime(contact.lastHeardAt))
            }
            .frame(maxWidth: .infinity, alignment: .leading)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(.vertical, 4)
    }

    @ViewBuilder
    private var iosRow: some View {
        VStack(alignment: .leading, spacing: 5) {
            HStack {
                VStack(alignment: .leading, spacing: 3) {
                    Text(contact.name)
                        .font(titleFont)
                        .foregroundColor(.white)

                    Text(contact.publicKey.prefix(12) + "...")
                        .font(keyFont)
                        .foregroundColor(.cyan)
                }

                Spacer()

                Image(systemName: "location.fill")
                    .foregroundColor(.gray)
            }

            HStack(spacing: 12) {
                if let type = contact.nodeType {
                    Label(type, systemImage: getNodeTypeIcon(type))
                        .font(.system(size: 9))
                        .foregroundColor(.gray)
                }

                Label("\(contact.reporterIds.count) collectors", systemImage: "dot.radiowaves.left.and.right")
                    .font(.system(size: 9))
                    .foregroundColor(.gray)

                Spacer()

                Label(formatTime(contact.lastHeardAt), systemImage: "clock")
                    .font(.system(size: 9))
                    .foregroundColor(.gray)
            }
        }
        .padding(.vertical, 4)
    }
    
    var body: some View {
#if os(tvOS)
        tvRow
#else
        iosRow
#endif
    }
    
    private func getNodeTypeIcon(_ type: String) -> String {
        switch type.lowercased() {
        case "chat": return "message.circle"
        case "repeater": return "repeat.circle"
        case "room_server": return "server.rack"
        case "sensor": return "sensor"
        default: return "antenna.radiowaves.left.and.right"
        }
    }
    
    private func formatTime(_ dateString: String) -> String {
        let formatter = DateFormatter()
        formatter.dateFormat = "yyyy-MM-dd HH:mm:ss"
        if let date = formatter.date(from: dateString) {
            let now = Date()
            let interval = now.timeIntervalSince(date)
            
            if interval < 60 {
                return "Now"
            } else if interval < 3600 {
                return "\(Int(interval / 60))m"
            } else if interval < 86400 {
                return "\(Int(interval / 3600))h"
            } else {
                return "\(Int(interval / 86400))d"
            }
        }
        return "Unknown"
    }
}

struct DeviceDetailView: View {
    @EnvironmentObject var apiClient: APIClient
    let contact: Contact
    @State private var advertisements: [Packet] = []
    @State private var stats: StatisticsResponse?
    @State private var isLoading = false
    
    var body: some View {
        ZStack {
            Color(red: 0.15, green: 0.2, blue: 0.3)
                .ignoresSafeArea()
            
            ScrollView {
                VStack(alignment: .leading, spacing: 10) {
                    // Contact info
                    VStack(alignment: .leading, spacing: 5) {
                        Text(contact.name)
                            .font(.system(size: 16, weight: .bold))
                            .foregroundColor(.white)
                        
                        Text(contact.publicKey)
                            .font(.system(size: 9, design: .monospaced))
                            .foregroundColor(.cyan)
                            .meshTextSelectionEnabled()
                    }
                    .padding(.bottom, 4)
                    
                    Divider()
                    
                    // Metadata
                    VStack(alignment: .leading, spacing: 5) {
                        if let type = contact.nodeType {
                            LabelWithValue(label: "Type", value: type)
                        }
                        
                        if let lat = contact.latitude, let lon = contact.longitude {
                            LabelWithValue(label: "Location", value: String(format: "%.4f, %.4f", lat, lon))
                        }
                        
                        LabelWithValue(label: "Hash Size", value: "\(contact.hashSize)")
                        LabelWithValue(label: "Last Heard", value: formatTime(contact.lastHeardAt))
                    }
                    
                    Divider()
                    
                    // Statistics section
                    if let stats = stats {
                        VStack(alignment: .leading, spacing: 12) {
                            Text("Statistics")
                                .font(.system(size: 13, weight: .semibold))
                                .foregroundColor(.white)
                            
                            VStack(alignment: .leading, spacing: 5) {
                                ForEach(stats.stats.sorted(by: { $0.key < $1.key }), id: \.key) { key, value in
                                    HStack {
                                        Text(key.capitalized)
                                            .font(.system(size: 11))
                                            .foregroundColor(.gray)
                                        
                                        Spacer()
                                        
                                        Text("\(value)")
                                            .font(.system(size: 11, weight: .semibold))
                                            .foregroundColor(.cyan)
                                    }
                                }
                            }
                        }
                        
                        Divider()
                    }
                    
                    // Recent advertisements
                    VStack(alignment: .leading, spacing: 12) {
                        Text("Recent Advertisements (\(advertisements.count))")
                            .font(.system(size: 13, weight: .semibold))
                            .foregroundColor(.white)
                        
                        if advertisements.isEmpty {
                            Text("No advertisements")
                                .font(.system(size: 12))
                                .foregroundColor(.gray)
                        } else {
                            ForEach(advertisements.prefix(5)) { adv in
                                VStack(alignment: .leading, spacing: 4) {
                                    HStack {
                                        Text(formatTime(adv.sentAt))
                                            .font(.system(size: 10))
                                            .foregroundColor(.gray)
                                        
                                        Spacer()
                                        
                                        if let snr = adv.snr {
                                            Text("SNR: \(String(format: "%.1f", snr))")
                                                .font(.system(size: 9))
                                                .foregroundColor(.cyan)
                                        }
                                    }
                                    
                                    if let path = adv.path {
                                        Text("Path: \(path)")
                                            .font(.system(size: 8, design: .monospaced))
                                            .foregroundColor(.gray)
                                            .lineLimit(1)
                                    }
                                }
                                .padding(.vertical, 2)
                            }
                        }
                    }
                }
                .padding(12)
            }
        }
        .navigationTitle("Device Details")
        .meshNavigationBarInline()
        .onAppear {
            Task {
                await loadDetails()
            }
        }
    }
    
    private func loadDetails() async {
        defer { isLoading = false }

        do {
            isLoading = true

            async let advs = apiClient.fetchContactAdvertisements(contactId: contact.id)
            async let statsData = apiClient.fetchContactStats(contactId: contact.id)

            let advertisements = try await advs
            let stats = try await statsData

            await MainActor.run {
                self.advertisements = advertisements
                self.stats = stats
            }
        } catch {
            print("Error loading device details: \(error)")
        }
    }
}

struct LabelWithValue: View {
    let label: String
    let value: String
    
    var body: some View {
        HStack {
            Text(label)
                .font(.system(size: 12))
                .foregroundColor(.gray)
            
            Spacer()
            
            Text(value)
                .font(.system(size: 12))
                .foregroundColor(.cyan)
        }
    }
}

private func formatTime(_ dateString: String) -> String {
    let formatter = DateFormatter()
    formatter.dateFormat = "yyyy-MM-dd HH:mm:ss"
    if let date = formatter.date(from: dateString) {
        let now = Date()
        let interval = now.timeIntervalSince(date)
        
        if interval < 60 {
            return "Just now"
        } else if interval < 3600 {
            return "\(Int(interval / 60))m ago"
        } else if interval < 86400 {
            return "\(Int(interval / 3600))h ago"
        } else {
            return "\(Int(interval / 86400))d ago"
        }
    }
    return dateString
}

#Preview {
    DevicesView()
        .environmentObject(APIClient())
        .environmentObject(AuthenticationManager())
}
