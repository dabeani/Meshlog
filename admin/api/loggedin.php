<?php
    require_once __DIR__ . '/../../lib/meshlog.class.php';
    require_once __DIR__ . '/../../api/v1/utils.php';

    session_start();
    header('Content-Type: application/json; charset=utf-8');

    if (isset($_SESSION['user'])) {
        $user = $_SESSION['user'];
    } else {
        header("HTTP/1.1 401 Unauthorized");
        echo json_encode(array('error' => 'unauthorized'), JSON_PRETTY_PRINT);
        exit;
    }

    $config = meshlogLoadConfig(__DIR__);
    $meshlog = new MeshLog(array_merge($config['db'], array('ntp' => $config['ntp'] ?? array())));
    $err = $meshlog->getError();
    if ($err) {
        header("HTTP/1.1 503 Service Unavailable");
        echo json_encode(array('error' => $err), JSON_PRETTY_PRINT);
        exit;
    }
