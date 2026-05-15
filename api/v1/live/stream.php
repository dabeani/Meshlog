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
require_once __DIR__ . "/../../../lib/meshlog.class.php";
require_once __DIR__ . "/../utils.php";
require_once __DIR__ . "/helpers.php";

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

$config = meshlogLoadConfig(__DIR__);
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
$requestedLimit = intval(getParam('limit', 50));
if ($requestedLimit <= 0) $requestedLimit = 50;
$limit = min($requestedLimit, 500);
$maxDurationSec = max(5, min(intval(getParam('max_duration_sec', 25)), 55));
$sleepMicros = 1000000; // 1s

$startedAt = time();

if ($historyMode) {
    $result = buildLivePacketBatch($meshlog, $sinceMs, $beforeMs, $types, $limit, true);
    $payload = array(
        'packets' => $result['packets'],
        'timestamp_ms' => $result['newest_timestamp_ms'] ?? $sinceMs,
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

    $result = buildLivePacketBatch($meshlog, $sinceMs, 0, $types, $limit, false);
    $packets = $result['packets'];

    if (!empty($packets)) {
        $cursorMs = $result['newest_timestamp_ms'] ?? $sinceMs;
        $payload = array(
            'packets' => $packets,
            'timestamp_ms' => $cursorMs,
            'count' => count($packets),
            'has_more' => $result['has_more'],
            'oldest_timestamp_ms' => $result['oldest_timestamp_ms'],
        );

        echo "event: packets\n";
        echo "data: " . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();

        $sinceMs = $cursorMs;
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
