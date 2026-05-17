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
                            Text("MeshLogAustria iOS")
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
            .meshNavigationBarInline()
        }
    }
}

struct GeneralSettingsView: View {
    @EnvironmentObject var authManager: AuthenticationManager
    @EnvironmentObject var apiClient: APIClient
    
    @State private var showingLogoutAlert = false
    @State private var reporters: [Reporter] = []
    @State private var channels: [Channel] = []
    @State private var collectorNameByPublicKey: [String: String] = [:]
    @State private var isLoadingCollectors = false
    @State private var isLoadingChannels = false
    @State private var collectorLoadError = ""
    @State private var channelLoadError = ""
    @State private var serverDraftURL = ""
    @State private var selectedServerMode = "custom"
    @State private var serverApplyError = ""
    @State private var lastLoadedBaseURL = ""

    @AppStorage("live_type_adv") private var showADV = true
    @AppStorage("live_type_msg") private var showMSG = true
    @AppStorage("live_type_pub") private var showPUB = true
    @AppStorage("live_type_raw") private var showRAW = true
    @AppStorage("live_type_tel") private var showTEL = true
    @AppStorage("live_type_sys") private var showSYS = true
    @AppStorage("collector_filter_selected_ids") private var collectorFilterRaw = ""
    @AppStorage("channels_filter_mode") private var channelFilterModeRaw = "all"
    @AppStorage("channels_filter_selected_keys") private var selectedChannelFilterRaw = "[]"
    @AppStorage("notify_new_device") private var notifyNewDevice = false
    @AppStorage("notify_new_message") private var notifyNewMessage = false
    @AppStorage("distance_unit") private var distanceUnit = "km"
    @AppStorage("snr_excellent_threshold") private var snrExcellentThreshold = 9
    @AppStorage("snr_good_threshold") private var snrGoodThreshold = 3
    @AppStorage("snr_fair_threshold") private var snrFairThreshold = -3
    @AppStorage("snr_weak_threshold") private var snrWeakThreshold = -10
    @AppStorage("live_feed_items_limit") private var liveFeedItemsLimit = 300

    @State private var showNotificationPermissionHint = false
    @State private var suppressNotificationToggleHandler = false

    private let officialServerURL = "https://meshlog.1tld.net"

    private var selectedCollectorIds: Set<Int> {
        Set(
            collectorFilterRaw
                .split(separator: ",")
                .compactMap { Int($0.trimmingCharacters(in: .whitespaces)) }
        )
    }

    private var selectedChannelFilterKeys: Set<String> {
        decodeStringSet(selectedChannelFilterRaw)
    }

    private func normalizedChannelName(_ raw: String?) -> String {
        let trimmed = (raw ?? "").trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmed.isEmpty else { return "Unknown" }
        return trimmed.trimmingCharacters(in: CharacterSet(charactersIn: "#"))
    }

    private func channelKeys(for channel: Channel) -> Set<String> {
        let idKey = "id:\(channel.id)"
        let nameKey = "name:\(normalizedChannelName(channel.name).lowercased())"
        return [idKey, nameKey]
    }

    private func encodeStringSet(_ value: Set<String>) -> String {
        guard let data = try? JSONEncoder().encode(Array(value).sorted()),
              let text = String(data: data, encoding: .utf8) else {
            return "[]"
        }
        return text
    }

    private func decodeStringSet(_ raw: String) -> Set<String> {
        guard let data = raw.data(using: .utf8),
              let values = try? JSONDecoder().decode([String].self, from: data) else {
            return []
        }
        return Set(values)
    }

    private func toggleChannelSelection(_ channel: Channel, enabled: Bool) {
        var set = selectedChannelFilterKeys
        let keys = channelKeys(for: channel)
        if enabled {
            set.formUnion(keys)
        } else {
            set.subtract(keys)
        }
        selectedChannelFilterRaw = encodeStringSet(set)
    }

    private func clearChannelSelection() {
        selectedChannelFilterRaw = "[]"
    }

    private func selectAllChannels() {
        var set = Set<String>()
        for channel in channels {
            set.formUnion(channelKeys(for: channel))
        }
        selectedChannelFilterRaw = encodeStringSet(set)
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

    private func collectorDisplayName(for reporter: Reporter) -> String {
        collectorNameByPublicKey[reporter.publicKey.uppercased()] ?? "Collector \(reporter.id)"
    }

    private func shortReporterKey(_ key: String) -> String {
        String(key.prefix(16)) + "..."
    }

    private func channelSelectionBinding(for channel: Channel) -> Binding<Bool> {
        Binding(
            get: {
                if channelFilterModeRaw != "selected" {
                    return true
                }
                return !selectedChannelFilterKeys.isDisjoint(with: channelKeys(for: channel))
            },
            set: { isEnabled in
                if channelFilterModeRaw != "selected" {
                    channelFilterModeRaw = "selected"
                    selectAllChannels()
                }
                toggleChannelSelection(channel, enabled: isEnabled)
            }
        )
    }

    private func syncServerSelectionFromCurrentURL() {
        let currentURL = apiClient.baseURL.trimmingCharacters(in: .whitespacesAndNewlines)
        if serverDraftURL.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty || currentURL != lastLoadedBaseURL {
            serverDraftURL = currentURL
        }
        selectedServerMode = currentURL.caseInsensitiveCompare(officialServerURL) == .orderedSame ? "official" : "custom"
    }

    private func normalizedServerURL(_ raw: String) -> String? {
        let trimmed = raw.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmed.isEmpty,
              let url = URL(string: trimmed),
              let scheme = url.scheme?.lowercased(),
              (scheme == "http" || scheme == "https"),
              url.host != nil else {
            return nil
        }
        return trimmed
    }

    @MainActor
    private func reloadRemoteSelections(force: Bool = false) async {
        syncServerSelectionFromCurrentURL()

        let currentBaseURL = apiClient.baseURL.trimmingCharacters(in: .whitespacesAndNewlines)
        let shouldReloadCollectors = force || currentBaseURL != lastLoadedBaseURL || reporters.isEmpty || !collectorLoadError.isEmpty
        let shouldReloadChannels = force || currentBaseURL != lastLoadedBaseURL || channels.isEmpty || !channelLoadError.isEmpty
        lastLoadedBaseURL = currentBaseURL

        if shouldReloadCollectors {
            await loadCollectors()
        }

        if shouldReloadChannels {
            await loadChannels()
        }
    }

    @MainActor
    private func applyServerSelection() async {
        serverApplyError = ""

        let targetURL = selectedServerMode == "official" ? officialServerURL : serverDraftURL
        guard let normalizedURL = normalizedServerURL(targetURL) else {
            serverApplyError = "Enter a valid http(s) server URL."
            return
        }

        let previousURL = apiClient.baseURL
        apiClient.updateBaseURL(normalizedURL)
        serverDraftURL = normalizedURL
        syncServerSelectionFromCurrentURL()

        if previousURL != normalizedURL {
            reporters = []
            channels = []
            collectorNameByPublicKey = [:]
            collectorLoadError = ""
            channelLoadError = ""
        }

        await reloadRemoteSelections(force: true)
    }

    private func collectorToggleBinding(for reporterId: Int, allIds: [Int]) -> Binding<Bool> {
        Binding(
            get: {
                let selected = selectedCollectorIds
                return selected.isEmpty || selected.contains(reporterId)
            },
            set: { isEnabled in
                updateCollectorSelection(reporterId, isEnabled: isEnabled, allIds: allIds)
            }
        )
    }

    private var appVersionText: String {
        let short = Bundle.main.object(forInfoDictionaryKey: "CFBundleShortVersionString") as? String
        let build = Bundle.main.object(forInfoDictionaryKey: "CFBundleVersion") as? String

        let v = short?.trimmingCharacters(in: .whitespacesAndNewlines) ?? "-"
        let b = build?.trimmingCharacters(in: .whitespacesAndNewlines) ?? "-"
        return "\(v) (\(b))"
    }

    @ViewBuilder
    private func meshIntControl<Label: View>(
        value: Binding<Int>,
        range: ClosedRange<Int>,
        step: Int = 1,
        @ViewBuilder label: () -> Label
    ) -> some View {
#if os(iOS)
        Stepper(value: value, in: range, step: step) {
            label()
        }
#else
        VStack(alignment: .leading, spacing: 8) {
            label()
            HStack(spacing: 10) {
                Button("-") {
                    value.wrappedValue = max(range.lowerBound, value.wrappedValue - step)
                }
                .buttonStyle(.bordered)
                .disabled(value.wrappedValue <= range.lowerBound)

                Text("\(value.wrappedValue)")
                    .font(.system(size: 11, weight: .semibold, design: .monospaced))
                    .foregroundColor(.cyan)
                    .frame(minWidth: 40)

                Button("+") {
                    value.wrappedValue = min(range.upperBound, value.wrappedValue + step)
                }
                .buttonStyle(.bordered)
                .disabled(value.wrappedValue >= range.upperBound)
            }
        }
#endif
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

                        Button("Reload") {
                            Task { await reloadRemoteSelections(force: true) }
                        }
                        .font(.system(size: 11, weight: .semibold))
                        .foregroundColor(.cyan)

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
                            let allReporterIds = reporters.map { $0.id }
                            ForEach(reporters) { reporter in
                                Toggle(isOn: collectorToggleBinding(for: reporter.id, allIds: allReporterIds)) {
                                    VStack(alignment: .leading, spacing: 2) {
                                        Text(collectorDisplayName(for: reporter))
                                            .font(.system(size: 12, weight: .semibold))
                                            .foregroundColor(.white)
                                        Text(shortReporterKey(reporter.publicKey))
                                            .font(.system(size: 9, design: .monospaced))
                                            .foregroundColor(.gray)
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

                // Live channel filter section
                VStack(alignment: .leading, spacing: 12) {
                    Text("Live Channels")
                        .font(.system(size: 14, weight: .semibold))
                        .foregroundColor(.white)

                    Picker("Channel Filter", selection: $channelFilterModeRaw) {
                        Text("All").tag("all")
                        Text("Selected").tag("selected")
                    }
                    .pickerStyle(.segmented)

                    HStack(spacing: 10) {
                        Button("All") {
                            selectAllChannels()
                            channelFilterModeRaw = "selected"
                        }
                        .font(.system(size: 11, weight: .semibold))
                        .foregroundColor(.cyan)

                        Button("None") {
                            clearChannelSelection()
                            channelFilterModeRaw = "selected"
                        }
                        .font(.system(size: 11, weight: .semibold))
                        .foregroundColor(.cyan)

                        Button("Reload") {
                            Task { await reloadRemoteSelections(force: true) }
                        }
                        .font(.system(size: 11, weight: .semibold))
                        .foregroundColor(.cyan)

                        Spacer()
                    }

                    if channelFilterModeRaw == "all" {
                        Text("All channels are currently enabled. Toggle any channel below to switch into a custom selected set.")
                            .font(.system(size: 10))
                            .foregroundColor(.gray)
                    }

                    if isLoadingChannels {
                        Text("Loading channels...")
                            .font(.system(size: 11))
                            .foregroundColor(.gray)
                    } else if !channelLoadError.isEmpty {
                        VStack(alignment: .leading, spacing: 6) {
                            Text("Channels unavailable")
                                .font(.system(size: 11, weight: .semibold))
                                .foregroundColor(.orange)
                            Text(channelLoadError)
                                .font(.system(size: 10))
                                .foregroundColor(.gray)
                            Button("Retry") {
                                Task { await reloadRemoteSelections(force: true) }
                            }
                            .font(.system(size: 11, weight: .semibold))
                            .foregroundColor(.cyan)
                        }
                    } else if channels.isEmpty {
                        Text("No channels found")
                            .font(.system(size: 11))
                            .foregroundColor(.gray)
                    } else {
                        ForEach(channels) { channel in
                            Toggle(isOn: channelSelectionBinding(for: channel)) {
                                VStack(alignment: .leading, spacing: 2) {
                                    Text(channel.name)
                                        .font(.system(size: 12, weight: .semibold))
                                        .foregroundColor(.white)
                                    Text("ID \(channel.id)")
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
                            Text("Notifications are disabled in iOS settings for this app. Enable them in Settings > Notifications > MeshLogAustria.")
                                .font(.system(size: 10))
                                .foregroundColor(.orange)
                                .padding(.top, 4)
                        }

                        Text("Background alerts depend on iOS background runtime. Keep Live monitoring enabled for best reliability.")
                            .font(.system(size: 10))
                            .foregroundColor(.gray)
                            .padding(.top, 4)
                    }
                    .font(.system(size: 12, weight: .medium, design: .monospaced))
                    .foregroundColor(.white)
                    .padding(12)
                    .background(Color(red: 0.12, green: 0.17, blue: 0.27))
                    .cornerRadius(8)
                }

                // Distance unit section
                VStack(alignment: .leading, spacing: 12) {
                    Text("Distance Units")
                        .font(.system(size: 14, weight: .semibold))
                        .foregroundColor(.white)

                    Picker("Distance Unit", selection: $distanceUnit) {
                        Text("KM").tag("km")
                        Text("Miles").tag("mi")
                    }
                    .pickerStyle(.segmented)
                    .padding(12)
                    .background(Color(red: 0.12, green: 0.17, blue: 0.27))
                    .cornerRadius(8)
                }

                // SNR badge colors
                VStack(alignment: .leading, spacing: 12) {
                    Text("SNR Badge Colors")
                        .font(.system(size: 14, weight: .semibold))
                        .foregroundColor(.white)

                    VStack(alignment: .leading, spacing: 8) {
                        meshIntControl(value: $snrExcellentThreshold, range: (snrGoodThreshold + 1)...40) {
                            thresholdRow(label: "Excellent", value: snrExcellentThreshold, color: Color(red: 0.24, green: 0.66, blue: 0.42))
                        }

                        meshIntControl(value: $snrGoodThreshold, range: (snrFairThreshold + 1)...(snrExcellentThreshold - 1)) {
                            thresholdRow(label: "Good", value: snrGoodThreshold, color: Color(red: 0.27, green: 0.66, blue: 0.76))
                        }

                        meshIntControl(value: $snrFairThreshold, range: (snrWeakThreshold + 1)...(snrGoodThreshold - 1)) {
                            thresholdRow(label: "Fair", value: snrFairThreshold, color: Color(red: 0.78, green: 0.60, blue: 0.23))
                        }

                        meshIntControl(value: $snrWeakThreshold, range: -40...(snrFairThreshold - 1)) {
                            thresholdRow(label: "Weak", value: snrWeakThreshold, color: Color(red: 0.83, green: 0.49, blue: 0.22))
                        }

                        HStack(spacing: 6) {
                            Image(systemName: "slider.horizontal.3")
                                .font(.system(size: 11))
                                .foregroundColor(.gray)
                            Text("WebUI colors are fixed by default. You can customize only the dB thresholds.")
                                .font(.system(size: 10))
                                .foregroundColor(.gray)
                        }
                        .padding(.top, 2)
                    }
                    .padding(12)
                    .background(Color(red: 0.12, green: 0.17, blue: 0.27))
                    .cornerRadius(8)
                }

                // Live feed items limit
                VStack(alignment: .leading, spacing: 12) {
                    HStack {
                        Text("Live Feed Items")
                            .font(.system(size: 14, weight: .semibold))
                            .foregroundColor(.white)

                        Spacer()

                        Text("\(liveFeedItemsLimit)")
                            .font(.system(size: 12, weight: .semibold, design: .monospaced))
                            .foregroundColor(.cyan)
                    }

                    meshIntControl(value: $liveFeedItemsLimit, range: 20...500, step: 10) {
                        Text("Items loaded")
                            .font(.system(size: 12, weight: .medium))
                            .foregroundColor(.white)
                    }
                    .tint(.cyan)

                    HStack(spacing: 6) {
                        Image(systemName: "questionmark.circle")
                            .font(.system(size: 11))
                            .foregroundColor(.gray)
                        Text("Configured app maximum is 500 items.")
                            .font(.system(size: 10))
                            .foregroundColor(.gray)
                    }
                }
                .padding(12)
                .background(Color(red: 0.12, green: 0.17, blue: 0.27))
                .cornerRadius(8)
                
                // Server section
                VStack(alignment: .leading, spacing: 12) {
                    Text("Server")
                        .font(.system(size: 14, weight: .semibold))
                        .foregroundColor(.white)
                    
                    VStack(alignment: .leading, spacing: 8) {
                        Picker("Server Endpoint", selection: $selectedServerMode) {
                            Text("Official").tag("official")
                            Text("Custom").tag("custom")
                        }
                        .pickerStyle(.segmented)
                        .onChange(of: selectedServerMode) { _, newValue in
                            if newValue == "official" {
                                serverDraftURL = officialServerURL
                            } else if serverDraftURL.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
                                serverDraftURL = apiClient.baseURL
                            }
                        }

                        if selectedServerMode == "custom" {
                            TextField(
                                "Custom server URL",
                                text: $serverDraftURL,
                                prompt: Text("https://your-meshlog-server").foregroundColor(Color.white.opacity(0.65))
                            )
                            .textInputAutocapitalization(.never)
                            .autocorrectionDisabled()
                            .meshTextInputStyle()
                        }

                        HStack(spacing: 10) {
                            Button("Apply") {
                                Task { await applyServerSelection() }
                            }
                            .font(.system(size: 11, weight: .semibold))
                            .foregroundColor(.cyan)

                            Button("Reload") {
                                Task { await reloadRemoteSelections(force: true) }
                            }
                            .font(.system(size: 11, weight: .semibold))
                            .foregroundColor(.cyan)

                            Spacer()
                        }

                        if !serverApplyError.isEmpty {
                            Text(serverApplyError)
                                .font(.system(size: 10))
                                .foregroundColor(.orange)
                        }

                        HStack {
                            Text("Current")
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
                            Text(appVersionText)
                                .foregroundColor(.cyan)
                                .font(.system(size: 12, weight: .semibold, design: .monospaced))
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
            .task(id: apiClient.baseURL) {
                syncServerSelectionFromCurrentURL()
                await reloadRemoteSelections()
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
    private func loadChannels() async {
        isLoadingChannels = true
        channelLoadError = ""

        do {
            channels = try await apiClient.fetchChannels().filter { $0.enabled }
            isLoadingChannels = false
        } catch {
            channels = []
            channelLoadError = error.localizedDescription
            isLoadingChannels = false
        }
    }

    @MainActor
    private func loadCollectors() async {
        isLoadingCollectors = true
        collectorLoadError = ""

        do {
            let fetched = try await apiClient.fetchReporters()
            let contactsResponse = try? await apiClient.fetchContacts(offset: 0, count: 500)
            let nameMap: [String: String] = Dictionary(
                uniqueKeysWithValues: (contactsResponse?.contacts ?? [])
                    .filter { !$0.publicKey.isEmpty && !$0.name.isEmpty }
                    .map { ($0.publicKey.uppercased(), $0.name) }
            )

            if !fetched.isEmpty {
                reporters = fetched
                collectorNameByPublicKey = nameMap
                isLoadingCollectors = false
                return
            }

            // Fallback: derive collector IDs from contacts if reporters endpoint is empty.
            let contacts = try await apiClient.fetchContacts(offset: 0, count: 300)
            let ids = Array(Set(contacts.contacts.flatMap { $0.reporterIds })).sorted()
            reporters = ids.map {
                Reporter(id: $0, publicKey: "Collector \($0)", authorized: true, lastHeardAt: nil, timeSync: nil, reporterStatus: nil)
            }
            collectorNameByPublicKey = nameMap

            if reporters.isEmpty {
                collectorLoadError = "The server returned no collector records."
            }
            isLoadingCollectors = false
        } catch {
            reporters = []
            collectorNameByPublicKey = [:]
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

    @ViewBuilder
    private func thresholdRow(label: String, value: Int, color: Color) -> some View {
        HStack(spacing: 8) {
            Circle()
                .fill(color)
                .frame(width: 8, height: 8)

            Text(label)
                .font(.system(size: 11, weight: .semibold, design: .monospaced))
                .foregroundColor(.white)

            Spacer()

            Text("\(value) dB")
                .font(.system(size: 11, weight: .semibold, design: .monospaced))
                .foregroundColor(.cyan)
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
                        
                        TextField(
                            "",
                            text: $username,
                            prompt: Text("Enter username")
                                .foregroundColor(Color.white.opacity(0.65))
                        )
                            .textInputAutocapitalization(.never)
                            .autocorrectionDisabled()
                            .meshTextInputStyle()
                    }
                    
                    VStack(alignment: .leading, spacing: 4) {
                        Text("Password")
                            .font(.system(size: 12, weight: .semibold))
                            .foregroundColor(.gray)
                        
                        SecureField(
                            "",
                            text: $password,
                            prompt: Text("Enter password")
                                .foregroundColor(Color.white.opacity(0.65))
                        )
                            .meshTextInputStyle()
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
