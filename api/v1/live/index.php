<?php
/**
 * iOS Live Feed Endpoint (Polling)
 * GET /api/v1/live?since_ms=1234567890&type=ADV,MSG,PUB
 * Returns only packets since the given timestamp for efficient polling
 */
require_once "../../../lib/meshlog.class.php";
require_once "../../../config.php";
include "../utils.php";
include "helpers.php";

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
$before_ms = intval(getParam('before_ms', 0));
$types = explode(',', getParam('types', 'ADV,MSG,PUB,RAW,TEL,SYS'));
$types = array_filter(array_map('trim', $types));

$requestedLimit = intval(getParam('limit', 50));
if ($requestedLimit <= 0) $requestedLimit = 50;
$limit = min($requestedLimit, 500);

// Fetch one extra item per packet class so we can expose has_more.
$fetchCount = $limit + 1;

$queryWindow = [
    'count' => $fetchCount,
    'after_ms' => 0,
    'before_ms' => 0,
];

if ($before_ms > 0) {
    $queryWindow['before_ms'] = $before_ms;
} else if ($since_ms > 0) {
    $queryWindow['after_ms'] = $since_ms;
}

// Get advertisements since timestamp
$advertisements = $meshlog->getAdvertisementsQuick($queryWindow);

// Get direct messages
$messages = $meshlog->getDirectMessagesQuick($queryWindow);

// Get channel messages
$channel_messages = $meshlog->getChannelMessagesQuick($queryWindow);

// Get raw packets
$raw_packets = $meshlog->getRawPackets($queryWindow);

// Get telemetry
$telemetry = $meshlog->getTelemetry($queryWindow);

// Get system reports
$system_reports = $meshlog->getSystemReports($queryWindow);

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
        $packet['node_type'] = (string)$packet['type'];  // preserve integer node type
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

// Enrich: add reporter_name, contact_public_key, channel_name, lift report fields to root.
$combined = enrichPackets($combined, $meshlog->pdo);

// Sort by received_at descending (newest first)
usort($combined, function($a, $b) {
    $timeA = strtotime($a['received_at'] ?? $a['sent_at'] ?? 0);
    $timeB = strtotime($b['received_at'] ?? $b['sent_at'] ?? 0);
    return $timeB <=> $timeA;
});

$hasMore = count($combined) > $limit;
$packets = array_slice($combined, 0, $limit);

$oldestTimestampMs = null;
if (!empty($packets)) {
    $last = $packets[count($packets) - 1];
    $oldestUnix = strtotime($last['received_at'] ?? ($last['sent_at'] ?? 0));
    if ($oldestUnix !== false && $oldestUnix > 0) {
        $oldestTimestampMs = intval($oldestUnix * 1000);
    }
}

echo json_encode([
    'packets' => $packets,
    'timestamp_ms' => intval(microtime(true) * 1000),
    'count' => count($packets),
    'has_more' => $hasMore,
    'oldest_timestamp_ms' => $oldestTimestampMs,
]);

?>
