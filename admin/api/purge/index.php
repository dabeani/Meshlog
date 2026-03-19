<?php
    require_once __DIR__ . '/../loggedin.php';

    $errors = array();
    $results = array('status' => 'unknown');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            $stats = $meshlog->purgeOldData();
            $total = array_sum($stats);
            $results = array(
                'status' => 'OK',
                'deleted' => $stats,
                'total' => $total,
                'message' => "Purged {$stats['advertisements']} advertisements, {$stats['messages']} messages, {$stats['raw_packets']} raw packets."
            );
        } catch (Exception $e) {
            $results = array('status' => 'error', 'error' => $e->getMessage());
        }
    } else {
        $results = array('status' => 'error', 'error' => 'POST required');
    }

    header('Content-Type: application/json');
    echo json_encode($results);
?>
