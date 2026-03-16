<?php
require_once "config.php";
include "lib/meshlog.class.php";

function dockerLog($level, $message) {
    $ts = date('c');
    $out = "[$ts][$level] $message\n";
    // write to STDERR so Docker / supervisord capture the message
    fwrite(STDERR, $out);
}

$raw = file_get_contents("php://input");
$data = json_decode($raw, true) ?? array();

$reporter = '';
foreach (array('reporter','origin_id','public_key','pubkey') as $k) {
    if (isset($data[$k]) && is_scalar($data[$k]) && trim($data[$k]) !== '') { $reporter = trim(strval($data[$k])); break; }
}

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$maskedAuth = $auth !== '' ? (substr($auth, 0, 16) . '...') : '';
dockerLog('INFO', "HTTP ingest received reporter={$reporter} auth={$maskedAuth} payload_len=" . strlen($raw));

$systime = floor(microtime(true) * 1000);
$data["time"]["server"] = $systime;

$meshlog = new MeshLog($config['db']);
$response = $meshlog->insert($data);

if (is_array($response) && array_key_exists("error", $response)) {
    dockerLog('WARN', 'Insert failed: ' . $response["error"]);
    echo $response["error"];
} else {
    dockerLog('INFO', 'Insert OK type=' . ($data['type'] ?? '') . ' reporter=' . ($data['reporter'] ?? $reporter));
}

?>