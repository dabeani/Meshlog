<?php
/**
 * Shared helpers for live-feed endpoints (index.php / stream.php).
 */

/**
 * Normalises the result from a MeshLog::get*Quick() call into a plain array.
 */
function extractList($result, $legacyKey) {
    if (!is_array($result)) return array();
    if (isset($result['objects']) && is_array($result['objects'])) return $result['objects'];
    if (isset($result[$legacyKey]) && is_array($result[$legacyKey])) return $result[$legacyKey];
    if (!empty($result) && array_keys($result) === range(0, count($result) - 1)) return $result;
    return array();
}

/**
 * Enriches a combined packets array with:
 *  - node_type preserved as integer string for ADV (caller must set this before overwriting type)
 *  - reporter_id lifted from reports[0] to root for ADV/MSG/PUB (enables collector filter)
 *  - received_at lifted from reports[0] to root for ADV/MSG/PUB (enables RX timestamp)
 *  - reporter_name for all types
 *  - contact_public_key + contact_name for all types that carry a contact_id
 *  - channel_name for PUB packets
 *
 * Uses three batch DB queries regardless of packet count.
 */
function enrichPackets(array $packets, $pdo) {
    if (empty($packets)) return $packets;

    $reporterIds = array();
    $contactIds  = array();
    $channelIds  = array();

    foreach ($packets as $p) {
        if (!empty($p['reporter_id']))                $reporterIds[(int)$p['reporter_id']] = true;
        if (!empty($p['reports'][0]['reporter_id']))  $reporterIds[(int)$p['reports'][0]['reporter_id']] = true;
        if (!empty($p['contact_id']))                 $contactIds[(int)$p['contact_id']] = true;
        if (!empty($p['channel_id']))                 $channelIds[(int)$p['channel_id']] = true;
    }

    // Helper: build IN clause placeholders
    $makeIn = function($ids) { return implode(',', array_fill(0, count($ids), '?')); };

    // Batch: reporter names
    $reporterNames = array();
    if (!empty($reporterIds)) {
        $ids  = array_keys($reporterIds);
        $stmt = $pdo->prepare("SELECT id, name FROM reporters WHERE id IN (" . $makeIn($ids) . ")");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $reporterNames[(int)$row['id']] = $row['name'];
        }
    }

    // Batch: contact name + public_key (exclude hidden contacts)
    $contactData = array();
    if (!empty($contactIds)) {
        $ids  = array_keys($contactIds);
        $stmt = $pdo->prepare("SELECT id, name, public_key FROM contacts WHERE hidden = 0 AND id IN (" . $makeIn($ids) . ")");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $contactData[(int)$row['id']] = array(
                'name'       => $row['name'],
                'public_key' => $row['public_key'],
            );
        }
    }

    // Batch: channel names
    $channelNames = array();
    if (!empty($channelIds)) {
        $ids  = array_keys($channelIds);
        $stmt = $pdo->prepare("SELECT id, name FROM channels WHERE id IN (" . $makeIn($ids) . ")");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $channelNames[(int)$row['id']] = $row['name'];
        }
    }

    foreach ($packets as &$p) {
        // Lift first-report fields to packet root (ADV/MSG/PUB only).
        if (in_array($p['type'], array('ADV', 'MSG', 'PUB'), true) && !empty($p['reports'])) {
            $first = $p['reports'][0];
            if (empty($p['reporter_id']) && !empty($first['reporter_id'])) {
                $p['reporter_id'] = (int)$first['reporter_id'];
            }
            if (empty($p['received_at']) && !empty($first['received_at'])) {
                $p['received_at'] = $first['received_at'];
            }
        }

        // reporter_name
        $rid = isset($p['reporter_id']) ? (int)$p['reporter_id'] : null;
        if ($rid !== null && isset($reporterNames[$rid])) {
            $p['reporter_name'] = $reporterNames[$rid];
        }

        // contact_public_key + contact_name
        $cid = isset($p['contact_id']) ? (int)$p['contact_id'] : null;
        if ($cid !== null && isset($contactData[$cid])) {
            $p['contact_public_key'] = $contactData[$cid]['public_key'];
            if (empty($p['contact_name'])) {
                $p['contact_name'] = $contactData[$cid]['name'];
            }
        }

        // channel_name for PUB
        if ($p['type'] === 'PUB' && !empty($p['channel_id']) && isset($channelNames[(int)$p['channel_id']])) {
            $p['channel_name'] = $channelNames[(int)$p['channel_id']];
        }
    }
    unset($p);

    return $packets;
}

function packetTimestampMs($packet) {
    if (!is_array($packet)) return null;

    foreach (array('created_at', 'received_at', 'sent_at') as $field) {
        if (empty($packet[$field])) continue;
        $unix = strtotime(strval($packet[$field]));
        if ($unix !== false && $unix > 0) {
            return intval($unix * 1000);
        }
    }

    return null;
}

function newestPacketTimestampMs(array $packets) {
    $newest = null;
    foreach ($packets as $packet) {
        $timestamp = packetTimestampMs($packet);
        if ($timestamp === null) continue;
        if ($newest === null || $timestamp > $newest) {
            $newest = $timestamp;
        }
    }
    return $newest;
}

function oldestPacketTimestampMs(array $packets) {
    $oldest = null;
    foreach ($packets as $packet) {
        $timestamp = packetTimestampMs($packet);
        if ($timestamp === null) continue;
        if ($oldest === null || $timestamp < $oldest) {
            $oldest = $timestamp;
        }
    }
    return $oldest;
}

function buildLivePacketBatch($meshlog, $sinceMs, $beforeMs, array $types, $limit, $historyMode = false) {
    $fetchCount = max(1, intval($limit) + 1);
    $queryWindow = array(
        'after_ms' => $historyMode ? 0 : intval($sinceMs),
        'before_ms' => $historyMode ? intval($beforeMs) : 0,
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

    if (in_array('ADV', $types, true)) {
        foreach ($advertisementRows as $adv) {
            $packet = $adv;
            $packet['node_type'] = (string)$packet['type'];
            $packet['type'] = 'ADV';
            $combined[] = $packet;
        }
    }

    if (in_array('MSG', $types, true)) {
        foreach ($messageRows as $msg) {
            $packet = $msg;
            $packet['type'] = 'MSG';
            $combined[] = $packet;
        }
    }

    if (in_array('PUB', $types, true)) {
        foreach ($channelMessageRows as $cmsg) {
            $packet = $cmsg;
            $packet['type'] = 'PUB';
            $combined[] = $packet;
        }
    }

    if (in_array('RAW', $types, true)) {
        foreach ($rawPacketRows as $raw) {
            $packet = $raw;
            $packet['type'] = 'RAW';
            $combined[] = $packet;
        }
    }

    if (in_array('TEL', $types, true)) {
        foreach ($telemetryRows as $tel) {
            $packet = $tel;
            $packet['type'] = 'TEL';
            $combined[] = $packet;
        }
    }

    if (in_array('SYS', $types, true)) {
        foreach ($systemReportRows as $sys) {
            $packet = $sys;
            $packet['type'] = 'SYS';
            $combined[] = $packet;
        }
    }

    $combined = enrichPackets($combined, $meshlog->pdo);

    usort($combined, function($a, $b) {
        return (packetTimestampMs($b) ?? 0) <=> (packetTimestampMs($a) ?? 0);
    });

    $hasMore = count($combined) > $limit;
    $packets = array_slice($combined, 0, $limit);

    return array(
        'packets' => $packets,
        'has_more' => $hasMore,
        'oldest_timestamp_ms' => oldestPacketTimestampMs($packets),
        'newest_timestamp_ms' => newestPacketTimestampMs($packets),
    );
}
