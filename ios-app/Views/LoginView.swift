/// Login View - Authentication screen
import SwiftUI

struct LoginView: View {
    @EnvironmentObject var authManager: AuthenticationManager
    @EnvironmentObject var apiClient: APIClient
    
    @State private var username = ""
    @State private var password = ""
    @State private var baseURL = ""
    @State private var isLoading = false

    private var trimmedUsername: String { username.trimmingCharacters(in: .whitespacesAndNewlines) }
    private var trimmedPassword: String { password.trimmingCharacters(in: .whitespacesAndNewlines) }
    private var trimmedBaseURL: String { baseURL.trimmingCharacters(in: .whitespacesAndNewlines) }

    private var isReadOnlyMode: Bool {
        trimmedUsername.isEmpty && trimmedPassword.isEmpty
    }
    
    var body: some View {
        NavigationStack {
            ZStack {
                // Background gradient
                LinearGradient(
                    gradient: Gradient(colors: [
                        Color(red: 0.15, green: 0.2, blue: 0.3),
                        Color(red: 0.1, green: 0.15, blue: 0.25)
                    ]),
                    startPoint: .topLeading,
                    endPoint: .bottomTrailing
                )
                .ignoresSafeArea()
                
                VStack(spacing: 24) {
                    // Logo/Title
                    VStack(spacing: 8) {
                        Image(systemName: "antenna.radiowaves.left.and.right")
                            .font(.system(size: 48))
                            .foregroundColor(.cyan)
                        
                        Text("MeshLogAustria")
                            .font(.system(size: 32, weight: .bold))
                            .foregroundColor(.white)
                        
                        Text("MeshCore Network Viewer")
                            .font(.system(size: 14))
                            .foregroundColor(.gray)
                    }
                    .padding(.bottom, 32)
                    
                    // Form
                    VStack(spacing: 16) {
                        // Server URL
                        TextField(
                            "Server URL",
                            text: $baseURL,
                            prompt: Text("Server URL").foregroundColor(Color.white.opacity(0.65))
                        )
                            .textInputAutocapitalization(.never)
                            .meshTextInputStyle()
                        
                        // Username
                        TextField(
                            "Username",
                            text: $username,
                            prompt: Text("Username").foregroundColor(Color.white.opacity(0.65))
                        )
                            .textInputAutocapitalization(.never)
                            .meshTextInputStyle()
                        
                        // Password
                        SecureField(
                            "Password",
                            text: $password,
                            prompt: Text("Password").foregroundColor(Color.white.opacity(0.65))
                        )
                            .meshTextInputStyle()
                    }
                    .padding(.horizontal, 24)

                    Text("Leave username and password empty to continue in read-only mode.")
                        .font(.system(size: 12))
                        .foregroundColor(.gray)
                        .padding(.horizontal, 24)
                    
                    // Error message
                    if let error = authManager.errorMessage {
                        Text(error)
                            .font(.system(size: 12))
                            .foregroundColor(.red)
                            .padding(.horizontal, 24)
                    }
                    
                    // Login button
                    Button(action: {
                        Task {
                            isLoading = true
                            apiClient.updateBaseURL(trimmedBaseURL)
                            await authManager.login(baseURL: trimmedBaseURL, username: trimmedUsername, password: trimmedPassword)
                            isLoading = false
                        }
                    }) {
                        if isLoading {
                            ProgressView()
                                .tint(.white)
                        } else {
                            Text(isReadOnlyMode ? "Continue Read-Only" : "Login")
                                .font(.system(size: 16, weight: .semibold))
                        }
                    }
                    .frame(maxWidth: .infinity)
                    .frame(height: 48)
                    .background(Color.cyan)
                    .foregroundColor(.black)
                    .cornerRadius(8)
                    .disabled(isLoading || trimmedBaseURL.isEmpty)
                    .padding(.horizontal, 24)
                    
                    Spacer()
                }
                .padding(.vertical, 24)
            }
            .onAppear {
                if baseURL.isEmpty {
                    baseURL = apiClient.baseURL
                }
                if username.isEmpty {
                    username = authManager.lastUsername
                }
            }
        }
    }
}

#Preview {
    LoginView()
        .environmentObject(AuthenticationManager())
        .environmentObject(APIClient())
}
