<?php
    require_once __DIR__ . '/../loggedin.php';

    $errors = array();
    $results = array('status' => 'unknown');
    $connectedAgeSeconds = 7200;

    function findReporterLastActivity($meshlog, $reporterId, $publicKey) {
        $sql = "
            SELECT MAX(ts) AS last_activity_at
            FROM (
                SELECT MAX(received_at) AS ts FROM advertisement_reports WHERE reporter_id = :reporter_id
                UNION ALL
                SELECT MAX(received_at) AS ts FROM direct_message_reports WHERE reporter_id = :reporter_id
                UNION ALL
                SELECT MAX(received_at) AS ts FROM channel_message_reports WHERE reporter_id = :reporter_id
                UNION ALL
                SELECT MAX(received_at) AS ts FROM raw_packets WHERE reporter_id = :reporter_id
                UNION ALL
                SELECT MAX(received_at) AS ts FROM telemetry WHERE reporter_id = :reporter_id
                UNION ALL
                SELECT MAX(last_heard_at) AS ts FROM contacts WHERE public_key = :public_key
            ) activity
        ";

        $stmt = $meshlog->pdo->prepare($sql);
        $stmt->bindValue(':reporter_id', intval($reporterId), PDO::PARAM_INT);
        $stmt->bindValue(':public_key', strval($publicKey), PDO::PARAM_STR);
        $stmt->execute();

        $value = $stmt->fetchColumn();
        return $value !== false && $value !== null && $value !== '' ? $value : null;
    }

    function reporterSnapshot($reporter) {
        if (!$reporter) return array();
        return array(
            'name' => $reporter->name,
            'public_key' => $reporter->public_key,
            'hash_size' => intval($reporter->hash_size ?? 1),
            'report_format' => MeshLogReporter::normalizeFormat($reporter->report_format ?? MeshLogReporter::FORMAT_MESHLOG),
            'iata_code' => MeshLogReporter::normalizeIataCode($reporter->iata_code ?? ''),
            'lat' => strval($reporter->lat),
            'lon' => strval($reporter->lon),
            'auth' => $reporter->auth,
            'authorized' => intval($reporter->authorized ?? 0),
            'style' => $reporter->style,
        );
    }

    function diffAssocValues($oldValues, $newValues) {
        $changes = array();
        foreach ($newValues as $key => $newValue) {
            $oldValue = $oldValues[$key] ?? null;
            if ((string)$oldValue !== (string)$newValue) {
                $changes[] = "$key: {$oldValue} → {$newValue}";
            }
        }
        return $changes;
    }

    function enrichReporterRow($meshlog, $row, $connectedAgeSeconds) {
        $contact = MeshLogContact::findBy('public_key', $row['public_key'], $meshlog, array());
        $row['contact'] = null;
        $row['contact_id'] = null;
        $row['contact_type'] = null;
        $row['last_heard_at'] = null;
        $row['connection_state'] = 'never-seen';

        if ($contact) {
            $row['contact'] = $contact->asArray();
            $row['contact_id'] = $contact->getId();
            $advertisement = MeshLogAdvertisement::findBy('contact_id', $contact->getId(), $meshlog, array());
            if ($advertisement) {
                $row['contact_type'] = intval($advertisement->type);
                $row['advertisement'] = $advertisement->asArray();
            }
        }

        $row['last_heard_at'] = findReporterLastActivity($meshlog, intval($row['id']), strval($row['public_key']));
        if (!empty($row['last_heard_at'])) {
            $lastHeardTs = strtotime($row['last_heard_at']);
            if ($lastHeardTs !== false && (time() - $lastHeardTs) <= $connectedAgeSeconds) {
                $row['connection_state'] = 'connected';
            } else {
                $row['connection_state'] = 'disconnected';
            }
        }

        return $row;
    }

    if (isset($_POST['add']) || isset($_POST['edit'])) {
        $isAdd = isset($_POST['add']);
        $reporter = new MeshLogReporter($meshlog);
        $before = array();
        if (isset($_POST['edit'])) {
            $id = $_POST['id'] ?? $errors[] = 'Missing id';
            $reporter = MeshLogReporter::findById($id, $meshlog);
            $before = reporterSnapshot($reporter);
        }

        $reporter->name = htmlspecialchars($_POST['name'] ?? '', ENT_QUOTES, 'UTF-8');
        if (!$reporter->name) $errors[] = 'Missing name';
        
        $reporter->public_key = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $_POST['public_key'] ?? ''));
        if (!$reporter->public_key) $errors[] = 'Missing or invalid public key';
        if ($reporter->public_key && strlen($reporter->public_key) < 4) {
            $errors[] = 'Public key must be at least 4 hex characters';
            $reporter->public_key = '';
        }
        
        $reporter->lat = floatval($_POST['lat'] ?? 0);
        $reporter->lon = floatval($_POST['lon'] ?? 0);
        $reporter->auth = htmlspecialchars($_POST['auth'] ?? '', ENT_QUOTES, 'UTF-8');
        if (!$reporter->auth) $errors[] = 'Missing auth key';
        
        $reporter->authorized = intval($_POST['authorized'] ?? 0);
        $reporter->style = $_POST['style'] ?? '';
        if (!$reporter->style) $errors[] = 'Missing style';
        
        $reporter->hash_size = intval($_POST['hash_size'] ?? 1);
        $reporter->report_format = MeshLogReporter::normalizeFormat($_POST['report_format'] ?? MeshLogReporter::FORMAT_MESHLOG);
        $reporter->iata_code = MeshLogReporter::normalizeIataCode($_POST['iata_code'] ?? '');

        if (!sizeof($errors)) {
            // save
            if ($reporter->save($meshlog)) {
                $after = reporterSnapshot($reporter);
                $actor = is_object($user) ? $user->name : ($user['name'] ?? 'admin');
                if ($isAdd) {
                    $meshlog->auditLog(
                        \MeshLogAuditLog::EVENT_REPORTER_SAVE,
                        $actor,
                        'created reporter ' . ($reporter->name ?? '') . ' [' . ($reporter->public_key ?? '') . ']'
                    );
                } else {
                    $changes = diffAssocValues($before, $after);
                    if (!empty($changes)) {
                        $meshlog->auditLog(
                            \MeshLogAuditLog::EVENT_REPORTER_SAVE,
                            $actor,
                            'updated reporter ' . ($reporter->name ?? '') . ': ' . implode('; ', $changes)
                        );
                    }
                }

                $results = array(
                    'status' => 'OK',
                    'reported' => $reporter->asArray()
                );
            } else {
                $errors[] = 'Failed to save: ' . $reporter->getError();
            }
        }
    } else if (isset($_POST['delete'])) {
        $id = $_POST['id'] ?? $errors[] = 'Missing id';
        $reporter = MeshLogReporter::findById($id, $meshlog);
        if ($reporter && $reporter->delete()) {
            $actor = is_object($user) ? $user->name : ($user['name'] ?? 'admin');
            $meshlog->auditLog(
                \MeshLogAuditLog::EVENT_REPORTER_DELETE,
                $actor,
                'deleted reporter ' . ($reporter->name ?? '') . ' [' . ($reporter->public_key ?? '') . ']'
            );
            $results = array('status' => 'OK');
        } else{
            $errors[] = 'Failed to delete';
        }
    } else {
        $results = MeshLogReporter::getAll($meshlog, array('secret' => true, 'order' => 'ASC'));
        $publicKeys = array();
        foreach (($results['objects'] ?? array()) as $row) {
            if (!empty($row['public_key'])) {
                $publicKeys[] = $row['public_key'];
            }
        }
        $timeSyncMap = $meshlog->getReporterTimeSyncMap($publicKeys);
        $objects = array();
        foreach (($results['objects'] ?? array()) as $row) {
            $enriched = enrichReporterRow($meshlog, $row, $connectedAgeSeconds);
            $timeSync = $timeSyncMap[strtoupper(strval($row['public_key'] ?? ''))] ?? null;
            if ($timeSync) {
                $enriched['time_sync'] = $timeSync;
            }
            $objects[] = $enriched;
        }
        $results['objects'] = $objects;
    }

    if (sizeof($errors)) {
        $results = array(
            'status' => 'error',
            'error' => implode("\n", $errors)
        );
    }

    echo json_encode($results, JSON_PRETTY_PRINT);
