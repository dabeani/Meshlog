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
            'report_format' => MeshLogReporter::normalizeFormat($reporter->report_format ?? MeshLogReporter::FORMAT_MESHLOG),
            'iata_code' => MeshLogReporter::normalizeIataCode($reporter->iata_code ?? ''),
            'lat' => strval($reporter->lat),
            'lon' => strval($reporter->lon),
            'authorized' => intval($reporter->authorized ?? 0),
            'reporter_pending' => intval($reporter->reporter_pending ?? 0),
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

    function normalizeReporterKeys($values) {
        $normalized = array();
        foreach ($values as $value) {
            $value = strtoupper(trim(strval($value)));
            if ($value === '') continue;
            $normalized[$value] = true;
        }
        return array_keys($normalized);
    }

    function sqlPlaceholders($count) {
        if ($count <= 0) return '';
        return implode(',', array_fill(0, $count, '?'));
    }

    function pickLatestSqlTimestamp($first, $second) {
        $firstTs = $first ? strtotime(strval($first)) : false;
        $secondTs = $second ? strtotime(strval($second)) : false;

        if ($firstTs === false) return $second ?: null;
        if ($secondTs === false) return $first ?: null;

        return $firstTs >= $secondTs ? $first : $second;
    }

    function fetchContactsByPublicKey($meshlog, $publicKeys) {
        $publicKeys = normalizeReporterKeys($publicKeys);
        if (count($publicKeys) < 1) {
            return array();
        }

        $stmt = $meshlog->pdo->prepare(
            'SELECT * FROM contacts WHERE public_key IN (' . sqlPlaceholders(count($publicKeys)) . ')'
        );
        foreach ($publicKeys as $index => $publicKey) {
            $stmt->bindValue($index + 1, $publicKey, PDO::PARAM_STR);
        }
        $stmt->execute();

        $contacts = array();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $contact = MeshLogContact::fromDb($row, $meshlog);
            if (!$contact) continue;
            $contacts[strtoupper(strval($row['public_key'] ?? ''))] = $contact->asArray();
        }

        return $contacts;
    }

    function fetchLatestAdvertisementsByContactId($meshlog, $contactIds) {
        $contactIds = array_values(array_unique(array_filter(array_map('intval', $contactIds), function($value) {
            return $value > 0;
        })));
        if (count($contactIds) < 1) {
            return array();
        }

        $sql = '
            SELECT a.id, a.contact_id, a.hash, a.name, a.lat, a.lon, a.type, a.flags, a.hash_size, a.sent_at, a.created_at
            FROM advertisements a
            INNER JOIN (
                SELECT contact_id, MAX(id) AS latest_id
                FROM advertisements
                WHERE contact_id IN (' . sqlPlaceholders(count($contactIds)) . ')
                GROUP BY contact_id
            ) latest ON latest.latest_id = a.id
        ';
        $stmt = $meshlog->pdo->prepare($sql);
        foreach ($contactIds as $index => $contactId) {
            $stmt->bindValue($index + 1, $contactId, PDO::PARAM_INT);
        }
        $stmt->execute();

        $advertisements = array();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $contactId = intval($row['contact_id'] ?? 0);
            if ($contactId <= 0) continue;
            $advertisements[$contactId] = array(
                'id' => intval($row['id'] ?? 0),
                'contact_id' => $contactId,
                'hash' => $row['hash'] ?? null,
                'name' => $row['name'] ?? null,
                'lat' => isset($row['lat']) ? floatval($row['lat']) : null,
                'lon' => isset($row['lon']) ? floatval($row['lon']) : null,
                'type' => intval($row['type'] ?? 0),
                'flags' => intval($row['flags'] ?? 0),
                'hash_size' => intval($row['hash_size'] ?? 1),
                'sent_at' => $row['sent_at'] ?? null,
                'created_at' => $row['created_at'] ?? null,
            );
        }

        return $advertisements;
    }

    function fetchReporterActivityMap($meshlog, $reporterIds) {
        $reporterIds = array_values(array_unique(array_filter(array_map('intval', $reporterIds), function($value) {
            return $value > 0;
        })));
        if (count($reporterIds) < 1) {
            return array();
        }

        $placeholders = sqlPlaceholders(count($reporterIds));
        $sql = '
            SELECT reporter_id, MAX(ts) AS last_activity_at
            FROM (
                SELECT reporter_id, MAX(received_at) AS ts FROM advertisement_reports WHERE reporter_id IN (' . $placeholders . ') GROUP BY reporter_id
                UNION ALL
                SELECT reporter_id, MAX(received_at) AS ts FROM direct_message_reports WHERE reporter_id IN (' . $placeholders . ') GROUP BY reporter_id
                UNION ALL
                SELECT reporter_id, MAX(received_at) AS ts FROM channel_message_reports WHERE reporter_id IN (' . $placeholders . ') GROUP BY reporter_id
                UNION ALL
                SELECT reporter_id, MAX(received_at) AS ts FROM raw_packets WHERE reporter_id IN (' . $placeholders . ') GROUP BY reporter_id
                UNION ALL
                SELECT reporter_id, MAX(received_at) AS ts FROM telemetry WHERE reporter_id IN (' . $placeholders . ') GROUP BY reporter_id
                UNION ALL
                SELECT reporter_id, MAX(received_at) AS ts FROM system_reports WHERE reporter_id IN (' . $placeholders . ') GROUP BY reporter_id
            ) activity
            GROUP BY reporter_id
        ';
        $stmt = $meshlog->pdo->prepare($sql);

        $bindIndex = 1;
        for ($repeat = 0; $repeat < 6; $repeat++) {
            foreach ($reporterIds as $reporterId) {
                $stmt->bindValue($bindIndex++, $reporterId, PDO::PARAM_INT);
            }
        }
        $stmt->execute();

        $activity = array();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $activity[intval($row['reporter_id'] ?? 0)] = $row['last_activity_at'] ?? null;
        }

        return $activity;
    }

    if (isset($_POST['approve'])) {
        $id = intval($_POST['id'] ?? 0);
        $reporter = MeshLogReporter::findById($id, $meshlog);
        if (!$reporter || !intval($reporter->reporter_pending ?? 0)) {
            $errors[] = 'Reporter not found or not pending';
        } else {
            $reporter->authorized = 1;
            $reporter->reporter_pending = 0;
            $reporter->name = htmlspecialchars(trim($_POST['name'] ?? $reporter->name ?? ''), ENT_QUOTES, 'UTF-8');
            if (array_key_exists('auth', $_POST)) {
                $reporter->auth = trim($_POST['auth']);
            }
            $reporter->style = trim($_POST['style'] ?? '{"color":"#4ea4c4"}');
            $reporter->report_format = MeshLogReporter::normalizeFormat($_POST['report_format'] ?? MeshLogReporter::FORMAT_MESHLOG);
            $reporter->iata_code = MeshLogReporter::normalizeIataCode($_POST['iata_code'] ?? '');
            $reporter->lat = floatval($_POST['lat'] ?? 0);
            $reporter->lon = floatval($_POST['lon'] ?? 0);
            if ($reporter->save($meshlog)) {
                $actor = is_object($user) ? $user->name : ($user['name'] ?? 'admin');
                $meshlog->auditLog(
                    \MeshLogAuditLog::EVENT_REPORTER_SAVE,
                    $actor,
                    'approved reporter ' . ($reporter->name ?? '') . ' [' . ($reporter->public_key ?? '') . ']'
                );
                $results = array('status' => 'OK', 'reporter' => $reporter->asArray());
            } else {
                $errors[] = 'Failed to approve: ' . $reporter->getError();
            }
        }
    } else if (isset($_POST['add']) || isset($_POST['edit'])) {
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

        if (array_key_exists('auth', $_POST)) {
            $reporter->auth = htmlspecialchars($_POST['auth'], ENT_QUOTES, 'UTF-8');
        } else if ($isAdd) {
            $reporter->auth = '';
        }
        
        $reporter->authorized = intval($_POST['authorized'] ?? 0);
        $reporter->style = $_POST['style'] ?? '';
        if (!$reporter->style && !intval($reporter->reporter_pending ?? 0)) $errors[] = 'Missing style';
        if (!$reporter->style) $reporter->style = '{"color":"#888888"}';
        
        $reporter->report_format = MeshLogReporter::normalizeFormat($_POST['report_format'] ?? MeshLogReporter::FORMAT_MESHLOG);
        $reporter->iata_code = MeshLogReporter::normalizeIataCode($_POST['iata_code'] ?? '');
        $reporter->reporter_pending = intval($_POST['reporter_pending'] ?? 0);

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
        $stmt = $meshlog->pdo->query('SELECT * FROM reporters ORDER BY id ASC');
        $reporterRows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();

        $objects = array();
        $publicKeys = array();
        $reporterIds = array();
        foreach ($reporterRows as $reporterRow) {
            $reporter = MeshLogReporter::fromDb($reporterRow, $meshlog);
            if (!$reporter) continue;
            $row = $reporter->asArray(true);
            $objects[] = $row;
            if (!empty($row['public_key'])) {
                $publicKeys[] = $row['public_key'];
            }
            if (!empty($row['id'])) {
                $reporterIds[] = intval($row['id']);
            }
        }

        $contactMap = fetchContactsByPublicKey($meshlog, $publicKeys);
        $contactIds = array();
        foreach ($contactMap as $contact) {
            $contactIds[] = intval($contact['id'] ?? 0);
        }
        $advertisementMap = fetchLatestAdvertisementsByContactId($meshlog, $contactIds);
        $activityMap = fetchReporterActivityMap($meshlog, $reporterIds);
        $timeSyncMap = $meshlog->getReporterTimeSyncMap($publicKeys);

        foreach ($objects as $index => $row) {
            $publicKey = strtoupper(strval($row['public_key'] ?? ''));
            $contact = $contactMap[$publicKey] ?? null;
            $contactId = intval($contact['id'] ?? 0);
            $advertisement = $contactId > 0 ? ($advertisementMap[$contactId] ?? null) : null;
            $lastHeardAt = pickLatestSqlTimestamp(
                $activityMap[intval($row['id'] ?? 0)] ?? null,
                $contact['last_heard_at'] ?? null
            );

            $objects[$index]['contact'] = $contact;
            $objects[$index]['contact_id'] = $contactId > 0 ? $contactId : null;
            $objects[$index]['contact_type'] = $advertisement ? intval($advertisement['type'] ?? 0) : null;
            $objects[$index]['advertisement'] = $advertisement;
            $objects[$index]['last_heard_at'] = $lastHeardAt;
            $objects[$index]['connection_state'] = 'never-seen';
            if (!empty($lastHeardAt)) {
                $lastHeardTs = strtotime($lastHeardAt);
                if ($lastHeardTs !== false && (time() - $lastHeardTs) <= $connectedAgeSeconds) {
                    $objects[$index]['connection_state'] = 'connected';
                } else {
                    $objects[$index]['connection_state'] = 'disconnected';
                }
            }

            $timeSync = $timeSyncMap[strtoupper(strval($row['public_key'] ?? ''))] ?? null;
            if ($timeSync) {
                $objects[$index]['time_sync'] = $timeSync;
            }
            unset($objects[$index]['auth']);
        }

        $results = array(
            'status' => 'OK',
            'objects' => $objects,
        );
    }

    if (sizeof($errors)) {
        $results = array(
            'status' => 'error',
            'error' => implode("\n", $errors)
        );
    }

    echo json_encode($results, JSON_PRETTY_PRINT);
