<?php
require_once "../../../../lib/meshlog.class.php";
include "../../utils.php";

$config = meshlogLoadConfig(__DIR__);
$meshlog = new MeshLog(array_merge($config['db'], array('ntp' => $config['ntp'] ?? array())));
$err = $meshlog->getError();

if ($err) {
    $results = array('error' => $err);
} else {
    $windowHours = intval(getParam('window_hours', 168));
    $precision   = intval(getParam('precision', 3));
    $results     = $meshlog->getCoverageSpots($windowHours, $precision);
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($results);
