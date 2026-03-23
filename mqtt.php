<?php

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit;
}

const MQTT_RECONNECT_DELAY_SECONDS = 5;

require_once "config.php";
require_once "lib/meshlog.class.php";
require_once "lib/meshlog.mqtt_client.class.php";

if (!isset($config['db']) || !is_array($config['db'])) {
    fwrite(STDERR, "Invalid config.php: missing db configuration\n");
    exit(1);
}

$mqttConfig = $config['mqtt'] ?? array();
$enabled = boolval($mqttConfig['enabled'] ?? false);
$debug = boolval($mqttConfig['debug'] ?? false);
if (!$enabled) {
    echo "MQTT disabled. Set \$config['mqtt']['enabled'] = true\n";
    exit(0);
}

function mqttLog($level, $message) {
    echo "[" . date('c') . "][$level] $message\n";
}

function mqttDebug($enabled, $message) {
    if (!$enabled) return;
    mqttLog("DEBUG", $message);
}

function mqttGetEffectiveReporter($mqttMeta) {
    if (($mqttMeta['attempted_reporter'] ?? '') !== '') return $mqttMeta['attempted_reporter'];
    if (($mqttMeta['topic_reporter'] ?? '') !== '') return $mqttMeta['topic_reporter'];
    if (($mqttMeta['payload_reporter'] ?? '') !== '') return $mqttMeta['payload_reporter'];

    return 'unknown';
}

while (true) {
    try {
        // Create a fresh MeshLog (and therefore a fresh PDO connection) on every
        // reconnect cycle.  If the MySQL connection goes stale (e.g. MySQL
        // wait_timeout after 8+ hours of inactivity) the old PDO handle throws on
        // the first query, and a new MeshLog here ensures the next cycle starts
        // with a healthy connection instead of looping in a permanent error state.
        $meshlog = new MeshLog(array_merge($config['db'], array('ntp' => $config['ntp'] ?? array())));
        $client = new MeshLogMqttClient($mqttConfig);
        mqttLog(
            "INFO",
            "Connecting to MQTT broker transport=" . ($mqttConfig['transport'] ?? 'tcp') .
            " host=" . ($mqttConfig['host'] ?? '') .
            " port=" . strval($mqttConfig['port'] ?? 1883) .
            " topic=" . ($mqttConfig['topic'] ?? 'meshcore/+/+/packets')
        );
        $client->connect();
        mqttLog("INFO", "Connected. Waiting for packets...");

        $client->loop(function($topic, $payload) use ($meshlog, $debug) {
            mqttDebug($debug, "MQTT message received topic=" . $topic . " bytes=" . strlen($payload));

            // Decode minimal metadata for logging
            $payloadArr = json_decode($payload, true) ?: array();
            $meta = MeshLogMqttDecoder::extractMetadata($topic, $payloadArr);
            $type = strtoupper($payloadArr['type'] ?? (isset($payloadArr['packet_type']) ? 'PACKET' : ''));

            // Add detailed decode logging for debugging encrypted/RAW packets.
            if ($debug) {
                $pktType = isset($payloadArr['packet_type']) ? intval($payloadArr['packet_type']) : null;
                if (isset($payloadArr['type']) && strtoupper($payloadArr['type']) === 'PACKET') {
                    $rawHex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $payloadArr['raw'] ?? ''));
                    $pathStr = $payloadArr['path'] ?? '';
                $rawBytes = intval(strlen($rawHex) / 2);
                mqttDebug($debug, "PACKET detail packet_type=" . var_export($pktType, true) . " path=$pathStr raw_len=" . $rawBytes . " raw_head=" . substr($rawHex, 0, 64));
                } elseif (isset($payloadArr['type'])) {
                    $t = strtoupper($payloadArr['type']);
                    if ($t === 'PUB' || $t === 'MSG') {
                        $chan = $payloadArr['channel']['hash'] ?? ($payloadArr['contact']['pubkey'] ?? '');
                        $msg = $payloadArr['message']['text'] ?? $payloadArr['message'] ?? '';
                        mqttDebug($debug, "$t structured detail channel=$chan msg_len=" . strlen($msg));
                    }
                }
            }

            $result = $meshlog->insertMqtt($topic, $payload);
            $meshlog->maybeAutoPurge();
            $mqttMeta = is_array($result) ? ($result['_mqtt'] ?? array()) : array();

            if ($debug && is_array($mqttMeta)) {
                mqttDebug(
                    $debug,
                    "MQTT reporter resolution reporter=" . mqttGetEffectiveReporter($mqttMeta) .
                    " source=" . ($mqttMeta['reporter_source'] ?? 'unknown') .
                    " topic_reporter=" . ($mqttMeta['topic_reporter'] ?? '') .
                    " payload_reporter=" . ($mqttMeta['payload_reporter'] ?? '') .
                    " mismatch=" . (boolval($mqttMeta['topic_payload_mismatch'] ?? false) ? 'yes' : 'no')
                );
            }

            $insertType = is_array($result) ? ($result['insert_type'] ?? $type) : $type;

            if (is_array($result) && array_key_exists("error", $result)) {
                mqttLog("WARN", "Skipped MQTT message from topic " . $topic . ": " . $result["error"]);
            } else {
                mqttLog("INFO", "Insert OK type={$insertType} reporter=" . ($meta['attempted_reporter'] ?? '') . " bytes=" . strlen($payload));
            }
        });

        fwrite(STDERR, "[" . date('c') . "][ERROR] MQTT connection closed\n");
    } catch (Throwable $e) {
        fwrite(STDERR, "[" . date('c') . "][ERROR] MQTT worker error: " . $e->getMessage() . "\n");
    }

    fwrite(STDERR, "[" . date('c') . "][INFO] Retrying in " . MQTT_RECONNECT_DELAY_SECONDS . " seconds...\n");
    sleep(MQTT_RECONNECT_DELAY_SECONDS);
}

?>
