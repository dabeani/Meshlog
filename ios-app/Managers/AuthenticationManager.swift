/// Authentication Manager - Handles login and session management
import Foundation
import Combine

@MainActor
class AuthenticationManager: ObservableObject {
    @Published var isAuthenticated = false
    @Published var currentUser: UserResponse?
    @Published var authToken: String?
    @Published var errorMessage: String?
    
    private let userDefaults = UserDefaults.standard
    private let tokenKey = "meshlog_auth_token"
    private let userKey = "meshlog_user"
    
    init() {
        // Check if we have a stored token
        loadStoredCredentials()
    }
    
    func login(baseURL: String, username: String, password: String) async {
        do {
            guard let url = URL(string: "\(baseURL)/api/v1/auth/") else {
                await MainActor.run {
                    self.errorMessage = "Invalid URL"
                }
                return
            }
            
            var request = URLRequest(url: url)
            request.httpMethod = "POST"
            request.setValue("application/json", forHTTPHeaderField: "Content-Type")
            
            let loginData = ["username": username, "password": password]
            request.httpBody = try JSONEncoder().encode(loginData)
            
            let (data, response) = try await URLSession.shared.data(for: request)
            
            guard let httpResponse = response as? HTTPURLResponse else {
                await MainActor.run {
                    self.errorMessage = "Invalid response"
                }
                return
            }
            
            if httpResponse.statusCode == 200 {
                let authResponse = try JSONDecoder().decode(AuthResponse.self, from: data)
                
                await MainActor.run {
                    self.authToken = authResponse.token
                    self.currentUser = authResponse.user
                    self.isAuthenticated = true
                    self.errorMessage = nil
                    
                    // Store credentials
                    self.userDefaults.set(authResponse.token, forKey: self.tokenKey)
                    if let encoded = try? JSONEncoder().encode(authResponse.user) {
                        self.userDefaults.set(encoded, forKey: self.userKey)
                    }
                }
            } else {
                let errorResponse = try JSONDecoder().decode(APIError.self, from: data)
                await MainActor.run {
                    self.errorMessage = errorResponse.error
                }
            }
        } catch {
            await MainActor.run {
                self.errorMessage = "Login failed: \(error.localizedDescription)"
            }
        }
    }
    
    func logout() {
        self.isAuthenticated = false
        self.currentUser = nil
        self.authToken = nil
        self.userDefaults.removeObject(forKey: self.tokenKey)
        self.userDefaults.removeObject(forKey: self.userKey)
    }
    
    private func loadStoredCredentials() {
        if let token = userDefaults.string(forKey: tokenKey) {
            if let userData = userDefaults.data(forKey: userKey),
               let user = try? JSONDecoder().decode(UserResponse.self, from: userData) {
                authToken = token
                currentUser = user
                isAuthenticated = true
            }
        }
    }
}
