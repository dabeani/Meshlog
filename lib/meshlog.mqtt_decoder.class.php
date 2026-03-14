<?php

class MeshLogMqttDecoder {
    // At least 4 hex characters (2 bytes) so tiny placeholders (for example "AA") are rejected.
    const MIN_REPORTER_KEY_LENGTH = 4;
    const TOPIC_TYPES = array('status', 'packets', 'debug');

    // Structured types sent by firmware over MQTT (same JSON format as HTTP ingest).
    const STRUCTURED_TYPES = array('ADV', 'MSG', 'PUB', 'SYS', 'TEL', 'RAW');

    // MeshCore binary payload types (bits 2-5 of the packet header byte).
    const PAYLOAD_TYPE_ADVERT  = 4;  // PAYLOAD_TYPE_ADVERT  (0x04) - node advertisement, unencrypted
    const PAYLOAD_TYPE_TXT_MSG = 2;  // PAYLOAD_TYPE_TXT_MSG (0x02) - direct text message, encrypted
    const PAYLOAD_TYPE_GRP_TXT = 5;  // PAYLOAD_TYPE_GRP_TXT (0x05) - group text message, encrypted

    // MeshCore route types (bits 0-1 of the packet header byte).
    const ROUTE_TYPE_TRANSPORT_FLOOD  = 0x00;  // flood + transport codes (4 extra bytes)
    const ROUTE_TYPE_FLOOD            = 0x01;  // plain flood
    const ROUTE_TYPE_DIRECT           = 0x02;  // direct routing
    const ROUTE_TYPE_TRANSPORT_DIRECT = 0x03;  // direct + transport codes (4 extra bytes)

    // Minimum Unix timestamp (2020-01-01) used to detect invalid/unset device clocks.
    const MIN_VALID_UNIX_TIMESTAMP = 1577836800;

    public static function decode($topic, $payload) {
        $data = json_decode($payload, true);
        if (!is_array($data)) return null;

        $typeRaw = isset($data['type']) ? trim(strval($data['type'])) : '';
        $type = ($typeRaw === '') ? null : strtoupper($typeRaw);

        $mqttMeta = static::extractMetadata($topic, $data);
        $reporter = $mqttMeta['attempted_reporter'];

        if (!$reporter) return null;

        // Binary PACKET from meshcoretomqtt: attempt structured decode first,
        // then fall back to storing as a RAW packet.
        if ($type === 'PACKET') {
            $raw = preg_replace('/[^0-9A-Fa-f]/', '', strtoupper($data['raw'] ?? ''));
            if (strlen($raw) % 2 !== 0 || !$raw) return null;

            $bytes = hex2bin($raw);
            $packet = static::extractPacket($bytes);
            $path = $packet['path'] ?? static::decodePath($data['path'] ?? '');
            $hashSize = $packet['hash_size'] ?? static::decodeHashSize($path);
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
                    $raw, $path, $hashSize, $snr, $timestamp, $reporter, $data, $mqttMeta
                );
                if ($decoded !== null) return $decoded;
            }

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
                    "hash_size" => $hashSize
                ),
                "_mqtt" => $mqttMeta,
            );
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
            $data['_mqtt'] = $mqttMeta;
            return $data;
        }

        return null;
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

        return array(
            "topic" => is_string($topic) ? $topic : '',
            "topic_reporter" => $topicReporter,
            "payload_reporter" => $payloadReporter,
            "reporter_source" => $topicReporter ? 'topic' : ($payloadReporter ? 'payload' : 'unknown'),
            "topic_payload_mismatch" => boolval($topicReporter && $payloadReporter && $topicReporter !== $payloadReporter),
            "attempted_reporter" => $topicReporter ?: $payloadReporter,
        );
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

            $ts = strtotime($trimmed);
            if ($ts !== false && $ts >= 0) {
                return intval($ts) * 1000;
            }
        }

        return $fallback;
    }

    private static function extractPacket($bytes) {
        $hashSize = 1;
        $payload = static::extractPayloadBytes($bytes, $hashSize);
        if ($payload === null || strlen($bytes) < 2) return null;

        $headerByte = ord($bytes[0]);
        $routeType = $headerByte & 0x03;
        $hasTransport = ($routeType === static::ROUTE_TYPE_TRANSPORT_FLOOD ||
                         $routeType === static::ROUTE_TYPE_TRANSPORT_DIRECT);
        $pathLenOffset = $hasTransport ? 5 : 1;
        $pathLenByte = ord($bytes[$pathLenOffset]);
        $pathHopCount = $pathLenByte & 0x3F;
        $pathOffset = $pathLenOffset + 1;
        $pathByteLen = $pathHopCount * $hashSize;

        return array(
            'header' => $headerByte,
            'path' => static::decodePathBytes(substr($bytes, $pathOffset, $pathByteLen), $hashSize),
            'payload' => strtoupper(bin2hex($payload)),
            'hash_size' => $hashSize,
        );
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
        if (strlen($bytes) < 2) return null;

        $headerByte = ord($bytes[0]);
        $routeType  = $headerByte & 0x03;

        // TRANSPORT_FLOOD and TRANSPORT_DIRECT carry 4 extra transport-code bytes
        // after the header before the path_len byte.
        $hasTransport   = ($routeType === static::ROUTE_TYPE_TRANSPORT_FLOOD ||
                           $routeType === static::ROUTE_TYPE_TRANSPORT_DIRECT);
        $pathLenOffset  = $hasTransport ? 5 : 1;

        if (strlen($bytes) <= $pathLenOffset) return null;

        // The path_len byte encodes both hash size and hop count:
        //   bits 6-7: hash_size - 1  (value 3 = reserved/invalid)
        //   bits 0-5: hop count
        $pathLenByte  = ord($bytes[$pathLenOffset]);
        $pathHashSizeBits = ($pathLenByte >> 6);
        if ($pathHashSizeBits > 2) return null;
        $pathHashSize = $pathHashSizeBits + 1;  // 1, 2, or 3 bytes per hop
        $pathHopCount = $pathLenByte & 0x3F;       // number of hops
        $pathByteLen  = $pathHopCount * $pathHashSize;
        $payloadOffset = $pathLenOffset + 1 + $pathByteLen;

        $hashSize = $pathHashSize;

        if (strlen($bytes) <= $payloadOffset) return null;

        return substr($bytes, $payloadOffset);
    }

    private static function decodePathBytes($pathBytes, $hashSize) {
        if (!is_string($pathBytes) || $pathBytes === '' || $hashSize < 1) return '';

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
    private static function decodeAdvertPacket($rawHex, $path, $hashSize, $snr, $timestamp, $reporter, $data, $mqttMeta) {
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

        // Deduplication hash: use the meshcoretomqtt "hash" field (16 hex chars = 8 bytes,
        // fits advertisements.hash varchar(16)).  Fall back to a combination of the first
        // 4 bytes of the public key and the sender timestamp so that consecutive ADV
        // broadcasts from the same node produce distinct hashes while multiple reporters
        // hearing the exact same broadcast still produce the same hash.
        $rawHash    = preg_replace('/[^0-9a-fA-F]/', '', $data['hash'] ?? '');
        $packetHash = strtolower(substr($rawHash, 0, 16));
        if ($packetHash === '') {
            $packetHash = strtolower(
                substr(bin2hex(substr($payload, 0, 4)), 0, 8) .
                sprintf('%08x', $senderTimestampSec)
            );
        }

        $serverTimestampMs = intval(floor(microtime(true) * 1000));

        return array(
            "type"      => "ADV",
            "reporter"  => $reporter,
            "hash"      => $packetHash,
            "hash_size" => $hashSize,
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
}

?>
