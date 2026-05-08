<?php
require_once __DIR__ . "/../../../lib/meshlog.class.php";
require_once __DIR__ . "/../utils.php";

$config = meshlogLoadConfig(__DIR__);
$meshlog = new MeshLog(array_merge($config['db'], array('ntp' => $config['ntp'] ?? array())));
$err = $meshlog->getError();

if ($err) {
    $results = array('error' => $err);
} else {
    $results = $meshlog->getSystemReports(array(
        'offset' => getParam('offset', 0),
        'count' => getParam('count', DEFAULT_COUNT),
        'after_ms' => getParam('after_ms', 0),
        'before_ms' => getParam('before_ms', 0),
    ));
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($results);
?>