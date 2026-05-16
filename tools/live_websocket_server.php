<?php
require_once __DIR__ . '/../lib/meshlog.class.php';
require_once __DIR__ . '/../api/v1/utils.php';
require_once __DIR__ . '/../api/v1/live/helpers.php';

const MESHLOG_WS_BIND = 'tcp://0.0.0.0:8081';
const MESHLOG_WS_QUERY_INTERVAL_SEC = 1.0;
const MESHLOG_WS_PING_INTERVAL_SEC = 20.0;
const MESHLOG_WS_CLIENT_PONG_TIMEOUT_SEC = 75.0;
const MESHLOG_WS_METADATA_INTERVAL_SEC = 120.0;
const MESHLOG_WS_MAX_LIMIT = 500;
const MESHLOG_WS_BOOTSTRAP_DEFAULT_COUNT = 500;
const MESHLOG_WS_BOOTSTRAP_CHUNK_DEFAULT = 120;
const MESHLOG_WS_BOOTSTRAP_CHUNK_MIN = 25;
const MESHLOG_WS_BOOTSTRAP_CHUNK_MAX = 250;
const MESHLOG_WS_METADATA_COUNT = 5000;
const MESHLOG_WS_PACKET_DEDUPE_MAX = 4000;

function meshlogWsLog($level, $message) {
    fwrite(STDOUT, sprintf("[%s][%s] %s\n", date('c'), $level, $message));
}

function meshlogCreateInstance($config) {
    $meshlog = new MeshLog(array_merge($config['db'], array('ntp' => $config['ntp'] ?? array())));
    $err = $meshlog->getError();
    if ($err) {
        throw new RuntimeException($err);
    }
    return $meshlog;
}

function meshlogAllowedLiveTypes() {
    return array('ADV', 'MSG', 'PUB', 'RAW', 'TEL', 'SYS');
}

function meshlogNormalizeTypes($rawTypes) {
    if (is_array($rawTypes)) {
        $parts = $rawTypes;
    } else {
        $parts = explode(',', strval($rawTypes ?? ''));
    }

    $allowed = array_flip(meshlogAllowedLiveTypes());
    $types = array();
    foreach ($parts as $part) {
        $type = strtoupper(trim(strval($part)));
        if ($type === '' || !isset($allowed[$type])) continue;
        $types[$type] = $type;
    }

    if (count($types) < 1) {
        foreach (meshlogAllowedLiveTypes() as $type) {
            $types[$type] = $type;
        }
    }

    return array_values($types);
}

function meshlogParseHandshakeRequest($buffer) {
    $headerEnd = strpos($buffer, "\r\n\r\n");
    if ($headerEnd === false) {
        return null;
    }

    $headerText = substr($buffer, 0, $headerEnd);
    $lines = explode("\r\n", $headerText);
    if (count($lines) < 1) {
        throw new RuntimeException('Invalid WebSocket handshake');
    }

    $requestLine = array_shift($lines);
    $requestParts = explode(' ', $requestLine, 3);
    if (count($requestParts) < 2) {
        throw new RuntimeException('Invalid WebSocket request line');
    }

    $headers = array();
    foreach ($lines as $line) {
        $pos = strpos($line, ':');
        if ($pos === false) continue;
        $key = strtolower(trim(substr($line, 0, $pos)));
        $value = trim(substr($line, $pos + 1));
        $headers[$key] = $value;
    }

    $target = $requestParts[1];
    $path = parse_url($target, PHP_URL_PATH) ?: '/';
    $query = array();
    $queryString = parse_url($target, PHP_URL_QUERY);
    if (is_string($queryString) && $queryString !== '') {
        parse_str($queryString, $query);
    }

    return array(
        'consumed' => $headerEnd + 4,
        'path' => $path,
        'headers' => $headers,
        'query' => $query,
    );
}

function meshlogEncodeFrame($payload, $opcode = 0x1) {
    $payload = (string)$payload;
    $length = strlen($payload);
    $head = chr(0x80 | ($opcode & 0x0F));

    if ($length < 126) {
        return $head . chr($length) . $payload;
    }

    if ($length <= 0xFFFF) {
        return $head . chr(126) . pack('n', $length) . $payload;
    }

    return $head . chr(127) . pack('NN', 0, $length) . $payload;
}

function meshlogSendBytes($socket, $bytes) {
    $written = 0;
    $length = strlen($bytes);

    while ($written < $length) {
        $result = @fwrite($socket, substr($bytes, $written));
        if ($result === false || $result === 0) {
            return false;
        }
        $written += $result;
    }

    return true;
}

function meshlogSendFrame($socket, $payload, $opcode = 0x1) {
    return meshlogSendBytes($socket, meshlogEncodeFrame($payload, $opcode));
}

function meshlogSendJsonFrame($socket, $payload) {
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    return meshlogSendFrame($socket, $json, 0x1);
}

function meshlogExtractFrames(&$buffer) {
    $frames = array();

    while (strlen($buffer) >= 2) {
        $byte1 = ord($buffer[0]);
        $byte2 = ord($buffer[1]);
        $opcode = $byte1 & 0x0F;
        $masked = ($byte2 & 0x80) !== 0;
        $length = $byte2 & 0x7F;
        $offset = 2;

        if ($length === 126) {
            if (strlen($buffer) < $offset + 2) break;
            $length = unpack('n', substr($buffer, $offset, 2))[1];
            $offset += 2;
        } else if ($length === 127) {
            if (strlen($buffer) < $offset + 8) break;
            $parts = unpack('Nhigh/Nlow', substr($buffer, $offset, 8));
            if ($parts['high'] !== 0) {
                throw new RuntimeException('Unsupported oversized WebSocket frame');
            }
            $length = $parts['low'];
            $offset += 8;
        }

        $mask = '';
        if ($masked) {
            if (strlen($buffer) < $offset + 4) break;
            $mask = substr($buffer, $offset, 4);
            $offset += 4;
        }

        if (strlen($buffer) < $offset + $length) {
            break;
        }

        $payload = substr($buffer, $offset, $length);
        $buffer = substr($buffer, $offset + $length);

        if ($masked) {
            $decoded = '';
            for ($i = 0; $i < $length; $i++) {
                $decoded .= $payload[$i] ^ $mask[$i % 4];
            }
            $payload = $decoded;
        }

        $frames[] = array('opcode' => $opcode, 'payload' => $payload);
    }

    return $frames;
}

function meshlogCloseClient(&$clients, $clientId, $statusCode = 1000, $reason = '') {
    if (!isset($clients[$clientId])) return;

    $socket = $clients[$clientId]['socket'] ?? null;
    if (is_resource($socket)) {
        $payload = pack('n', intval($statusCode));
        if ($reason !== '') {
            $payload .= substr((string)$reason, 0, 120);
        }
        @meshlogSendFrame($socket, $payload, 0x8);
        @fclose($socket);
    }

    unset($clients[$clientId]);
}

function meshlogFetchLiveBatch(&$meshlog, $config, $sinceMs, array $types, $limit) {
    if (!$meshlog instanceof MeshLog) {
        $meshlog = meshlogCreateInstance($config);
    }

    try {
        return buildLivePacketBatch($meshlog, $sinceMs, 0, $types, $limit, false);
    } catch (Throwable $e) {
        meshlogWsLog('WARN', 'Live WebSocket query failed, recreating MeshLog instance: ' . $e->getMessage());
        $meshlog = meshlogCreateInstance($config);
        return buildLivePacketBatch($meshlog, $sinceMs, 0, $types, $limit, false);
    }
}

function meshlogNormalizeWindowHours($value, $default = 24) {
    $hours = intval($value);
    if (!in_array($hours, array(1, 24, 36), true)) {
        return intval($default);
    }
    return $hours;
}

function meshlogResolveQueryAction(&$meshlog, $config, $action, array $params) {
    if (!$meshlog instanceof MeshLog) {
        $meshlog = meshlogCreateInstance($config);
    }

    $queryAction = strtolower(trim(strval($action ?? '')));
    $queryParams = is_array($params) ? $params : array();

    $executeAction = function ($meshlogInstance) use ($queryAction, $queryParams) {
        switch ($queryAction) {
            case 'stats': {
                $windowHours = meshlogNormalizeWindowHours($queryParams['window_hours'] ?? 24, 24);
                return $meshlogInstance->getGeneralAdvertisementStats($windowHours);
            }

            case 'stats_heatmap': {
                $windowHours = meshlogNormalizeWindowHours($queryParams['window_hours'] ?? 24, 24);
                return $meshlogInstance->getHeatmapData($windowHours);
            }

            case 'coverage': {
                $windowHours = intval($queryParams['window_hours'] ?? 168);
                if ($windowHours <= 0 || $windowHours > 8760) {
                    $windowHours = 168;
                }

                $precision = intval($queryParams['precision'] ?? 3);
                if ($precision < 1 || $precision > 6) {
                    $precision = 3;
                }

                return $meshlogInstance->getCoverageSpots($windowHours, $precision);
            }

            case 'contact_stats': {
                $contactId = intval($queryParams['contact_id'] ?? 0);
                if ($contactId <= 0) {
                    return array('error' => 'contact_id is required');
                }

                $windowHours = meshlogNormalizeWindowHours($queryParams['window_hours'] ?? 24, 24);
                return $meshlogInstance->getContactPacketStats($contactId, $windowHours);
            }

            case 'contact_health': {
                $contactId = intval($queryParams['contact_id'] ?? 0);
                if ($contactId <= 0) {
                    return array('error' => 'contact_id is required');
                }

                $limit = intval($queryParams['limit'] ?? 48);
                if ($limit < 1 || $limit > 200) {
                    $limit = 48;
                }

                return $meshlogInstance->getContactHealthTimeline($contactId, $limit);
            }

            case 'contact_advertisements': {
                $contactId = intval($queryParams['contact_id'] ?? 0);
                if ($contactId <= 0) {
                    return array('error' => 'contact_id is required');
                }

                return $meshlogInstance->getContactAdvertisementsWithCoordinates($contactId);
            }
        }

        return array('error' => 'Unsupported query action');
    };

    try {
        return $executeAction($meshlog);
    } catch (Throwable $e) {
        meshlogWsLog('WARN', 'WebSocket query action failed, recreating MeshLog instance: ' . $e->getMessage());
        $meshlog = meshlogCreateInstance($config);
        return $executeAction($meshlog);
    }
}

function meshlogReadScopesMap($meshlog) {
    $map = array();
    $scopes = MeshLogScope::getAll($meshlog);
    foreach ($scopes as $scope) {
        $number = intval($scope->number ?? -1);
        if ($number < 0 || $number > 255) continue;
        $name = trim((string)($scope->name ?? ''));
        if ($name === '') continue;
        $map[strval($number)] = $name;
    }
    return $map;
}

function meshlogFetchMetadataSnapshot(&$meshlog, $config, $count = MESHLOG_WS_METADATA_COUNT) {
    if (!$meshlog instanceof MeshLog) {
        $meshlog = meshlogCreateInstance($config);
    }

    $limit = max(1, intval($count));
    $params = array(
        'offset' => 0,
        'count' => $limit,
        'after_ms' => 0,
        'before_ms' => 0,
    );

    try {
        $reporters = $meshlog->getReporters($params);
        $contacts = $meshlog->getContactsQuick($params);
        $channels = $meshlog->getChannels($params);
        $scopesMap = meshlogReadScopesMap($meshlog);
    } catch (Throwable $e) {
        meshlogWsLog('WARN', 'WebSocket metadata query failed, recreating MeshLog instance: ' . $e->getMessage());
        $meshlog = meshlogCreateInstance($config);
        $reporters = $meshlog->getReporters($params);
        $contacts = $meshlog->getContactsQuick($params);
        $channels = $meshlog->getChannels($params);
        $scopesMap = meshlogReadScopesMap($meshlog);
    }

    return array(
        'type' => 'metadata',
        'reporters' => $reporters,
        'contacts' => $contacts,
        'channels' => $channels,
        'scopes_map' => $scopesMap,
        'timestamp_ms' => intval(round(microtime(true) * 1000)),
    );
}

function meshlogNormalizeMetadataRows($rows, array $keys) {
    $normalized = array();
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $item = array();
        foreach ($keys as $key) {
            if (array_key_exists($key, $row)) {
                $item[$key] = $row[$key];
            }
        }
        if (count($item) > 0) {
            $normalized[] = $item;
        }
    }
    usort($normalized, function ($a, $b) {
        return intval($a['id'] ?? 0) <=> intval($b['id'] ?? 0);
    });
    return $normalized;
}

function meshlogMetadataFingerprintFromPayload(array $payload) {
    $reporters = meshlogNormalizeMetadataRows(
        extractList($payload['reporters'] ?? array(), 'reporters'),
        array('id', 'public_key', 'name', 'authorized', 'pending')
    );
    $contacts = meshlogNormalizeMetadataRows(
        extractList($payload['contacts'] ?? array(), 'contacts'),
        array('id', 'public_key', 'name', 'type', 'hidden', 'enabled')
    );
    $channels = meshlogNormalizeMetadataRows(
        extractList($payload['channels'] ?? array(), 'channels'),
        array('id', 'hash', 'name', 'enabled')
    );

    $scopesMap = $payload['scopes_map'] ?? array();
    if (!is_array($scopesMap)) {
        $scopesMap = array();
    }
    ksort($scopesMap);

    $fingerprintData = array(
        'reporters' => $reporters,
        'contacts' => $contacts,
        'channels' => $channels,
        'scopes_map' => $scopesMap,
    );

    return sha1(json_encode($fingerprintData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function meshlogFetchBootstrapSnapshot(&$meshlog, $config, array $types, $count) {
    if (!$meshlog instanceof MeshLog) {
        $meshlog = meshlogCreateInstance($config);
    }

    $limit = min(MESHLOG_WS_MAX_LIMIT, max(1, intval($count)));
    $params = array(
        'offset' => 0,
        'count' => $limit,
        'after_ms' => 0,
        'before_ms' => 0,
    );

    $paramsContacts = array(
        'offset' => 0,
        'count' => $limit,
        'after_ms' => 0,
        'before_ms' => 0,
    );

    $includeRawPackets = in_array('RAW', $types, true);
    $includeTelemetry = in_array('TEL', $types, true);
    $includeSystemReports = in_array('SYS', $types, true);

    try {
        $reporters = $meshlog->getReporters($params);
        $contacts = $meshlog->getContactsQuick($paramsContacts);
        $advertisements = $meshlog->getAdvertisementsQuick($params);
        $channels = $meshlog->getChannels($params);
        $directMessages = $meshlog->getDirectMessagesQuick($params);
        $channelMessages = $meshlog->getChannelMessagesQuick($params);
        $telemetry = $includeTelemetry ? $meshlog->getTelemetry($params) : array('objects' => array());
        $systemReports = $includeSystemReports ? $meshlog->getSystemReports($params) : array('objects' => array());
        $rawPackets = $includeRawPackets ? $meshlog->getRawPackets($params) : array('objects' => array());
    } catch (Throwable $e) {
        meshlogWsLog('WARN', 'WebSocket bootstrap query failed, recreating MeshLog instance: ' . $e->getMessage());
        $meshlog = meshlogCreateInstance($config);
        $reporters = $meshlog->getReporters($params);
        $contacts = $meshlog->getContactsQuick($paramsContacts);
        $advertisements = $meshlog->getAdvertisementsQuick($params);
        $channels = $meshlog->getChannels($params);
        $directMessages = $meshlog->getDirectMessagesQuick($params);
        $channelMessages = $meshlog->getChannelMessagesQuick($params);
        $telemetry = $includeTelemetry ? $meshlog->getTelemetry($params) : array('objects' => array());
        $systemReports = $includeSystemReports ? $meshlog->getSystemReports($params) : array('objects' => array());
        $rawPackets = $includeRawPackets ? $meshlog->getRawPackets($params) : array('objects' => array());
    }

    $packets = array_merge(
        extractList($advertisements, 'advertisements'),
        extractList($channelMessages, 'channel_messages'),
        extractList($directMessages, 'direct_messages'),
        extractList($rawPackets, 'raw_packets'),
        extractList($telemetry, 'telemetry'),
        extractList($systemReports, 'system_reports')
    );

    return array(
        'type' => 'bootstrap',
        'reporters' => $reporters,
        'contacts' => $contacts,
        'channels' => $channels,
        'scopes_map' => meshlogReadScopesMap($meshlog),
        'advertisements' => $advertisements,
        'channel_messages' => $channelMessages,
        'direct_messages' => $directMessages,
        'raw_packets' => $rawPackets,
        'telemetry' => $telemetry,
        'system_reports' => $systemReports,
        'timestamp_ms' => newestPacketTimestampMs($packets) ?? 0,
    );
}

function meshlogPacketSignature($packet) {
    if (!is_array($packet)) return null;

    $type = strtoupper(strval($packet['type'] ?? ''));
    $id = intval($packet['id'] ?? 0);
    if ($type === '' || $id <= 0) return null;

    $reportCount = 0;
    $lastReportId = 0;
    if (isset($packet['reports']) && is_array($packet['reports'])) {
        $reportCount = count($packet['reports']);
        if ($reportCount > 0) {
            $last = $packet['reports'][$reportCount - 1] ?? null;
            if (is_array($last)) {
                $lastReportId = intval($last['id'] ?? 0);
            }
        }
    }

    $receivedAt = strval($packet['received_at'] ?? '');
    return sprintf('%s:%d:%s:%d:%d', $type, $id, $receivedAt, $reportCount, $lastReportId);
}

function meshlogFilterClientDuplicates(&$client, array $packets) {
    if (!isset($client['sent_packet_signatures']) || !is_array($client['sent_packet_signatures'])) {
        $client['sent_packet_signatures'] = array();
    }
    if (!isset($client['sent_packet_order']) || !is_array($client['sent_packet_order'])) {
        $client['sent_packet_order'] = array();
    }

    $filtered = array();
    foreach ($packets as $packet) {
        $sig = meshlogPacketSignature($packet);
        if ($sig === null) {
            $filtered[] = $packet;
            continue;
        }

        if (isset($client['sent_packet_signatures'][$sig])) {
            continue;
        }

        $client['sent_packet_signatures'][$sig] = true;
        $client['sent_packet_order'][] = $sig;
        $filtered[] = $packet;

        while (count($client['sent_packet_order']) > MESHLOG_WS_PACKET_DEDUPE_MAX) {
            $oldSig = array_shift($client['sent_packet_order']);
            if ($oldSig !== null) {
                unset($client['sent_packet_signatures'][$oldSig]);
            }
        }
    }

    return $filtered;
}

function meshlogSendBootstrapSlices($socket, array $bootstrap, $bootstrapId, $chunkSize, array $types) {
    $sections = array(
        array('key' => 'reporters', 'legacy' => 'reporters'),
        array('key' => 'contacts', 'legacy' => 'contacts'),
        array('key' => 'channels', 'legacy' => 'channels'),
        array('key' => 'advertisements', 'legacy' => 'advertisements'),
        array('key' => 'channel_messages', 'legacy' => 'channel_messages'),
        array('key' => 'direct_messages', 'legacy' => 'direct_messages'),
        array('key' => 'raw_packets', 'legacy' => 'raw_packets'),
        array('key' => 'telemetry', 'legacy' => 'telemetry'),
        array('key' => 'system_reports', 'legacy' => 'system_reports'),
    );

    $counts = array();
    foreach ($sections as $section) {
        $counts[$section['key']] = count(extractList($bootstrap[$section['key']] ?? array(), $section['legacy']));
    }

    if (!meshlogSendJsonFrame($socket, array(
        'type' => 'bootstrap_start',
        'bootstrap_id' => $bootstrapId,
        'chunk_size' => $chunkSize,
        'counts' => $counts,
        'types' => array_values($types),
        'scopes_map' => $bootstrap['scopes_map'] ?? array(),
        'timestamp_ms' => intval($bootstrap['timestamp_ms'] ?? 0),
    ))) {
        return false;
    }

    foreach ($sections as $section) {
        $key = $section['key'];
        $objects = extractList($bootstrap[$key] ?? array(), $section['legacy']);
        $total = count($objects);
        if ($total < 1) continue;

        $chunkTotal = max(1, intval(ceil($total / $chunkSize)));
        for ($i = 0; $i < $chunkTotal; $i++) {
            $slice = array_slice($objects, $i * $chunkSize, $chunkSize);
            if (!meshlogSendJsonFrame($socket, array(
                'type' => 'bootstrap_slice',
                'bootstrap_id' => $bootstrapId,
                'section' => $key,
                'chunk_index' => $i + 1,
                'chunk_total' => $chunkTotal,
                'objects' => $slice,
            ))) {
                return false;
            }
        }
    }

    return meshlogSendJsonFrame($socket, array(
        'type' => 'bootstrap_done',
        'bootstrap_id' => $bootstrapId,
        'types' => array_values($types),
        'timestamp_ms' => intval($bootstrap['timestamp_ms'] ?? 0),
    ));
}

$config = meshlogLoadConfig(__DIR__);
$meshlog = null;

$metadataCache = array(
    'last_refresh_at' => 0.0,
    'fingerprint' => '',
    'version' => 0,
    'payload' => null,
);

$server = @stream_socket_server(MESHLOG_WS_BIND, $errno, $errstr);
if (!$server) {
    meshlogWsLog('ERROR', sprintf('Unable to bind WebSocket server on %s: %s (%d)', MESHLOG_WS_BIND, $errstr, $errno));
    exit(1);
}

stream_set_blocking($server, false);
meshlogWsLog('INFO', 'Live WebSocket server listening on ' . MESHLOG_WS_BIND);

$clients = array();

while (true) {
    $read = array($server);
    foreach ($clients as $client) {
        if (is_resource($client['socket'])) {
            $read[] = $client['socket'];
        }
    }

    $write = null;
    $except = null;
    @stream_select($read, $write, $except, 1, 0);

    foreach ($read as $socket) {
        if ($socket === $server) {
            $clientSocket = @stream_socket_accept($server, 0);
            if (!$clientSocket) continue;

            stream_set_blocking($clientSocket, false);
            $clientId = intval($clientSocket);
            $clients[$clientId] = array(
                'socket' => $clientSocket,
                'buffer' => '',
                'handshake' => false,
                'bootstrap' => false,
                'since_ms' => 0,
                'types' => meshlogAllowedLiveTypes(),
                'limit' => 100,
                'count' => MESHLOG_WS_BOOTSTRAP_DEFAULT_COUNT,
                'chunk_size' => MESHLOG_WS_BOOTSTRAP_CHUNK_DEFAULT,
                'last_query_at' => 0.0,
                'last_ping_at' => microtime(true),
                'last_pong_at' => microtime(true),
                'last_metadata_version' => 0,
                'sent_packet_signatures' => array(),
                'sent_packet_order' => array(),
            );
            continue;
        }

        $clientId = intval($socket);
        if (!isset($clients[$clientId])) {
            continue;
        }

        $chunk = @fread($socket, 8192);
        if (($chunk === '' && feof($socket)) || $chunk === false) {
            meshlogCloseClient($clients, $clientId, 1001, 'disconnect');
            continue;
        }

        if ($chunk === '') {
            continue;
        }

        $clients[$clientId]['last_pong_at'] = microtime(true);

        $clients[$clientId]['buffer'] .= $chunk;

        if (!$clients[$clientId]['handshake']) {
            try {
                $request = meshlogParseHandshakeRequest($clients[$clientId]['buffer']);
                if ($request === null) {
                    continue;
                }

                $clients[$clientId]['buffer'] = substr($clients[$clientId]['buffer'], $request['consumed']);
                $headers = $request['headers'];
                $key = $headers['sec-websocket-key'] ?? '';
                if ($key === '') {
                    throw new RuntimeException('Missing Sec-WebSocket-Key');
                }

                $accept = base64_encode(sha1(trim($key) . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
                $response = "HTTP/1.1 101 Switching Protocols\r\n";
                $response .= "Upgrade: websocket\r\n";
                $response .= "Connection: Upgrade\r\n";
                $response .= "Sec-WebSocket-Accept: {$accept}\r\n\r\n";

                if (!meshlogSendBytes($socket, $response)) {
                    throw new RuntimeException('Handshake write failed');
                }

                $query = $request['query'];
                $clients[$clientId]['handshake'] = true;
                $clients[$clientId]['bootstrap'] = intval($query['bootstrap'] ?? 0) !== 0;
                $clients[$clientId]['since_ms'] = max(0, intval($query['since_ms'] ?? 0));
                $clients[$clientId]['types'] = meshlogNormalizeTypes($query['types'] ?? '');
                $clients[$clientId]['limit'] = min(MESHLOG_WS_MAX_LIMIT, max(1, intval($query['limit'] ?? 100)));
                $clients[$clientId]['count'] = min(MESHLOG_WS_MAX_LIMIT, max(1, intval($query['count'] ?? MESHLOG_WS_BOOTSTRAP_DEFAULT_COUNT)));
                $clients[$clientId]['chunk_size'] = min(
                    MESHLOG_WS_BOOTSTRAP_CHUNK_MAX,
                    max(MESHLOG_WS_BOOTSTRAP_CHUNK_MIN, intval($query['chunk_size'] ?? MESHLOG_WS_BOOTSTRAP_CHUNK_DEFAULT))
                );

                if ($clients[$clientId]['bootstrap']) {
                    $bootstrap = meshlogFetchBootstrapSnapshot(
                        $meshlog,
                        $config,
                        $clients[$clientId]['types'],
                        $clients[$clientId]['count']
                    );

                    $bootstrapId = sprintf('%d-%d', $clientId, intval(round(microtime(true) * 1000)));
                    if (!meshlogSendBootstrapSlices(
                        $socket,
                        $bootstrap,
                        $bootstrapId,
                        $clients[$clientId]['chunk_size'],
                        $clients[$clientId]['types']
                    )) {
                        throw new RuntimeException('Bootstrap send failed');
                    }

                    $clients[$clientId]['since_ms'] = max(
                        $clients[$clientId]['since_ms'],
                        max(0, intval($bootstrap['timestamp_ms'] ?? 0))
                    );

                    $bootstrapFingerprint = meshlogMetadataFingerprintFromPayload($bootstrap);
                    if ($bootstrapFingerprint === ($metadataCache['fingerprint'] ?? '')) {
                        $clients[$clientId]['last_metadata_version'] = intval($metadataCache['version'] ?? 0);
                    }
                }

                meshlogWsLog('INFO', sprintf(
                    'WebSocket client %d connected path=%s bootstrap=%d since_ms=%d types=%s limit=%d count=%d chunk_size=%d',
                    $clientId,
                    $request['path'],
                    $clients[$clientId]['bootstrap'] ? 1 : 0,
                    $clients[$clientId]['since_ms'],
                    implode(',', $clients[$clientId]['types']),
                    $clients[$clientId]['limit'],
                    $clients[$clientId]['count'],
                    $clients[$clientId]['chunk_size']
                ));
            } catch (Throwable $e) {
                meshlogWsLog('WARN', sprintf('WebSocket handshake failed for client %d: %s', $clientId, $e->getMessage()));
                meshlogCloseClient($clients, $clientId, 1002, 'handshake failed');
            }
            continue;
        }

        try {
            $frames = meshlogExtractFrames($clients[$clientId]['buffer']);
            foreach ($frames as $frame) {
                $opcode = $frame['opcode'];
                if ($opcode === 0x8) {
                    meshlogCloseClient($clients, $clientId, 1000, 'closed');
                    continue 2;
                }
                if ($opcode === 0xA) {
                    $clients[$clientId]['last_pong_at'] = microtime(true);
                    continue;
                }
                if ($opcode === 0x9) {
                    if (!meshlogSendFrame($socket, $frame['payload'], 0xA)) {
                        meshlogCloseClient($clients, $clientId, 1001, 'pong failed');
                        continue 2;
                    }
                    $clients[$clientId]['last_pong_at'] = microtime(true);
                    continue;
                }
                if ($opcode === 0x1) {
                    $payload = trim(strval($frame['payload'] ?? ''));
                    if ($payload === '') continue;

                    $decoded = json_decode($payload, true);
                    if (!is_array($decoded)) continue;

                    if (strtolower(strval($decoded['type'] ?? '')) === 'ping') {
                        if (!meshlogSendJsonFrame($socket, array(
                            'type' => 'pong',
                            'timestamp_ms' => intval(round(microtime(true) * 1000)),
                        ))) {
                            meshlogCloseClient($clients, $clientId, 1001, 'pong json failed');
                            continue 2;
                        }
                        $clients[$clientId]['last_pong_at'] = microtime(true);
                        continue;
                    }

                    if (strtolower(strval($decoded['type'] ?? '')) === 'query') {
                        $requestId = trim(strval($decoded['request_id'] ?? ''));
                        if ($requestId === '') {
                            continue;
                        }

                        $action = strtolower(trim(strval($decoded['action'] ?? '')));
                        $params = is_array($decoded['params'] ?? null) ? $decoded['params'] : array();

                        $response = array(
                            'type' => 'query_result',
                            'request_id' => substr($requestId, 0, 128),
                            'action' => $action,
                            'timestamp_ms' => intval(round(microtime(true) * 1000)),
                        );

                        try {
                            $response['data'] = meshlogResolveQueryAction($meshlog, $config, $action, $params);
                        } catch (Throwable $e) {
                            $response['error'] = 'Query action failed';
                            meshlogWsLog('WARN', sprintf('WebSocket query failed for client %d action=%s error=%s', $clientId, $action, $e->getMessage()));
                        }

                        if (!meshlogSendJsonFrame($socket, $response)) {
                            meshlogCloseClient($clients, $clientId, 1001, 'query response failed');
                            continue 2;
                        }

                        $clients[$clientId]['last_pong_at'] = microtime(true);
                    }
                }
            }
        } catch (Throwable $e) {
            meshlogWsLog('WARN', sprintf('WebSocket frame parse failed for client %d: %s', $clientId, $e->getMessage()));
            meshlogCloseClient($clients, $clientId, 1002, 'frame error');
        }
    }

    $now = microtime(true);

    if (($now - floatval($metadataCache['last_refresh_at'] ?? 0.0)) >= MESHLOG_WS_METADATA_INTERVAL_SEC) {
        try {
            $snapshot = meshlogFetchMetadataSnapshot($meshlog, $config);
            $fingerprint = meshlogMetadataFingerprintFromPayload($snapshot);
            if ($fingerprint !== ($metadataCache['fingerprint'] ?? '')) {
                $metadataCache['payload'] = $snapshot;
                $metadataCache['fingerprint'] = $fingerprint;
                $metadataCache['version'] = intval($metadataCache['version'] ?? 0) + 1;
            }
            $metadataCache['last_refresh_at'] = $now;
        } catch (Throwable $e) {
            meshlogWsLog('WARN', 'WebSocket metadata refresh failed: ' . $e->getMessage());
            $metadataCache['last_refresh_at'] = $now;
        }
    }

    foreach (array_keys($clients) as $clientId) {
        if (!isset($clients[$clientId]) || !$clients[$clientId]['handshake']) {
            continue;
        }

        $client = &$clients[$clientId];
        $socket = $client['socket'];
        if (!is_resource($socket)) {
            meshlogCloseClient($clients, $clientId, 1001, 'socket gone');
            continue;
        }

        if (($now - $client['last_ping_at']) >= MESHLOG_WS_PING_INTERVAL_SEC) {
            if (!meshlogSendFrame($socket, '', 0x9)) {
                meshlogCloseClient($clients, $clientId, 1001, 'ping failed');
                continue;
            }
            $client['last_ping_at'] = $now;
        }

        if (($now - floatval($client['last_pong_at'] ?? 0.0)) >= MESHLOG_WS_CLIENT_PONG_TIMEOUT_SEC) {
            meshlogCloseClient($clients, $clientId, 1001, 'pong timeout');
            continue;
        }

        if (($now - $client['last_query_at']) < MESHLOG_WS_QUERY_INTERVAL_SEC) {
            // fall through and still allow metadata heartbeat frames
        } else {
            $client['last_query_at'] = $now;

            try {
                $result = meshlogFetchLiveBatch($meshlog, $config, $client['since_ms'], $client['types'], $client['limit']);
                $packets = $result['packets'] ?? array();
                $packets = meshlogFilterClientDuplicates($client, $packets);
                if (count($packets) > 0) {
                    $cursorMs = $result['newest_timestamp_ms'] ?? $client['since_ms'];
                    $payload = json_encode(array(
                        'packets' => $packets,
                        'timestamp_ms' => $cursorMs,
                        'count' => count($packets),
                        'has_more' => $result['has_more'] ?? false,
                        'oldest_timestamp_ms' => $result['oldest_timestamp_ms'] ?? null,
                    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                    if ($payload === false || !meshlogSendFrame($socket, $payload, 0x1)) {
                        meshlogCloseClient($clients, $clientId, 1001, 'send failed');
                        unset($client);
                        continue;
                    }

                    $client['since_ms'] = intval($cursorMs);
                }
            } catch (Throwable $e) {
                meshlogWsLog('ERROR', sprintf('WebSocket live query failed for client %d: %s', $clientId, $e->getMessage()));
                meshlogCloseClient($clients, $clientId, 1011, 'query failed');
                unset($client);
                continue;
            }
        }

        $metadataVersion = intval($metadataCache['version'] ?? 0);
        if ($metadataVersion > intval($client['last_metadata_version'] ?? 0) && is_array($metadataCache['payload'])) {
            $metadataPayload = json_encode($metadataCache['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($metadataPayload === false || !meshlogSendFrame($socket, $metadataPayload, 0x1)) {
                meshlogCloseClient($clients, $clientId, 1001, 'metadata send failed');
                unset($client);
                continue;
            }
            $client['last_metadata_version'] = $metadataVersion;
        }

        unset($client);
    }
}