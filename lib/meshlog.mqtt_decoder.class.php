<?php

class MeshLogMqttDecoder {
    // At least 4 hex characters (2 bytes) so tiny placeholders (for example "AA") are rejected.
    const MIN_REPORTER_KEY_LENGTH = 4;

    public static function decode($topic, $payload) {
        $data = json_decode($payload, true);
        if (!is_array($data)) return null;

        if (($data['type'] ?? null) != 'PACKET') return null;

        $topicReporter = static::extractReporterFromTopic($topic);
        $payloadReporter = preg_replace('/[^0-9A-Fa-f]/', '', strtoupper($data['origin_id'] ?? ''));
        $reporter = $topicReporter ?: $payloadReporter;
        $reporterSource = $topicReporter ? 'topic' : 'payload';
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
            "_mqtt" => array(
                "topic" => $topic,
                "topic_reporter" => $topicReporter,
                "payload_reporter" => $payloadReporter,
                "reporter_source" => $reporterSource,
                "topic_payload_mismatch" => boolval($topicReporter && $payloadReporter && $topicReporter !== $payloadReporter),
            ),
        );
    }

    public static function extractReporterFromTopic($topic) {
        if (!$topic || !is_string($topic)) return '';

        $parts = explode('/', trim($topic));
        if (count($parts) < 2 || strtolower($parts[0]) !== 'meshcore') return '';

        $candidate = strtoupper(trim($parts[1]));
        if ($candidate === '' || $candidate === '+') return '';
        if (!preg_match('/^[0-9A-F]+$/', $candidate)) return '';
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
