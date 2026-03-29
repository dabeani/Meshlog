/// Map View - Visualize device locations
import SwiftUI
import MapKit

struct MapView: View {
    @EnvironmentObject var apiClient: APIClient
    @State private var contacts: [Contact] = []
    @State private var cameraPosition: MapCameraPosition = .automatic
    @State private var isLoading = false
    @State private var selectedContact: Contact?
    @State private var config: AppConfiguration?
    
    var body: some View {
        NavigationStack {
            ZStack {
                // Map
                Map(position: $cameraPosition) {
                    ForEach(contacts) { contact in
                        if let lat = contact.latitude, let lon = contact.longitude {
                            Annotation(contact.name, coordinate: CLLocationCoordinate2D(latitude: lat, longitude: lon)) {
                                VStack(spacing: 4) {
                                    Image(systemName: contactIconName(contact.nodeType))
                                        .font(.system(size: 16, weight: .bold))
                                        .foregroundColor(.white)
                                        .frame(width: 32, height: 32)
                                        .background(Circle().fill(Color.cyan))
                                        .onTapGesture {
                                            selectedContact = contact
                                        }
                                    
                                    Text(contact.name)
                                        .font(.system(size: 10, weight: .semibold))
                                        .foregroundColor(.white)
                                        .padding(.horizontal, 4)
                                        .padding(.vertical, 2)
                                        .background(Color.black.opacity(0.7))
                                        .cornerRadius(3)
                                }
                            }
                        }
                    }
                }
                .mapStyle(.standard(elevation: .realistic))
                
                // Loading overlay
                if isLoading {
                    VStack {
                        ProgressView()
                            .tint(.cyan)
                        Text("Loading devices...")
                            .font(.system(size: 12))
                            .foregroundColor(.white)
                    }
                    .frame(maxWidth: .infinity, maxHeight: .infinity)
                    .background(Color.black.opacity(0.3))
                }
                
                // Detail sheet
                if let contact = selectedContact {
                    VStack {
                        Spacer()
                        
                        VStack(alignment: .leading, spacing: 12) {
                            HStack {
                                VStack(alignment: .leading, spacing: 4) {
                                    Text(contact.name)
                                        .font(.system(size: 16, weight: .bold))
                                        .foregroundColor(.white)
                                    
                                    Text(contact.publicKey.prefix(16) + "...")
                                    .font(.system(size: 11, design: .monospaced))
                                        .foregroundColor(.gray)
                                }
                                
                                Spacer()
                                
                                Button(action: { selectedContact = nil }) {
                                    Image(systemName: "xmark.circle.fill")
                                        .font(.system(size: 20))
                                        .foregroundColor(.gray)
                                }
                            }
                            
                            Divider()
                            
                            VStack(alignment: .leading, spacing: 8) {
                                if let nodeType = contact.nodeType {
                                    HStack {
                                        Text("Type:")
                                            .foregroundColor(.gray)
                                        Text(nodeType)
                                            .foregroundColor(.cyan)
                                    }
                                }
                                
                                if let lat = contact.latitude, let lon = contact.longitude {
                                    HStack {
                                        Text("Location:")
                                            .foregroundColor(.gray)
                                        Text(String(format: "%.4f, %.4f", lat, lon))
                                            .foregroundColor(.cyan)
                                            .font(.monospaced(.system(size: 11))())
                                    }
                                }
                                
                                HStack {
                                    Text("Last Heard:")
                                        .foregroundColor(.gray)
                                    Text(formatTime(contact.lastHeardAt))
                                        .foregroundColor(.cyan)
                                }
                            }
                            .font(.system(size: 12))
                        }
                        .padding(.horizontal, 16)
                        .padding(.vertical, 12)
                        .background(Color(red: 0.12, green: 0.17, blue: 0.27))
                        .cornerRadius(12)
                        .padding(16)
                    }
                    .transition(.move(edge: .bottom))
                }
            }
            .navigationTitle("Map View")
            .navigationBarTitleDisplayMode(.inline)
            .onAppear {
                Task {
                    await loadContacts()
                    await loadConfig()
                }
            }
        }
    }
    
    private func loadContacts() async {
        do {
            isLoading = true
            let response = try await apiClient.fetchContacts()
            DispatchQueue.main.async {
                self.contacts = response.contacts
                
                // Set camera to center of contacts with location
                let locatedContacts = self.contacts.filter { $0.latitude != nil && $0.longitude != nil }
                if !locatedContacts.isEmpty {
                    let latitudes = locatedContacts.compactMap { $0.latitude }
                    let longitudes = locatedContacts.compactMap { $0.longitude }
                    
                    let avgLat = latitudes.reduce(0, +) / Double(latitudes.count)
                    let avgLon = longitudes.reduce(0, +) / Double(longitudes.count)
                    
                    self.cameraPosition = .region(MKCoordinateRegion(
                        center: CLLocationCoordinate2D(latitude: avgLat, longitude: avgLon),
                        span: MKCoordinateSpan(latitudeDelta: 5, longitudeDelta: 5)
                    ))
                }
                
                isLoading = false
            }
        } catch {
            print("Error loading contacts: \(error)")
        }
    }
    
    private func loadConfig() async {
        do {
            let appConfig = try await apiClient.fetchConfiguration()
            DispatchQueue.main.async {
                self.config = appConfig
            }
        } catch {
            print("Error loading config: \(error)")
        }
    }
    
    private func contactIconName(_ type: String?) -> String {
        switch type?.lowercased() {
        case "chat": return "message.circle.fill"
        case "repeater": return "repeat.circle.fill"
        case "room_server": return "server.rack"
        case "sensor": return "sensor.fill"
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
}

#Preview {
    MapView()
        .environmentObject(APIClient())
        .environmentObject(AuthenticationManager())
}
