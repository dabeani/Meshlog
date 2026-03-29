<?php
/**
 * Configuration Endpoint
 * GET /api/v1/config
 * Returns public app configuration for iOS app
 */
require_once "../../../lib/meshlog.class.php";
require_once "../../../config.php";

header('Content-Type: application/json; charset=utf-8');

$meshlog = new MeshLog(array_merge($config['db'], array('ntp' => $config['ntp'] ?? array())));
$err = $meshlog->getError();

if ($err) {
    http_response_code(500);
    echo json_encode(['error' => $err]);
    exit;
}

$mapConfig = $config['map'] ?? array();

echo json_encode([
    'app_version' => '1.0.0',
    'api_version' => 'v1',
    'map' => [
        'default_lat' => floatval($mapConfig['lat'] ?? 51.5074),
        'default_lon' => floatval($mapConfig['lon'] ?? -0.1278),
        'default_zoom' => intval($mapConfig['zoom'] ?? 10),
    ],
    'features' => [
        'live_feed' => true,
        'map' => true,
        'devices' => true,
        'statistics' => true,
        'admin' => true,
    ],
    'polling_interval_seconds' => 5,
    'max_live_items' => 500,
]);

?>
