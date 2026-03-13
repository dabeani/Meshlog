<?php
    require_once __DIR__ . '/../loggedin.php';

    $errors = array();
    $results = array('status' => 'unknown');

    if (isset($_POST['add'])) {
        $channel = new MeshLogChannel($meshlog);

        if (!isset($_POST['hash']) || trim($_POST['hash']) === '') {
            $errors[] = 'Missing hash';
        } else {
            $channel->hash = trim($_POST['hash']);
        }
        if (!isset($_POST['name']) || trim($_POST['name']) === '') {
            $errors[] = 'Missing name';
        } else {
            $channel->name = trim($_POST['name']);
        }
        $channel->enabled = isset($_POST['enabled']) ? intval($_POST['enabled']) : 1;

        if (!sizeof($errors)) {
            if ($channel->save($meshlog)) {
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
            $channel->name = $_POST['name'] ?? $channel->name;
            $channel->enabled = isset($_POST['enabled']) ? intval($_POST['enabled']) : $channel->enabled;

            if (!sizeof($errors)) {
                if ($channel->save($meshlog)) {
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
        $channel = MeshLogChannel::findById($id, $meshlog);
        if ($channel && $channel->delete()) {
            $results = array('status' => 'OK');
        } else {
            $errors[] = 'Failed to delete';
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
