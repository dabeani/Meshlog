/// Login View - Authentication screen
import SwiftUI

struct LoginView: View {
    @EnvironmentObject var authManager: AuthenticationManager
    @EnvironmentObject var apiClient: APIClient
    
    @State private var username = ""
    @State private var password = ""
    @State private var baseURL = "http://localhost"
    @State private var isLoading = false
    
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
                        
                        Text("MeshLog")
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
                            prompt: Text("Server URL").foregroundColor(.gray)
                        )
                            .textFieldStyle(.roundedBorder)
                            .foregroundColor(.black)
                            .textInputAutocapitalization(.never)
                        
                        // Username
                        TextField(
                            "Username",
                            text: $username,
                            prompt: Text("Username").foregroundColor(.gray)
                        )
                            .textFieldStyle(.roundedBorder)
                            .foregroundColor(.black)
                            .textInputAutocapitalization(.never)
                        
                        // Password
                        SecureField(
                            "Password",
                            text: $password,
                            prompt: Text("Password").foregroundColor(.gray)
                        )
                            .textFieldStyle(.roundedBorder)
                            .foregroundColor(.black)
                    }
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
                            apiClient.baseURL = baseURL
                            await authManager.login(baseURL: baseURL, username: username, password: password)
                            isLoading = false
                        }
                    }) {
                        if isLoading {
                            ProgressView()
                                .tint(.white)
                        } else {
                            Text("Login")
                                .font(.system(size: 16, weight: .semibold))
                        }
                    }
                    .frame(maxWidth: .infinity)
                    .frame(height: 48)
                    .background(Color.cyan)
                    .foregroundColor(.black)
                    .cornerRadius(8)
                    .disabled(isLoading || username.isEmpty || password.isEmpty || baseURL.isEmpty)
                    .padding(.horizontal, 24)
                    
                    Spacer()
                }
                .padding(.vertical, 24)
            }
        }
    }
}

#Preview {
    LoginView()
        .environmentObject(AuthenticationManager())
        .environmentObject(APIClient())
}
