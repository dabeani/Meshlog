/// Settings & Admin View
import SwiftUI

struct SettingsView: View {
    @EnvironmentObject var authManager: AuthenticationManager
    @EnvironmentObject var apiClient: APIClient
    
    @State private var showingAdminPanel = false
    @State private var selectedTab = "general"
    
    var body: some View {
        NavigationStack {
            ZStack {
                Color(red: 0.15, green: 0.2, blue: 0.3)
                    .ignoresSafeArea()
                
                VStack(spacing: 0) {
                    // Tab selection
                    Picker("Settings Tab", selection: $selectedTab) {
                        Text("General").tag("general")
                        Text("Admin").tag("admin")
                    }
                    .pickerStyle(.segmented)
                    .padding(16)
                    .background(Color(red: 0.1, green: 0.15, blue: 0.25))
                    
                    // Content
                    if selectedTab == "general" {
                        GeneralSettingsView()
                    } else {
                        AdminPanelView()
                    }
                }
            }
            .navigationTitle("Settings")
            .navigationBarTitleDisplayMode(.inline)
        }
    }
}

struct GeneralSettingsView: View {
    @EnvironmentObject var authManager: AuthenticationManager
    @EnvironmentObject var apiClient: APIClient
    
    @State private var showingLogoutAlert = false
    
    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 16) {
                // Account section
                VStack(alignment: .leading, spacing: 12) {
                    Text("Account")
                        .font(.system(size: 14, weight: .semibold))
                        .foregroundColor(.white)
                    
                    if let user = authManager.currentUser {
                        VStack(alignment: .leading, spacing: 8) {
                            HStack {
                                Text("User")
                                    .foregroundColor(.gray)
                                Spacer()
                                Text(user.name)
                                    .foregroundColor(.cyan)
                                    .font(.system(size: 12, weight: .semibold))
                            }
                            
                            Divider()
                            
                            HStack {
                                Text("Permissions")
                                    .foregroundColor(.gray)
                                Spacer()
                                Text(user.permissions.joined(separator: ", "))
                                    .foregroundColor(.cyan)
                                    .font(.system(size: 12))
                            }
                        }
                        .padding(12)
                        .background(Color(red: 0.12, green: 0.17, blue: 0.27))
                        .cornerRadius(8)
                    }
                }
                
                // Server section
                VStack(alignment: .leading, spacing: 12) {
                    Text("Server")
                        .font(.system(size: 14, weight: .semibold))
                        .foregroundColor(.white)
                    
                    VStack(alignment: .leading, spacing: 8) {
                        HStack {
                            Text("URL")
                                .foregroundColor(.gray)
                            Spacer()
                            Text(apiClient.baseURL)
                                .foregroundColor(.cyan)
                                .font(.system(size: 11, design: .monospaced))
                        }
                    }
                    .padding(12)
                    .background(Color(red: 0.12, green: 0.17, blue: 0.27))
                    .cornerRadius(8)
                }
                
                // App info
                VStack(alignment: .leading, spacing: 12) {
                    Text("About")
                        .font(.system(size: 14, weight: .semibold))
                        .foregroundColor(.white)
                    
                    VStack(alignment: .leading, spacing: 8) {
                        HStack {
                            Text("App Version")
                                .foregroundColor(.gray)
                            Spacer()
                            Text("1.0.0")
                                .foregroundColor(.cyan)
                                .font(.system(size: 12, weight: .semibold))
                        }
                        
                        Divider()
                        
                        HStack {
                            Text("API Version")
                                .foregroundColor(.gray)
                            Spacer()
                            Text("v1")
                                .foregroundColor(.cyan)
                                .font(.system(size: 12, weight: .semibold))
                        }
                    }
                    .padding(12)
                    .background(Color(red: 0.12, green: 0.17, blue: 0.27))
                    .cornerRadius(8)
                }
                
                Spacer()
                
                // Logout button
                Button(action: { showingLogoutAlert = true }) {
                    HStack {
                        Image(systemName: "power")
                        Text("Logout")
                    }
                    .frame(maxWidth: .infinity)
                    .padding(12)
                    .background(Color.red)
                    .foregroundColor(.white)
                    .cornerRadius(8)
                }
                .alert("Logout", isPresented: $showingLogoutAlert) {
                    Button("Cancel", role: .cancel) { }
                    Button("Logout", role: .destructive) {
                        authManager.logout()
                    }
                } message: {
                    Text("Are you sure you want to logout?")
                }
            }
            .padding(16)
        }
    }
}

struct AdminPanelView: View {
    @EnvironmentObject var apiClient: APIClient
    @State private var channels: [Channel] = []
    @State private var reporters: [Reporter] = []
    @State private var isLoading = false
    @State private var selectedAdminTab = "channels"
    
    var body: some View {
        VStack(spacing: 0) {
            // Admin tab picker
            Picker("Admin Tab", selection: $selectedAdminTab) {
                Text("Channels").tag("channels")
                Text("Reporters").tag("reporters")
            }
            .pickerStyle(.segmented)
            .padding(16)
            
            // Content
            if isLoading {
                ProgressView()
                    .tint(.cyan)
                    .frame(maxHeight: .infinity)
            } else if selectedAdminTab == "channels" {
                ChannelsListView(channels: channels)
            } else {
                ReportersListView(reporters: reporters)
            }
        }
        .onAppear {
            Task {
                await loadAdminData()
            }
        }
    }
    
    private func loadAdminData() async {
        do {
            isLoading = true

            async let channelsData = apiClient.fetchChannels()
            async let reportersData = apiClient.fetchReporters()

            let channels = try await channelsData
            let reporters = try await reportersData

            await MainActor.run {
                self.channels = channels
                self.reporters = reporters
                self.isLoading = false
            }
        } catch {
            print("Error loading admin data: \(error)")
        }
    }
}

struct ChannelsListView: View {
    let channels: [Channel]
    
    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 12) {
                ForEach(channels) { channel in
                    VStack(alignment: .leading, spacing: 8) {
                        HStack {
                            VStack(alignment: .leading, spacing: 2) {
                                Text(channel.name)
                                    .font(.system(size: 13, weight: .semibold))
                                    .foregroundColor(.white)
                                
                                Text(channel.hash.prefix(16) + "...")
                                    .font(.system(size: 10, design: .monospaced))
                                    .foregroundColor(.cyan)
                            }
                            
                            Spacer()
                            
                            VStack(alignment: .trailing, spacing: 2) {
                                Text(channel.enabled ? "✓ Enabled" : "✗ Disabled")
                                    .font(.system(size: 11, weight: .semibold))
                                    .foregroundColor(channel.enabled ? .green : .red)
                                
                                if let psk = channel.psk {
                                    Text(psk.prefix(8) + "...")
                                        .font(.system(size: 9, design: .monospaced))
                                        .foregroundColor(.gray)
                                }
                            }
                        }
                    }
                    .padding(12)
                    .background(Color(red: 0.12, green: 0.17, blue: 0.27))
                    .cornerRadius(8)
                }
            }
            .padding(16)
        }
    }
}

struct ReportersListView: View {
    let reporters: [Reporter]
    
    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 12) {
                ForEach(reporters) { reporter in
                    VStack(alignment: .leading, spacing: 8) {
                        HStack {
                            VStack(alignment: .leading, spacing: 2) {
                                Text(reporter.publicKey.prefix(12) + "...")
                                    .font(.system(size: 12, design: .monospaced))
                                    .foregroundColor(.cyan)
                            }
                            
                            Spacer()
                            
                            VStack(alignment: .trailing, spacing: 2) {
                                Text(reporter.authorized ? "✓ Auth" : "✗ Not Auth")
                                    .font(.system(size: 11, weight: .semibold))
                                    .foregroundColor(reporter.authorized ? .green : .red)
                                
                                if let lastHeard = reporter.lastHeardAt {
                                    Text(formatTime(lastHeard))
                                        .font(.system(size: 9))
                                        .foregroundColor(.gray)
                                }
                            }
                        }
                    }
                    .padding(12)
                    .background(Color(red: 0.12, green: 0.17, blue: 0.27))
                    .cornerRadius(8)
                }
            }
            .padding(16)
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

#Preview {
    SettingsView()
        .environmentObject(APIClient())
        .environmentObject(AuthenticationManager())
}
