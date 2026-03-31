<?php
/**
 * Live feed Server-Sent Events stream endpoint.
 *
 * GET /api/v1/live/stream.php?since_ms=0&types=ADV,MSG,PUB,RAW&limit=50
 * GET /api/v1/live/stream.php?mode=history&before_ms=1711900000000&types=ADV,MSG,PUB,RAW&limit=50
 *
 * Streams newline-delimited SSE events with JSON payload:
 *   event: packets
 *   data: {"packets": [...], "timestamp_ms": 123, "count": 4, "has_more": true}
 */
require_once "../../../lib/meshlog.class.php";
require_once "../../../config.php";
include "../utils.php";
include "helpers.php";

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-transform');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');
@set_time_limit(0);

while (ob_get_level() > 0) {
    @ob_end_flush();
}
ob_implicit_flush(true);

$meshlog = new MeshLog(array_merge($config['db'], array('ntp' => $config['ntp'] ?? array())));
$err = $meshlog->getError();

if ($err) {
    echo "event: error\n";
    echo "data: " . json_encode(array('error' => $err)) . "\n\n";
    flush();
    exit;
}

$sinceMs = intval(getParam('since_ms', 0));
$beforeMs = intval(getParam('before_ms', 0));
$mode = strtolower(trim(strval(getParam('mode', 'live'))));
$historyMode = ($mode === 'history') || $beforeMs > 0;
$types = explode(',', getParam('types', 'ADV,MSG,PUB,RAW,TEL,SYS'));
$types = array_filter(array_map('trim', $types));
$limit = min(intval(getParam('limit', 50)), 200);
$maxDurationSec = max(5, min(intval(getParam('max_duration_sec', 25)), 55));
$sleepMicros = 1000000; // 1s

function buildCombinedPackets($meshlog, $sinceMs, $beforeMs, $types, $limit, $historyMode = false) {
    // Fetch one extra row so callers can know if older/newer rows remain.
    $fetchCount = max(1, $limit + 1);
    $queryWindow = array(
        'after_ms' => $historyMode ? 0 : $sinceMs,
        'before_ms' => $historyMode ? $beforeMs : 0,
        'count' => $fetchCount,
    );

    $advertisements = $meshlog->getAdvertisementsQuick(array(
        'after_ms' => $queryWindow['after_ms'],
        'before_ms' => $queryWindow['before_ms'],
        'count' => $queryWindow['count'],
    ));

    $messages = $meshlog->getDirectMessagesQuick(array(
        'after_ms' => $queryWindow['after_ms'],
        'before_ms' => $queryWindow['before_ms'],
        'count' => $queryWindow['count'],
    ));

    $channelMessages = $meshlog->getChannelMessagesQuick(array(
        'after_ms' => $queryWindow['after_ms'],
        'before_ms' => $queryWindow['before_ms'],
        'count' => $queryWindow['count'],
    ));

    $rawPackets = $meshlog->getRawPackets(array(
        'after_ms' => $queryWindow['after_ms'],
        'before_ms' => $queryWindow['before_ms'],
        'count' => $queryWindow['count'],
    ));

    $telemetry = $meshlog->getTelemetry(array(
        'after_ms' => $queryWindow['after_ms'],
        'before_ms' => $queryWindow['before_ms'],
        'count' => $queryWindow['count'],
    ));

    $systemReports = $meshlog->getSystemReports(array(
        'after_ms' => $queryWindow['after_ms'],
        'before_ms' => $queryWindow['before_ms'],
        'count' => $queryWindow['count'],
    ));

    $advertisementRows = extractList($advertisements, 'advertisements');
    $messageRows = extractList($messages, 'direct_messages');
    $channelMessageRows = extractList($channelMessages, 'channel_messages');
    $rawPacketRows = extractList($rawPackets, 'raw_packets');
    $telemetryRows = extractList($telemetry, 'telemetry');
    $systemReportRows = extractList($systemReports, 'system_reports');

    $combined = array();

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

    usort($combined, function($a, $b) {
        $timeA = strtotime($a['received_at'] ?? ($a['sent_at'] ?? 0));
        $timeB = strtotime($b['received_at'] ?? ($b['sent_at'] ?? 0));
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

    return array(
        'packets' => $packets,
        'has_more' => $hasMore,
        'oldest_timestamp_ms' => $oldestTimestampMs,
    );
}

$startedAt = time();

if ($historyMode) {
    $result = buildCombinedPackets($meshlog, $sinceMs, $beforeMs, $types, $limit, true);
    $payload = array(
        'packets' => $result['packets'],
        'timestamp_ms' => intval(microtime(true) * 1000),
        'count' => count($result['packets']),
        'has_more' => $result['has_more'],
        'oldest_timestamp_ms' => $result['oldest_timestamp_ms'],
    );

    echo "event: packets\n";
    echo "data: " . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
    echo "event: end\n";
    echo "data: {}\n\n";
    flush();
    exit;
}

while (true) {
    if (connection_aborted()) {
        break;
    }

    $result = buildCombinedPackets($meshlog, $sinceMs, 0, $types, $limit, false);
    $packets = $result['packets'];
    $nowMs = intval(microtime(true) * 1000);

    if (!empty($packets)) {
        $payload = array(
            'packets' => $packets,
            'timestamp_ms' => $nowMs,
            'count' => count($packets),
            'has_more' => $result['has_more'],
            'oldest_timestamp_ms' => $result['oldest_timestamp_ms'],
        );

        echo "event: packets\n";
        echo "data: " . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();

        $sinceMs = $nowMs;
    } else {
        // SSE comment heartbeat keeps the connection alive through proxies.
        echo ": keepalive\n\n";
        flush();
    }

    if ((time() - $startedAt) >= $maxDurationSec) {
        echo "event: end\n";
        echo "data: {}\n\n";
        flush();
        break;
    }

    usleep($sleepMicros);
}

exit;
