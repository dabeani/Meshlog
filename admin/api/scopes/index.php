<?php
    require_once __DIR__ . '/../loggedin.php';

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

    if (isset($_POST['add'])) {
        $scope = new MeshLogScope($meshlog);

        if (!isset($_POST['number']) || $_POST['number'] === '') {
            $errors[] = 'Missing scope number';
        } else {
            $number = intval($_POST['number']);
            if ($number < 0 || $number > 255) {
                $errors[] = 'Scope number must be between 0-255';
            } else {
                $scope->number = $number;
            }
        }

        if (!isset($_POST['name']) || trim($_POST['name']) === '') {
            $errors[] = 'Missing scope name';
        } else {
            $scope->name = htmlspecialchars(trim($_POST['name']), ENT_QUOTES, 'UTF-8');
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

            if (isset($_POST['number'])) {
                $number = intval($_POST['number']);
                if ($number < 0 || $number > 255) {
                    $errors[] = 'Scope number must be between 0-255';
                } else {
                    $scope->number = $number;
                }
            }

            if (isset($_POST['name']) && trim($_POST['name']) !== '') {
                $scope->name = htmlspecialchars(trim($_POST['name']), ENT_QUOTES, 'UTF-8');
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
        if (MeshLogScope::deleteById($meshlog, $id)) {
            $actor = is_object($user) ? $user->name : ($user['name'] ?? 'admin');
            $meshlog->auditLog(
                \MeshLogAuditLog::EVENT_SCOPE_DELETE,
                $actor,
                'deleted scope id ' . $id
            );
            $results = array('status' => 'OK');
        } else {
            $errors[] = 'Failed to delete scope';
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
