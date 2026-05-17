/// MeshLog iOS App - Main App Entry Point
import SwiftUI
#if canImport(UIKit)
import UIKit
#endif
#if canImport(UserNotifications)
import UserNotifications
#endif

final class AppNavigationState: ObservableObject {
    @Published var selectedTab: Int = 0
    @Published var mapFocusContactId: Int?
    @Published var mapFocusContactPublicKey: String?
    @Published var mapFocusNonce: Int = 0

    func focusOnMap(contactId: Int?, publicKey: String?) {
        mapFocusContactId = contactId
        mapFocusContactPublicKey = publicKey
        mapFocusNonce += 1
        selectedTab = 1
    }
}

final class AppDelegate: NSObject, UIApplicationDelegate, UNUserNotificationCenterDelegate {
    func application(
        _ application: UIApplication,
        didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey : Any]? = nil
    ) -> Bool {
        UNUserNotificationCenter.current().delegate = self
        configureGlobalAppearance()
        return true
    }

    private func configureGlobalAppearance() {
#if os(iOS)
        let darkBlue = UIColor(red: 0.15, green: 0.20, blue: 0.30, alpha: 1.0)
        let tabBarDark = UIColor(red: 0.10, green: 0.13, blue: 0.19, alpha: 1.0)

        let navAppearance = UINavigationBarAppearance()
        navAppearance.configureWithOpaqueBackground()
        navAppearance.backgroundColor = darkBlue
        navAppearance.titleTextAttributes = [.foregroundColor: UIColor.white]
        navAppearance.largeTitleTextAttributes = [.foregroundColor: UIColor.white]
        UINavigationBar.appearance().standardAppearance = navAppearance
        UINavigationBar.appearance().scrollEdgeAppearance = navAppearance
        UINavigationBar.appearance().compactAppearance = navAppearance

        let tabAppearance = UITabBarAppearance()
        tabAppearance.configureWithOpaqueBackground()
        tabAppearance.backgroundColor = tabBarDark

        let normalItemColor = UIColor(white: 0.84, alpha: 1.0)
        let selectedItemColor = UIColor.white

        tabAppearance.stackedLayoutAppearance.normal.iconColor = normalItemColor
        tabAppearance.stackedLayoutAppearance.normal.titleTextAttributes = [.foregroundColor: normalItemColor]
        tabAppearance.stackedLayoutAppearance.selected.iconColor = selectedItemColor
        tabAppearance.stackedLayoutAppearance.selected.titleTextAttributes = [.foregroundColor: selectedItemColor]

        tabAppearance.inlineLayoutAppearance.normal.iconColor = normalItemColor
        tabAppearance.inlineLayoutAppearance.normal.titleTextAttributes = [.foregroundColor: normalItemColor]
        tabAppearance.inlineLayoutAppearance.selected.iconColor = selectedItemColor
        tabAppearance.inlineLayoutAppearance.selected.titleTextAttributes = [.foregroundColor: selectedItemColor]

        tabAppearance.compactInlineLayoutAppearance.normal.iconColor = normalItemColor
        tabAppearance.compactInlineLayoutAppearance.normal.titleTextAttributes = [.foregroundColor: normalItemColor]
        tabAppearance.compactInlineLayoutAppearance.selected.iconColor = selectedItemColor
        tabAppearance.compactInlineLayoutAppearance.selected.titleTextAttributes = [.foregroundColor: selectedItemColor]

        UITabBar.appearance().standardAppearance = tabAppearance
        UITabBar.appearance().scrollEdgeAppearance = tabAppearance
        UITabBar.appearance().tintColor = selectedItemColor
        UITabBar.appearance().unselectedItemTintColor = normalItemColor

        let segmentedAppearance = UISegmentedControl.appearance()
        segmentedAppearance.backgroundColor = UIColor(white: 0.18, alpha: 1.0)
        segmentedAppearance.selectedSegmentTintColor = .white
        segmentedAppearance.setTitleTextAttributes([.foregroundColor: UIColor(white: 0.94, alpha: 1.0)], for: .normal)
        segmentedAppearance.setTitleTextAttributes([.foregroundColor: UIColor.black], for: .selected)
#endif
    }

    func userNotificationCenter(
        _ center: UNUserNotificationCenter,
        willPresent notification: UNNotification,
        withCompletionHandler completionHandler: @escaping (UNNotificationPresentationOptions) -> Void
    ) {
#if os(iOS)
        completionHandler([.banner, .sound, .badge])
#else
        completionHandler([.sound])
#endif
    }
}

@main
struct MeshLogApp: App {
    @UIApplicationDelegateAdaptor(AppDelegate.self) private var appDelegate
    @StateObject private var authManager = AuthenticationManager()
    @StateObject private var apiClient = APIClient()
    @StateObject private var navigationState = AppNavigationState()
    @State private var activatedTabs: Set<Int> = [0]
    
    var body: some Scene {
        WindowGroup {
            appContainer()
            .environmentObject(authManager)
            .environmentObject(apiClient)
            .environmentObject(navigationState)
            .onAppear {
                // Auto-enter guest mode on app launch
                authManager.enterGuestMode()
                apiClient.setAuthToken(authManager.authToken)
            }
            .onChange(of: authManager.authToken) { _, token in
                apiClient.setAuthToken(token)
            }
            .onChange(of: navigationState.selectedTab) { _, newTab in
                activatedTabs.insert(newTab)
            }
        }
    }

    @ViewBuilder
    private func appContainer() -> some View {
#if os(tvOS)
        VStack(spacing: 0) {
            tvOSTabStrip()
                .padding(.horizontal, 18)
                .padding(.top, 14)
                .padding(.bottom, 10)

            activeTabContent()
                .frame(maxWidth: .infinity, maxHeight: .infinity)
        }
#else
        TabView(selection: $navigationState.selectedTab) {
            tabContent(for: 0) {
                LiveFeedView()
            }
                .tag(0)
                .tabItem {
                    Label("Live", systemImage: "list.bullet.rectangle.fill")
                }

            tabContent(for: 1) {
                MapView()
            }
                .tag(1)
                .tabItem {
                    Label("Map", systemImage: "map.fill")
                }

            tabContent(for: 2) {
                DevicesView()
            }
                .tag(2)
                .tabItem {
                    Label("Devices", systemImage: "antenna.radiowaves.left.and.right")
                }

            tabContent(for: 3) {
                StatsView()
            }
                .tag(3)
                .tabItem {
                    Label("Stats", systemImage: "chart.bar.fill")
                }

            tabContent(for: 4) {
                SettingsView()
            }
                .tag(4)
                .tabItem {
                    Label("Settings", systemImage: "gear")
                }
        }
        .tint(.white)
#endif
    }

    @ViewBuilder
    private func activeTabContent() -> some View {
        ZStack {
            tabContent(for: 0) { LiveFeedView() }
                .opacity(navigationState.selectedTab == 0 ? 1 : 0)
                .allowsHitTesting(navigationState.selectedTab == 0)

            tabContent(for: 1) { MapView() }
                .opacity(navigationState.selectedTab == 1 ? 1 : 0)
                .allowsHitTesting(navigationState.selectedTab == 1)

            tabContent(for: 2) { DevicesView() }
                .opacity(navigationState.selectedTab == 2 ? 1 : 0)
                .allowsHitTesting(navigationState.selectedTab == 2)

            tabContent(for: 3) { StatsView() }
                .opacity(navigationState.selectedTab == 3 ? 1 : 0)
                .allowsHitTesting(navigationState.selectedTab == 3)

            tabContent(for: 4) { SettingsView() }
                .opacity(navigationState.selectedTab == 4 ? 1 : 0)
                .allowsHitTesting(navigationState.selectedTab == 4)
        }
    }

    @ViewBuilder
    private func tvOSTabStrip() -> some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 10) {
                tvOSTabButton(index: 0, title: "Live", systemImage: "list.bullet.rectangle.fill")
                tvOSTabButton(index: 1, title: "Map", systemImage: "map.fill")
                tvOSTabButton(index: 2, title: "Devices", systemImage: "antenna.radiowaves.left.and.right")
                tvOSTabButton(index: 3, title: "Stats", systemImage: "chart.bar.fill")
                tvOSTabButton(index: 4, title: "Settings", systemImage: "gear")
            }
        }
    }

    @ViewBuilder
    private func tvOSTabButton(index: Int, title: String, systemImage: String) -> some View {
        let isSelected = navigationState.selectedTab == index
        let foreground = isSelected ? Color.white : Color(red: 0.86, green: 0.90, blue: 0.95)
        let background = isSelected ? Color.cyan.opacity(0.40) : Color.white.opacity(0.16)
        let border = isSelected ? Color.cyan.opacity(0.82) : Color.white.opacity(0.28)

        Button {
            navigationState.selectedTab = index
        } label: {
            HStack(spacing: 8) {
                Image(systemName: systemImage)
                    .font(.system(size: 16, weight: .semibold))
                Text(title)
                    .font(.system(size: 15, weight: .semibold))
            }
            .foregroundColor(foreground)
            .padding(.horizontal, 14)
            .padding(.vertical, 10)
            .background(
                RoundedRectangle(cornerRadius: 10, style: .continuous)
                    .fill(background)
            )
            .overlay(
                RoundedRectangle(cornerRadius: 10, style: .continuous)
                    .stroke(border, lineWidth: 1.0)
            )
        }
        .buttonStyle(.plain)
        .focusEffectDisabled()
    }

    @ViewBuilder
    private func tabContent<Content: View>(for index: Int, @ViewBuilder content: () -> Content) -> some View {
        if activatedTabs.contains(index) {
            content()
        } else {
            Color.clear
        }
    }
}

struct MeshTextInputStyle: ViewModifier {
    func body(content: Content) -> some View {
        content
            .padding(.horizontal, 12)
            .padding(.vertical, 10)
            .background(Color(red: 0.12, green: 0.17, blue: 0.27))
            .cornerRadius(8)
            .foregroundColor(.white)
    }
}

extension View {
    func meshTextInputStyle() -> some View {
        modifier(MeshTextInputStyle())
    }

    @ViewBuilder
    func meshNavigationBarInline() -> some View {
#if os(iOS)
        self.navigationBarTitleDisplayMode(.inline)
#else
        self
#endif
    }

        @ViewBuilder
        func meshListRowSeparator(_ visibility: Visibility) -> some View {
    #if os(iOS)
        self.listRowSeparator(visibility)
    #else
        self
    #endif
        }

        @ViewBuilder
        func meshListRowSeparatorTint(_ color: Color) -> some View {
    #if os(iOS)
        self.listRowSeparatorTint(color)
    #else
        self
    #endif
        }

        @ViewBuilder
        func meshRefreshable(_ action: @escaping () async -> Void) -> some View {
    #if os(iOS)
            self.refreshable {
                await action()
            }
    #else
            self
    #endif
        }

        @ViewBuilder
        func meshTextSelectionEnabled() -> some View {
    #if os(iOS) && !targetEnvironment(simulator)
            self.textSelection(.enabled)
    #else
            self
    #endif
        }

            @ViewBuilder
            func meshScrollContentBackgroundHidden() -> some View {
        #if os(iOS)
            self.scrollContentBackground(.hidden)
        #else
            self
        #endif
            }
}
