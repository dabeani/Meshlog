<?php
require_once "../../../../lib/meshlog.class.php";
require_once "../../../../config.php";
include "../../utils.php";

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
