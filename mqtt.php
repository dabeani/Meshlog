<?php

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit;
}

require_once "config.php";
require_once "lib/meshlog.class.php";
require_once "lib/meshlog.mqtt_client.class.php";

if (!isset($config['db']) || !is_array($config['db'])) {
    fwrite(STDERR, "Invalid config.php: missing db configuration\n");
    exit(1);
}

$mqttConfig = $config['mqtt'] ?? array();
$enabled = boolval($mqttConfig['enabled'] ?? false);
if (!$enabled) {
    echo "MQTT disabled. Set \$config['mqtt']['enabled'] = true\n";
    exit(0);
}

$meshlog = new MeshLog($config['db']);
$reconnectDelay = 5;

while (true) {
    try {
        $client = new MeshLogMqttClient($mqttConfig);
        echo "Connecting to MQTT broker...\n";
        $client->connect();
        echo "Connected. Waiting for packets...\n";

        $client->loop(function($topic, $payload) use ($meshlog) {
            $result = $meshlog->insertMqtt($topic, $payload);
            if (is_array($result) && array_key_exists("error", $result)) {
                echo "Skipped MQTT message from topic $topic: " . $result["error"] . "\n";
            } else if ($result === false) {
                echo "Skipped MQTT message from topic $topic\n";
            }
        });

        throw new RuntimeException("MQTT connection closed");
    } catch (Throwable $e) {
        fwrite(STDERR, "MQTT worker error: " . $e->getMessage() . "\n");
        fwrite(STDERR, "Retrying in {$reconnectDelay} seconds...\n");
    }

    sleep($reconnectDelay);
}

?>
