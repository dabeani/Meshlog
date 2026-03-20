<?php
require_once "../../../lib/meshlog.class.php";
require_once "../../../config.php";
include "../utils.php";

$meshlog = new MeshLog(array_merge($config['db'], array('ntp' => $config['ntp'] ?? array())));
$err = $meshlog->getError();

if ($err) {
    $results = array('error' => $err);  
} else {
    $results = $meshlog->getReporters(array('offset' => 0, 'count' => DEFAULT_COUNT));
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($results);
?>