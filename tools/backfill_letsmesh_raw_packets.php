#!/usr/bin/env php
<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the CLI.\n");
    exit(1);
}

function usage() {
    $usage = <<<TXT
Usage:
  php tools/backfill_letsmesh_raw_packets.php [--apply] [--limit=N] [--reporter-id=ID] [--packet-id=ID] [--verbose]

Options:
  --apply         Persist changes. Without this flag the script runs in dry-run mode.
  --limit=N       Process at most N candidate rows.
  --reporter-id   Restrict to a single reporter_id.
  --packet-id     Restrict to a single raw_packets.id.
  --verbose       Print one line per inspected row.
TXT;

    fwrite(STDERR, $usage . "\n");
}

function normalizeNullableInt($value) {
    if ($value === null || $value === '') return null;
    return intval($value);
}

function normalizeNullableString($value) {
    if ($value === null) return null;
    $value = strval($value);
    return $value;
}

function buildSyntheticLetsMeshPacketPayload($reporterPublicKey, $timestamp, $snr, $rawHex, $packetType) {
    return json_encode(array(
        'origin_id' => $reporterPublicKey,
        'timestamp' => $timestamp,
        'type' => 'PACKET',
        'packet_type' => $packetType,
        'raw' => $rawHex,
        'SNR' => $snr,
    ));
}

function resolveBackfilledContactId($decoded, $meshlog) {
    if (!is_array($decoded)) return null;

    if (($decoded['type'] ?? '') === 'ADV') {
        $pubkey = strtoupper(trim(strval($decoded['contact']['pubkey'] ?? '')));
        if ($pubkey !== '') {
            $contact = MeshLogContact::findBy('public_key', $pubkey, $meshlog);
            if ($contact) return intval($contact->getId());
        }
    }

    if (($decoded['type'] ?? '') === 'RAW' && !empty($decoded['packet']['decoded'])) {
        $payloadHex = strtoupper(trim(strval($decoded['packet']['payload'] ?? '')));
        if ($payloadHex !== '' && (strlen($payloadHex) % 2) === 0) {
            $meta = json_decode(hex2bin($payloadHex), true);
            if (is_array($meta)) {
                $senderPubkey = strtoupper(trim(strval($meta['sender_pubkey'] ?? '')));
                if (strlen($senderPubkey) === 64) {
                    $contact = MeshLogContact::findBy('public_key', $senderPubkey, $meshlog);
                    if ($contact) return intval($contact->getId());
                }

                $srcHash = strtoupper(trim(strval($meta['src_hash'] ?? '')));
                if (strlen($srcHash) === 2) {
                    $contact = MeshLogContact::findByHashPrefix($srcHash, $meshlog);
                    if ($contact) return intval($contact->getId());
                }
            }
        }
    }

    return null;
}

$options = getopt('', array('apply', 'limit::', 'reporter-id::', 'packet-id::', 'verbose', 'help'));
if (array_key_exists('help', $options)) {
    usage();
    exit(0);
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/meshlog.class.php';

$apply = array_key_exists('apply', $options);
$verbose = array_key_exists('verbose', $options);
$limit = isset($options['limit']) ? max(1, intval($options['limit'])) : 0;
$reporterId = isset($options['reporter-id']) ? max(0, intval($options['reporter-id'])) : 0;
$packetId = isset($options['packet-id']) ? max(0, intval($options['packet-id'])) : 0;

$meshlog = new MeshLog(array_merge($config['db'], array('ntp' => $config['ntp'] ?? array())));
$err = $meshlog->getError();
if ($err) {
    fwrite(STDERR, "MeshLog error: $err\n");
    exit(1);
}

$channels = MeshLogChannel::getAllWithPsk($meshlog);

$sql = "
    SELECT
        rp.id,
        rp.reporter_id,
        rp.contact_id,
        rp.header,
        rp.path,
        HEX(rp.payload) AS payload_hex,
        rp.snr,
        rp.decoded,
        rp.hash_size,
        rp.scope,
        rp.route_type,
        rp.received_at,
        rp.created_at,
        r.public_key,
        r.iata_code,
        r.name AS reporter_name
    FROM raw_packets rp
    INNER JOIN reporters r ON r.id = rp.reporter_id
    WHERE r.report_format = :report_format
";

$bind = array(
    ':report_format' => MeshLogReporter::FORMAT_LETSMESH,
);

if ($reporterId > 0) {
    $sql .= ' AND rp.reporter_id = :reporter_id';
    $bind[':reporter_id'] = $reporterId;
}

if ($packetId > 0) {
    $sql .= ' AND rp.id = :packet_id';
    $bind[':packet_id'] = $packetId;
}

$sql .= ' ORDER BY rp.id ASC';
if ($limit > 0) {
    $sql .= ' LIMIT :limit_count';
}

$stmt = $meshlog->pdo->prepare($sql);
foreach ($bind as $param => $value) {
    $stmt->bindValue($param, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
if ($limit > 0) {
    $stmt->bindValue(':limit_count', $limit, PDO::PARAM_INT);
}
$stmt->execute();

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stats = array(
    'inspected' => 0,
    'repairable' => 0,
    'updated' => 0,
    'decoded_rows' => 0,
    'adv_rows' => 0,
    'pub_rows' => 0,
    'dir_rows' => 0,
    'ctrl_rows' => 0,
    'raw_rows' => 0,
    'skipped_non_packet' => 0,
    'skipped_unchanged' => 0,
    'errors' => 0,
);

foreach ($rows as $row) {
    $stats['inspected']++;

    $storedPayloadHex = strtoupper(trim(strval($row['payload_hex'] ?? '')));
    $summary = MeshLogMqttDecoder::summarizeRawPacketHex($storedPayloadHex);
    if (!is_array($summary)) {
        $stats['skipped_non_packet']++;
        if ($verbose) {
            echo "skip id={$row['id']} reason=non-packet-payload\n";
        }
        continue;
    }

    $topicIata = MeshLogReporter::normalizeIataCode($row['iata_code'] ?? '');
    if ($topicIata === '') $topicIata = 'LETS';
    $topic = 'meshcore/' . $topicIata . '/' . trim(strval($row['public_key'] ?? '')) . '/packets';

    $syntheticPayload = buildSyntheticLetsMeshPacketPayload(
        trim(strval($row['public_key'] ?? '')),
        trim(strval($row['received_at'] ?? $row['created_at'] ?? '')),
        is_numeric($row['snr'] ?? null) ? $row['snr'] : 0,
        $storedPayloadHex,
        intval($summary['packet_type'] ?? 0)
    );

    $decoded = MeshLogMqttDecoder::decode(
        $topic,
        $syntheticPayload,
        $channels,
        array(
            'forced_reporter' => trim(strval($row['public_key'] ?? '')),
            'format' => MeshLogReporter::FORMAT_LETSMESH,
        )
    );

    $newPacket = array(
        'header' => intval($summary['header'] ?? 0),
        'path' => strval($summary['path'] ?? ''),
        'payload_hex' => strtoupper(strval($summary['payload'] ?? '')),
        'decoded' => 0,
        'hash_size' => intval($summary['hash_size'] ?? 1),
        'scope' => normalizeNullableInt($summary['scope'] ?? null),
        'route_type' => normalizeNullableInt($summary['route_type'] ?? null),
        'contact_id' => null,
    );

    $classifiedType = 'RAW';
    if (is_array($decoded)) {
        $decodedType = strtoupper(trim(strval($decoded['type'] ?? 'RAW')));
        if ($decodedType === 'RAW' && !empty($decoded['packet']) && is_array($decoded['packet'])) {
            $newPacket['header'] = intval($decoded['packet']['header'] ?? $newPacket['header']);
            $newPacket['path'] = strval($decoded['packet']['path'] ?? $newPacket['path']);
            $newPacket['payload_hex'] = strtoupper(strval($decoded['packet']['payload'] ?? $newPacket['payload_hex']));
            $newPacket['decoded'] = !empty($decoded['packet']['decoded']) ? 1 : 0;
            $newPacket['hash_size'] = intval($decoded['packet']['hash_size'] ?? $newPacket['hash_size']);
            $newPacket['scope'] = normalizeNullableInt($decoded['packet']['scope'] ?? $newPacket['scope']);
            $newPacket['route_type'] = normalizeNullableInt($decoded['packet']['route_type'] ?? $newPacket['route_type']);
        }

        $newPacket['contact_id'] = resolveBackfilledContactId($decoded, $meshlog);

        switch ($decodedType) {
            case 'ADV':
                $stats['adv_rows']++;
                $classifiedType = 'ADV';
                break;
            case 'PUB':
                $stats['pub_rows']++;
                $classifiedType = 'PUB';
                break;
            case 'RAW':
                $payloadType = (($newPacket['header'] >> 2) & 0x0F);
                if (in_array($payloadType, array(
                    MeshLogMqttDecoder::PAYLOAD_TYPE_REQ,
                    MeshLogMqttDecoder::PAYLOAD_TYPE_RESPONSE,
                    MeshLogMqttDecoder::PAYLOAD_TYPE_TXT_MSG,
                    MeshLogMqttDecoder::PAYLOAD_TYPE_ANON_REQ,
                ), true)) {
                    $stats['dir_rows']++;
                    $classifiedType = 'DIR';
                } elseif ($payloadType === MeshLogMqttDecoder::PAYLOAD_TYPE_CONTROL) {
                    $stats['ctrl_rows']++;
                    $classifiedType = 'CTRL';
                } else {
                    $stats['raw_rows']++;
                }
                if (!empty($newPacket['decoded'])) {
                    $stats['decoded_rows']++;
                }
                break;
            default:
                $stats['raw_rows']++;
                break;
        }
    } else {
        $stats['raw_rows']++;
    }

    $storedHeader = intval($row['header'] ?? 0);
    $storedPath = strval($row['path'] ?? '');
    $storedDecoded = !empty($row['decoded']) ? 1 : 0;
    $storedHashSize = intval($row['hash_size'] ?? 1);
    $storedScope = normalizeNullableInt($row['scope'] ?? null);
    $storedRouteType = normalizeNullableInt($row['route_type'] ?? null);
    $storedContactId = normalizeNullableInt($row['contact_id'] ?? null);

    $clearlyBroken = ($storedHeader !== $newPacket['header'])
        || ($storedPath !== $newPacket['path'])
        || ($storedPayloadHex !== $newPacket['payload_hex'])
        || ($storedDecoded !== $newPacket['decoded'])
        || ($storedHashSize !== $newPacket['hash_size'])
        || ($storedScope !== $newPacket['scope'])
        || ($storedRouteType !== $newPacket['route_type'])
        || ($storedContactId !== $newPacket['contact_id']);

    if (!$clearlyBroken) {
        $stats['skipped_unchanged']++;
        if ($verbose) {
            echo "skip id={$row['id']} reason=unchanged type={$classifiedType}\n";
        }
        continue;
    }

    $stats['repairable']++;
    if ($verbose || !$apply) {
        echo sprintf(
            "%s id=%d reporter=%d header=%d->%d type=%s decoded=%d->%d payload_len=%d->%d\n",
            $apply ? 'repair' : 'plan',
            intval($row['id']),
            intval($row['reporter_id']),
            $storedHeader,
            $newPacket['header'],
            $classifiedType,
            $storedDecoded,
            $newPacket['decoded'],
            intval(strlen($storedPayloadHex) / 2),
            intval(strlen($newPacket['payload_hex']) / 2)
        );
    }

    if (!$apply) {
        continue;
    }

    $payloadBinary = hex2bin($newPacket['payload_hex']);
    if ($payloadBinary === false) {
        $stats['errors']++;
        fwrite(STDERR, "Failed to convert payload hex for raw_packets.id={$row['id']}\n");
        continue;
    }

    try {
        $updateStmt = $meshlog->pdo->prepare(
            'UPDATE raw_packets
             SET header = :header,
                 path = :path,
                 payload = :payload,
                 decoded = :decoded,
                 hash_size = :hash_size,
                 scope = :scope,
                 route_type = :route_type,
                 contact_id = :contact_id
             WHERE id = :id'
        );
        $updateStmt->bindValue(':header', $newPacket['header'], PDO::PARAM_INT);
        $updateStmt->bindValue(':path', $newPacket['path'], PDO::PARAM_STR);
        $updateStmt->bindValue(':payload', $payloadBinary, PDO::PARAM_LOB);
        $updateStmt->bindValue(':decoded', $newPacket['decoded'], PDO::PARAM_INT);
        $updateStmt->bindValue(':hash_size', $newPacket['hash_size'], PDO::PARAM_INT);
        if ($newPacket['scope'] === null) {
            $updateStmt->bindValue(':scope', null, PDO::PARAM_NULL);
        } else {
            $updateStmt->bindValue(':scope', $newPacket['scope'], PDO::PARAM_INT);
        }
        if ($newPacket['route_type'] === null) {
            $updateStmt->bindValue(':route_type', null, PDO::PARAM_NULL);
        } else {
            $updateStmt->bindValue(':route_type', $newPacket['route_type'], PDO::PARAM_INT);
        }
        if ($newPacket['contact_id'] === null) {
            $updateStmt->bindValue(':contact_id', null, PDO::PARAM_NULL);
        } else {
            $updateStmt->bindValue(':contact_id', $newPacket['contact_id'], PDO::PARAM_INT);
        }
        $updateStmt->bindValue(':id', intval($row['id']), PDO::PARAM_INT);
        $updateStmt->execute();
        $stats['updated']++;
    } catch (Throwable $e) {
        $stats['errors']++;
        fwrite(STDERR, "Failed to repair raw_packets.id={$row['id']}: {$e->getMessage()}\n");
    }
}

if ($apply && $stats['updated'] > 0) {
    $meshlog->rebuildCollectorPacketStatsRollup();
}

echo json_encode(array(
    'mode' => $apply ? 'apply' : 'dry-run',
    'stats' => $stats,
    'rollups_rebuilt' => ($apply && $stats['updated'] > 0),
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
