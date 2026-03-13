<?php

class MeshLogMqttDecoder {
    // At least 4 hex characters (2 bytes) so tiny placeholders (for example "AA") are rejected.
    const MIN_REPORTER_KEY_LENGTH = 4;
    const TOPIC_TYPES = array('status', 'packets', 'debug');

    public static function decode($topic, $payload) {
        $data = json_decode($payload, true);
        if (!is_array($data)) return null;

        if (($data['type'] ?? null) != 'PACKET') return null;

        $mqttMeta = static::extractMetadata($topic, $data);
        $topicReporter = $mqttMeta['topic_reporter'];
        $payloadReporter = $mqttMeta['payload_reporter'];
        $reporter = $mqttMeta['attempted_reporter'];
        $reporterSource = $mqttMeta['reporter_source'];
        $raw = preg_replace('/[^0-9A-Fa-f]/', '', strtoupper($data['raw'] ?? ''));
        if (strlen($raw) % 2 !== 0) return null;

        if (!$reporter || !$raw) return null;

        $path = static::decodePath($data['path'] ?? '');
        $hashSize = static::decodeHashSize($path);
        $packetType = intval($data['packet_type'] ?? 0);
        $snr = intval($data['SNR'] ?? 0);

        $timestamp = floor(microtime(true) * 1000);
        // meshcoretomqtt uses ISO 8601 timestamp strings (datetime.now().isoformat()).
        if (isset($data['timestamp'])) {
            $ts = strtotime($data['timestamp']);
            if ($ts) {
                $timestamp = intval($ts) * 1000;
            }
        }

        return array(
            "type" => "RAW",
            "reporter" => $reporter,
            "time" => array(
                "local" => $timestamp,
                "sender" => $timestamp,
                "server" => $timestamp
            ),
            "packet" => array(
                "header" => $packetType,
                "path" => $path,
                "payload" => $raw,
                "snr" => $snr,
                "decoded" => false,
                "hash_size" => $hashSize
            ),
            "_mqtt" => $mqttMeta,
        );
    }

    public static function extractReporterFromTopic($topic) {
        if (!is_string($topic) || $topic === '') return '';

        $parts = explode('/', trim($topic, "/ \t\n\r\0\x0B"));
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
}

?>
