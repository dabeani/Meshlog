<?php
/**
 * Live feed Server-Sent Events stream endpoint.
 *
 * GET /api/v1/live/stream.php?since_ms=0&types=ADV,MSG,PUB,RAW&limit=50
 *
 * Streams newline-delimited SSE events with JSON payload:
 *   event: packets
 *   data: {"packets": [...], "timestamp_ms": 123, "count": 4}
 */
require_once "../../../lib/meshlog.class.php";
require_once "../../../config.php";
include "../utils.php";

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
$types = explode(',', getParam('types', 'ADV,MSG,PUB,RAW'));
$types = array_filter(array_map('trim', $types));
$limit = min(intval(getParam('limit', 50)), 200);
$maxDurationSec = max(5, min(intval(getParam('max_duration_sec', 25)), 55));
$sleepMicros = 1000000; // 1s

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

    if (array_keys($result) === range(0, count($result) - 1)) {
        return $result;
    }

    return array();
}

function buildCombinedPackets($meshlog, $sinceMs, $types, $limit) {
    $advertisements = $meshlog->getAdvertisementsQuick(array(
        'after_ms' => $sinceMs,
        'count' => $limit,
    ));

    $messages = $meshlog->getDirectMessagesQuick(array(
        'after_ms' => $sinceMs,
        'count' => $limit,
    ));

    $channelMessages = $meshlog->getChannelMessagesQuick(array(
        'after_ms' => $sinceMs,
        'count' => $limit,
    ));

    $rawPackets = $meshlog->getRawPackets(array(
        'after_ms' => $sinceMs,
        'count' => $limit,
    ));

    $advertisementRows = extractList($advertisements, 'advertisements');
    $messageRows = extractList($messages, 'direct_messages');
    $channelMessageRows = extractList($channelMessages, 'channel_messages');
    $rawPacketRows = extractList($rawPackets, 'raw_packets');

    $combined = array();

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

    usort($combined, function($a, $b) {
        $timeA = strtotime($a['received_at'] ?? ($a['sent_at'] ?? 0));
        $timeB = strtotime($b['received_at'] ?? ($b['sent_at'] ?? 0));
        return $timeB <=> $timeA;
    });

    return array_slice($combined, 0, $limit);
}

$startedAt = time();

while (true) {
    if (connection_aborted()) {
        break;
    }

    $packets = buildCombinedPackets($meshlog, $sinceMs, $types, $limit);
    $nowMs = intval(microtime(true) * 1000);

    if (!empty($packets)) {
        $payload = array(
            'packets' => $packets,
            'timestamp_ms' => $nowMs,
            'count' => count($packets),
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
