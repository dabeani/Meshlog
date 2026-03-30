/// Settings & Admin View
import SwiftUI
import UIKit
import UserNotifications

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
                    HStack(spacing: 10) {
                        SettingsLogoMark(height: 24)

                        VStack(alignment: .leading, spacing: 1) {
                            Text("MeshCore Austria")
                                .font(.system(size: 12, weight: .semibold, design: .monospaced))
                                .foregroundColor(.white)
                            Text("MeshLog iOS")
                                .font(.system(size: 10, weight: .medium, design: .monospaced))
                                .foregroundColor(Color(red: 0.66, green: 0.78, blue: 0.86))
                        }

                        Spacer()
                    }
                    .padding(.horizontal, 16)
                    .padding(.top, 10)
                    .padding(.bottom, 8)

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
    @State private var reporters: [Reporter] = []
    @State private var isLoadingCollectors = false
    @State private var collectorLoadError = ""

    @AppStorage("live_type_adv") private var showADV = true
    @AppStorage("live_type_msg") private var showMSG = true
    @AppStorage("live_type_pub") private var showPUB = true
    @AppStorage("live_type_raw") private var showRAW = true
    @AppStorage("live_type_tel") private var showTEL = true
    @AppStorage("live_type_sys") private var showSYS = true
    @AppStorage("collector_filter_selected_ids") private var collectorFilterRaw = ""
    @AppStorage("notify_new_device") private var notifyNewDevice = false
    @AppStorage("notify_new_message") private var notifyNewMessage = false

    @State private var showNotificationPermissionHint = false
    @State private var suppressNotificationToggleHandler = false

    private var selectedCollectorIds: Set<Int> {
        Set(
            collectorFilterRaw
                .split(separator: ",")
                .compactMap { Int($0.trimmingCharacters(in: .whitespaces)) }
        )
    }

    private func updateCollectorSelection(_ id: Int, isEnabled: Bool, allIds: [Int]) {
        var set = selectedCollectorIds

        // Empty selection means "all collectors". Turning one off should
        // materialize an explicit set of all others.
        if set.isEmpty && !isEnabled {
            set = Set(allIds)
        }

        if isEnabled {
            set.insert(id)
        } else {
            set.remove(id)
        }
        collectorFilterRaw = set.sorted().map(String.init).joined(separator: ",")
    }
    
    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 16) {
                // Account section
                VStack(alignment: .leading, spacing: 12) {
                    Text("Account")
                        .font(.system(size: 14, weight: .semibold))
                        .foregroundColor(.white)
                    
                    if authManager.isGuestMode {
                        VStack(alignment: .leading, spacing: 8) {
                            HStack {
                                Text("Mode")
                                    .foregroundColor(.gray)
                                Spacer()
                                Text("Read-only")
                                    .foregroundColor(.cyan)
                                    .font(.system(size: 12, weight: .semibold))
                            }

                            Divider()

                            Text("Admin actions are disabled until you log in with username and password.")
                                .foregroundColor(.gray)
                                .font(.system(size: 11))
                        }
                        .padding(12)
                        .background(Color(red: 0.12, green: 0.17, blue: 0.27))
                        .cornerRadius(8)
                    } else if let user = authManager.currentUser {
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

                // Packet type filter section
                VStack(alignment: .leading, spacing: 12) {
                    Text("Live Packet Types")
                        .font(.system(size: 14, weight: .semibold))
                        .foregroundColor(.white)

                    VStack(alignment: .leading, spacing: 6) {
                        Toggle("ADV", isOn: $showADV).tint(.cyan)
                        Toggle("MSG", isOn: $showMSG).tint(.cyan)
                        Toggle("PUB", isOn: $showPUB).tint(.cyan)
                        Toggle("RAW", isOn: $showRAW).tint(.cyan)
                        Toggle("TEL", isOn: $showTEL).tint(.cyan)
                        Toggle("SYS", isOn: $showSYS).tint(.cyan)
                    }
                    .font(.system(size: 12, weight: .medium, design: .monospaced))
                    .foregroundColor(.white)
                    .padding(12)
                    .background(Color(red: 0.12, green: 0.17, blue: 0.27))
                    .cornerRadius(8)
                }

                // Collector filter section
                VStack(alignment: .leading, spacing: 12) {
                    HStack {
                        Text("Collectors")
                            .font(.system(size: 14, weight: .semibold))
                            .foregroundColor(.white)

                        Spacer()

                        Button("All") {
                            collectorFilterRaw = ""
                        }
                        .font(.system(size: 11, weight: .semibold))
                        .foregroundColor(.cyan)
                    }

                    VStack(alignment: .leading, spacing: 8) {
                        if isLoadingCollectors {
                            Text("Loading collectors...")
                                .font(.system(size: 11))
                                .foregroundColor(.gray)
                        } else if !collectorLoadError.isEmpty {
                            VStack(alignment: .leading, spacing: 6) {
                                Text("Collectors unavailable")
                                    .font(.system(size: 11, weight: .semibold))
                                    .foregroundColor(.orange)
                                Text(collectorLoadError)
                                    .font(.system(size: 10))
                                    .foregroundColor(.gray)
                                Button("Retry") {
                                    Task { await loadCollectors() }
                                }
                                .font(.system(size: 11, weight: .semibold))
                                .foregroundColor(.cyan)
                            }
                        } else if reporters.isEmpty {
                            Text("No collectors found")
                                .font(.system(size: 11))
                                .foregroundColor(.gray)
                        } else {
                            ForEach(reporters) { reporter in
                                let selected = selectedCollectorIds.contains(reporter.id)
                                Toggle(isOn: Binding(
                                    get: { selectedCollectorIds.isEmpty || selected },
                                    set: {
                                        updateCollectorSelection(
                                            reporter.id,
                                            isEnabled: $0,
                                            allIds: reporters.map { $0.id }
                                        )
                                    }
                                )) {
                                    VStack(alignment: .leading, spacing: 2) {
                                        Text(reporter.publicKey.prefix(16) + "...")
                                            .font(.system(size: 10, design: .monospaced))
                                            .foregroundColor(.cyan)
                                        Text("ID \(reporter.id)")
                                            .font(.system(size: 9))
                                            .foregroundColor(.gray)
                                    }
                                }
                                .tint(.cyan)
                            }
                        }
                    }
                    .padding(12)
                    .background(Color(red: 0.12, green: 0.17, blue: 0.27))
                    .cornerRadius(8)
                }

                // Notification section
                VStack(alignment: .leading, spacing: 12) {
                    Text("Notifications")
                        .font(.system(size: 14, weight: .semibold))
                        .foregroundColor(.white)

                    VStack(alignment: .leading, spacing: 6) {
                        Toggle("New Device", isOn: $notifyNewDevice)
                            .tint(.cyan)
                        Toggle("New Message", isOn: $notifyNewMessage)
                            .tint(.cyan)

                        if showNotificationPermissionHint {
                            Text("Notifications are disabled in iOS settings for this app. Enable them in Settings > Notifications > MeshLog.")
                                .font(.system(size: 10))
                                .foregroundColor(.orange)
                                .padding(.top, 4)
                        }
                    }
                    .font(.system(size: 12, weight: .medium, design: .monospaced))
                    .foregroundColor(.white)
                    .padding(12)
                    .background(Color(red: 0.12, green: 0.17, blue: 0.27))
                    .cornerRadius(8)
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
                
                // Logout/Admin button (only show when authenticated as admin)
                if authManager.isAuthenticated {
                    Button(action: { showingLogoutAlert = true }) {
                        HStack {
                            Image(systemName: "power")
                            Text("Logout Admin")
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
                        Text("You will return to read-only mode.")
                    }
                }
            }
            .padding(16)
            .onAppear {
                Task {
                    if reporters.isEmpty { await loadCollectors() }
                }
            }
            .onChange(of: notifyNewDevice) { _, enabled in
                guard !suppressNotificationToggleHandler else { return }
                if enabled {
                    Task { await ensureNotificationPermission(for: "device") }
                }
            }
            .onChange(of: notifyNewMessage) { _, enabled in
                guard !suppressNotificationToggleHandler else { return }
                if enabled {
                    Task { await ensureNotificationPermission(for: "message") }
                }
            }
        }
    }

    @MainActor
    private func loadCollectors() async {
        isLoadingCollectors = true
        collectorLoadError = ""

        do {
            let fetched = try await apiClient.fetchReporters()
            if !fetched.isEmpty {
                reporters = fetched
                isLoadingCollectors = false
                return
            }

            // Fallback: derive collector IDs from contacts if reporters endpoint is empty.
            let contacts = try await apiClient.fetchContacts(offset: 0, count: 300)
            let ids = Array(Set(contacts.contacts.flatMap { $0.reporterIds })).sorted()
            reporters = ids.map {
                Reporter(id: $0, publicKey: "Collector \($0)", authorized: true, lastHeardAt: nil)
            }

            if reporters.isEmpty {
                collectorLoadError = "The server returned no collector records."
            }
            isLoadingCollectors = false
        } catch {
            reporters = []
            collectorLoadError = error.localizedDescription
            isLoadingCollectors = false
        }
    }

    @MainActor
    private func revertNotificationToggle(_ key: String) {
        suppressNotificationToggleHandler = true
        if key == "device" {
            notifyNewDevice = false
        } else {
            notifyNewMessage = false
        }
        suppressNotificationToggleHandler = false
    }

    private func ensureNotificationPermission(for key: String) async {
        let center = UNUserNotificationCenter.current()
        let settings = await center.notificationSettings()

        switch settings.authorizationStatus {
        case .authorized, .provisional, .ephemeral:
            await MainActor.run {
                showNotificationPermissionHint = false
            }
        case .notDetermined:
            do {
                let granted = try await center.requestAuthorization(options: [.alert, .badge, .sound])
                await MainActor.run {
                    showNotificationPermissionHint = !granted
                }
                if !granted {
                    revertNotificationToggle(key)
                }
            } catch {
                await MainActor.run {
                    showNotificationPermissionHint = true
                }
                revertNotificationToggle(key)
            }
        case .denied:
            await MainActor.run {
                showNotificationPermissionHint = true
            }
            revertNotificationToggle(key)
        @unknown default:
            await MainActor.run {
                showNotificationPermissionHint = true
            }
            revertNotificationToggle(key)
        }
    }
}

struct AdminPanelView: View {
    @EnvironmentObject var authManager: AuthenticationManager
    @EnvironmentObject var apiClient: APIClient
    @State private var channels: [Channel] = []
    @State private var reporters: [Reporter] = []
    @State private var isLoading = false
    @State private var selectedAdminTab = "channels"
    
    var body: some View {
        if authManager.isAuthenticated {
            // Authenticated: show admin controls
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
        } else {
            // Not authenticated: show login form
            AdminLoginFormView()
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

struct AdminLoginFormView: View {
    @EnvironmentObject var authManager: AuthenticationManager
    @EnvironmentObject var apiClient: APIClient
    
    @State private var username = ""
    @State private var password = ""
    @State private var isLoading = false
    @State private var errorMessage = ""
    
    var body: some View {
        ScrollView {
            VStack(alignment: .center, spacing: 20) {
                VStack(alignment: .center, spacing: 8) {
                    Image(systemName: "lock.circle.fill")
                        .font(.system(size: 40))
                        .foregroundColor(.cyan)
                    
                    Text("Admin Login")
                        .font(.system(size: 18, weight: .bold))
                        .foregroundColor(.white)
                    
                    Text("Enter your credentials to access admin features")
                        .font(.system(size: 12))
                        .foregroundColor(.gray)
                        .multilineTextAlignment(.center)
                }
                .padding(.vertical, 20)
                
                // Error message
                if !errorMessage.isEmpty {
                    VStack(alignment: .leading, spacing: 8) {
                        HStack(spacing: 8) {
                            Image(systemName: "exclamationmark.triangle.fill")
                                .foregroundColor(.red)
                            Text(errorMessage)
                                .font(.system(size: 12))
                                .foregroundColor(.red)
                        }
                    }
                    .padding(12)
                    .background(Color.red.opacity(0.1))
                    .cornerRadius(8)
                }
                
                // Form fields
                VStack(alignment: .leading, spacing: 12) {
                    VStack(alignment: .leading, spacing: 4) {
                        Text("Username")
                            .font(.system(size: 12, weight: .semibold))
                            .foregroundColor(.gray)
                        
                        TextField("Enter username", text: $username)
                            .textInputAutocapitalization(.never)
                            .autocorrectionDisabled()
                            .padding(12)
                            .background(Color(red: 0.12, green: 0.17, blue: 0.27))
                            .cornerRadius(8)
                            .foregroundColor(.white)
                    }
                    
                    VStack(alignment: .leading, spacing: 4) {
                        Text("Password")
                            .font(.system(size: 12, weight: .semibold))
                            .foregroundColor(.gray)
                        
                        SecureField("Enter password", text: $password)
                            .padding(12)
                            .background(Color(red: 0.12, green: 0.17, blue: 0.27))
                            .cornerRadius(8)
                            .foregroundColor(.white)
                    }
                }
                
                // Login button
                Button {
                    Task { await login() }
                } label: {
                    if isLoading {
                        ProgressView()
                            .tint(.white)
                            .frame(maxWidth: .infinity, minHeight: 44)
                            .background(Color.cyan.opacity(0.7))
                            .cornerRadius(8)
                    } else {
                        Text("Login")
                            .font(.system(size: 14, weight: .semibold))
                            .frame(maxWidth: .infinity, minHeight: 44)
                            .background(Color.cyan)
                            .foregroundColor(.black)
                            .cornerRadius(8)
                    }
                }
                .disabled(isLoading || username.isEmpty || password.isEmpty)
                
                Spacer()
            }
            .padding(16)
        }
    }
    
    private func login() async {
        errorMessage = ""
        isLoading = true
        
        await authManager.login(
            baseURL: apiClient.baseURL,
            username: username,
            password: password
        )
        
        isLoading = false
        if !authManager.isAuthenticated {
            errorMessage = authManager.errorMessage ?? "Login failed"
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

private struct SettingsLogoMark: View {
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
