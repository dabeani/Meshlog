<?php
    require_once __DIR__ . '/../loggedin.php';
    require_once __DIR__ . '/../../../lib/meshlog.scope.class.php';

    $errors = array();
    $results = array('status' => 'unknown');

    function scopeSnapshot($scope) {
        if (!$scope) return array();
        return array(
            'number' => intval($scope->number),
            'name' => $scope->name,
            'description' => $scope->description,
        );
    }

    function scopeChanges($before, $after) {
        $changes = array();
        foreach ($after as $key => $newValue) {
            $oldValue = $before[$key] ?? null;
            if ((string)$oldValue !== (string)$newValue) {
                $changes[] = "$key: {$oldValue} → {$newValue}";
            }
        }
        return $changes;
    }

    function normalizeScopeName($number, $name) {
        $name = trim((string)$name);
        if ($name !== '') {
            return htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        }

        $decoded = MeshLogScope::decodeName($number);
        return htmlspecialchars($decoded, ENT_QUOTES, 'UTF-8');
    }

    if (isset($_POST['add'])) {
        $scope = new MeshLogScope($meshlog);

        $rawName = trim((string)($_POST['name'] ?? ''));

        if ($rawName === '') {
            $errors[] = 'Missing scope name';
        }

        $scope->name = normalizeScopeName($scope->number ?? 0, $rawName);

        $generatedNumber = MeshLogScope::deriveNumberFromName($rawName);
        if ($generatedNumber !== null) {
            $scope->number = $generatedNumber;
        } else if (isset($_POST['number']) && $_POST['number'] !== '') {
            $number = intval($_POST['number']);
            if ($number < 0 || $number > 255) {
                $errors[] = 'Scope number must be between 0-255';
            } else {
                $scope->number = $number;
            }
        } else {
            $errors[] = 'Unable to auto-generate scope number from name';
        }

        if (!sizeof($errors)) {
            $existing = MeshLogScope::getByNumber($meshlog, $scope->number);
            if ($existing) {
                $errors[] = 'Scope number already exists: ' . intval($scope->number);
            }
        }

        $scope->description = htmlspecialchars(trim($_POST['description'] ?? ''), ENT_QUOTES, 'UTF-8');

        if (!sizeof($errors)) {
            if ($scope->save($meshlog)) {
                $actor = is_object($user) ? $user->name : ($user['name'] ?? 'admin');
                $meshlog->auditLog(
                    \MeshLogAuditLog::EVENT_SCOPE_SAVE,
                    $actor,
                    'created scope ' . intval($scope->number) . ': ' . ($scope->name ?? '')
                );
                $results = array(
                    'status' => 'OK',
                    'scope' => $scope->asArray()
                );
            } else {
                $errors[] = 'Failed to save: ' . $scope->getError();
            }
        }
    } else if (isset($_POST['edit'])) {
        $id = intval($_POST['id'] ?? 0);
        $scope = MeshLogScope::findById($id, $meshlog);
        if (!$scope) {
            $errors[] = 'Scope not found';
        } else {
            $before = scopeSnapshot($scope);
            $numberTouched = false;
            $rawName = trim((string)($_POST['name'] ?? $scope->name));
            $derivedNumber = MeshLogScope::deriveNumberFromName($rawName);

            if ($derivedNumber !== null) {
                $numberTouched = true;
                $scope->number = $derivedNumber;
            } else if (isset($_POST['number'])) {
                $numberTouched = true;
                $number = intval($_POST['number']);
                if ($number < 0 || $number > 255) {
                    $errors[] = 'Scope number must be between 0-255';
                } else {
                    $scope->number = $number;
                }
            } else if (isset($_POST['name'])) {
                $generatedNumber = MeshLogScope::deriveNumberFromName($rawName);
                if ($generatedNumber === null) {
                    $errors[] = 'Unable to auto-generate scope number from name';
                } else {
                    $scope->number = $generatedNumber;
                    $numberTouched = true;
                }
            }

            if (isset($_POST['name'])) {
                $scope->name = normalizeScopeName($scope->number ?? 0, $_POST['name']);
            } else if (isset($_POST['number'])) {
                // Keep name in sync when only number is changed.
                $scope->name = normalizeScopeName($scope->number ?? 0, '');
            }

            if ($numberTouched && !sizeof($errors)) {
                $existing = MeshLogScope::getByNumber($meshlog, $scope->number);
                if ($existing && intval($existing->getId()) !== intval($scope->getId())) {
                    $errors[] = 'Scope number already exists: ' . intval($scope->number);
                }
            }

            if (isset($_POST['description'])) {
                $scope->description = htmlspecialchars(trim($_POST['description']), ENT_QUOTES, 'UTF-8');
            }

            if (!sizeof($errors)) {
                if ($scope->save($meshlog)) {
                    $after = scopeSnapshot($scope);
                    $changes = scopeChanges($before, $after);
                    if (!empty($changes)) {
                        $actor = is_object($user) ? $user->name : ($user['name'] ?? 'admin');
                        $meshlog->auditLog(
                            \MeshLogAuditLog::EVENT_SCOPE_SAVE,
                            $actor,
                            'updated scope ' . intval($scope->number) . ': ' . implode('; ', $changes)
                        );
                    }
                    $results = array(
                        'status' => 'OK',
                        'scope' => $scope->asArray()
                    );
                } else {
                    $errors[] = 'Failed to save: ' . $scope->getError();
                }
            }
        }
    } else if (isset($_POST['delete'])) {
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            $errors[] = 'Invalid scope id';
        }
        if (!sizeof($errors) && MeshLogScope::deleteById($meshlog, $id)) {
            $actor = is_object($user) ? $user->name : ($user['name'] ?? 'admin');
            $meshlog->auditLog(
                \MeshLogAuditLog::EVENT_SCOPE_DELETE,
                $actor,
                'deleted scope id ' . $id
            );
            $results = array('status' => 'OK');
        } else {
            if (!sizeof($errors)) {
                $errors[] = 'Failed to delete scope (not found or database error)';
            }
        }
    } else if (isset($_GET['all'])) {
        // Fetch all scopes for the admin UI
        $scopes = MeshLogScope::getAll($meshlog);
        $scopeArray = array();
        foreach ($scopes as $scope) {
            $scopeArray[] = $scope->asArray();
        }
        $results = array(
            'status' => 'OK',
            'scopes' => $scopeArray
        );
    }

    if (sizeof($errors)) {
        $results['status'] = 'ERROR';
        $results['errors'] = $errors;
    }

    header('Content-Type: application/json');
    echo json_encode($results, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
?>
