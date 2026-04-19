<?php
    require_once __DIR__ . '/../loggedin.php';

    $errors = array();
    $results = array('status' => 'unknown');

    function channelSnapshot($channel) {
        if (!$channel) return array();
        return array(
            'hash' => $channel->hash,
            'name' => $channel->name,
            'psk' => $channel->psk,
            'enabled' => intval($channel->enabled ?? 0),
        );
    }

    function channelChanges($before, $after) {
        $changes = array();
        foreach ($after as $key => $newValue) {
            $oldValue = $before[$key] ?? null;
            if ((string)$oldValue !== (string)$newValue) {
                $changes[] = "$key: {$oldValue} → {$newValue}";
            }
        }
        return $changes;
    }

    /**
     * Compute the channel hash byte (first byte of SHA-256 of the PSK bytes) and
     * return it as a 2-char lowercase hex string.
     *
     * Rules (matching the firmware / decoder logic):
     *   - Named PSK provided (hex 32/64 chars or base64): hash = SHA-256(psk_bytes)[0]
     *   - No PSK, name starts with '#': PSK = SHA-256(name)[0:16], hash = SHA-256(psk)[0]
     *   - Special name 'public' or 'Public': hash = '11' (well-known MeshCore value)
     *   - Anything else without a PSK: return null (cannot determine hash)
     */
    function computeChannelHash($name, $psk) {
        $pskTrimmed = trim($psk ?? '');

        if ($pskTrimmed !== '') {
            // Accept hex (32 or 64 chars = 16 or 32 bytes) or base64.
            if (preg_match('/^[0-9A-Fa-f]+$/', $pskTrimmed) && (strlen($pskTrimmed) === 32 || strlen($pskTrimmed) === 64)) {
                $pskBytes = hex2bin($pskTrimmed);
            } else {
                $pskBytes = base64_decode($pskTrimmed, true);
            }
            if ($pskBytes === false) return null;
        } else {
            $nameTrimmed = trim($name ?? '');
            if (strtolower($nameTrimmed) === 'public') return '11';
            if (substr($nameTrimmed, 0, 1) === '#') {
                // Hashtag channel: PSK = SHA-256(name)[0:16]
                $pskBytes = substr(hash('sha256', $nameTrimmed, true), 0, 16);
            } else {
                return null;
            }
        }

        return bin2hex(hash('sha256', $pskBytes, true)[0]);
    }

    if (isset($_POST['add'])) {
        $channel = new MeshLogChannel($meshlog);

        if (!isset($_POST['name']) || trim($_POST['name']) === '') {
            $errors[] = 'Missing name';
        } else {
            $channel->name = htmlspecialchars(trim($_POST['name']), ENT_QUOTES, 'UTF-8');
        }
        $channel->psk = trim($_POST['psk'] ?? '');
        $channel->enabled = isset($_POST['enabled']) ? intval($_POST['enabled']) : 1;

        // Accept an explicit hash override; otherwise auto-compute from PSK / name.
        $explicitHash = trim($_POST['hash'] ?? '');
        if ($explicitHash !== '') {
            $channel->hash = strtolower($explicitHash);
        } elseif (!sizeof($errors)) {
            $computed = computeChannelHash($channel->name, $channel->psk);
            if ($computed === null) {
                $errors[] = 'Cannot compute hash: provide a PSK or use a #hashtag name';
            } else {
                $channel->hash = $computed;
            }
        }

        if (!sizeof($errors)) {
            if ($channel->save($meshlog)) {
                $actor = is_object($user) ? $user->name : ($user['name'] ?? 'admin');
                $meshlog->auditLog(
                    \MeshLogAuditLog::EVENT_CHANNEL_SAVE,
                    $actor,
                    'created channel ' . ($channel->name ?? '') . ' [' . ($channel->hash ?? '') . ']'
                );
                $results = array(
                    'status' => 'OK',
                    'channel' => $channel->asArray()
                );
            } else {
                $errors[] = 'Failed to save: ' . $channel->getError();
            }
        }
    } else if (isset($_POST['edit'])) {
        $id = intval($_POST['id'] ?? 0);
        $channel = MeshLogChannel::findById($id, $meshlog);
        if (!$channel) {
            $errors[] = 'Channel not found';
        } else {
            $before = channelSnapshot($channel);
            if (isset($_POST['hash']) && trim($_POST['hash']) !== '') {
                $channel->hash = strtolower(trim($_POST['hash']));
            }
            $channel->name = htmlspecialchars(trim($_POST['name'] ?? $channel->name ?? ''), ENT_QUOTES, 'UTF-8');
            $channel->psk  = trim($_POST['psk'] ?? $channel->psk ?? '');
            $channel->enabled = isset($_POST['enabled']) ? intval($_POST['enabled']) : $channel->enabled;

            // If hash was not explicitly provided, recompute from updated PSK/name.
            if (!isset($_POST['hash']) || trim($_POST['hash']) === '') {
                $computed = computeChannelHash($channel->name, $channel->psk);
                if ($computed !== null) $channel->hash = $computed;
            }

            if (!sizeof($errors)) {
                if ($channel->save($meshlog)) {
                    $after = channelSnapshot($channel);
                    $changes = channelChanges($before, $after);
                    if (!empty($changes)) {
                        $actor = is_object($user) ? $user->name : ($user['name'] ?? 'admin');
                        $meshlog->auditLog(
                            \MeshLogAuditLog::EVENT_CHANNEL_SAVE,
                            $actor,
                            'updated channel ' . ($channel->name ?? '') . ': ' . implode('; ', $changes)
                        );
                    }
                    $results = array(
                        'status' => 'OK',
                        'channel' => $channel->asArray()
                    );
                } else {
                    $errors[] = 'Failed to save: ' . $channel->getError();
                }
            }
        }
    } else if (isset($_POST['delete'])) {
        $id = intval($_POST['id'] ?? 0);
        $force = isset($_POST['force']) ? boolval(intval($_POST['force'])) : false;
        $channel = MeshLogChannel::findById($id, $meshlog);
        if ($channel && $channel->delete($force)) {
            $actor = is_object($user) ? $user->name : ($user['name'] ?? 'admin');
            $meshlog->auditLog(
                \MeshLogAuditLog::EVENT_CHANNEL_DELETE,
                $actor,
                'deleted channel ' . ($channel->name ?? '') . ' [' . ($channel->hash ?? '') . ']' . ($force ? ' with messages' : '')
            );
            $results = array('status' => 'OK');
        } else {
            $errors[] = 'Failed to delete: ' . ($channel ? $channel->getError() : 'Channel not found');
        }
    } else {
        // Return all channels including disabled ones
        $results = MeshLogChannel::getAll($meshlog, array('offset' => 0, 'count' => 1000, 'where' => array()));
    }

    if (sizeof($errors)) {
        $results = array(
            'status' => 'error',
            'error' => implode("\n", $errors)
        );
    }

    echo json_encode($results, JSON_PRETTY_PRINT);
?>
