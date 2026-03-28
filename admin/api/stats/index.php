<?php
    require_once __DIR__ . '/../loggedin.php';

    function clampInt($value, $min, $max, $defaultValue) {
        if (!is_numeric($value)) {
            return $defaultValue;
        }
        $intVal = intval($value);
        if ($intVal < $min) {
            return $min;
        }
        if ($intVal > $max) {
            return $max;
        }
        return $intVal;
    }

    function retentionSecondsFromSetting($value) {
        $seconds = intval($value);
        if ($seconds <= 0) {
            return 0;
        }
        // Keep retention semantics aligned with admin input (days) while
        // still supporting existing second-based values larger than 365.
        if ($seconds <= 365) {
            return $seconds * 86400;
        }
        return $seconds;
    }

    function scalarQuery($pdo, $sql, $params = array()) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    function hasColumn($pdo, $tableName, $columnName) {
        $stmt = $pdo->prepare('
            SELECT COUNT(*)
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = :table_name
              AND column_name = :column_name
        ');
        $stmt->execute(array(
            ':table_name' => $tableName,
            ':column_name' => $columnName,
        ));
        return intval($stmt->fetchColumn()) > 0;
    }

    $windowHours = clampInt($_GET['window_hours'] ?? 24, 1, 168, 24);

    try {
        $pdo = $meshlog->pdo;
        $databaseName = strval(scalarQuery($pdo, 'SELECT DATABASE()'));

        $sizeStmt = $pdo->prepare('
            SELECT table_name, table_rows, data_length, index_length
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
        ');
        $sizeStmt->execute();
        $sizeRows = $sizeStmt->fetchAll(PDO::FETCH_ASSOC);

        $tableSizeMap = array();
        $databaseBytes = 0;
        foreach ($sizeRows as $row) {
            $table = strval($row['table_name'] ?? '');
            $bytes = intval($row['data_length'] ?? 0) + intval($row['index_length'] ?? 0);
            $databaseBytes += $bytes;
            $tableSizeMap[$table] = array(
                'estimated_rows' => intval($row['table_rows'] ?? 0),
                'bytes' => $bytes,
            );
        }

        $tableSpecs = array(
            'reporters' => null,
            'contacts' => 'last_heard_at',
            'advertisements' => 'created_at',
            'advertisement_reports' => 'received_at',
            'direct_messages' => 'created_at',
            'direct_message_reports' => 'received_at',
            'channel_messages' => 'created_at',
            'channel_message_reports' => 'received_at',
            'raw_packets' => 'received_at',
            'telemetry' => 'received_at',
            'system_reports' => 'received_at',
            'audit_log' => 'created_at',
            'channels' => null,
            'users' => null,
            'settings' => null,
        );

        $tables = array();
        foreach ($tableSpecs as $tableName => $timeColumn) {
            $tableData = array(
                'name' => $tableName,
                'rows' => intval(scalarQuery($pdo, "SELECT COUNT(*) FROM {$tableName}")),
                'bytes' => intval($tableSizeMap[$tableName]['bytes'] ?? 0),
                'estimated_rows' => intval($tableSizeMap[$tableName]['estimated_rows'] ?? 0),
            );

            if ($timeColumn !== null) {
                $rangeStmt = $pdo->prepare("SELECT MIN({$timeColumn}) AS oldest_at, MAX({$timeColumn}) AS newest_at FROM {$tableName}");
                $rangeStmt->execute();
                $range = $rangeStmt->fetch(PDO::FETCH_ASSOC) ?: array();

                $windowStmt = $pdo->prepare("SELECT COUNT(*) FROM {$tableName} WHERE {$timeColumn} >= DATE_SUB(NOW(), INTERVAL :window HOUR)");
                $windowStmt->bindValue(':window', $windowHours, PDO::PARAM_INT);
                $windowStmt->execute();

                $tableData['oldest_at'] = $range['oldest_at'] ?? null;
                $tableData['newest_at'] = $range['newest_at'] ?? null;
                $tableData['rows_in_window'] = intval($windowStmt->fetchColumn());
            }

            $tables[] = $tableData;
        }

        $rawPacketTypeExpr = hasColumn($pdo, 'raw_packets', 'packet_type')
            ? '`packet_type`'
            : '((`header` >> 2) & 15)';

        $packetTypeStmt = $pdo->prepare('
            SELECT ' . $rawPacketTypeExpr . ' AS packet_type, COUNT(*) AS count
            FROM raw_packets
            WHERE received_at >= DATE_SUB(NOW(), INTERVAL :window HOUR)
            GROUP BY packet_type
            ORDER BY count DESC
        ');
        $packetTypeStmt->bindValue(':window', $windowHours, PDO::PARAM_INT);
        $packetTypeStmt->execute();
        $packetTypes = $packetTypeStmt->fetchAll(PDO::FETCH_ASSOC);

        $ingestBreakdown = array(
            array('type' => 'ADV reports', 'count' => intval(scalarQuery($pdo, 'SELECT COUNT(*) FROM advertisement_reports WHERE received_at >= DATE_SUB(NOW(), INTERVAL :window HOUR)', array(':window' => $windowHours)))),
            array('type' => 'MSG reports', 'count' => intval(scalarQuery($pdo, 'SELECT COUNT(*) FROM direct_message_reports WHERE received_at >= DATE_SUB(NOW(), INTERVAL :window HOUR)', array(':window' => $windowHours)))),
            array('type' => 'PUB reports', 'count' => intval(scalarQuery($pdo, 'SELECT COUNT(*) FROM channel_message_reports WHERE received_at >= DATE_SUB(NOW(), INTERVAL :window HOUR)', array(':window' => $windowHours)))),
            array('type' => 'RAW packets', 'count' => intval(scalarQuery($pdo, 'SELECT COUNT(*) FROM raw_packets WHERE received_at >= DATE_SUB(NOW(), INTERVAL :window HOUR)', array(':window' => $windowHours)))),
            array('type' => 'Telemetry', 'count' => intval(scalarQuery($pdo, 'SELECT COUNT(*) FROM telemetry WHERE received_at >= DATE_SUB(NOW(), INTERVAL :window HOUR)', array(':window' => $windowHours)))),
            array('type' => 'System reports', 'count' => intval(scalarQuery($pdo, 'SELECT COUNT(*) FROM system_reports WHERE received_at >= DATE_SUB(NOW(), INTERVAL :window HOUR)', array(':window' => $windowHours)))),
        );

        $topReportersStmt = $pdo->prepare('
            SELECT
                r.id,
                r.name,
                r.public_key,
                COALESCE(ar.cnt, 0) + COALESCE(dr.cnt, 0) + COALESCE(cr.cnt, 0) + COALESCE(rr.cnt, 0) + COALESCE(tr.cnt, 0) + COALESCE(sr.cnt, 0) AS packets_in_window
            FROM reporters r
            LEFT JOIN (
                SELECT reporter_id, COUNT(*) AS cnt
                FROM advertisement_reports
                WHERE received_at >= DATE_SUB(NOW(), INTERVAL :window HOUR)
                GROUP BY reporter_id
            ) ar ON ar.reporter_id = r.id
            LEFT JOIN (
                SELECT reporter_id, COUNT(*) AS cnt
                FROM direct_message_reports
                WHERE received_at >= DATE_SUB(NOW(), INTERVAL :window HOUR)
                GROUP BY reporter_id
            ) dr ON dr.reporter_id = r.id
            LEFT JOIN (
                SELECT reporter_id, COUNT(*) AS cnt
                FROM channel_message_reports
                WHERE received_at >= DATE_SUB(NOW(), INTERVAL :window HOUR)
                GROUP BY reporter_id
            ) cr ON cr.reporter_id = r.id
            LEFT JOIN (
                SELECT reporter_id, COUNT(*) AS cnt
                FROM raw_packets
                WHERE received_at >= DATE_SUB(NOW(), INTERVAL :window HOUR)
                GROUP BY reporter_id
            ) rr ON rr.reporter_id = r.id
            LEFT JOIN (
                SELECT reporter_id, COUNT(*) AS cnt
                FROM telemetry
                WHERE received_at >= DATE_SUB(NOW(), INTERVAL :window HOUR)
                GROUP BY reporter_id
            ) tr ON tr.reporter_id = r.id
            LEFT JOIN (
                SELECT reporter_id, COUNT(*) AS cnt
                FROM system_reports
                WHERE received_at >= DATE_SUB(NOW(), INTERVAL :window HOUR)
                GROUP BY reporter_id
            ) sr ON sr.reporter_id = r.id
            ORDER BY packets_in_window DESC, r.id ASC
            LIMIT 25
        ');
        $topReportersStmt->bindValue(':window', $windowHours, PDO::PARAM_INT);
        $topReportersStmt->execute();
        $topReporters = $topReportersStmt->fetchAll(PDO::FETCH_ASSOC);

        $retAdvSeconds = retentionSecondsFromSetting($meshlog->getConfig(MeshLogSetting::KEY_DATA_RETENTION_ADV, 0));
        $retMsgSeconds = retentionSecondsFromSetting($meshlog->getConfig(MeshLogSetting::KEY_DATA_RETENTION_MSG, 0));
        $retRawSeconds = retentionSecondsFromSetting($meshlog->getConfig(MeshLogSetting::KEY_DATA_RETENTION_RAW, 0));
        $lastPurgeAtUnix = intval($meshlog->getConfig(MeshLogSetting::KEY_LAST_PURGE_AT, 0));

        $maxRetentionSeconds = max($retAdvSeconds, $retMsgSeconds, $retRawSeconds);
        $autoPurgeEnabled = $maxRetentionSeconds > 0;
        $nowUnix = time();
        $cooldownRemainingSeconds = 0;
        if ($autoPurgeEnabled && $lastPurgeAtUnix > 0) {
            $cooldownRemainingSeconds = max(0, MeshLog::PURGE_INTERVAL_SECONDS - ($nowUnix - $lastPurgeAtUnix));
        }
        $nextAutoPurgeAtUnix = null;
        $autoPurgeState = 'disabled';
        if ($autoPurgeEnabled) {
            if ($cooldownRemainingSeconds > 0) {
                $autoPurgeState = 'cooldown';
                $nextAutoPurgeAtUnix = $nowUnix + $cooldownRemainingSeconds;
            } else {
                $autoPurgeState = 'ready';
                $nextAutoPurgeAtUnix = $nowUnix;
            }
        }

        $retention = array(
            'advertisements_days' => $retAdvSeconds > 0 ? intval(ceil($retAdvSeconds / 86400)) : 0,
            'messages_days' => $retMsgSeconds > 0 ? intval(ceil($retMsgSeconds / 86400)) : 0,
            'raw_packets_days' => $retRawSeconds > 0 ? intval(ceil($retRawSeconds / 86400)) : 0,
            'advertisements_seconds' => $retAdvSeconds,
            'messages_seconds' => $retMsgSeconds,
            'raw_packets_seconds' => $retRawSeconds,
            'last_purge_at_unix' => $lastPurgeAtUnix,
            'auto_purge' => array(
                'enabled' => $autoPurgeEnabled,
                'state' => $autoPurgeState,
                'purge_interval_seconds' => MeshLog::PURGE_INTERVAL_SECONDS,
                'cooldown_remaining_seconds' => $cooldownRemainingSeconds,
                'next_auto_purge_at_unix' => $nextAutoPurgeAtUnix,
                'trigger' => 'ingest',
            ),
        );

        $results = array(
            'status' => 'OK',
            'generated_at' => gmdate('Y-m-d H:i:s') . ' UTC',
            'database' => array(
                'name' => $databaseName,
                'total_bytes' => $databaseBytes,
                'table_count' => count($tableSpecs),
            ),
            'window_hours' => $windowHours,
            'retention' => $retention,
            'tables' => $tables,
            'ingest_breakdown' => $ingestBreakdown,
            'raw_packet_type_breakdown' => $packetTypes,
            'top_reporters' => $topReporters,
        );
    } catch (Throwable $e) {
        $results = array(
            'status' => 'error',
            'error' => $e->getMessage(),
        );
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($results);
?>
