<?php
$start = microtime(true);
require_once __DIR__ . "/../../../lib/meshlog.class.php";
require_once __DIR__ . "/../utils.php";

$config = meshlogLoadConfig(__DIR__);
$meshlog = new MeshLog(array_merge($config['db'], array('ntp' => $config['ntp'] ?? array())));
$err = $meshlog->getError();

if ($err) {
    $results = array('error' => $err);
} else {
    $includeRawPackets = intval(getParam('include_raw_packets', 1)) !== 0;
    $includeTelemetry = intval(getParam('include_telemetry', 1)) !== 0;
    $includeSystemReports = intval(getParam('include_system_reports', 1)) !== 0;

    $params = array(
        'offset' => getParam('offset', 0),
        'count' => getParam('count', DEFAULT_COUNT),
        'after_ms' => getParam('after_ms', 0),
        'before_ms' => getParam('before_ms', 0),
    );

    $paramsContacts = array(
        'offset' => getParam('offset', 0),
        'count' => getParam('count', DEFAULT_COUNT),
        'after_ms' => getParam('after_ms', 0),
        'before_ms' => getParam('before_ms', 0),
    );

    $reporters = $meshlog->getReporters($params);
    $contacts = $meshlog->getContactsQuick($paramsContacts);
    $advertisements = $meshlog->getAdvertisementsQuick($params);
    $channels = $meshlog->getChannels($params);
    $direct_messages = $meshlog->getDirectMessagesQuick($params);
    $channel_messages = $meshlog->getChannelMessagesQuick($params);
    $telemetry = $includeTelemetry ? $meshlog->getTelemetry($params) : array('objects' => array());
    $system_reports = $includeSystemReports ? $meshlog->getSystemReports($params) : array('objects' => array());
    $raw_packets = $includeRawPackets ? $meshlog->getRawPackets($params) : array('objects' => array());

    $results = array(
        'reporters' => $reporters,
        'contacts' => $contacts,
        'advertisements' => $advertisements,
        'channels' => $channels,
        'direct_messages' => $direct_messages,
        'channel_messages' => $channel_messages,
        'telemetry' => $telemetry,
        'system_reports' => $system_reports,
        'raw_packets' => $raw_packets
    );
}

$results['time'] = microtime(true) - $start;

header('Content-Type: application/json; charset=utf-8');
echo json_encode($results);

?>