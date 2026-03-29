/// MeshLog iOS App - Main App Entry Point
import SwiftUI

@main
struct MeshLogApp: App {
    @StateObject private var authManager = AuthenticationManager()
    @StateObject private var apiClient = APIClient()
    
    var body: some Scene {
        WindowGroup {
            if authManager.isAuthenticated {
                TabView {
                    LiveFeedView()
                        .tabItem {
                            Label("Live", systemImage: "list.bullet.rectangle.fill")
                        }
                    
                    MapView()
                        .tabItem {
                            Label("Map", systemImage: "map.fill")
                        }
                    
                    DevicesView()
                        .tabItem {
                            Label("Devices", systemImage: "antenna.radiowaves.left.and.right")
                        }
                    
                    StatsView()
                        .tabItem {
                            Label("Stats", systemImage: "chart.bar.fill")
                        }
                    
                    SettingsView()
                        .tabItem {
                            Label("Settings", systemImage: "gear")
                        }
                }
                .environmentObject(authManager)
                .environmentObject(apiClient)
            } else {
                LoginView()
                    .environmentObject(authManager)
                    .environmentObject(apiClient)
            }
        }
    }
}
