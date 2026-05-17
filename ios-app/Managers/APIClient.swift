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
    private let session: URLSession

    private struct EmptyResponse: Decodable {}

    private struct APIEnvelopeError: Decodable {
        let error: String
    }

    private struct WebSocketMetadataSnapshot {
        let contacts: [Contact]
        let reporters: [Reporter]
        let channels: [Channel]
    }

    private struct WebSocketQueryEnvelope: Encodable {
        let type: String
        let request_id: String
        let action: String
        let params: [String: AnyEncodable]
    }

    private struct AnyEncodable: Encodable {
        private let encodeValue: (Encoder) throws -> Void

        init(_ value: Any) {
            self.encodeValue = { encoder in
                var container = encoder.singleValueContainer()

                switch value {
                case let string as String:
                    try container.encode(string)
                case let int as Int:
                    try container.encode(int)
                case let double as Double:
                    try container.encode(double)
                case let bool as Bool:
                    try container.encode(bool)
                case let stringArray as [String]:
                    try container.encode(stringArray)
                case let intArray as [Int]:
                    try container.encode(intArray)
                default:
                    throw EncodingError.invalidValue(
                        value,
                        EncodingError.Context(codingPath: encoder.codingPath, debugDescription: "Unsupported websocket query parameter")
                    )
                }
            }
        }

        func encode(to encoder: Encoder) throws {
            try encodeValue(encoder)
        }
    }

    init() {
        let config = URLSessionConfiguration.default
        config.waitsForConnectivity = true
        config.timeoutIntervalForRequest = 30
        config.timeoutIntervalForResource = 120
        session = URLSession(configuration: config)
        baseURL = userDefaults.string(forKey: baseURLKey) ?? "https://meshlog.1tld.net"
    }

    func updateBaseURL(_ url: String) {
        baseURL = url.trimmingCharacters(in: .whitespacesAndNewlines)
    }
    
    func setAuthToken(_ token: String?) {
        self.authToken = token
    }
    
    // MARK: - Live Feed
    
    func fetchLiveFeed(
        sinceMs: Int = 0,
        beforeMs: Int = 0,
        types: [String] = ["ADV", "MSG", "PUB", "RAW", "TEL", "SYS"],
        limit: Int = 50
    ) async throws -> LiveFeedResponse {
        var params: [String: Any] = [
            "types": types,
            "limit": limit
        ]

        if beforeMs > 0 {
            params["before_ms"] = beforeMs
        } else {
            params["since_ms"] = sinceMs
        }

        let data = try await makeWebSocketQueryData(action: "live_feed", params: params, types: types)
        return try JSONDecoder().decode(LiveFeedResponse.self, from: data)
    }

    func streamLiveFeed(
        sinceMs: Int = 0,
        types: [String] = ["ADV", "MSG", "PUB", "RAW", "TEL", "SYS"],
        limit: Int = 50
    ) -> AsyncThrowingStream<LiveFeedResponse, Error> {
        return AsyncThrowingStream { continuation in
            let task = Task {
                var webSocketTask: URLSessionWebSocketTask?

                do {
                    let url = try makeWebSocketURL(queryItems: [
                        URLQueryItem(name: "bootstrap", value: "0"),
                        URLQueryItem(name: "since_ms", value: "\(sinceMs)"),
                        URLQueryItem(name: "types", value: types.joined(separator: ",")),
                        URLQueryItem(name: "limit", value: "\(limit)")
                    ])

                    let liveTask = session.webSocketTask(with: url)
                    webSocketTask = liveTask
                    liveTask.resume()

                    while !Task.isCancelled {
                        let message = try await liveTask.receive()
                        if Task.isCancelled {
                            break
                        }

                        let jsonData = try data(from: message)

                        do {
                            let payload = try JSONDecoder().decode(LiveFeedResponse.self, from: jsonData)
                            continuation.yield(payload)
                        } catch {
                            continue
                        }
                    }

                    continuation.finish()
                } catch {
                    continuation.finish(throwing: error)
                }

                if let webSocketTask {
                    webSocketTask.cancel(with: .normalClosure, reason: nil)
                }
            }

            continuation.onTermination = { _ in
                task.cancel()
            }
        }
    }
    
    // MARK: - Contacts
    
    func fetchContacts(offset: Int = 0, count: Int = 500) async throws -> ContactsResponse {
        let fetchCount = max(count, offset + count)
        let snapshot = try await makeWebSocketMetadataSnapshot(count: fetchCount)
        let contacts = Array(snapshot.contacts.dropFirst(max(0, offset)).prefix(max(0, count)))
        return ContactsResponse(contacts: contacts, count: contacts.count)
    }
    
    func fetchContactAdvertisements(contactId: Int) async throws -> [Packet] {
        let data = try await makeWebSocketQueryData(action: "contact_advertisements", params: [
            "contact_id": contactId
        ])
        return try decodeFlexibleArray(from: data, preferredKeys: ["advertisements", "objects"])
    }
    
    // MARK: - Statistics
    
    func fetchGeneralStats(windowHours: Int = 24) async throws -> StatisticsResponse {
        let data = try await makeWebSocketQueryData(action: "stats", params: [
            "window_hours": windowHours
        ])

        return try JSONDecoder().decode(StatisticsResponse.self, from: data)
    }
    
    func fetchContactStats(contactId: Int) async throws -> StatisticsResponse {
        let data = try await makeWebSocketQueryData(action: "contact_stats", params: [
            "contact_id": contactId
        ])

        return try JSONDecoder().decode(StatisticsResponse.self, from: data)
    }
    
    // MARK: - Reporters
    
    func fetchReporters() async throws -> [Reporter] {
        let snapshot = try await makeWebSocketMetadataSnapshot(count: 500)
        return snapshot.reporters
    }
    
    // MARK: - Channels
    
    func fetchChannels() async throws -> [Channel] {
        let snapshot = try await makeWebSocketMetadataSnapshot(count: 500)
        return snapshot.channels
    }
    
    func updateChannel(_ channel: Channel) async throws {
        let encoder = JSONEncoder()
        let body = try encoder.encode(channel)

        let _: EmptyResponse = try await makeRequest(path: "/api/v1/channels", method: "PUT", body: body)
    }
    
    // MARK: - Configuration
    
    func fetchConfiguration() async throws -> AppConfiguration {
        let data = try await makeWebSocketQueryData(action: "config", params: [:])
        return try JSONDecoder().decode(AppConfiguration.self, from: data)
    }
    
    // MARK: - Advertisements
    
    func fetchAdvertisements(afterMs: Int = 0, beforeMs: Int = 0) async throws -> [Packet] {
        let response = try await fetchLiveFeed(
            sinceMs: afterMs,
            beforeMs: beforeMs,
            types: ["ADV"],
            limit: 500
        )
        return response.packets
    }
    
    // MARK: - Raw Packets
    
    func fetchRawPackets(afterMs: Int = 0) async throws -> [Packet] {
        let response = try await fetchLiveFeed(
            sinceMs: afterMs,
            types: ["RAW"],
            limit: 500
        )
        return response.packets
    }
    
    // MARK: - Private Helper Methods
    
    private func makeRequest<T: Decodable>(
        path: String,
        method: String = "GET",
        queryItems: [URLQueryItem]? = nil,
        body: Data? = nil
    ) async throws -> T {
        let data = try await makeRequestData(path: path, method: method, queryItems: queryItems, body: body)

        if data.isEmpty, let emptyResponse = EmptyResponse() as? T {
            return emptyResponse
        }

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
        
        let (data, response) = try await session.data(for: request)
        
          guard let httpResponse = response as? HTTPURLResponse,
              (200...299).contains(httpResponse.statusCode) else {
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

    private func makeWebSocketURL(queryItems: [URLQueryItem]) throws -> URL {
        guard var components = URLComponents(string: baseURL), components.host != nil else {
            throw URLError(.badURL)
        }

        components.scheme = (components.scheme?.lowercased() == "https") ? "wss" : "ws"
        components.path = "/ws/live"
        components.query = nil
        components.fragment = nil
        components.queryItems = queryItems

        guard let url = components.url else {
            throw URLError(.badURL)
        }

        return url
    }

    private func makeWebSocketQueryData(
        action: String,
        params: [String: Any],
        types: [String] = ["ADV"]
    ) async throws -> Data {
        let url = try makeWebSocketURL(queryItems: [
            URLQueryItem(name: "bootstrap", value: "0"),
            URLQueryItem(name: "since_ms", value: "0"),
            URLQueryItem(name: "types", value: types.joined(separator: ",")),
            URLQueryItem(name: "limit", value: "1"),
            URLQueryItem(name: "query_only", value: "1")
        ])

        let webSocketTask = session.webSocketTask(with: url)
        webSocketTask.resume()
        defer {
            webSocketTask.cancel(with: .normalClosure, reason: nil)
        }

        let requestId = UUID().uuidString.lowercased()
        let payload = WebSocketQueryEnvelope(
            type: "query",
            request_id: requestId,
            action: action,
            params: params.mapValues { AnyEncodable($0) }
        )
        let requestData = try JSONEncoder().encode(payload)
        guard let requestText = String(data: requestData, encoding: .utf8) else {
            throw NSError(domain: "WebSocketQueryError", code: -3, userInfo: [NSLocalizedDescriptionKey: "Failed to encode websocket request"])
        }

        try await webSocketTask.send(.string(requestText))

        while true {
            let message = try await webSocketTask.receive()
            let responseData = try data(from: message)
            let jsonObject = try JSONSerialization.jsonObject(with: responseData)

            guard let envelope = jsonObject as? [String: Any] else {
                continue
            }

            guard (envelope["type"] as? String) == "query_result",
                  (envelope["request_id"] as? String) == requestId else {
                continue
            }

            if let error = envelope["error"] as? String, !error.isEmpty {
                throw NSError(domain: "WebSocketQueryError", code: -1, userInfo: [NSLocalizedDescriptionKey: error])
            }

            guard let payloadObject = envelope["data"] else {
                return Data()
            }

            if payloadObject is NSNull {
                return Data()
            }

            guard JSONSerialization.isValidJSONObject(payloadObject) else {
                throw NSError(domain: "WebSocketQueryError", code: -2, userInfo: [NSLocalizedDescriptionKey: "Unexpected websocket response payload"])
            }

            return try JSONSerialization.data(withJSONObject: payloadObject)
        }
    }

    private func makeWebSocketMetadataSnapshot(count: Int) async throws -> WebSocketMetadataSnapshot {
        let fetchCount = max(1, min(500, count))
        let url = try makeWebSocketURL(queryItems: [
            URLQueryItem(name: "bootstrap", value: "1"),
            URLQueryItem(name: "since_ms", value: "0"),
            URLQueryItem(name: "types", value: "ADV"),
            URLQueryItem(name: "limit", value: "1"),
            URLQueryItem(name: "count", value: "\(fetchCount)"),
            URLQueryItem(name: "chunk_size", value: "120")
        ])

        let webSocketTask = session.webSocketTask(with: url)
        webSocketTask.resume()
        defer {
            webSocketTask.cancel(with: .normalClosure, reason: nil)
        }

        var contactObjects: [Any] = []
        var reporterObjects: [Any] = []
        var channelObjects: [Any] = []

        while true {
            let message = try await webSocketTask.receive()
            let responseData = try data(from: message)
            let jsonObject = try JSONSerialization.jsonObject(with: responseData)

            guard let envelope = jsonObject as? [String: Any],
                  let type = envelope["type"] as? String else {
                continue
            }

            if type == "bootstrap_done" {
                return WebSocketMetadataSnapshot(
                    contacts: try decodeBootstrapObjects(contactObjects, as: Contact.self),
                    reporters: try decodeBootstrapObjects(reporterObjects, as: Reporter.self),
                    channels: try decodeBootstrapObjects(channelObjects, as: Channel.self)
                )
            }

            guard type == "bootstrap_slice",
                  let section = envelope["section"] as? String,
                  let objects = envelope["objects"] as? [Any] else {
                continue
            }

            switch section {
            case "contacts":
                contactObjects.append(contentsOf: objects)
            case "reporters":
                reporterObjects.append(contentsOf: objects)
            case "channels":
                channelObjects.append(contentsOf: objects)
            default:
                continue
            }
        }
    }

    private func decodeBootstrapObjects<T: Decodable>(_ objects: [Any], as type: T.Type) throws -> [T] {
        guard !objects.isEmpty else {
            return []
        }

        let data = try JSONSerialization.data(withJSONObject: objects)
        return try JSONDecoder().decode([T].self, from: data)
    }

    private func data(from message: URLSessionWebSocketTask.Message) throws -> Data {
        switch message {
        case .string(let text):
            guard let data = text.data(using: .utf8) else {
                throw URLError(.cannotDecodeRawData)
            }
            return data
        case .data(let data):
            return data
        @unknown default:
            throw URLError(.cannotDecodeContentData)
        }
    }
}
