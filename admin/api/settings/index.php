<?php
    require_once __DIR__ . '/../loggedin.php';

    $errors = array();
    $results = array('status' => 'unknown');
    $adminDefs = MeshLogSetting::getAdminDefinitions();

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
            $settings[$key] = $meshlog->getConfig($key, $definition['default'] ?? null);
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