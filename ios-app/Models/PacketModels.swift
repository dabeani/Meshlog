/// Models matching MeshLog PHP entities
import Foundation

// MARK: - API Response Models

struct AuthResponse: Codable {
    let token: String
    let user: UserResponse
    let expiresIn: Int
    
    enum CodingKeys: String, CodingKey {
        case token
        case user
        case expiresIn = "expires_in"
    }
}

struct UserResponse: Codable {
    let id: Int
    let name: String
    let permissions: [String]
}

// MARK: - Packet Models

struct Packet: Identifiable, Codable {
    let id: Int
    let type: String  // ADV, MSG, PUB, RAW, TEL, SYS
    
    // Common fields
    let contactId: Int?
    let contactPublicKey: String?
    let contactName: String?
    let reporterId: Int?
    let reporterName: String?
    let messageHash: String?
    let snr: Double?
    let path: String?
    let receivedAt: String
    let sentAt: String
    
    // ADV-specific
    let latitude: Double?
    let longitude: Double?
    let nodeType: String?
    
    // MSG/PUB-specific
    let message: String?
    let channelId: Int?
    let channelName: String?
    
    // RAW-specific
    let header: Int?
    let payload: String?
    
    enum CodingKeys: String, CodingKey {
        case id, type
        case contactId = "contact_id"
        case contactPublicKey = "contact_public_key"
        case contactName = "contact_name"
        case reporterId = "reporter_id"
        case reporterName = "reporter_name"
        case messageHash = "message_hash"
        case snr
        case path
        case receivedAt = "received_at"
        case sentAt = "sent_at"
        case latitude = "lat"
        case longitude = "lon"
        case nodeType = "node_type"
        case message
        case channelId = "channel_id"
        case channelName = "channel_name"
        case header, payload
    }
}

struct LiveFeedResponse: Codable {
    let packets: [Packet]
    let timestampMs: Int
    let count: Int
    
    enum CodingKeys: String, CodingKey {
        case packets
        case timestampMs = "timestamp_ms"
        case count
    }
}

// MARK: - Contact Model

struct Contact: Identifiable, Codable {
    let id: Int
    let publicKey: String
    let name: String
    let latitude: Double?
    let longitude: Double?
    let nodeType: String?
    let lastHeardAt: String
    let hashSize: Int
    
    enum CodingKeys: String, CodingKey {
        case id
        case publicKey = "public_key"
        case name
        case latitude = "lat"
        case longitude = "lon"
        case nodeType = "type"
        case lastHeardAt = "last_heard_at"
        case hashSize = "hash_size"
    }
}

struct ContactsResponse: Codable {
    let contacts: [Contact]
    let count: Int
}

// MARK: - Statistics Models

struct StatisticsResponse: Codable {
    let stats: [String: Int]
    let window: String
    let timestamp: String
}

// MARK: - Reporter Model

struct Reporter: Identifiable, Codable {
    let id: Int
    let publicKey: String
    let authorized: Bool
    let lastHeardAt: String?
    
    enum CodingKeys: String, CodingKey {
        case id
        case publicKey = "public_key"
        case authorized
        case lastHeardAt = "last_heard_at"
    }
}

// MARK: - Channel Model

struct Channel: Identifiable, Codable {
    let id: Int
    let hash: String
    let name: String
    let enabled: Bool
    let psk: String?
    
    enum CodingKeys: String, CodingKey {
        case id, hash, name, enabled
        case psk
    }
}

// MARK: - App Configuration

struct AppConfiguration: Codable {
    let appVersion: String
    let apiVersion: String
    let map: MapConfig
    let features: Features
    let pollingIntervalSeconds: Int
    let maxLiveItems: Int
    
    enum CodingKeys: String, CodingKey {
        case appVersion = "app_version"
        case apiVersion = "api_version"
        case map, features
        case pollingIntervalSeconds = "polling_interval_seconds"
        case maxLiveItems = "max_live_items"
    }
}

struct MapConfig: Codable {
    let defaultLat: Double
    let defaultLon: Double
    let defaultZoom: Int
    
    enum CodingKeys: String, CodingKey {
        case defaultLat = "default_lat"
        case defaultLon = "default_lon"
        case defaultZoom = "default_zoom"
    }
}

struct Features: Codable {
    let liveFeed: Bool
    let map: Bool
    let devices: Bool
    let statistics: Bool
    let admin: Bool
    
    enum CodingKeys: String, CodingKey {
        case liveFeed = "live_feed"
        case map
        case devices
        case statistics
        case admin
    }
}

// MARK: - Error Model

struct APIError: Codable {
    let error: String
}
