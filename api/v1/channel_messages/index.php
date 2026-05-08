<?php
require_once "../../../lib/meshlog.class.php";
include "../utils.php";

$config = meshlogLoadConfig(__DIR__);
$meshlog = new MeshLog(array_merge($config['db'], array('ntp' => $config['ntp'] ?? array())));
$err = $meshlog->getError();

if ($err) {
    $results = array('error' => $err);  
} else {
    $maxage = date("Y-m-d H:i:s", time() - $meshlog->getConfig(MeshlogSetting::KEY_MAX_CONTACT_AGE));

    $params = array(
        'offset' => getParam('offset', 0),
        'count' => getParam('count', DEFAULT_COUNT),
        'after_ms' => getParam('after_ms', 0),
        'before_ms' => getParam('before_ms', 0),
    );
    $results = $meshlog->getChannelMessagesQuick($params);
}
header('Content-Type: application/json; charset=utf-8');
echo json_encode($results);

?>