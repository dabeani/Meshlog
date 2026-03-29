/// Devices View - List and details of mesh devices
import SwiftUI

struct DevicesView: View {
    @EnvironmentObject var apiClient: APIClient
    @State private var contacts: [Contact] = []
    @State private var isLoading = false
    @State private var selectedContact: Contact?
    @State private var searchText = ""
    
    var filteredContacts: [Contact] {
        if searchText.isEmpty {
            return contacts
        }
        return contacts.filter {
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
                            prompt: Text("Search devices...").foregroundColor(.gray)
                        )
                            .textFieldStyle(.roundedBorder)
                            .foregroundColor(.black)
                            .textInputAutocapitalization(.never)
                    }
                    .padding(12)
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
                                NavigationLink(destination: DeviceDetailView(contact: contact)) {
                                    DeviceRowView(contact: contact)
                                }
                                .listRowBackground(Color(red: 0.12, green: 0.17, blue: 0.27))
                                .listRowSeparator(.hidden)
                            }
                        }
                        .listStyle(.plain)
                        .refreshable {
                            await loadContacts()
                        }
                    }
                }
            }
            .navigationTitle("Devices")
            .navigationBarTitleDisplayMode(.inline)
            .onAppear {
                Task {
                    await loadContacts()
                }
            }
        }
    }
    
    private func loadContacts() async {
        do {
            isLoading = true
            let response = try await apiClient.fetchContacts()
            DispatchQueue.main.async {
                self.contacts = response.contacts.sorted { $0.lastHeardAt > $1.lastHeardAt }
                isLoading = false
            }
        } catch {
            print("Error loading contacts: \(error)")
        }
    }
}

struct DeviceRowView: View {
    let contact: Contact
    
    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            HStack {
                VStack(alignment: .leading, spacing: 3) {
                    Text(contact.name)
                        .font(.system(size: 13, weight: .semibold))
                        .foregroundColor(.white)
                    
                    Text(contact.publicKey.prefix(12) + "...")
                        .font(.system(size: 10, design: .monospaced))
                        .foregroundColor(.cyan)
                }
                
                Spacer()
                
                Image(systemName: "chevron.right")
                    .foregroundColor(.gray)
            }
            
            HStack(spacing: 12) {
                if let type = contact.nodeType {
                    Label(type, systemImage: getNodeTypeIcon(type))
                        .font(.system(size: 10))
                        .foregroundColor(.gray)
                }
                
                Spacer()
                
                Label(formatTime(contact.lastHeardAt), systemImage: "clock")
                    .font(.system(size: 10))
                    .foregroundColor(.gray)
            }
        }
        .padding(.vertical, 8)
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
                VStack(alignment: .leading, spacing: 16) {
                    // Contact info
                    VStack(alignment: .leading, spacing: 8) {
                        Text(contact.name)
                            .font(.system(size: 18, weight: .bold))
                            .foregroundColor(.white)
                        
                        Text(contact.publicKey)
                            .font(.system(size: 10, design: .monospaced))
                            .foregroundColor(.cyan)
                            .textSelection(.enabled)
                    }
                    .padding(.bottom, 8)
                    
                    Divider()
                    
                    // Metadata
                    VStack(alignment: .leading, spacing: 8) {
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
                                .font(.system(size: 14, weight: .semibold))
                                .foregroundColor(.white)
                            
                            VStack(alignment: .leading, spacing: 8) {
                                ForEach(stats.stats.sorted(by: { $0.key < $1.key }), id: \.key) { key, value in
                                    HStack {
                                        Text(key.capitalized)
                                            .font(.system(size: 12))
                                            .foregroundColor(.gray)
                                        
                                        Spacer()
                                        
                                        Text("\(value)")
                                            .font(.system(size: 12, weight: .semibold))
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
                            .font(.system(size: 14, weight: .semibold))
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
                                            .font(.system(size: 11))
                                            .foregroundColor(.gray)
                                        
                                        Spacer()
                                        
                                        if let snr = adv.snr {
                                            Text("SNR: \(String(format: "%.1f", snr))")
                                                .font(.system(size: 10))
                                                .foregroundColor(.cyan)
                                        }
                                    }
                                    
                                    if let path = adv.path {
                                        Text("Path: \(path)")
                                            .font(.system(size: 9, design: .monospaced))
                                            .foregroundColor(.gray)
                                            .lineLimit(1)
                                    }
                                }
                                .padding(.vertical, 4)
                            }
                        }
                    }
                }
                .padding(16)
            }
        }
        .navigationTitle("Device Details")
        .navigationBarTitleDisplayMode(.inline)
        .onAppear {
            Task {
                await loadDetails()
            }
        }
    }
    
    private func loadDetails() async {
        do {
            isLoading = true

            async let advs = apiClient.fetchContactAdvertisements(contactId: contact.id)
            async let statsData = apiClient.fetchContactStats(contactId: contact.id)

            let advertisements = try await advs
            let stats = try await statsData

            await MainActor.run {
                self.advertisements = advertisements
                self.stats = stats
                self.isLoading = false
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
