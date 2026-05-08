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

function putScopeMapEntry(&$scopeMap, $number, $name) {
    $num = intval($number);
    if ($num < 0 || $num > 255) return;

    $key = (string)$num;
    if (!isset($scopeMap[$key])) {
        $scopeMap[$key] = $name;
    }
}

foreach ($scopes as $scope) {
    $item = $scope->asArray();
    $storedNumber = intval($item['number'] ?? 0);
    $name = trim((string)($item['name'] ?? ''));
    if ($name === '') {
        $name = MeshLogScope::decodeName($storedNumber);
    }

    $derivedNumber = MeshLogScope::deriveNumberFromName($name);

    $item['number'] = $storedNumber;
    $item['derived_number'] = ($derivedNumber !== null) ? intval($derivedNumber) : null;
    $item['name'] = $name;
    $scopeList[] = $item;

    putScopeMapEntry($scopeMap, $storedNumber, $name);
    if ($derivedNumber !== null) {
        putScopeMapEntry($scopeMap, $derivedNumber, $name);
    }
}

echo json_encode(array(
    'status' => 'OK',
    'scopes' => $scopeList,
    'map' => $scopeMap,
));

?>