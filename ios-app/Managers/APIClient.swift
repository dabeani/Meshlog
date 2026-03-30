/// API Client - Handles all network requests
import Foundation
import Combine

class APIClient: ObservableObject {
    @Published var baseURL: String {
        didSet {
            userDefaults.set(baseURL, forKey: baseURLKey)
        }
    }
    @Published var isLoading = false
    @Published var errorMessage: String?
    
    private var authToken: String?
    private let userDefaults = UserDefaults.standard
    private let baseURLKey = "meshlog_base_url"

    private struct EmptyResponse: Decodable {}

    private struct APIEnvelopeError: Decodable {
        let error: String
    }

    init() {
        baseURL = userDefaults.string(forKey: baseURLKey) ?? "https://meshlog.1tld.net"
    }

    func updateBaseURL(_ url: String) {
        baseURL = url.trimmingCharacters(in: .whitespacesAndNewlines)
    }
    
    func setAuthToken(_ token: String?) {
        self.authToken = token
    }
    
    // MARK: - Live Feed
    
    func fetchLiveFeed(sinceMs: Int = 0, types: [String] = ["ADV", "MSG", "PUB", "RAW", "TEL", "SYS"], limit: Int = 50) async throws -> LiveFeedResponse {
        let typeString = types.joined(separator: ",")
        let queryItems = [
            URLQueryItem(name: "since_ms", value: "\(sinceMs)"),
            URLQueryItem(name: "types", value: typeString),
            URLQueryItem(name: "limit", value: "\(limit)")
        ]
        
        return try await makeRequest(path: "/api/v1/live", queryItems: queryItems)
    }

    func streamLiveFeed(
        sinceMs: Int = 0,
        types: [String] = ["ADV", "MSG", "PUB", "RAW", "TEL", "SYS"],
        limit: Int = 50
    ) -> AsyncThrowingStream<LiveFeedResponse, Error> {
        let typeString = types.joined(separator: ",")

        return AsyncThrowingStream { continuation in
            let task = Task {
                do {
                    guard var components = URLComponents(string: "\(baseURL)/api/v1/live/stream.php") else {
                        throw URLError(.badURL)
                    }

                    components.queryItems = [
                        URLQueryItem(name: "since_ms", value: "\(sinceMs)"),
                        URLQueryItem(name: "types", value: typeString),
                        URLQueryItem(name: "limit", value: "\(limit)")
                    ]

                    guard let url = components.url else {
                        throw URLError(.badURL)
                    }

                    var request = URLRequest(url: url)
                    request.timeoutInterval = 120
                    request.setValue("text/event-stream", forHTTPHeaderField: "Accept")
                    request.setValue("no-cache", forHTTPHeaderField: "Cache-Control")
                    if let token = authToken {
                        request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
                    }

                    let (bytes, response) = try await URLSession.shared.bytes(for: request)
                    guard let httpResponse = response as? HTTPURLResponse,
                          (200...299).contains(httpResponse.statusCode) else {
                        throw URLError(.badServerResponse)
                    }

                    for try await line in bytes.lines {
                        if Task.isCancelled {
                            break
                        }

                        // Parse SSE payload lines: data: {json}
                        guard line.hasPrefix("data:"),
                              let dataLine = line.split(separator: ":", maxSplits: 1).last else {
                            continue
                        }

                        let jsonString = dataLine.trimmingCharacters(in: .whitespacesAndNewlines)
                        guard !jsonString.isEmpty,
                              let jsonData = jsonString.data(using: .utf8) else {
                            continue
                        }

                        do {
                            let payload = try JSONDecoder().decode(LiveFeedResponse.self, from: jsonData)
                            continuation.yield(payload)
                        } catch {
                            // Ignore malformed lines while keeping the stream alive.
                            continue
                        }
                    }

                    continuation.finish()
                } catch {
                    continuation.finish(throwing: error)
                }
            }

            continuation.onTermination = { _ in
                task.cancel()
            }
        }
    }
    
    // MARK: - Contacts
    
    func fetchContacts(offset: Int = 0, count: Int = 500) async throws -> ContactsResponse {
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

        let data = try await makeRequestData(path: "/api/v1/contact_advertisements", queryItems: queryItems)
        return try decodeFlexibleArray(from: data, preferredKeys: ["advertisements", "objects"])
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
        let data = try await makeRequestData(path: "/api/v1/reporters")
        return try decodeFlexibleArray(from: data, preferredKeys: ["reporters", "objects"])
    }
    
    // MARK: - Channels
    
    func fetchChannels() async throws -> [Channel] {
        let data = try await makeRequestData(path: "/api/v1/channels")
        return try decodeFlexibleArray(from: data, preferredKeys: ["channels", "objects"])
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
        
        let data = try await makeRequestData(path: "/api/v1/advertisements", queryItems: queryItems)
        return try decodeFlexibleArray(from: data, preferredKeys: ["advertisements", "objects"])
    }
    
    // MARK: - Raw Packets
    
    func fetchRawPackets(afterMs: Int = 0) async throws -> [Packet] {
        let queryItems = [
            URLQueryItem(name: "after_ms", value: "\(afterMs)")
        ]
        
        let data = try await makeRequestData(path: "/api/v1/raw_packets", queryItems: queryItems)
        return try decodeFlexibleArray(from: data, preferredKeys: ["raw_packets", "objects"])
    }
    
    // MARK: - Private Helper Methods
    
    private func makeRequest<T: Decodable>(
        path: String,
        method: String = "GET",
        queryItems: [URLQueryItem]? = nil,
        body: Data? = nil
    ) async throws -> T {
        let data = try await makeRequestData(path: path, method: method, queryItems: queryItems, body: body)
        return try JSONDecoder().decode(T.self, from: data)
    }

    private func makeRequestData(
        path: String,
        method: String = "GET",
        queryItems: [URLQueryItem]? = nil,
        body: Data? = nil
    ) async throws -> Data {
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
            let errorResponse = try? JSONDecoder().decode(APIEnvelopeError.self, from: data)
            throw NSError(domain: "APIError", code: -1, userInfo: [NSLocalizedDescriptionKey: errorResponse?.error ?? "Unknown error"])
        }

        return data
    }

    private func decodeFlexibleArray<T: Decodable>(from data: Data, preferredKeys: [String]) throws -> [T] {
        if let direct = try? JSONDecoder().decode([T].self, from: data) {
            return direct
        }

        let jsonObject = try JSONSerialization.jsonObject(with: data)

        if let dict = jsonObject as? [String: Any] {
            for key in preferredKeys {
                if let anyArray = dict[key] as? [Any] {
                    let arrayData = try JSONSerialization.data(withJSONObject: anyArray)
                    return try JSONDecoder().decode([T].self, from: arrayData)
                }
            }
        }

        return []
    }
}
