<?php
    require_once __DIR__ . '/../loggedin.php';

    $limit  = max(1, min(500, intval($_GET['limit']  ?? 100)));
    $offset = max(0, intval($_GET['offset'] ?? 0));

    try {
        $rows  = MeshLogAuditLog::recent($meshlog, $limit, $offset);
        $total = MeshLogAuditLog::countTotal($meshlog);
        $results = array(
            'status'  => 'OK',
            'total'   => $total,
            'limit'   => $limit,
            'offset'  => $offset,
            'objects' => $rows,
        );
    } catch (Exception $e) {
        $results = array('status' => 'error', 'error' => $e->getMessage());
    }

    header('Content-Type: application/json');
    echo json_encode($results);
?>
