/// Models matching MeshLog PHP entities
import Foundation

// MARK: - API Response Models

enum MeshNodeType: String {
    case chat
    case repeater
    case roomServer
    case sensor
    case unknown

    init(rawValueOrCode value: String?) {
        let normalized = value?
            .trimmingCharacters(in: .whitespacesAndNewlines)
            .lowercased() ?? ""

        switch normalized {
        case "1", "chat", "client":
            self = .chat
        case "2", "repeater":
            self = .repeater
        case "3", "room", "room_server", "room server":
            self = .roomServer
        case "4", "sensor":
            self = .sensor
        default:
            self = .unknown
        }
    }

    var label: String {
        switch self {
        case .chat:
            return "Chat"
        case .repeater:
            return "Repeater"
        case .roomServer:
            return "Room"
        case .sensor:
            return "Sensor"
        case .unknown:
            return "Unknown"
        }
    }

    var mapSymbolName: String {
        switch self {
        case .chat:
            return "message.fill"
        case .repeater:
            return "antenna.radiowaves.left.and.right"
        case .roomServer:
            return "person.3.fill"
        case .sensor:
            return "sensor.tag.radiowaves.forward.fill"
        case .unknown:
            return "questionmark"
        }
    }
}

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

    enum CodingKeys: String, CodingKey {
        case id, name, permissions
    }

    init(id: Int, name: String, permissions: [String]) {
        self.id = id
        self.name = name
        self.permissions = permissions
    }

    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        id = try container.decode(Int.self, forKey: .id)
        name = try container.decode(String.self, forKey: .name)

        if let values = try? container.decode([String].self, forKey: .permissions) {
            permissions = values
        } else if let value = try? container.decode(String.self, forKey: .permissions) {
            permissions = value.isEmpty ? [] : [value]
        } else {
            permissions = []
        }
    }

    func encode(to encoder: Encoder) throws {
        var container = encoder.container(keyedBy: CodingKeys.self)
        try container.encode(id, forKey: .id)
        try container.encode(name, forKey: .name)
        try container.encode(permissions, forKey: .permissions)
    }
}

// MARK: - Packet Models

struct Packet: Identifiable, Decodable {
    let id: Int
    let type: String  // ADV, MSG, PUB, RAW, TEL, SYS
    
    // Common fields
    let contactId: Int?
    let contactPublicKey: String?
    let contactName: String?
    let reporterId: Int?
    let reporterName: String?
    let messageHash: String?
    let hashSize: Int?
    let scope: Int?
    let routeType: Int?
    let senderAt: String?
    let snr: Double?
    let path: String?
    let receivedAt: String
    let sentAt: String
    let reports: [PacketReport]
    
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

    // TEL-specific
    let telemetryData: String?

    // SYS-specific
    let version: String?
    let heapTotal: Int?
    let heapFree: Int?
    let rssi: Int?
    let uptime: Int?

    // Universal fallback timestamp
    let createdAt: String?
    
    enum CodingKeys: String, CodingKey {
        case id, type
        case contactId = "contact_id"
        case contactPublicKey = "contact_public_key"
        case contactName = "contact_name"
        case legacyPublicKey = "public_key"
        case legacyName = "name"
        case reporterId = "reporter_id"
        case reporterName = "reporter_name"
        case messageHash = "message_hash"
        case hash = "hash"
        case hashSize = "hash_size"
        case scope
        case routeType = "route_type"
        case senderAt = "sender_at"
        case snr
        case path
        case receivedAt = "received_at"
        case sentAt = "sent_at"
        case reports
        case latitude = "lat"
        case longitude = "lon"
        case nodeType = "node_type"
        case message
        case channelId = "channel_id"
        case channelName = "channel_name"
        case header, payload
        case telemetryData = "data"
        case version
        case heapTotal = "heap_total"
        case heapFree = "heap_free"
        case rssi
        case uptime
        case createdAt = "created_at"
    }

    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)

        id = try container.decode(Int.self, forKey: .id)
        if let stringType = try? container.decode(String.self, forKey: .type) {
            type = stringType
        } else if let intType = try? container.decode(Int.self, forKey: .type) {
            type = String(intType)
        } else {
            type = "RAW"
        }

        contactId = try? container.decodeIfPresent(Int.self, forKey: .contactId)
        let decodedContactPublicKey = try? container.decodeIfPresent(String.self, forKey: .contactPublicKey)
        let decodedLegacyPublicKey = try? container.decodeIfPresent(String.self, forKey: .legacyPublicKey)
        contactPublicKey = decodedContactPublicKey ?? decodedLegacyPublicKey

        let decodedContactName = try? container.decodeIfPresent(String.self, forKey: .contactName)
        let decodedLegacyName = try? container.decodeIfPresent(String.self, forKey: .legacyName)
        contactName = decodedContactName ?? decodedLegacyName
        reporterId = try? container.decodeIfPresent(Int.self, forKey: .reporterId)
        reporterName = try? container.decodeIfPresent(String.self, forKey: .reporterName)
        let hashFromMessageField = try? container.decodeIfPresent(String.self, forKey: .messageHash)
        let hashFromHashField = try? container.decodeIfPresent(String.self, forKey: .hash)
        messageHash = hashFromMessageField ?? hashFromHashField
        hashSize = try? container.decodeIfPresent(Int.self, forKey: .hashSize)
        scope = try? container.decodeIfPresent(Int.self, forKey: .scope)
        routeType = try? container.decodeIfPresent(Int.self, forKey: .routeType)
        senderAt = try? container.decodeIfPresent(String.self, forKey: .senderAt)
        snr = try? container.decodeIfPresent(Double.self, forKey: .snr)
        path = try? container.decodeIfPresent(String.self, forKey: .path)
        receivedAt = (try? container.decode(String.self, forKey: .receivedAt)) ?? ""
        sentAt = (try? container.decode(String.self, forKey: .sentAt)) ?? ""
        reports = (try? container.decodeIfPresent([PacketReport].self, forKey: .reports)) ?? []

        latitude = try? container.decodeIfPresent(Double.self, forKey: .latitude)
        longitude = try? container.decodeIfPresent(Double.self, forKey: .longitude)
        if let sv = try? container.decodeIfPresent(String.self, forKey: .nodeType) {
            nodeType = sv
        } else if let iv = try? container.decodeIfPresent(Int.self, forKey: .nodeType) {
            nodeType = String(iv)
        } else {
            nodeType = nil
        }
        message = try? container.decodeIfPresent(String.self, forKey: .message)
        channelId = try? container.decodeIfPresent(Int.self, forKey: .channelId)
        channelName = try? container.decodeIfPresent(String.self, forKey: .channelName)
        header = try? container.decodeIfPresent(Int.self, forKey: .header)
        payload = try? container.decodeIfPresent(String.self, forKey: .payload)
        telemetryData = try? container.decodeIfPresent(String.self, forKey: .telemetryData)
        version = try? container.decodeIfPresent(String.self, forKey: .version)
        heapTotal = try? container.decodeIfPresent(Int.self, forKey: .heapTotal)
        heapFree = try? container.decodeIfPresent(Int.self, forKey: .heapFree)
        if let iv = try? container.decodeIfPresent(Int.self, forKey: .rssi) {
            rssi = iv
        } else if let dv = try? container.decodeIfPresent(Double.self, forKey: .rssi) {
            rssi = Int(dv)
        } else {
            rssi = nil
        }
        uptime = try? container.decodeIfPresent(Int.self, forKey: .uptime)
        createdAt = try? container.decodeIfPresent(String.self, forKey: .createdAt)
    }
}

// MARK: - Packet computed helpers

extension Packet {
    var meshNodeType: MeshNodeType {
        MeshNodeType(rawValueOrCode: nodeType)
    }

    /// For ADV: human-readable node type label ("Chat", "Repeater", "Room", "Sensor"), nil if unknown.
    var nodeTypeLabel: String? {
        guard type == "ADV" else { return nil }
        let nt = meshNodeType
        guard nt != .unknown else { return nil }
        return nt.label
    }

    /// Summary of TEL sensor data for display, e.g. "ch1: 3.72 · ch2: 21.5°C"
    var telemetrySummary: String? {
        guard type == "TEL", let raw = telemetryData, !raw.isEmpty else { return nil }
        guard let jsonData = raw.data(using: .utf8),
              let obj = try? JSONSerialization.jsonObject(with: jsonData)
        else { return raw.count > 80 ? String(raw.prefix(80)) + "…" : raw }

        if let dict = obj as? [String: Any] {
            let parts = dict.sorted { $0.key < $1.key }.prefix(4).map { "\($0.key): \($0.value)" }
            return parts.joined(separator: " · ")
        }
        if let arr = obj as? [[String: Any]] {
            let parts: [String] = arr.prefix(3).compactMap { item in
                let ch = (item["channel"] as? String)
                    ?? (item["ch"] as? String)
                    ?? (item["name"] as? String)
                let val = item["value"] ?? item["v"] ?? item["val"]
                if let c = ch { return "\(c): \(val ?? "?")" }
                return item.first.map { "\($0.key): \($0.value)" }
            }
            return parts.isEmpty ? nil : parts.joined(separator: " · ")
        }
        return raw.count > 80 ? String(raw.prefix(80)) + "…" : raw
    }

    /// Summary line for SYS packets, e.g. "v1.2.3 · RSSI -85 · heap 12k/40k · up 1d2h"
    var sysSummary: String? {
        guard type == "SYS" else { return nil }
        var parts: [String] = []
        if let v = version, !v.isEmpty { parts.append("v\(v)") }
        if let r = rssi { parts.append("RSSI \(r)") }
        if let hf = heapFree, let ht = heapTotal {
            parts.append("heap \(hf / 1024)k/\(ht / 1024)k")
        }
        if let u = uptime {
            let d = u / 86400; let h = (u % 86400) / 3600; let m = (u % 3600) / 60
            if d > 0 { parts.append("up \(d)d\(h)h") }
            else if h > 0 { parts.append("up \(h)h\(m)m") }
            else { parts.append("up \(m)m") }
        }
        return parts.isEmpty ? nil : parts.joined(separator: " · ")
    }

    /// Decoded sub-type label for RAW packets derived from the header byte ((header >> 2) & 0x0F).
    var rawSubtypeLabel: String? {
        guard type == "RAW", let h = header else { return nil }
        let payloadType = (h >> 2) & 0x0F
        let labels = ["REQ", "RESP", "MSG", "ACK", "ADV", "PUB", "GRP",
                      "ANON", "PATH", "TRACE", "MULTI", "CTRL", "CUST", "PKT"]
        if payloadType < labels.count { return labels[payloadType] }
        return "PT\(payloadType)"
    }
}

struct PacketReport: Decodable {
    let id: Int?
    let reporterId: Int?
    let snr: Double?
    let scope: Int?
    let routeType: Int?
    let path: String?
    let senderAt: String?
    let receivedAt: String?

    enum CodingKeys: String, CodingKey {
        case id
        case reporterId = "reporter_id"
        case snr
        case scope
        case routeType = "route_type"
        case path
        case senderAt = "sender_at"
        case receivedAt = "received_at"
    }
}

struct LiveFeedResponse: Decodable {
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

struct Contact: Identifiable, Decodable {
    let id: Int
    let publicKey: String
    let name: String
    let latitude: Double?
    let longitude: Double?
    let nodeType: String?
    let reporterIds: [Int]
    let telemetry: [String: String]
    let lastHeardAt: String
    let createdAt: String
    let hashSize: Int
    let advertisementHash: String?
    let advertisementSentAt: String?
    let advertisementCreatedAt: String?
    
    enum CodingKeys: String, CodingKey {
        case id
        case publicKey = "public_key"
        case name
        case latitude = "lat"
        case longitude = "lon"
        case nodeType = "type"
        case reporterIds = "reporter_ids"
        case telemetry
        case lastHeardAt = "last_heard_at"
        case createdAt = "created_at"
        case hashSize = "hash_size"
        case advertisement
    }

    private enum AdvertisementCodingKeys: String, CodingKey {
        case hash
        case latitude = "lat"
        case longitude = "lon"
        case nodeType = "type"
        case sentAt = "sent_at"
        case createdAt = "created_at"
    }

    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)

        id = try container.decode(Int.self, forKey: .id)
        publicKey = (try? container.decode(String.self, forKey: .publicKey)) ?? ""
        name = (try? container.decode(String.self, forKey: .name)) ?? "Unknown"
        lastHeardAt = (try? container.decode(String.self, forKey: .lastHeardAt)) ?? ""
        createdAt = (try? container.decode(String.self, forKey: .createdAt)) ?? ""
        hashSize = (try? container.decode(Int.self, forKey: .hashSize)) ?? 1

        let topLevelLat = try? container.decodeIfPresent(Double.self, forKey: .latitude)
        let topLevelLon = try? container.decodeIfPresent(Double.self, forKey: .longitude)
        let topLevelType = try? container.decodeIfPresent(String.self, forKey: .nodeType)
        reporterIds = (try? container.decodeIfPresent([Int].self, forKey: .reporterIds)) ?? []

        if let values = try? container.decodeIfPresent([String: String].self, forKey: .telemetry) {
            telemetry = values
        } else if let values = try? container.decodeIfPresent([String: Double].self, forKey: .telemetry) {
            telemetry = values.mapValues { String(format: "%.2f", $0) }
        } else if let values = try? container.decodeIfPresent([String: Int].self, forKey: .telemetry) {
            telemetry = values.mapValues(String.init)
        } else if let values = try? container.decodeIfPresent([String: Bool].self, forKey: .telemetry) {
            telemetry = values.mapValues { $0 ? "true" : "false" }
        } else {
            telemetry = [:]
        }

        var advLat: Double?
        var advLon: Double?
        var advType: String?
        var advHash: String?
        var advSentAt: String?
        var advCreatedAt: String?

        if container.contains(.advertisement),
           let adContainer = try? container.nestedContainer(keyedBy: AdvertisementCodingKeys.self, forKey: .advertisement) {
            advHash = try? adContainer.decodeIfPresent(String.self, forKey: .hash)
            advLat = try? adContainer.decodeIfPresent(Double.self, forKey: .latitude)
            advLon = try? adContainer.decodeIfPresent(Double.self, forKey: .longitude)
            advSentAt = try? adContainer.decodeIfPresent(String.self, forKey: .sentAt)
            advCreatedAt = try? adContainer.decodeIfPresent(String.self, forKey: .createdAt)

            if let t = try? adContainer.decodeIfPresent(String.self, forKey: .nodeType) {
                advType = t
            } else if let t = try? adContainer.decodeIfPresent(Int.self, forKey: .nodeType) {
                advType = String(t)
            }
        }

        latitude = topLevelLat ?? advLat
        longitude = topLevelLon ?? advLon
        nodeType = topLevelType ?? advType
        advertisementHash = advHash
        advertisementSentAt = advSentAt
        advertisementCreatedAt = advCreatedAt
    }
}

extension Contact {
    var meshNodeType: MeshNodeType {
        MeshNodeType(rawValueOrCode: nodeType)
    }

    var typeLabel: String {
        meshNodeType.label
    }

    var shortHash: String {
        let visibleChars = max(2, min(publicKey.count, max(1, hashSize) * 2))
        return String(publicKey.prefix(visibleChars)).uppercased()
    }
}

struct ContactsResponse: Decodable {
    let contacts: [Contact]
    let count: Int

    enum CodingKeys: String, CodingKey {
        case contacts
        case objects
        case count
    }

    init(contacts: [Contact], count: Int) {
        self.contacts = contacts
        self.count = count
    }

    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)

        if let values = try? container.decode([Contact].self, forKey: .contacts) {
            contacts = values
        } else if let values = try? container.decode([Contact].self, forKey: .objects) {
            contacts = values
        } else {
            contacts = []
        }

        if let decodedCount = try? container.decode(Int.self, forKey: .count) {
            count = decodedCount
        } else {
            count = contacts.count
        }
    }
}

// MARK: - Statistics Models

struct StatisticsResponse: Decodable {
    let stats: [String: Int]
    let window: String
    let timestamp: String
    let windowHours: Int
    let bucketCount: Int
    let bucketSeconds: Int
    let totalPackets: Int
    let totalReports: Int
    let totalAdvertisements: Int
    let uniqueDevices: Int
    let uniqueCollectors: Int
    let last1h: Int
    let last24h: Int
    let last36h: Int
    let directReports: Int
    let floodReports: Int
    let unknownRouteReports: Int
    let noHopReports: Int
    let relayedReports: Int
    let chartBuckets: [Int]
    let uniqueDeviceBuckets: [Int]
    let collectorTotals: [CollectorStatsTotal]
    let note: String?

    enum CodingKeys: String, CodingKey {
        case stats
        case window
        case timestamp
        case windowHours = "window_hours"
        case packetMix = "packet_mix"
        case totalPackets = "total_packets"
        case totalReports = "total_reports"
        case totalAdvertisements = "total_advertisements"
        case uniqueDevices = "unique_devices"
        case uniqueCollectors = "unique_collectors"
        case last1h = "last_1h"
        case last24h = "last_24h"
        case last36h = "last_36h"
        case directReports = "direct_reports"
        case floodReports = "flood_reports"
        case unknownRouteReports = "unknown_route_reports"
        case noHopReports = "no_hop_reports"
        case relayedReports = "relayed_reports"
        case bucketCount = "bucket_count"
        case bucketSeconds = "bucket_seconds"
        case chartBuckets = "chart_buckets"
        case uniqueDeviceBuckets = "unique_device_buckets"
        case collectorTotals = "collector_totals"
        case note
    }

    init(stats: [String: Int], window: String, timestamp: String) {
        self.stats = stats
        self.window = window
        self.timestamp = timestamp
        self.windowHours = 24
        self.bucketCount = 0
        self.bucketSeconds = 0
        self.totalPackets = 0
        self.totalReports = 0
        self.totalAdvertisements = 0
        self.uniqueDevices = 0
        self.uniqueCollectors = 0
        self.last1h = 0
        self.last24h = 0
        self.last36h = 0
        self.directReports = 0
        self.floodReports = 0
        self.unknownRouteReports = 0
        self.noHopReports = 0
        self.relayedReports = 0
        self.chartBuckets = []
        self.uniqueDeviceBuckets = []
        self.collectorTotals = []
        self.note = nil
    }

    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)

        totalPackets = (try? container.decode(Int.self, forKey: .totalPackets)) ?? 0
        totalReports = (try? container.decode(Int.self, forKey: .totalReports)) ?? 0
        totalAdvertisements = (try? container.decode(Int.self, forKey: .totalAdvertisements)) ?? 0
        uniqueDevices = (try? container.decode(Int.self, forKey: .uniqueDevices)) ?? 0
        uniqueCollectors = (try? container.decode(Int.self, forKey: .uniqueCollectors)) ?? 0
        last1h = (try? container.decode(Int.self, forKey: .last1h)) ?? 0
        last24h = (try? container.decode(Int.self, forKey: .last24h)) ?? 0
        last36h = (try? container.decode(Int.self, forKey: .last36h)) ?? 0
        directReports = (try? container.decode(Int.self, forKey: .directReports)) ?? 0
        floodReports = (try? container.decode(Int.self, forKey: .floodReports)) ?? 0
        unknownRouteReports = (try? container.decode(Int.self, forKey: .unknownRouteReports)) ?? 0
        noHopReports = (try? container.decode(Int.self, forKey: .noHopReports)) ?? 0
        relayedReports = (try? container.decode(Int.self, forKey: .relayedReports)) ?? 0

        bucketCount = (try? container.decode(Int.self, forKey: .bucketCount)) ?? 0
        bucketSeconds = (try? container.decode(Int.self, forKey: .bucketSeconds)) ?? 0
        chartBuckets = (try? container.decode([Int].self, forKey: .chartBuckets)) ?? []
        uniqueDeviceBuckets = (try? container.decode([Int].self, forKey: .uniqueDeviceBuckets)) ?? []
        collectorTotals = (try? container.decode([CollectorStatsTotal].self, forKey: .collectorTotals)) ?? []
        note = try? container.decodeIfPresent(String.self, forKey: .note)

        if let nested = try? container.decode([String: Int].self, forKey: .stats) {
            stats = nested
        } else {
            var aggregated: [String: Int] = [:]

            if let packetMix = try? container.decode([String: Int].self, forKey: .packetMix) {
                for (key, value) in packetMix {
                    aggregated[key] = value
                }
            }

            let numericKeys: [(CodingKeys, String)] = [
                (.totalPackets, "total_packets"),
                (.totalReports, "total_reports"),
                (.totalAdvertisements, "total_advertisements"),
                (.uniqueDevices, "unique_devices"),
                (.uniqueCollectors, "unique_collectors"),
                (.last1h, "last_1h"),
                (.last24h, "last_24h"),
                (.last36h, "last_36h"),
                (.directReports, "direct_reports"),
                (.floodReports, "flood_reports"),
                (.unknownRouteReports, "unknown_route_reports"),
                (.noHopReports, "no_hop_reports"),
                (.relayedReports, "relayed_reports")
            ]

            for (codingKey, outputKey) in numericKeys {
                if let value = try? container.decode(Int.self, forKey: codingKey) {
                    aggregated[outputKey] = value
                }
            }

            // Backward and forward compatibility: always expose the validated totals map.
            aggregated["total_packets"] = totalPackets
            aggregated["total_reports"] = totalReports
            aggregated["total_advertisements"] = totalAdvertisements
            aggregated["unique_devices"] = uniqueDevices
            aggregated["unique_collectors"] = uniqueCollectors
            aggregated["last_1h"] = last1h
            aggregated["last_24h"] = last24h
            aggregated["last_36h"] = last36h
            aggregated["direct_reports"] = directReports
            aggregated["flood_reports"] = floodReports
            aggregated["unknown_route_reports"] = unknownRouteReports
            aggregated["no_hop_reports"] = noHopReports
            aggregated["relayed_reports"] = relayedReports

            stats = aggregated
        }

        if let windowStr = try? container.decode(String.self, forKey: .window) {
            window = windowStr
        } else if let windowHours = try? container.decode(Int.self, forKey: .windowHours) {
            window = "\(windowHours)h"
        } else {
            window = "24h"
        }

        if let decodedWindowHours = try? container.decode(Int.self, forKey: .windowHours) {
            windowHours = decodedWindowHours
        } else if let parsed = Int(window.replacingOccurrences(of: "h", with: "")) {
            windowHours = parsed
        } else {
            windowHours = 24
        }

        timestamp = (try? container.decode(String.self, forKey: .timestamp)) ?? ""
    }
}

struct CollectorStatsTotal: Decodable, Identifiable {
    let reporterId: Int
    let reporterName: String
    let publicKey: String
    let totalPackets: Int
    let advPackets: Int
    let dirPackets: Int
    let pubPackets: Int
    let telPackets: Int
    let sysPackets: Int
    let rawPackets: Int

    var id: Int { reporterId }

    enum CodingKeys: String, CodingKey {
        case reporterId = "reporter_id"
        case reporterName = "reporter_name"
        case publicKey = "public_key"
        case totalPackets = "total_packets"
        case advPackets = "adv_packets"
        case dirPackets = "dir_packets"
        case pubPackets = "pub_packets"
        case telPackets = "tel_packets"
        case sysPackets = "sys_packets"
        case rawPackets = "raw_packets"
    }
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

    init(id: Int, publicKey: String, authorized: Bool, lastHeardAt: String?) {
        self.id = id
        self.publicKey = publicKey
        self.authorized = authorized
        self.lastHeardAt = lastHeardAt
    }

    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)

        id = try container.decode(Int.self, forKey: .id)
        publicKey = (try? container.decode(String.self, forKey: .publicKey)) ?? ""
        lastHeardAt = try? container.decodeIfPresent(String.self, forKey: .lastHeardAt)

        if let value = try? container.decode(Bool.self, forKey: .authorized) {
            authorized = value
        } else if let value = try? container.decode(Int.self, forKey: .authorized) {
            authorized = value != 0
        } else if let value = try? container.decode(String.self, forKey: .authorized) {
            authorized = value == "1" || value.lowercased() == "true"
        } else {
            authorized = false
        }
    }

    func encode(to encoder: Encoder) throws {
        var container = encoder.container(keyedBy: CodingKeys.self)
        try container.encode(id, forKey: .id)
        try container.encode(publicKey, forKey: .publicKey)
        try container.encode(authorized, forKey: .authorized)
        try container.encodeIfPresent(lastHeardAt, forKey: .lastHeardAt)
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

    init(id: Int, hash: String, name: String, enabled: Bool, psk: String?) {
        self.id = id
        self.hash = hash
        self.name = name
        self.enabled = enabled
        self.psk = psk
    }

    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)

        id = try container.decode(Int.self, forKey: .id)
        hash = (try? container.decode(String.self, forKey: .hash)) ?? ""
        name = (try? container.decode(String.self, forKey: .name)) ?? ""
        psk = try? container.decodeIfPresent(String.self, forKey: .psk)

        if let value = try? container.decode(Bool.self, forKey: .enabled) {
            enabled = value
        } else if let value = try? container.decode(Int.self, forKey: .enabled) {
            enabled = value != 0
        } else if let value = try? container.decode(String.self, forKey: .enabled) {
            enabled = value == "1" || value.lowercased() == "true"
        } else {
            enabled = false
        }
    }

    func encode(to encoder: Encoder) throws {
        var container = encoder.container(keyedBy: CodingKeys.self)
        try container.encode(id, forKey: .id)
        try container.encode(hash, forKey: .hash)
        try container.encode(name, forKey: .name)
        try container.encode(enabled, forKey: .enabled)
        try container.encodeIfPresent(psk, forKey: .psk)
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
