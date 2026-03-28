<?php
    require_once __DIR__ . '/../loggedin.php';

    $errors = array();
    $results = array('status' => 'unknown');
    $adminDefs = MeshLogSetting::getAdminDefinitions();

    function isRetentionKey($key) {
        return in_array($key, array(
            MeshLogSetting::KEY_DATA_RETENTION_ADV,
            MeshLogSetting::KEY_DATA_RETENTION_MSG,
            MeshLogSetting::KEY_DATA_RETENTION_RAW,
        ), true);
    }

    function daysToSeconds($days) {
        $days = intval($days);
        if ($days <= 0) {
            return 0;
        }
        return $days * 86400;
    }

    function secondsToDays($seconds) {
        $seconds = intval($seconds);
        if ($seconds <= 0) {
            return 0;
        }
        return max(1, (int)ceil($seconds / 86400));
    }

    function normalizeSettingValue($key, $value, $definitions, &$errors) {
        if (!isset($definitions[$key])) return null;

        $type = $definitions[$key]['type'] ?? 'text';
        if ($type === 'boolean') {
            return intval($value) ? 1 : 0;
        }

        if ($type === 'number') {
            if ($value === '' || !is_numeric($value)) {
                $errors[] = "Invalid value for $key";
                return null;
            }

            $normalized = intval($value);
            if ($normalized < 0) {
                $errors[] = "Invalid value for $key";
                return null;
            }

            if (isRetentionKey($key)) {
                // Admin retention fields are entered in days; DB stores seconds.
                return daysToSeconds($normalized);
            }

            return $normalized;
        }

        return trim(strval($value));
    }

    if (isset($_POST['save'])) {
        // Capture current values before update for audit comparison
        $oldValues = array();
        foreach ($adminDefs as $key => $definition) {
            $oldValues[$key] = $meshlog->getConfig($key, $definition['default'] ?? null);
        }

        $newValues = array();
        foreach ($adminDefs as $key => $definition) {
            if (!array_key_exists($key, $_POST)) continue;

            $normalized = normalizeSettingValue($key, $_POST[$key], $adminDefs, $errors);
            if ($normalized === null && sizeof($errors)) {
                continue;
            }

            $newValues[$key] = $normalized;
            $meshlog->setConfig($key, $normalized);
        }

        if (!sizeof($errors)) {
            $meshlog->saveSettings();

            $changes = array();
            foreach ($newValues as $key => $newVal) {
                $oldVal = $oldValues[$key] ?? null;
                if ((string)$oldVal !== (string)$newVal) {
                    $changes[] = "$key: {$oldVal} \u{2192} {$newVal}";
                }
            }
            if (!empty($changes)) {
                $actor = is_object($user) ? $user->name : ($user['name'] ?? 'admin');
                $meshlog->auditLog(\MeshLogAuditLog::EVENT_SETTINGS_SAVE, $actor, implode('; ', $changes));
            }

            $results = array('status' => 'OK');
        }
    } else {
        $settings = array();
        foreach ($adminDefs as $key => $definition) {
            $value = $meshlog->getConfig($key, $definition['default'] ?? null);
            if (isRetentionKey($key)) {
                $value = secondsToDays($value);
            }
            $settings[$key] = $value;
        }

        $results = array(
            'status' => 'OK',
            'settings' => $settings
        );
    }

    if (sizeof($errors)) {
        $results = array(
            'status' => 'error',
            'error' => implode("\n", $errors)
        );
    }

    header('Content-Type: application/json');
    echo json_encode($results);
?>