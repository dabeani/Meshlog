/// API Client - Handles all network requests
import Foundation
import Combine

class APIClient: ObservableObject {
    @Published var baseURL: String = "http://localhost"
    @Published var isLoading = false
    @Published var errorMessage: String?
    
    private var authToken: String?

    private struct EmptyResponse: Decodable {}
    
    func setAuthToken(_ token: String) {
        self.authToken = token
    }
    
    // MARK: - Live Feed
    
    func fetchLiveFeed(sinceMs: Int = 0, types: [String] = ["ADV", "MSG", "PUB", "RAW"], limit: Int = 50) async throws -> LiveFeedResponse {
        let typeString = types.joined(separator: ",")
        let queryItems = [
            URLQueryItem(name: "since_ms", value: "\(sinceMs)"),
            URLQueryItem(name: "types", value: typeString),
            URLQueryItem(name: "limit", value: "\(limit)")
        ]
        
        return try await makeRequest(path: "/api/v1/live", queryItems: queryItems)
    }
    
    // MARK: - Contacts
    
    func fetchContacts(offset: Int = 0, count: Int = 100) async throws -> ContactsResponse {
        let queryItems = [
            URLQueryItem(name: "offset", value: "\(offset)"),
            URLQueryItem(name: "count", value: "\(count)")
        ]
        
        return try await makeRequest(path: "/api/v1/contacts", queryItems: queryItems)
    }
    
    func fetchContactAdvertisements(contactId: Int) async throws -> [Packet] {
        let queryItems = [
            URLQueryItem(name: "contact_id", value: "\(contactId)")
        ]
        
        let response: [String: [Packet]] = try await makeRequest(path: "/api/v1/contact_advertisements", queryItems: queryItems)
        return response["advertisements"] ?? []
    }
    
    // MARK: - Statistics
    
    func fetchGeneralStats(windowHours: Int = 24) async throws -> StatisticsResponse {
        let queryItems = [
            URLQueryItem(name: "window_hours", value: "\(windowHours)")
        ]
        
        return try await makeRequest(path: "/api/v1/stats", queryItems: queryItems)
    }
    
    func fetchContactStats(contactId: Int) async throws -> StatisticsResponse {
        let queryItems = [
            URLQueryItem(name: "contact_id", value: "\(contactId)")
        ]
        
        return try await makeRequest(path: "/api/v1/contact_stats", queryItems: queryItems)
    }
    
    // MARK: - Reporters
    
    func fetchReporters() async throws -> [Reporter] {
        let response: [String: [Reporter]] = try await makeRequest(path: "/api/v1/reporters")
        return response["reporters"] ?? []
    }
    
    // MARK: - Channels
    
    func fetchChannels() async throws -> [Channel] {
        let response: [String: [Channel]] = try await makeRequest(path: "/api/v1/channels")
        return response["channels"] ?? []
    }
    
    func updateChannel(_ channel: Channel) async throws {
        let encoder = JSONEncoder()
        let body = try encoder.encode(channel)

        let _: EmptyResponse = try await makeRequest(path: "/api/v1/channels", method: "PUT", body: body)
    }
    
    // MARK: - Configuration
    
    func fetchConfiguration() async throws -> AppConfiguration {
        return try await makeRequest(path: "/api/v1/config")
    }
    
    // MARK: - Advertisements
    
    func fetchAdvertisements(afterMs: Int = 0, beforeMs: Int = 0) async throws -> [Packet] {
        var queryItems: [URLQueryItem] = []
        if afterMs > 0 {
            queryItems.append(URLQueryItem(name: "after_ms", value: "\(afterMs)"))
        }
        if beforeMs > 0 {
            queryItems.append(URLQueryItem(name: "before_ms", value: "\(beforeMs)"))
        }
        
        let response: [String: [Packet]] = try await makeRequest(path: "/api/v1/advertisements", queryItems: queryItems)
        return response["advertisements"] ?? []
    }
    
    // MARK: - Raw Packets
    
    func fetchRawPackets(afterMs: Int = 0) async throws -> [Packet] {
        let queryItems = [
            URLQueryItem(name: "after_ms", value: "\(afterMs)")
        ]
        
        let response: [String: [Packet]] = try await makeRequest(path: "/api/v1/raw_packets", queryItems: queryItems)
        return response["raw_packets"] ?? []
    }
    
    // MARK: - Private Helper Methods
    
    private func makeRequest<T: Decodable>(
        path: String,
        method: String = "GET",
        queryItems: [URLQueryItem]? = nil,
        body: Data? = nil
    ) async throws -> T {
        var urlComponents = URLComponents(string: baseURL + path)
        urlComponents?.queryItems = queryItems
        
        guard let url = urlComponents?.url else {
            throw URLError(.badURL)
        }
        
        var request = URLRequest(url: url)
        request.httpMethod = method
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        
        if let token = authToken {
            request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        }
        
        if let body = body {
            request.httpBody = body
        }
        
        let (data, response) = try await URLSession.shared.data(for: request)
        
        guard let httpResponse = response as? HTTPURLResponse,
              httpResponse.statusCode == 200 else {
            let errorResponse = try? JSONDecoder().decode(APIError.self, from: data)
            throw NSError(domain: "APIError", code: -1, userInfo: [NSLocalizedDescriptionKey: errorResponse?.error ?? "Unknown error"])
        }
        
        return try JSONDecoder().decode(T.self, from: data)
    }
}
