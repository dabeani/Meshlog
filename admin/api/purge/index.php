<?php
    require_once __DIR__ . '/../loggedin.php';

    $errors = array();
    $results = array('status' => 'unknown');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            $stats = $meshlog->purgeOldData();
            $total = array_sum($stats);
            $message = "Purged {$stats['advertisements']} advertisements, {$stats['messages']} messages, {$stats['raw_packets']} raw packets.";
            $actor = is_object($user) ? $user->name : ($user['name'] ?? 'admin');
            $meshlog->auditLog(\MeshLogAuditLog::EVENT_PURGE_MANUAL, $actor, $message);
            $results = array(
                'status' => 'OK',
                'deleted' => $stats,
                'total' => $total,
                'message' => $message
            );
        } catch (Exception $e) {
            $actor = is_object($user) ? $user->name : ($user['name'] ?? 'admin');
            $meshlog->auditLog(\MeshLogAuditLog::EVENT_ERROR, $actor, 'purge failed: ' . $e->getMessage());
            $results = array('status' => 'error', 'error' => $e->getMessage());
        }
    } else {
        $results = array('status' => 'error', 'error' => 'POST required');
    }

    header('Content-Type: application/json');
    echo json_encode($results);
?>
