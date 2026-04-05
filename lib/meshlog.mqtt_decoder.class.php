<?php

class MeshLogMqttDecoder {
    // At least 4 hex characters (2 bytes) so tiny placeholders (for example "AA") are rejected.
    const MIN_REPORTER_KEY_LENGTH = 4;
    const TOPIC_TYPES = array('status', 'packets', 'debug');

    // Structured types sent by firmware over MQTT (same JSON format as HTTP ingest).
    const STRUCTURED_TYPES = array('ADV', 'MSG', 'PUB', 'SYS', 'TEL', 'RAW');

    // MeshCore binary payload types (bits 2-5 of the packet header byte).
    // Full table from packet_format.md — values are the 4-bit PAYLOAD_TYPE field.
    const PAYLOAD_TYPE_REQ        = 0x00; // Request (dest_hash + src_hash + MAC + ciphertext)
    const PAYLOAD_TYPE_RESPONSE   = 0x01; // Response to REQ or ANON_REQ (encrypted)
    const PAYLOAD_TYPE_TXT_MSG    = 0x02; // Direct text message (encrypted)
    const PAYLOAD_TYPE_ACK        = 0x03; // Acknowledgment (4-byte CRC checksum, unencrypted)
    const PAYLOAD_TYPE_ADVERT     = 0x04; // Node advertisement (unencrypted)
    const PAYLOAD_TYPE_GRP_TXT    = 0x05; // Group text message (AES-128 encrypted)
    const PAYLOAD_TYPE_GRP_DATA   = 0x06; // Group datagram (same structure as GRP_TXT, encrypted)
    const PAYLOAD_TYPE_ANON_REQ   = 0x07; // Anonymous request (dest_hash + sender_pubkey + MAC + ciphertext)
    const PAYLOAD_TYPE_PATH       = 0x08; // Returned path (path_length + path_hashes + extra_type + extra)
    const PAYLOAD_TYPE_TRACE      = 0x09; // Trace packet (path with per-hop SNR data)
    const PAYLOAD_TYPE_MULTIPART  = 0x0A; // Multi-part packet sequence fragment
    const PAYLOAD_TYPE_CONTROL    = 0x0B; // Control / discovery data (unencrypted)
    const PAYLOAD_TYPE_RAW_CUSTOM = 0x0F; // Custom packet (raw bytes, application-defined)
    const PUBLIC_GROUP_PSK_HEX = '8b3387e9c5cdea6ac9e5edbaa115cd72'; // MeshCore well-known Public channel 128-bit key

    // MeshCore route types (bits 0-1 of the packet header byte).
    const ROUTE_TYPE_TRANSPORT_FLOOD  = 0x00;  // flood + transport codes (4 extra bytes)
    const ROUTE_TYPE_FLOOD            = 0x01;  // plain flood
    const ROUTE_TYPE_DIRECT           = 0x02;  // direct routing
    const ROUTE_TYPE_TRANSPORT_DIRECT = 0x03;  // direct + transport codes (4 extra bytes)
    const PATH_HASH_SIZE_VALID_MAX = 2;        // bits 6-7 encode hash_size-1; value 3 is reserved/invalid
    const PATH_HOP_COUNT_MASK = 0x3F;          // bits 0-5 of path_len encode hop count

    // Minimum Unix timestamp (2020-01-01) used to detect invalid/unset device clocks.
    const MIN_VALID_UNIX_TIMESTAMP = 1577836800;

    public static function decode($topic, $payload, $channels = array(), $options = array()) {
        $data = json_decode($payload, true);
        if (!is_array($data)) return null;

        $typeRaw = isset($data['type']) ? trim(strval($data['type'])) : '';
        $type = ($typeRaw === '') ? null : strtoupper($typeRaw);

        $mqttMeta = (isset($options['mqtt_meta']) && is_array($options['mqtt_meta']))
            ? $options['mqtt_meta']
            : static::extractMetadata($topic, $data);
        $forcedReporter = static::normalizeReporterKey($options['forced_reporter'] ?? '');
        $reporter = $forcedReporter !== '' ? $forcedReporter : ($mqttMeta['attempted_reporter'] ?? '');
        $format = MeshLogReporter::normalizeFormat($options['format'] ?? MeshLogReporter::FORMAT_MESHLOG);
        $topicType = static::extractTopicType($topic);

        if (!$reporter) return null;

        // Dedicated reporter status topic.
        // Both MeshLog and LetsMesh collectors publish to .../<pubkey>/status.
        if ($topicType === 'status') {
            $decodedStatus = static::decodeStatusTopicPayload($data, $reporter, $mqttMeta);
            if ($decodedStatus !== null) return $decodedStatus;
        }

        // Binary PACKET from meshcoretomqtt: attempt structured decode first,
        // then fall back to storing as a RAW packet.
        if ($type === 'PACKET') {
            $raw = preg_replace('/[^0-9A-Fa-f]/', '', strtoupper($data['raw'] ?? ''));
            if (strlen($raw) % 2 !== 0 || !$raw) return null;

            $bytes = hex2bin($raw);
            $packet = static::extractPacket($bytes);
            $path = $packet['path'] ?? static::decodePath($data['path'] ?? '');
            $hashSize = $packet['hash_size'] ?? static::decodeHashSize($path);
            $scope = static::normalizeScope($packet['scope'] ?? null);
            $routeType = static::normalizeRouteType($packet['route_type'] ?? null);
            $packetType = intval($data['packet_type'] ?? 0);
            $snr = intval($data['SNR'] ?? 0);

            $timestamp = static::normalizeTimestampMs(
                $data['timestamp'] ?? null,
                intval(floor(microtime(true) * 1000))
            );

            // ADVERT packets (packet_type=4) are unencrypted and can be fully decoded
            // into the ADV structured format that insertForReporter() already handles.
            if ($packetType === static::PAYLOAD_TYPE_ADVERT) {
                $decoded = static::decodeAdvertPacket(
                    $raw, $path, $hashSize, $scope, $routeType, $snr, $timestamp, $reporter, $data, $mqttMeta
                );
                if ($decoded !== null) return $decoded;
            }

            // GRP_TXT packets (packet_type=5) are AES-128 encrypted group messages.
            // Attempt decryption using all enabled channels that have a known PSK.
            // Falls through to RAW if no channel matches or decryption fails.
            if ($packetType === static::PAYLOAD_TYPE_GRP_TXT && !empty($channels)) {
                $decoded = static::decodeGroupPacket(
                    $raw, $path, $hashSize, $scope, $routeType, $snr, $timestamp, $reporter, $data, $mqttMeta, $channels
                );
                if ($decoded !== null) return $decoded;
            }

            // GRP_DATA (packet_type=6) uses the identical outer structure as GRP_TXT.
            // Attempt decryption; fall through to RAW if no channel matches.
            if ($packetType === static::PAYLOAD_TYPE_GRP_DATA && !empty($channels)) {
                $decoded = static::decodeGroupPacket(
                    $raw, $path, $hashSize, $scope, $routeType, $snr, $timestamp, $reporter, $data, $mqttMeta, $channels
                );
                if ($decoded !== null) return $decoded;
            }

            // ACK (packet_type=3): unencrypted 4-byte CRC — decode the checksum.
            if ($packetType === static::PAYLOAD_TYPE_ACK) {
                $decoded = static::decodeAckPacket(
                    $raw, $path, $hashSize, $scope, $routeType, $snr, $timestamp, $reporter, $data, $mqttMeta
                );
                if ($decoded !== null) return $decoded;
            }

            // PATH (packet_type=8): returned path — decode path hashes + extra payload type.
            if ($packetType === static::PAYLOAD_TYPE_PATH) {
                $decoded = static::decodeReturnedPathPacket(
                    $raw, $path, $hashSize, $scope, $routeType, $snr, $timestamp, $reporter, $data, $mqttMeta
                );
                if ($decoded !== null) return $decoded;
            }

            // CONTROL (packet_type=11): unencrypted control / discovery data.
            // Decodes sub_type from the flags byte; handles DISCOVER_REQ (8) and DISCOVER_RESP (9).
            if ($packetType === static::PAYLOAD_TYPE_CONTROL) {
                $decoded = static::decodeControlPacket(
                    $raw, $path, $hashSize, $scope, $routeType, $snr, $timestamp, $reporter, $data, $mqttMeta
                );
                if ($decoded !== null) return $decoded;
            }

            // ANON_REQ (packet_type=7): destination hash + sender pubkey visible; ciphertext encrypted.
            // Extracts the unencrypted routing fields and stores as a decoded RAW entry.
            if ($packetType === static::PAYLOAD_TYPE_ANON_REQ) {
                $decoded = static::decodeAnonReqPacket(
                    $raw, $path, $hashSize, $scope, $routeType, $snr, $timestamp, $reporter, $data, $mqttMeta
                );
                if ($decoded !== null) return $decoded;
            }

            // REQ (packet_type=0) and RESPONSE (packet_type=1): encrypted unicast frames.
            // The destination and source node hashes are visible in the unencrypted header.
            if ($packetType === static::PAYLOAD_TYPE_REQ || $packetType === static::PAYLOAD_TYPE_RESPONSE) {
                $decoded = static::decodeDirectFramePacket(
                    $raw, $path, $hashSize, $scope, $routeType, $snr, $timestamp, $reporter, $data, $mqttMeta, $packetType
                );
                if ($decoded !== null) return $decoded;
            }

            // TXT_MSG (packet_type=2): unicast encrypted direct message — not decrypted, stored as RAW.
            // TRACE (packet_type=9): per-hop SNR trace — format not fully specified, stored as RAW.
            // MULTIPART (packet_type=10): sequence fragment — requires reassembly, stored as RAW.
            // RAW_CUSTOM (packet_type=15): application-defined — no standard format, stored as RAW.

            // Fall-through: store packet as a RAW entry.
            return array(
                "type" => "RAW",
                "reporter" => $reporter,
                "time" => array(
                    "local" => $timestamp,
                    "sender" => $timestamp,
                    "server" => $timestamp
                ),
                "packet" => array(
                    "header" => $packet['header'] ?? $packetType,
                    "path" => $path,
                    "payload" => $packet['payload'] ?? $raw,
                    "snr" => $snr,
                    "decoded" => false,
                    "hash_size" => $hashSize,
                    "scope" => $scope,
                    "route_type" => $routeType,
                ),
                "_mqtt" => $mqttMeta,
            );
        }

        if ($format === MeshLogReporter::FORMAT_LETSMESH) {
            $decodedLetsMesh = static::decodeLetsMeshPayload($data, $reporter, $mqttMeta);
            if ($decodedLetsMesh !== null) return $decodedLetsMesh;
        }

        // Pre-decoded structured types (ADV, MSG, PUB, SYS, TEL, RAW) arriving over MQTT
        // in the same JSON format as the HTTP firmware logger.  Inject the reporter key and
        // a server-side timestamp so the payload is identical to what insertForReporter() receives
        // from the HTTP path.
        if (isset($type) && in_array($type, static::STRUCTURED_TYPES)) {
            $data['type'] = $type;
            $data['reporter'] = $reporter;

            if (!isset($data['time']) || !is_array($data['time'])) {
                $data['time'] = array();
            }
            $serverTime = static::normalizeTimestampMs(
                $data['time']['server'] ?? null,
                intval(floor(microtime(true) * 1000))
            );
            $fallbackTime = static::normalizeTimestampMs(
                $data['timestamp'] ?? null,
                $serverTime
            );
            $localTimeInput = static::normalizeTimestampMs(
                $data['time']['local'] ?? null,
                null
            );
            $senderTime = static::normalizeTimestampMs(
                $data['time']['sender'] ?? null,
                ($localTimeInput !== null) ? $localTimeInput : $fallbackTime
            );
            $localTime = ($localTimeInput !== null) ? $localTimeInput : $senderTime;

            $data['time']['server'] = $serverTime;
            $data['time']['sender'] = $senderTime;
            $data['time']['local'] = $localTime;
            $data['route_type'] = static::normalizeRouteType($data['route_type'] ?? null);
            if ($type === 'RAW' && isset($data['packet']) && is_array($data['packet'])) {
                $data['packet']['route_type'] = static::normalizeRouteType($data['packet']['route_type'] ?? $data['route_type']);
            }
            $data['_mqtt'] = $mqttMeta;
            return $data;
        }

        return null;
    }

    private static function extractTopicType($topic) {
        if (!is_string($topic) || $topic === '') return '';

        $parts = explode('/', trim(trim($topic), '/'));
        if (count($parts) < 1) return '';

        $last = strtolower(trim($parts[count($parts) - 1]));
        return in_array($last, static::TOPIC_TYPES, true) ? $last : '';
    }

    public static function extractReporterFromTopic($topic) {
        if (!is_string($topic) || $topic === '') return '';

        $parts = explode('/', trim(trim($topic), '/'));
        if (count($parts) < 2) return '';
        if (!in_array(strtolower(trim($parts[count($parts) - 1])), static::TOPIC_TYPES)) return '';

        for ($i = count($parts) - 2; $i >= 0; $i--) {
            $candidate = static::normalizeReporterKey($parts[$i]);
            if ($candidate !== '') return $candidate;
        }

        return '';
    }

    public static function extractMetadata($topic, $payload) {
        $data = is_array($payload) ? $payload : json_decode($payload, true);
        $topicReporter = static::extractReporterFromTopic($topic);
        $payloadReporter = static::extractReporterFromPayload($data);
        $topicIata = static::extractIataFromTopic($topic);
        $payloadIata = static::extractIataFromPayload($data);

        return array(
            "topic" => is_string($topic) ? $topic : '',
            "topic_reporter" => $topicReporter,
            "payload_reporter" => $payloadReporter,
            "topic_iata" => $topicIata,
            "payload_iata" => $payloadIata,
            "reporter_source" => $topicReporter ? 'topic' : ($payloadReporter ? 'payload' : 'unknown'),
            "topic_payload_mismatch" => boolval($topicReporter && $payloadReporter && $topicReporter !== $payloadReporter),
            "attempted_reporter" => $topicReporter ?: $payloadReporter,
        );
    }

    private static function extractIataFromTopic($topic) {
        if (!is_string($topic) || $topic === '') return '';

        $parts = explode('/', trim(trim($topic), '/'));
        if (count($parts) < 3) return '';
        if (!in_array(strtolower(trim($parts[count($parts) - 1])), static::TOPIC_TYPES)) return '';

        for ($i = count($parts) - 2; $i >= 1; $i--) {
            $candidateReporter = static::normalizeReporterKey($parts[$i]);
            if ($candidateReporter === '') continue;
            return static::normalizeIataCode($parts[$i - 1]);
        }

        return '';
    }

    private static function extractIataFromPayload($data) {
        if (!is_array($data)) return '';

        foreach (array('iata', 'airport', 'region') as $key) {
            $candidate = static::normalizeIataCode($data[$key] ?? '');
            if ($candidate !== '') return $candidate;
        }

        return '';
    }

    private static function normalizeIataCode($value) {
        if (!is_scalar($value)) return '';

        $iata = strtoupper(trim(strval($value)));
        if ($iata === '') return '';

        $iata = preg_replace('/[^A-Z0-9]/', '', $iata);
        if ($iata === '' || strlen($iata) < 2) return '';

        return substr($iata, 0, 8);
    }

    private static function extractReporterFromPayload($data) {
        if (!is_array($data)) return '';

        foreach (array('origin_id', 'public_key', 'pubkey', 'reporter') as $key) {
            $candidate = static::normalizeReporterKey($data[$key] ?? '');
            if ($candidate !== '') return $candidate;
        }

        return '';
    }

    private static function normalizeReporterKey($value) {
        if (!is_scalar($value)) return '';

        $candidate = preg_replace('/[^0-9A-Fa-f]/', '', strtoupper(trim(strval($value))));
        if ($candidate === '' || $candidate === '+') return '';
        if (strlen($candidate) % 2 !== 0) return '';
        if (strlen($candidate) < static::MIN_REPORTER_KEY_LENGTH) return '';

        return $candidate;
    }

    private static function decodePath($rawPath) {
        if (is_array($rawPath)) {
            $rawPath = implode('->', array_map(function($segment) {
                return is_scalar($segment) ? strval($segment) : '';
            }, $rawPath));
        }
        if (!$rawPath || !is_string($rawPath)) return "";

        $parts = preg_split('/\s*->\s*/', trim($rawPath));
        $hashes = array();
        foreach ($parts as $part) {
            $hash = preg_replace('/[^0-9A-Fa-f]/', '', strtoupper($part));
            if ($hash !== '') $hashes[] = strtolower($hash);
        }

        return implode(",", $hashes);
    }

    private static function decodeHashSize($path) {
        if (!$path) return 1;
        $first = explode(",", $path, 2)[0];
        $len = strlen($first);
        $hashSize = intval($len / 2);
        if ($hashSize < 1) return 1;
        if ($hashSize > 3) return 3;
        return $hashSize;
    }

    private static function normalizeTimestampMs($value, $fallback = null) {
        if (is_int($value) || is_float($value)) {
            $num = intval($value);
            if ($num < 0) return $fallback;
            return ($num > 10000000000) ? $num : ($num * 1000);
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') return $fallback;

            if (is_numeric($trimmed)) {
                $num = intval($trimmed);
                if ($num < 0) return $fallback;
                return ($num > 10000000000) ? $num : ($num * 1000);
            }

            // MeshCore firmware and MQTT bridges always emit UTC timestamps.
            // strtotime() interprets strings without a timezone indicator as
            // the server's local timezone, which causes a false drift equal to
            // the UTC offset (e.g. -3 600 000 ms for UTC-1).  Append the UTC
            // offset explicitly so the conversion is always timezone-neutral.
            $hasTimezone = (bool) preg_match('/Z$|[+-]\d{2}:?\d{2}$/', $trimmed);
            $lookupStr   = $hasTimezone ? $trimmed : ($trimmed . '+00:00');
            $ts = strtotime($lookupStr);
            if ($ts !== false && $ts >= 0) {
                return intval($ts) * 1000;
            }
        }

        return $fallback;
    }

    private static function decodeStatusTopicPayload($data, $reporter, $mqttMeta) {
        if (!is_array($data)) return null;

        $serverNow = intval(floor(microtime(true) * 1000));
        $senderTime = static::normalizeTimestampMs(
            $data['timestamp'] ?? ($data['ts'] ?? null),
            $serverNow
        );
        $localTime = static::normalizeTimestampMs(
            $data['received_at'] ?? null,
            $serverNow
        );
        $serverTime = static::normalizeTimestampMs(
            $data['time']['server'] ?? null,
            $serverNow
        );

        $statusValue = strtolower(trim(strval($data['status'] ?? 'unknown')));
        if ($statusValue === '') $statusValue = 'unknown';

        $contactName = trim(strval($data['origin'] ?? ($data['name'] ?? '')));
        if ($contactName === '') {
            $contactName = trim(strval($reporter));
        }

        $originId = static::normalizeReporterKey($data['origin_id'] ?? '');
        if ($originId !== '' && $originId !== $reporter) {
            $mqttMeta['origin_reporter_mismatch'] = true;
            $mqttMeta['origin_reporter'] = $originId;
        }

        $uptimeRaw = $data['uptime'] ?? ($data['uptime_secs'] ?? ($data['stats']['uptime_secs'] ?? null));
        $uptime = is_numeric($uptimeRaw) ? intval($uptimeRaw) : null;

        $heapTotalRaw = $data['heap_total'] ?? ($data['stats']['heap_total'] ?? null);
        $heapFreeRaw = $data['heap_free'] ?? ($data['stats']['heap_free'] ?? null);
        $rssiRaw = $data['rssi'] ?? ($data['stats']['last_rssi'] ?? null);

        $sys = array(
            'status' => $statusValue,
            'version' => strval($data['firmware_version'] ?? ($data['version'] ?? '')),
            'heap_total' => is_numeric($heapTotalRaw) ? intval($heapTotalRaw) : null,
            'heap_free' => is_numeric($heapFreeRaw) ? intval($heapFreeRaw) : null,
            'rssi' => is_numeric($rssiRaw) ? intval($rssiRaw) : null,
            'uptime' => $uptime,
            'model' => strval($data['model'] ?? ''),
            'radio' => strval($data['radio'] ?? ''),
            'client_version' => strval($data['client_version'] ?? ''),
        );

        if (is_array($data['stats'] ?? null)) {
            $sys['stats'] = $data['stats'];
        }

        $statusSnapshot = array(
            'status' => $statusValue,
            'timestamp_ms' => $senderTime,
            'origin' => strval($data['origin'] ?? ''),
            'origin_id' => $originId !== '' ? $originId : $reporter,
            'firmware_version' => strval($data['firmware_version'] ?? ($data['version'] ?? '')),
            'model' => strval($data['model'] ?? ''),
            'radio' => strval($data['radio'] ?? ''),
            'client_version' => strval($data['client_version'] ?? ''),
            'heap_total' => $sys['heap_total'],
            'heap_free' => $sys['heap_free'],
            'rssi' => $sys['rssi'],
            'uptime' => $sys['uptime'],
            'stats' => is_array($data['stats'] ?? null) ? $data['stats'] : array(),
            'iata' => static::normalizeIataCode($mqttMeta['topic_iata'] ?? ($mqttMeta['payload_iata'] ?? '')),
            'topic' => strval($mqttMeta['topic'] ?? ''),
        );

        return array(
            'type' => 'SYS',
            'reporter' => $reporter,
            'contact' => array(
                'pubkey' => $reporter,
                'name' => $contactName,
                'lat' => $data['lat'] ?? ($data['contact']['lat'] ?? null),
                'lon' => $data['lon'] ?? ($data['contact']['lon'] ?? null),
            ),
            'sys' => $sys,
            'time' => array(
                'sender' => $senderTime,
                'local' => $localTime,
                'server' => $serverTime,
            ),
            '_mqtt' => $mqttMeta,
            '_reporter_status' => $statusSnapshot,
        );
    }

    private static function decodeLetsMeshPayload($data, $reporter, $mqttMeta) {
        if (!is_array($data)) return null;

        $typeCandidates = array(
            $data['type'] ?? null,
            $data['packet_type'] ?? null,
            $data['msg_type'] ?? null,
            $data['kind'] ?? null,
            $data['event'] ?? null,
            (is_array($data['packet'] ?? null) ? ($data['packet']['type'] ?? null) : null),
        );
        $type = '';
        foreach ($typeCandidates as $candidateType) {
            $type = static::normalizeLetsMeshType($candidateType);
            if ($type !== '') break;
        }

        $serverNow = intval(floor(microtime(true) * 1000));
        $senderTime = static::normalizeTimestampMs(
            $data['time']['sender'] ?? ($data['sender_at'] ?? ($data['timestamp'] ?? ($data['ts'] ?? null))),
            $serverNow
        );
        $localTime = static::normalizeTimestampMs(
            $data['time']['local'] ?? ($data['received_at'] ?? null),
            $serverNow
        );
        $serverTime = static::normalizeTimestampMs(
            $data['time']['server'] ?? null,
            $serverNow
        );

        $contactKey = static::normalizeReporterKey(
            $data['contact']['pubkey'] ??
            ($data['contact']['public_key'] ??
            ($data['sender'] ??
            ($data['from'] ??
            ($data['origin_id'] ??
            ($data['source'] ?? '')))))
        );

        $contactName = strval(
            $data['contact']['name'] ??
            ($data['sender_name'] ??
            ($data['name'] ?? ''))
        );

        $hash = strtolower(strval(
            $data['hash'] ??
            ($data['message_hash'] ??
            ($data['id'] ?? ''))
        ));

        $path = static::decodePath($data['path'] ?? ($data['route'] ?? ($data['relay_path'] ?? '')));
        $hashSize = intval($data['hash_size'] ?? static::decodeHashSize($path));
        if ($hashSize < 1) $hashSize = 1;
        if ($hashSize > 3) $hashSize = 3;

        $base = array(
            'reporter' => $reporter,
            'hash' => $hash,
            'hash_size' => $hashSize,
            'route_type' => static::normalizeRouteType($data['route_type'] ?? null),
            'time' => array(
                'sender' => $senderTime,
                'local' => $localTime,
                'server' => $serverTime,
            ),
            '_mqtt' => $mqttMeta,
        );

        if ($type === 'ADV') {
            return array_merge($base, array(
                'type' => 'ADV',
                'contact' => array(
                    'pubkey' => $contactKey,
                    'name' => $contactName,
                    'lat' => $data['contact']['lat'] ?? ($data['lat'] ?? null),
                    'lon' => $data['contact']['lon'] ?? ($data['lon'] ?? null),
                    'type' => intval($data['contact']['type'] ?? ($data['node_type'] ?? 1)),
                    'flags' => intval($data['contact']['flags'] ?? ($data['flags'] ?? 0)),
                ),
                'message' => array(
                    'path' => $path,
                ),
            ));
        }

        if ($type === 'MSG') {
            return array_merge($base, array(
                'type' => 'MSG',
                'contact' => array(
                    'pubkey' => $contactKey,
                    'name' => $contactName,
                ),
                'message' => array(
                    'text' => strval($data['message']['text'] ?? ($data['message'] ?? ($data['text'] ?? ($data['body'] ?? '')))),
                    'path' => $path,
                ),
            ));
        }

        if ($type === 'PUB') {
            $channelHash = strtolower(trim(strval(
                $data['channel']['hash'] ?? ($data['channel_hash'] ?? ($data['channel'] ?? '11'))
            )));
            if ($channelHash === '') $channelHash = '11';

            return array_merge($base, array(
                'type' => 'PUB',
                'contact' => array(
                    'pubkey' => $contactKey,
                    'name' => $contactName,
                ),
                'channel' => array(
                    'hash' => $channelHash,
                    'name' => strval($data['channel']['name'] ?? ($data['channel_name'] ?? ('#' . $channelHash))),
                ),
                'message' => array(
                    'text' => strval($data['message']['text'] ?? ($data['message'] ?? ($data['text'] ?? ($data['body'] ?? '')))),
                    'path' => $path,
                ),
            ));
        }

        if ($type === 'TEL') {
            return array_merge($base, array(
                'type' => 'TEL',
                'contact' => array(
                    'pubkey' => $contactKey,
                    'name' => $contactName,
                ),
                'telemetry' => is_array($data['telemetry'] ?? null)
                    ? $data['telemetry']
                    : (is_array($data['data'] ?? null) ? $data['data'] : array()),
            ));
        }

        if ($type === 'SYS') {
            return array_merge($base, array(
                'type' => 'SYS',
                'contact' => array(
                    'pubkey' => $contactKey,
                    'name' => $contactName,
                    'lat' => $data['contact']['lat'] ?? ($data['lat'] ?? null),
                    'lon' => $data['contact']['lon'] ?? ($data['lon'] ?? null),
                ),
                'sys' => is_array($data['sys'] ?? null)
                    ? $data['sys']
                    : (is_array($data['status'] ?? null) ? $data['status'] : array()),
            ));
        }

        // Fallback to RAW mapping for unknown/packet-first LetsMesh payloads.
        $raw = preg_replace('/[^0-9A-Fa-f]/', '', strtoupper(strval($data['raw'] ?? ($data['packet']['raw'] ?? ''))));
        if ($raw === '') return null;

        return array_merge($base, array(
            'type' => 'RAW',
            'packet' => array(
                'header' => intval($data['header'] ?? 0),
                'path' => $path,
                'payload' => $raw,
                'snr' => intval($data['snr'] ?? ($data['SNR'] ?? 0)),
                'decoded' => false,
                'hash_size' => $hashSize,
                'route_type' => static::normalizeRouteType($data['route_type'] ?? null),
            ),
        ));
    }

    private static function normalizeLetsMeshType($type) {
        if (!is_scalar($type)) return '';

        $value = strtoupper(trim(strval($type)));
        if ($value === '') return '';

        if (in_array($value, array('ADV', 'ADVERT', 'ADVERTISEMENT'))) return 'ADV';
        if (in_array($value, array('MSG', 'DM', 'DIRECT', 'DIRECT_MESSAGE'))) return 'MSG';
        if (in_array($value, array('PUB', 'GROUP', 'CHANNEL', 'GROUP_MESSAGE'))) return 'PUB';
        if (in_array($value, array('TEL', 'TELEMETRY'))) return 'TEL';
        if (in_array($value, array('SYS', 'STATUS', 'SYSTEM'))) return 'SYS';
        if (in_array($value, array('RAW', 'PACKET'))) return 'RAW';

        return '';
    }

    private static function extractPacket($bytes) {
        $layout = static::extractPacketLayout($bytes);
        if ($layout === null) return null;

        return array(
            'header' => $layout['header'],
            'route_type' => $layout['route_type'],
            'path' => static::decodePathBytes(substr($bytes, $layout['path_offset'], $layout['path_byte_len']), $layout['hash_size']),
            'payload' => strtoupper(bin2hex(substr($bytes, $layout['payload_offset']))),
            'hash_size' => $layout['hash_size'],
            'scope' => static::decodeTransportScope($layout['transport_codes'] ?? null),
        );
    }

    public static function summarizeRawPacketHex($rawHex) {
        if (!is_scalar($rawHex)) return null;

        $normalizedHex = preg_replace('/[^0-9A-Fa-f]/', '', strtoupper(strval($rawHex)));
        if ($normalizedHex === '' || (strlen($normalizedHex) % 2) !== 0) return null;

        $bytes = hex2bin($normalizedHex);
        if ($bytes === false) return null;

        $packet = static::extractPacket($bytes);
        if (!is_array($packet)) return null;

        $header = intval($packet['header'] ?? 0);

        return array(
            'header' => $header,
            'packet_type' => (($header >> 2) & 0x0F),
            'path' => $packet['path'] ?? '',
            'payload' => $packet['payload'] ?? '',
            'hash_size' => intval($packet['hash_size'] ?? 1),
            'scope' => static::normalizeScope($packet['scope'] ?? null),
            'route_type' => static::normalizeRouteType($packet['route_type'] ?? null),
        );
    }

    private static function extractPacketLayout($bytes) {
        if (!is_string($bytes) || strlen($bytes) < 2) return null;

        $headerByte = ord($bytes[0]);
        $routeType  = $headerByte & 0x03;

        $hasTransport = ($routeType === static::ROUTE_TYPE_TRANSPORT_FLOOD ||
                         $routeType === static::ROUTE_TYPE_TRANSPORT_DIRECT);
        $pathLenOffset = $hasTransport ? 5 : 1;
        if (strlen($bytes) <= $pathLenOffset) return null;

        $pathLenByte = ord($bytes[$pathLenOffset]);
        $pathHashSizeBits = ($pathLenByte >> 6);
        if ($pathHashSizeBits > static::PATH_HASH_SIZE_VALID_MAX) return null;

        $hashSize = $pathHashSizeBits + 1;
        $pathHopCount = $pathLenByte & static::PATH_HOP_COUNT_MASK;
        $pathOffset = $pathLenOffset + 1;
        $pathByteLen = $pathHopCount * $hashSize;
        $payloadOffset = $pathOffset + $pathByteLen;
        if (strlen($bytes) < $payloadOffset) return null;

        return array(
            'header' => $headerByte,
            'route_type' => $routeType,
            'hash_size' => $hashSize,
            'path_offset' => $pathOffset,
            'path_byte_len' => $pathByteLen,
            'payload_offset' => $payloadOffset,
            'transport_codes' => $hasTransport ? array(
                ord($bytes[1]),
                ord($bytes[2]),
                ord($bytes[3]),
                ord($bytes[4]),
            ) : null,
        );
    }

    private static function decodeTransportScope($transportCodes) {
        if (!is_array($transportCodes) || !array_key_exists(0, $transportCodes)) return null;

        return static::normalizeScope($transportCodes[0]);
    }

    private static function normalizeScope($scope) {
        if ($scope === null || $scope === '') return null;

        $value = intval($scope);
        if ($value < 0 || $value > 255) return null;

        return $value;
    }

    private static function normalizeRouteType($routeType) {
        if ($routeType === null || $routeType === '') return null;

        $value = intval($routeType);
        if ($value < 0 || $value > 3) return null;

        return $value;
    }

    /**
     * Extract the payload bytes from a raw MeshCore packet.
     *
     * MeshCore v1 packet layout (bytes):
     *   [header(1)][transport_codes(4, optional)][path_len(1)][path(N)][payload]
     *
     * Transport codes are present only for ROUTE_TYPE_TRANSPORT_FLOOD (0x00)
     * and ROUTE_TYPE_TRANSPORT_DIRECT (0x03).
     *
     * The path_len byte is encoded as:
     *   bits 6-7: hash_size - 1  (00 = 1-byte hashes, 01 = 2-byte, 10 = 3-byte)
     *   bits 0-5: hop count
     * Actual path byte length = hop_count × hash_size.
     *
     * @param  string   $bytes      Raw binary packet bytes.
     * @param  int|null &$hashSize  (OUT) path hash size in bytes (1, 2 or 3); set to 1 on malformed input.
     * @return string|null          Payload bytes, or null if the packet is malformed.
     */
    private static function extractPayloadBytes($bytes, &$hashSize = null) {
        $hashSize = 1;  // default; updated below when path_len is decoded
        $layout = static::extractPacketLayout($bytes);
        if ($layout === null) return null;

        $hashSize = $layout['hash_size'];
        return substr($bytes, $layout['payload_offset']);
    }

    private static function decodePathBytes($pathBytes, $hashSize) {
        if (!is_string($pathBytes) || $pathBytes === '' || $hashSize < 1 || $hashSize > 3) return '';

        $hashes = array();
        $len = strlen($pathBytes);
        for ($offset = 0; $offset < $len; $offset += $hashSize) {
            $chunk = substr($pathBytes, $offset, $hashSize);
            if (strlen($chunk) !== $hashSize) return '';
            $hashes[] = strtolower(bin2hex($chunk));
        }

        return implode(",", $hashes);
    }

    /**
     * Unpack a little-endian signed 32-bit integer from a binary string.
     *
     * PHP's unpack('V', …) always returns an unsigned value; this helper
     * converts it to a signed PHP int in a portable, platform-independent way.
     *
     * @param  string $bytes   Binary string containing at least $offset + 4 bytes.
     * @param  int    $offset  Byte offset to read from.
     * @return int             Signed 32-bit integer value.
     */
    private static function unpackSignedInt32LE($bytes, $offset) {
        $unsigned = unpack('V', substr($bytes, $offset, 4))[1];
        return ($unsigned >= 0x80000000) ? intval($unsigned - 0x100000000) : intval($unsigned);
    }

    /**
     * Attempt to decode a binary MeshCore ADVERT packet into the ADV structured
     * format expected by MeshLog::insertForReporter().
     *
     * ADVERT payload layout (see MeshCore payloads.md):
     *   public_key (32 bytes)
     *   timestamp  (4 bytes, LE uint32, Unix seconds)
     *   signature  (64 bytes, Ed25519)
     *   appdata:
     *     flags    (1 byte)
     *       bits 0-3: node type  (1=chat, 2=repeater, 3=room_server, 4=sensor)
     *       bit  4:   has_location
     *       bit  5:   has_feature1 (reserved)
     *       bit  6:   has_feature2 (reserved)
     *       bit  7:   has_name
     *     latitude  (4 bytes, LE int32, micro-degrees; if has_location)
     *     longitude (4 bytes, LE int32, micro-degrees; if has_location)
     *     feature1  (2 bytes; if has_feature1)
     *     feature2  (2 bytes; if has_feature2)
     *     name      (rest of appdata; if has_name)
     *
     * @param  string $rawHex     Hex-encoded full packet bytes (from meshcoretomqtt "raw").
     * @param  string $path       Normalised routing path (comma-separated hashes).
     * @param  int    $hashSize   Path-hash byte length (1, 2 or 3).
     * @param  int    $snr        Signal-to-noise ratio from meshcoretomqtt.
     * @param  int    $timestamp  Server receive time in milliseconds (fallback).
     * @param  string $reporter   Reporter public-key string.
     * @param  array  $data       Decoded meshcoretomqtt JSON (for hash, etc.).
     * @param  array  $mqttMeta   MQTT metadata array from extractMetadata().
     * @return array|null         ADV data array for insertForReporter(), or null on failure.
     */
    private static function decodeAdvertPacket($rawHex, $path, $hashSize, $scope, $routeType, $snr, $timestamp, $reporter, $data, $mqttMeta) {
        $bytes = hex2bin($rawHex);

        $binaryHashSize = 1;
        $payload = static::extractPayloadBytes($bytes, $binaryHashSize);
        if ($payload === null) return null;

        // Prefer hash size derived from the binary path_len over the JSON-path-derived value,
        // because ADV packets use flood routing and the JSON path field is never populated.
        $hashSize = $binaryHashSize;

        // Minimum ADV payload: pubkey(32) + timestamp(4) + signature(64) + appdata_flags(1) = 101 bytes
        if (strlen($payload) < 101) return null;

        // Public key: first 32 bytes → uppercase hex string (64 chars, fits contacts.public_key varchar(64))
        $pubkey = strtoupper(bin2hex(substr($payload, 0, 32)));

        // Sender timestamp: 4 bytes LE uint32 at offset 32 (Unix seconds)
        $tsArr = unpack('V', substr($payload, 32, 4));
        $senderTimestampSec = intval($tsArr[1]);

        // Validate the sender clock; fall back to the server receive time if unreasonable.
        if ($senderTimestampSec >= static::MIN_VALID_UNIX_TIMESTAMP) {
            $senderTimestampMs = $senderTimestampSec * 1000;
        } else {
            $senderTimestampMs = $timestamp;
        }

        // Skip signature (64 bytes, offset 36–99); appdata starts at offset 100.
        $pos      = 100;
        $appFlags = ord($payload[$pos]);
        $pos++;

        $nodeType    = $appFlags & 0x0F;  // lower 4 bits = node type enumeration
        $hasLocation = ($appFlags & 0x10) !== 0;
        $hasFeature1 = ($appFlags & 0x20) !== 0;
        $hasFeature2 = ($appFlags & 0x40) !== 0;
        $hasName     = ($appFlags & 0x80) !== 0;

        $lat = 0;
        $lon = 0;
        if ($hasLocation) {
            if (strlen($payload) < $pos + 8) return null;

            $lat = static::unpackSignedInt32LE($payload, $pos);
            $pos += 4;
            $lon = static::unpackSignedInt32LE($payload, $pos);
            $pos += 4;
        }

        // Skip reserved feature fields; treat a truncated payload as malformed.
        if ($hasFeature1) {
            if (strlen($payload) < $pos + 2) return null;
            $pos += 2;
        }
        if ($hasFeature2) {
            if (strlen($payload) < $pos + 2) return null;
            $pos += 2;
        }

        $name = '';
        if ($hasName && strlen($payload) > $pos) {
            // The name occupies the rest of the appdata; strip any trailing null bytes.
            $name = rtrim(substr($payload, $pos), "\0");
        }

        // Deduplicate by the decoded ADVERT payload rather than the bridge's outer
        // packet hash. The payload is stable across repeated receptions of the same
        // broadcast while still changing when the node emits a new advertisement.
        $packetHash = strtolower(substr(hash('sha256', $payload), 0, 16));

        $serverTimestampMs = intval(floor(microtime(true) * 1000));

        return array(
            "type"      => "ADV",
            "reporter"  => $reporter,
            "hash"      => $packetHash,
            "hash_size" => $hashSize,
            "scope"     => $scope,
            "route_type" => $routeType,
            "contact"   => array(
                "pubkey" => $pubkey,
                "name"   => $name,
                "lat"    => $lat,
                "lon"    => $lon,
                "type"   => $nodeType,
                "flags"  => $appFlags,
            ),
            "time" => array(
                "sender" => $senderTimestampMs,
                "local"  => $serverTimestampMs,
                "server" => $serverTimestampMs,
            ),
            "snr"     => $snr,
            "message" => array(
                "path" => $path,
            ),
            "_mqtt" => $mqttMeta,
        );
    }
    /**
     * Attempt to decrypt a binary MeshCore GRP_TXT packet into the PUB structured
     * format expected by MeshLog::insertForReporter().
     *
     * GRP_TXT payload layout (from MeshCore Packet.h / Mesh.cpp):
     *   channel_hash  (1 byte)   SHA256(PSK_bytes)[0] — plaintext channel identifier
     *   mac           (2 bytes)  HMAC-SHA256(secret_32, ciphertext) truncated to 2 bytes
     *   ciphertext    (N*16 B)   AES-128-ECB( secret[0:16], plaintext_zero_padded )
     *
     * Decrypted plaintext layout:
     *   timestamp     (4 bytes, LE uint32, Unix seconds)
     *   flags         (1 byte, 0 = TXT_TYPE_PLAIN)
     *   text          (variable, "SenderName: message\0", null-terminated)
     *
     * @param  string $rawHex    Hex-encoded full packet bytes.
     * @param  string $path      Normalised routing path.
     * @param  int    $hashSize  Path-hash byte length.
     * @param  int    $snr       SNR from meshcoretomqtt.
     * @param  int    $timestamp Server receive time in milliseconds (fallback).
     * @param  string $reporter  Reporter public-key string.
     * @param  array  $data      Decoded meshcoretomqtt JSON.
     * @param  array  $mqttMeta  MQTT metadata from extractMetadata().
     * @param  array  $channels  Array of MeshLogChannel objects with PSK set.
     * @return array|null        PUB data array for insertForReporter(), or null on failure.
     */
    private static function decodeGroupPacket($rawHex, $path, $hashSize, $scope, $routeType, $snr, $timestamp, $reporter, $data, $mqttMeta, $channels) {
        $bytes = hex2bin($rawHex);
        $payload = static::extractPayloadBytes($bytes);
        if ($payload === null) return null;

        // Minimum: channel_hash(1) + mac(2) + one AES block(16) = 19 bytes
        if (strlen($payload) < 19) return null;

        $channelHashByte = ord($payload[0]);
        $mac             = substr($payload, 1, 2);
        $ciphertext      = substr($payload, 3);

        // Ciphertext must be a multiple of the AES-128 block size (16 bytes).
        if (strlen($ciphertext) % 16 !== 0) {
            error_log(sprintf('[GRP_TXT] ciphertext length %d is not a multiple of 16, raw payload_len=%d', strlen($ciphertext), strlen($payload)));
            return null;
        }

        // meshcoretomqtt exposes the radio's outer packet hash, but for encrypted
        // group packets the most stable deduplication key is the decrypted plaintext
        // plus channel identity. That avoids duplicate feed entries when the bridge
        // reports the same semantic message with different outer packet hashes.
        $packetHash = '';

        foreach ($channels as $channel) {
            $pskB64 = trim($channel->psk ?? '');
            $channelName = trim($channel->name ?? '');

            if ($pskB64 !== '') {
                // Accept both hex (32/64 hex chars = 16/32 bytes) and base64 encoded PSKs.
                // LetsMesh and other tools display PSKs as hex; base64 is the legacy format.
                if (preg_match('/^[0-9A-Fa-f]+$/', $pskB64) && (strlen($pskB64) === 32 || strlen($pskB64) === 64)) {
                    $pskBytes = hex2bin($pskB64);
                    if ($pskBytes === false) {
                        error_log('[GRP_TXT] channel "' . $channelName . '": hex2bin of PSK failed');
                        continue;
                    }
                } else {
                    $pskBytes = base64_decode($pskB64, true);
                    if ($pskBytes === false) {
                        error_log('[GRP_TXT] channel "' . $channelName . '": base64_decode of PSK failed');
                        continue;
                    }
                }
                $pskLen = strlen($pskBytes);
                if ($pskLen !== 16 && $pskLen !== 32) {
                    error_log('[GRP_TXT] channel "' . $channelName . '": PSK length ' . $pskLen . ' is not 16 or 32 (provide as 32/64 hex chars or base64)');
                    continue;
                }
            } elseif (strtolower($channelName) === 'public' || strtolower($channel->hash ?? '') === '11') {
                // MeshCore default public channel: fixed, well-known 128-bit key.
                $pskBytes = hex2bin(static::PUBLIC_GROUP_PSK_HEX);
                $pskLen = 16;
            } elseif ($channelName !== '' && $channelName[0] === '#') {
                // Public hashtag channel: PSK = first 16 bytes of SHA-256(channel_name_utf8).
                // MeshCore companion protocol: key = sha256(name)[0:16] (128-bit key).
                // channel.secret = [PSK_16_bytes][zeros_16_bytes] in the firmware.
                $pskBytes = substr(hash('sha256', $channelName, true), 0, 16);
                $pskLen = 16;
            } else {
                error_log('[GRP_TXT] channel "' . $channelName . '": no PSK and name does not start with #, skipping');
                continue;
            }

            // Channel hash test: SHA256(pskBytes, pskLen)[0] must equal the header byte.
            // MeshCore BaseChatMesh::addChannel/setChannel: hash = SHA256(secret, len) where
            // len == 16 for 128-bit keys (e.g. hashtag channels), 32 for 256-bit keys.
            $hashByte = ord(hash('sha256', $pskBytes, true)[0]);
            if ($hashByte !== $channelHashByte) continue;

            // channel.secret in MeshCore = PSK zero-padded to PUB_KEY_SIZE (32 bytes).
            // Both HMAC and AES use this 32-byte value (AES uses only the first 16 bytes).
            $secret = str_pad($pskBytes, 32, "\0");

            // Verify HMAC-SHA256(secret_32, ciphertext) truncated to 2 bytes.
            $computedMac = substr(hash_hmac('sha256', $ciphertext, $secret, true), 0, 2);
            if ($computedMac !== $mac) {
                error_log(sprintf('[GRP_TXT] channel "%s": HMAC mismatch (computed=%s packet=%s)',
                    $channelName, strtoupper(bin2hex($computedMac)), strtoupper(bin2hex($mac))));
                continue;
            }

            // HMAC verified — decrypt with AES-128-ECB using the first 16 bytes of secret.
            // Current MeshCore Utils::encrypt/decrypt are block-wise AES-128 with zero padding,
            // i.e. ECB semantics with no CBC chaining and no PKCS7 unpadding.
            $decrypted = openssl_decrypt(
                $ciphertext,
                'AES-128-ECB',
                substr($secret, 0, 16),
                OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING
            );
            if ($decrypted === false || strlen($decrypted) < 6) continue;

            // Decrypted format: [timestamp(4)][flags(1)]["SenderName: message\0"...]
            $tsArr            = unpack('V', substr($decrypted, 0, 4));
            $senderTimestampS = intval($tsArr[1]);
            $txtTypeByte      = ord($decrypted[4]);
            $txtType          = ($txtTypeByte >> 2) & 0x3F;

            // Only handle plain text (TXT_TYPE_PLAIN = 0).
            if ($txtType !== 0) continue;

            // Extract text portion after [timestamp(4)][flags(1)] and strip trailing zero padding.
            $textRaw = rtrim(substr($decrypted, 5), "\0");
            if ($textRaw === '') continue;

            // Deduplicate decrypted group messages by semantic content rather than
            // the bridge's outer packet hash. The plaintext contains the sender name
            // and message body; combining it with the channel hash and sender
            // timestamp keeps identical repeat receptions grouped together.
            $packetHash = substr(
                hash('sha256', $channel->hash . ':' . $senderTimestampS . ':' . $textRaw),
                0,
                16
            );

            // Validate sender clock; fall back to server receive time if unreasonable.
            $senderTimestampMs = ($senderTimestampS >= static::MIN_VALID_UNIX_TIMESTAMP)
                ? $senderTimestampS * 1000
                : $timestamp;

            $serverTimestampMs = intval(floor(microtime(true) * 1000));

            return array(
                'type'     => 'PUB',
                'reporter' => $reporter,
                'hash'     => $packetHash,
                'hash_size' => $hashSize,
                'scope'    => $scope,
                'route_type' => $routeType,
                'channel'  => array(
                    'hash' => $channel->hash,
                    'name' => $channel->name,
                ),
                'message'  => array(
                    'text' => $textRaw,
                    'path' => $path,
                ),
                'contact' => array(
                    'pubkey' => '',   // sender pubkey not available in flood packets
                ),
                'time' => array(
                    'sender' => $senderTimestampMs,
                    'local'  => $serverTimestampMs,
                    'server' => $serverTimestampMs,
                ),
                'snr'   => $snr,
                '_mqtt' => $mqttMeta,
            );
        }

        return null; // no matching channel / MAC verification failed
    }

    /**
     * Build a RAW packet return array with decoded metadata stored as JSON payload.
     *
     * @param  array  $layout    Packet layout from extractPacketLayout() (contains 'header').
     * @param  string $path      Normalised routing path.
     * @param  int    $hashSize  Path-hash byte length.
     * @param  int|null $scope   Transport scope code or null.
     * @param  int    $snr       SNR value.
     * @param  int    $timestamp Local timestamp in milliseconds.
     * @param  string $reporter  Reporter public key string.
     * @param  array  $mqttMeta  MQTT metadata from extractMetadata().
     * @param  string $payloadJson JSON-encoded decoded metadata to store as payload bytes.
     * @return array             RAW data array for insertForReporter().
     */
    private static function buildRawReturn($layout, $path, $hashSize, $scope, $routeType, $snr, $timestamp, $reporter, $mqttMeta, $payloadJson) {
        $serverTimestampMs = intval(floor(microtime(true) * 1000));
        return array(
            "type" => "RAW",
            "reporter" => $reporter,
            "time" => array(
                "local" => $timestamp,
                "sender" => $timestamp,
                "server" => $serverTimestampMs,
            ),
            "packet" => array(
                "header" => $layout['header'],
                "path" => $path,
                "payload" => strtoupper(bin2hex($payloadJson)),
                "snr" => $snr,
                "decoded" => true,
                "hash_size" => $hashSize,
                "scope" => $scope,
                "route_type" => $routeType,
            ),
            "_mqtt" => $mqttMeta,
        );
    }

    /**
     * Decode an ACK packet (payload_type = 0x03).
     * Payload: 4-byte CRC checksum (LE uint32) of the acknowledged message.
     */
    private static function decodeAckPacket($rawHex, $path, $hashSize, $scope, $routeType, $snr, $timestamp, $reporter, $data, $mqttMeta) {
        $bytes = hex2bin($rawHex);
        $layout = static::extractPacketLayout($bytes);
        if ($layout === null) return null;

        $payloadBytes = substr($bytes, $layout['payload_offset']);
        if (strlen($payloadBytes) < 4) return null;

        $crc = unpack('V', substr($payloadBytes, 0, 4))[1];

        return static::buildRawReturn($layout, $path, $hashSize, $scope, $routeType, $snr, $timestamp, $reporter, $mqttMeta,
            json_encode(array('crc' => sprintf('%08X', $crc)))
        );
    }

    /**
     * Decode a RETURNED_PATH packet (payload_type = 0x08).
     * Payload: path_length(1) + path_hashes(path_length bytes, 1 byte each) + extra_type(1) + extra(variable)
     */
    private static function decodeReturnedPathPacket($rawHex, $path, $hashSize, $scope, $routeType, $snr, $timestamp, $reporter, $data, $mqttMeta) {
        $bytes = hex2bin($rawHex);
        $layout = static::extractPacketLayout($bytes);
        if ($layout === null) return null;

        $payloadBytes = substr($bytes, $layout['payload_offset']);
        // Minimum: path_length(1) + extra_type(1) = 2 bytes
        if (strlen($payloadBytes) < 2) return null;

        $pos = 0;
        $returnedPathLen = ord($payloadBytes[$pos]);
        $pos++;

        if (strlen($payloadBytes) < 1 + $returnedPathLen + 1) return null;

        $returnedPathHashes = array();
        for ($i = 0; $i < $returnedPathLen; $i++) {
            $returnedPathHashes[] = strtolower(bin2hex($payloadBytes[$pos]));
            $pos++;
        }

        $extraType = ord($payloadBytes[$pos]);

        return static::buildRawReturn($layout, $path, $hashSize, $scope, $routeType, $snr, $timestamp, $reporter, $mqttMeta,
            json_encode(array(
                'returned_path' => $returnedPathHashes,
                'extra_type' => $extraType,
            ))
        );
    }

    /**
     * Decode a CONTROL packet (payload_type = 0x0B).
     * Payload: flags(1, upper 4 bits = sub_type) + data(variable, unencrypted)
     * Known sub_types: 8 = DISCOVER_REQ, 9 = DISCOVER_RESP
     */
    private static function decodeControlPacket($rawHex, $path, $hashSize, $scope, $routeType, $snr, $timestamp, $reporter, $data, $mqttMeta) {
        $bytes = hex2bin($rawHex);
        $layout = static::extractPacketLayout($bytes);
        if ($layout === null) return null;

        $payloadBytes = substr($bytes, $layout['payload_offset']);
        if (strlen($payloadBytes) < 1) return null;

        $flags   = ord($payloadBytes[0]);
        $subType = ($flags >> 4) & 0x0F;  // upper 4 bits

        $meta = array('sub_type' => $subType, 'flags' => $flags);

        // DISCOVER_REQ (sub_type=8): flags(1) + type_filter(1) + tag(4) + since(4, optional)
        if ($subType === 8 && strlen($payloadBytes) >= 6) {
            $meta['prefix_only']  = ($flags & 0x01) !== 0;
            $meta['type_filter']  = ord($payloadBytes[1]);
            $meta['tag']          = strtoupper(bin2hex(substr($payloadBytes, 2, 4)));
            if (strlen($payloadBytes) >= 10) {
                $meta['since'] = unpack('V', substr($payloadBytes, 6, 4))[1];
            }
        // DISCOVER_RESP (sub_type=9): flags(1) + snr(1, signed SNR*4) + tag(4) + pubkey(8 or 32)
        } elseif ($subType === 9 && strlen($payloadBytes) >= 6) {
            $meta['node_type']    = $flags & 0x0F;
            $snrRaw               = ord($payloadBytes[1]);
            $meta['discover_snr'] = ($snrRaw >= 128) ? intval($snrRaw - 256) : intval($snrRaw);
            $meta['tag']          = strtoupper(bin2hex(substr($payloadBytes, 2, 4)));
            $pubkeyLen = strlen($payloadBytes) - 6;
            if ($pubkeyLen === 8 || $pubkeyLen === 32) {
                $meta['pubkey_prefix'] = strtoupper(bin2hex(substr($payloadBytes, 6, $pubkeyLen)));
            }
        }

        return static::buildRawReturn($layout, $path, $hashSize, $scope, $routeType, $snr, $timestamp, $reporter, $mqttMeta,
            json_encode($meta)
        );
    }

    /**
     * Decode an ANON_REQ packet (payload_type = 0x07).
     * Payload: dest_hash(1) + sender_pubkey(32) + mac(2) + ciphertext(variable, encrypted)
     * The destination hash and sender public key are in plaintext and can be extracted.
     */
    private static function decodeAnonReqPacket($rawHex, $path, $hashSize, $scope, $routeType, $snr, $timestamp, $reporter, $data, $mqttMeta) {
        $bytes = hex2bin($rawHex);
        $layout = static::extractPacketLayout($bytes);
        if ($layout === null) return null;

        $payloadBytes = substr($bytes, $layout['payload_offset']);
        // Minimum: dest_hash(1) + sender_pubkey(32) + mac(2) = 35 bytes
        if (strlen($payloadBytes) < 35) return null;

        $destHash     = strtolower(bin2hex($payloadBytes[0]));
        $senderPubkey = strtoupper(bin2hex(substr($payloadBytes, 1, 32)));

        return static::buildRawReturn($layout, $path, $hashSize, $scope, $routeType, $snr, $timestamp, $reporter, $mqttMeta,
            json_encode(array(
                'dest_hash'     => $destHash,
                'sender_pubkey' => $senderPubkey,
            ))
        );
    }

    /**
     * Decode a REQ or RESPONSE packet (payload_type 0x00 or 0x01).
     * Payload: dest_hash(1) + src_hash(1) + mac(2) + ciphertext(variable, encrypted)
     * The destination and source hashes are in plaintext; the body is encrypted.
     *
     * @param int $packetType  PAYLOAD_TYPE_REQ (0) or PAYLOAD_TYPE_RESPONSE (1).
     */
    private static function decodeDirectFramePacket($rawHex, $path, $hashSize, $scope, $routeType, $snr, $timestamp, $reporter, $data, $mqttMeta, $packetType) {
        $bytes = hex2bin($rawHex);
        $layout = static::extractPacketLayout($bytes);
        if ($layout === null) return null;

        $payloadBytes = substr($bytes, $layout['payload_offset']);
        // Minimum: dest_hash(1) + src_hash(1) + mac(2) = 4 bytes
        if (strlen($payloadBytes) < 4) return null;

        $destHash = strtolower(bin2hex($payloadBytes[0]));
        $srcHash  = strtolower(bin2hex($payloadBytes[1]));

        return static::buildRawReturn($layout, $path, $hashSize, $scope, $routeType, $snr, $timestamp, $reporter, $mqttMeta,
            json_encode(array(
                'dest_hash' => $destHash,
                'src_hash'  => $srcHash,
            ))
        );
    }
}

?>
