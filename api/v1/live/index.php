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
$types = explode(',', getParam('types', 'ADV,MSG,PUB,RAW,TEL,SYS'));
$types = array_filter(array_map('trim', $types));
$limit = min(intval(getParam('limit', 50)), 200);

function extractList($result, $legacyKey) {
    if (!is_array($result)) {
        return array();
    }

    if (isset($result['objects']) && is_array($result['objects'])) {
        return $result['objects'];
    }

    if (isset($result[$legacyKey]) && is_array($result[$legacyKey])) {
        return $result[$legacyKey];
    }

    // Some endpoints may return plain indexed arrays.
    if (array_keys($result) === range(0, count($result) - 1)) {
        return $result;
    }

    return array();
}

// Get advertisements since timestamp
$advertisements = $meshlog->getAdvertisementsQuick([
    'after_ms' => $since_ms,
    'count' => $limit,
]);

// Get direct messages
$messages = $meshlog->getDirectMessagesQuick([
    'after_ms' => $since_ms,
    'count' => $limit,
]);

// Get channel messages
$channel_messages = $meshlog->getChannelMessagesQuick([
    'after_ms' => $since_ms,
    'count' => $limit,
]);

// Get raw packets
$raw_packets = $meshlog->getRawPackets([
    'after_ms' => $since_ms,
    'count' => $limit,
]);

// Get telemetry
$telemetry = $meshlog->getTelemetry([
    'after_ms' => $since_ms,
    'count' => $limit,
]);

// Get system reports
$system_reports = $meshlog->getSystemReports([
    'after_ms' => $since_ms,
    'count' => $limit,
]);

$advertisementRows = extractList($advertisements, 'advertisements');
$messageRows = extractList($messages, 'direct_messages');
$channelMessageRows = extractList($channel_messages, 'channel_messages');
$rawPacketRows = extractList($raw_packets, 'raw_packets');
$telemetryRows = extractList($telemetry, 'telemetry');
$systemReportRows = extractList($system_reports, 'system_reports');

// Combine and sort by timestamp
$combined = [];

if (in_array('ADV', $types)) {
    foreach ($advertisementRows as $adv) {
        $packet = $adv;
        $packet['type'] = 'ADV';
        $combined[] = $packet;
    }
}

if (in_array('MSG', $types)) {
    foreach ($messageRows as $msg) {
        $packet = $msg;
        $packet['type'] = 'MSG';
        $combined[] = $packet;
    }
}

if (in_array('PUB', $types)) {
    foreach ($channelMessageRows as $cmsg) {
        $packet = $cmsg;
        $packet['type'] = 'PUB';
        $combined[] = $packet;
    }
}

if (in_array('RAW', $types)) {
    foreach ($rawPacketRows as $raw) {
        $packet = $raw;
        $packet['type'] = 'RAW';
        $combined[] = $packet;
    }
}

if (in_array('TEL', $types)) {
    foreach ($telemetryRows as $tel) {
        $packet = $tel;
        $packet['type'] = 'TEL';
        $combined[] = $packet;
    }
}

if (in_array('SYS', $types)) {
    foreach ($systemReportRows as $sys) {
        $packet = $sys;
        $packet['type'] = 'SYS';
        $combined[] = $packet;
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
