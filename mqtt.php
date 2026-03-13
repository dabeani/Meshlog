<?php

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit;
}

require_once "config.php";
require_once "lib/meshlog.class.php";
require_once "lib/meshlog.mqtt_client.class.php";

$mqttConfig = $config['mqtt'] ?? array();
$enabled = boolval($mqttConfig['enabled'] ?? false);
if (!$enabled) {
    echo "MQTT disabled. Set \$config['mqtt']['enabled'] = true\n";
    exit(0);
}

$meshlog = new MeshLog($config['db']);
$client = new MeshLogMqttClient($mqttConfig);

try {
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
} catch (Throwable $e) {
    fwrite(STDERR, "MQTT worker error: " . $e->getMessage() . "\n");
    exit(1);
}

?>
