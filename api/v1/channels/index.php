<?php
require_once "../../../lib/meshlog.class.php";
include "../utils.php";

$config = meshlogLoadConfig(__DIR__);
$meshlog = new MeshLog(array_merge($config['db'], array('ntp' => $config['ntp'] ?? array())));
$err = $meshlog->getError();

if ($err) {
    $results = array('error' => $err);  
} else {
    $results = $meshlog->getChannels(array('offset' => 0, 'count' => DEFAULT_COUNT, 'after_ms' => getParam('after_ms', 0)));
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($results);

?>