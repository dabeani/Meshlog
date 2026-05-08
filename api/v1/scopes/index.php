<?php
require_once __DIR__ . "/../../../lib/meshlog.class.php";
require_once __DIR__ . "/../../../lib/meshlog.scope.class.php";
require_once __DIR__ . "/../utils.php";

header('Content-Type: application/json; charset=utf-8');

$config = meshlogLoadConfig(__DIR__);
$meshlog = new MeshLog(array_merge($config['db'], array('ntp' => $config['ntp'] ?? array())));
$err = $meshlog->getError();

if ($err) {
    echo json_encode(array('error' => $err));
    exit;
}

$scopes = MeshLogScope::getAll($meshlog);
$scopeList = array();
$scopeMap = array();

foreach ($scopes as $scope) {
    $item = $scope->asArray();
    $number = intval($item['number'] ?? 0);
    $name = trim((string)($item['name'] ?? ''));
    if ($name === '') {
        $name = MeshLogScope::decodeName($number);
    }

    $item['number'] = $number;
    $item['name'] = $name;
    $scopeList[] = $item;
    $scopeMap[(string)$number] = $name;
}

echo json_encode(array(
    'status' => 'OK',
    'scopes' => $scopeList,
    'map' => $scopeMap,
));

?>