/// Authentication Manager - Handles login and session management
import Foundation
import Combine

@MainActor
class AuthenticationManager: ObservableObject {
    @Published var isAuthenticated = false
    @Published var isGuestMode = false
    @Published var currentUser: UserResponse?
    @Published var authToken: String?
    @Published var errorMessage: String?
    
    private let userDefaults = UserDefaults.standard
    private let tokenKey = "meshlog_auth_token"
    private let userKey = "meshlog_user"
    private let usernameKey = "meshlog_last_username"

    var lastUsername: String {
        userDefaults.string(forKey: usernameKey) ?? ""
    }
    
    init() {
        // Check if we have a stored token
        loadStoredCredentials()
    }

    func enterGuestMode() {
        isGuestMode = true
        isAuthenticated = false
        currentUser = nil
        authToken = nil
        errorMessage = nil
    }
    
    func login(baseURL: String, username: String, password: String) async {
        let normalizedBaseURL = baseURL.trimmingCharacters(in: .whitespacesAndNewlines)
        let normalizedUsername = username.trimmingCharacters(in: .whitespacesAndNewlines)
        let normalizedPassword = password.trimmingCharacters(in: .whitespacesAndNewlines)

        // Empty credentials means read-only mode.
        if normalizedUsername.isEmpty && normalizedPassword.isEmpty {
            enterGuestMode()
            return
        }

        if normalizedUsername.isEmpty || normalizedPassword.isEmpty {
            errorMessage = "Enter both username and password, or leave both empty for read-only mode."
            return
        }

        do {
            guard let url = URL(string: "\(normalizedBaseURL)/api/v1/auth/") else {
                await MainActor.run {
                    self.errorMessage = "Invalid URL"
                }
                return
            }
            
            var request = URLRequest(url: url)
            request.httpMethod = "POST"
            request.setValue("application/json", forHTTPHeaderField: "Content-Type")
            
            let loginData = ["username": normalizedUsername, "password": normalizedPassword]
            request.httpBody = try JSONEncoder().encode(loginData)
            
            let (data, response) = try await URLSession.shared.data(for: request)
            
            guard let httpResponse = response as? HTTPURLResponse else {
                await MainActor.run {
                    self.errorMessage = "Invalid response"
                }
                return
            }
            
            if (200...299).contains(httpResponse.statusCode) {
                let authResponse = try JSONDecoder().decode(AuthResponse.self, from: data)
                
                await MainActor.run {
                    self.isGuestMode = false
                    self.authToken = authResponse.token
                    self.currentUser = authResponse.user
                    self.isAuthenticated = true
                    self.errorMessage = nil
                    
                    // Store credentials
                    self.userDefaults.set(authResponse.token, forKey: self.tokenKey)
                    self.userDefaults.set(normalizedUsername, forKey: self.usernameKey)
                    if let encoded = try? JSONEncoder().encode(authResponse.user) {
                        self.userDefaults.set(encoded, forKey: self.userKey)
                    }
                }
            } else {
                let errorResponse = try? JSONDecoder().decode(APIError.self, from: data)
                await MainActor.run {
                    self.errorMessage = errorResponse?.error ?? "Login failed (HTTP \(httpResponse.statusCode))."
                }
            }
        } catch {
            if let dataError = error as? DecodingError {
                errorMessage = "Login response decode failed: \(String(describing: dataError))"
                return
            }
            await MainActor.run {
                self.errorMessage = "Login failed: \(error.localizedDescription)"
            }
        }
    }
    
    func logout() {
        self.isAuthenticated = false
        self.isGuestMode = false
        self.currentUser = nil
        self.authToken = nil
        self.errorMessage = nil
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
