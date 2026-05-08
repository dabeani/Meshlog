<?php
require_once "../../../lib/meshlog.class.php";
include "../utils.php";

$config = meshlogLoadConfig(__DIR__);
$meshlog = new MeshLog(array_merge($config['db'], array('ntp' => $config['ntp'] ?? array())));
$err = $meshlog->getError();

if ($err) {
    $results = array('error' => $err);
} else {
    $contactId = intval(getParam('contact_id', 0));

    if ($contactId <= 0) {
        $results = array('error' => 'contact_id is required');
    } else {
        $results = $meshlog->getContactAdvertisementsWithCoordinates($contactId);
    }
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($results);

?>
