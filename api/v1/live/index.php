<?php
/**
 * iOS Live Feed Endpoint (Polling)
 * GET /api/v1/live?since_ms=1234567890&type=ADV,MSG,PUB
 * Returns only packets since the given timestamp for efficient polling
 */
require_once "../../../lib/meshlog.class.php";
require_once "../../../config.php";
include "../utils.php";

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

$meshlog = new MeshLog(array_merge($config['db'], array('ntp' => $config['ntp'] ?? array())));
$err = $meshlog->getError();

if ($err) {
    http_response_code(500);
    echo json_encode(['error' => $err]);
    exit;
}

$since_ms = intval(getParam('since_ms', 0));
$types = explode(',', getParam('types', 'ADV,MSG,PUB,RAW'));
$types = array_filter(array_map('trim', $types));
$limit = min(intval(getParam('limit', 50)), 200);

// Get advertisements since timestamp
$advertisements = $meshlog->getAdvertisementsQuick([
    'after_ms' => $since_ms,
    'count' => $limit,
], true);

// Get direct messages
$messages = $meshlog->getDirectMessagesQuick([
    'after_ms' => $since_ms,
    'count' => $limit,
], true);

// Get channel messages
$channel_messages = $meshlog->getChannelMessagesQuick([
    'after_ms' => $since_ms,
    'count' => $limit,
], true);

// Get raw packets
$raw_packets = $meshlog->getRawPacketsQuick([
    'after_ms' => $since_ms,
    'count' => $limit,
], true);

// Combine and sort by timestamp
$combined = [];

if (in_array('ADV', $types) && isset($advertisements['advertisements'])) {
    foreach ($advertisements['advertisements'] as $adv) {
        $combined[] = array_merge(['type' => 'ADV'], $adv);
    }
}

if (in_array('MSG', $types) && isset($messages['direct_messages'])) {
    foreach ($messages['direct_messages'] as $msg) {
        $combined[] = array_merge(['type' => 'MSG'], $msg);
    }
}

if (in_array('PUB', $types) && isset($channel_messages['channel_messages'])) {
    foreach ($channel_messages['channel_messages'] as $cmsg) {
        $combined[] = array_merge(['type' => 'PUB'], $cmsg);
    }
}

if (in_array('RAW', $types) && isset($raw_packets['raw_packets'])) {
    foreach ($raw_packets['raw_packets'] as $raw) {
        $combined[] = array_merge(['type' => 'RAW'], $raw);
    }
}

// Sort by received_at descending (newest first)
usort($combined, function($a, $b) {
    $timeA = strtotime($a['received_at'] ?? $a['sent_at'] ?? 0);
    $timeB = strtotime($b['received_at'] ?? $b['sent_at'] ?? 0);
    return $timeB <=> $timeA;
});

echo json_encode([
    'packets' => array_slice($combined, 0, $limit),
    'timestamp_ms' => intval(microtime(true) * 1000),
    'count' => count($combined)
]);

?>
