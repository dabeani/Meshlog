<?php
require_once __DIR__ . "/../../../../lib/meshlog.class.php";
require_once __DIR__ . "/../../utils.php";

$config = meshlogLoadConfig(__DIR__);
$meshlog = new MeshLog(array_merge($config['db'], array('ntp' => $config['ntp'] ?? array())));
$err = $meshlog->getError();

if ($err) {
    $results = array('error' => $err);
} else {
    $windowHours = intval(getParam('window_hours', 24));
    $results = $meshlog->getHeatmapData($windowHours);
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($results);
