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

function buildLivePacketWhereClause($alias, $sinceMs, $beforeMs, &$binds) {
    $conditions = array();

    if ($sinceMs > 0) {
        $conditions[] = "$alias.created_at >= FROM_UNIXTIME(?)";
        $binds[] = intval(floor($sinceMs / 1000));
    }

    if ($beforeMs > 0) {
        $conditions[] = "$alias.created_at < FROM_UNIXTIME(?)";
        $binds[] = intval(floor($beforeMs / 1000));
    }

    if (count($conditions) < 1) {
        return '';
    }

    return ' WHERE ' . implode(' AND ', $conditions);
}

function buildLivePacketReferenceQuery($sinceMs, $beforeMs, array $types) {
    $parts = array();
    $binds = array();

    if (in_array('ADV', $types, true)) {
        $parts[] = "SELECT 'ADV' AS packet_type, a.id, a.created_at FROM advertisements a" . buildLivePacketWhereClause('a', $sinceMs, $beforeMs, $binds);
    }

    if (in_array('MSG', $types, true)) {
        $parts[] = "SELECT 'MSG' AS packet_type, d.id, d.created_at FROM direct_messages d" . buildLivePacketWhereClause('d', $sinceMs, $beforeMs, $binds);
    }

    if (in_array('PUB', $types, true)) {
        $parts[] = "SELECT 'PUB' AS packet_type, c.id, c.created_at FROM channel_messages c" . buildLivePacketWhereClause('c', $sinceMs, $beforeMs, $binds);
    }

    if (in_array('RAW', $types, true)) {
        $parts[] = "SELECT 'RAW' AS packet_type, r.id, r.created_at FROM raw_packets r" . buildLivePacketWhereClause('r', $sinceMs, $beforeMs, $binds);
    }

    if (in_array('TEL', $types, true)) {
        $parts[] = "SELECT 'TEL' AS packet_type, t.id, t.created_at FROM telemetry t" . buildLivePacketWhereClause('t', $sinceMs, $beforeMs, $binds);
    }

    if (in_array('SYS', $types, true)) {
        $parts[] = "SELECT 'SYS' AS packet_type, s.id, s.created_at FROM system_reports s" . buildLivePacketWhereClause('s', $sinceMs, $beforeMs, $binds);
    }

    return array($parts, $binds);
}

function fetchLivePacketReferences($meshlog, $sinceMs, $beforeMs, array $types, $limit, $offset = 0) {
    list($parts, $binds) = buildLivePacketReferenceQuery($sinceMs, $beforeMs, $types);
    if (count($parts) < 1) {
        return array();
    }

    $limit = max(1, intval($limit));
    $offset = max(0, intval($offset));
    $sql = "
        SELECT packet_type, id, created_at
        FROM (
            " . implode("\nUNION ALL\n", $parts) . "
        ) live_packets
        ORDER BY created_at DESC, id DESC, packet_type DESC
        LIMIT ? OFFSET ?
    ";

    $stmt = $meshlog->pdo->prepare($sql);
    $position = 1;
    foreach ($binds as $bind) {
        $stmt->bindValue($position++, $bind, PDO::PARAM_INT);
    }
    $stmt->bindValue($position++, $limit, PDO::PARAM_INT);
    $stmt->bindValue($position, $offset, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : array();
}

function fetchLivePacketsByReferences($meshlog, array $references) {
    if (count($references) < 1) {
        return array();
    }

    $idsByType = array(
        'ADV' => array(),
        'MSG' => array(),
        'PUB' => array(),
        'RAW' => array(),
        'TEL' => array(),
        'SYS' => array(),
    );

    foreach ($references as $reference) {
        $type = strtoupper(strval($reference['packet_type'] ?? ''));
        $id = intval($reference['id'] ?? 0);
        if ($id <= 0 || !array_key_exists($type, $idsByType)) continue;
        $idsByType[$type][$id] = $id;
    }

    $indexed = array();
    $fetchParams = function($ids) {
        return array(
            'offset' => 0,
            'count' => count($ids),
            'after_ms' => 0,
            'before_ms' => 0,
            'ids' => array_values($ids),
        );
    };

    if (count($idsByType['ADV']) > 0) {
        $rows = extractList($meshlog->getAdvertisementsQuick($fetchParams($idsByType['ADV'])), 'advertisements');
        foreach ($rows as $row) {
            $packet = $row;
            $packet['node_type'] = (string)$packet['type'];
            $packet['type'] = 'ADV';
            $indexed['ADV:' . intval($packet['id'] ?? 0)] = $packet;
        }
    }

    if (count($idsByType['MSG']) > 0) {
        $rows = extractList($meshlog->getDirectMessagesQuick($fetchParams($idsByType['MSG'])), 'direct_messages');
        foreach ($rows as $row) {
            $packet = $row;
            $packet['type'] = 'MSG';
            $indexed['MSG:' . intval($packet['id'] ?? 0)] = $packet;
        }
    }

    if (count($idsByType['PUB']) > 0) {
        $rows = extractList($meshlog->getChannelMessagesQuick($fetchParams($idsByType['PUB'])), 'channel_messages');
        foreach ($rows as $row) {
            $packet = $row;
            $packet['type'] = 'PUB';
            $indexed['PUB:' . intval($packet['id'] ?? 0)] = $packet;
        }
    }

    if (count($idsByType['RAW']) > 0) {
        $rows = extractList($meshlog->getRawPackets($fetchParams($idsByType['RAW'])), 'raw_packets');
        foreach ($rows as $row) {
            $packet = $row;
            $packet['type'] = 'RAW';
            $indexed['RAW:' . intval($packet['id'] ?? 0)] = $packet;
        }
    }

    if (count($idsByType['TEL']) > 0) {
        $rows = extractList($meshlog->getTelemetry($fetchParams($idsByType['TEL'])), 'telemetry');
        foreach ($rows as $row) {
            $packet = $row;
            $packet['type'] = 'TEL';
            $indexed['TEL:' . intval($packet['id'] ?? 0)] = $packet;
        }
    }

    if (count($idsByType['SYS']) > 0) {
        $rows = extractList($meshlog->getSystemReports($fetchParams($idsByType['SYS'])), 'system_reports');
        foreach ($rows as $row) {
            $packet = $row;
            $packet['type'] = 'SYS';
            $indexed['SYS:' . intval($packet['id'] ?? 0)] = $packet;
        }
    }

    $ordered = array();
    foreach ($references as $reference) {
        $key = strtoupper(strval($reference['packet_type'] ?? '')) . ':' . intval($reference['id'] ?? 0);
        if (!isset($indexed[$key])) continue;
        $ordered[] = $indexed[$key];
    }

    return $ordered;
}

function buildLivePacketBatch($meshlog, $sinceMs, $beforeMs, array $types, $limit, $historyMode = false, $offset = 0) {
    $fetchCount = max(1, intval($limit) + 1);
    $querySinceMs = $historyMode ? 0 : intval($sinceMs);
    $queryBeforeMs = intval($beforeMs);
    $references = fetchLivePacketReferences($meshlog, $querySinceMs, $queryBeforeMs, $types, $fetchCount, $offset);

    $hasMore = count($references) > $limit;
    $pageReferences = array_slice($references, 0, $limit);
    $packets = fetchLivePacketsByReferences($meshlog, $pageReferences);
    $packets = enrichPackets($packets, $meshlog->pdo);

    return array(
        'packets' => $packets,
        'has_more' => $hasMore,
        'oldest_timestamp_ms' => oldestPacketTimestampMs($packets),
        'newest_timestamp_ms' => newestPacketTimestampMs($packets),
        'returned_count' => count($pageReferences),
    );
}
