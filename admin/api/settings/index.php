<?php
    require_once __DIR__ . '/../loggedin.php';

    $errors = array();
    $results = array('status' => 'unknown');

    if (isset($_POST['save'])) {
        $anonymize = isset($_POST['anonymize_usernames']) ? intval($_POST['anonymize_usernames']) : 0;
        $meshlog->setConfig(MeshLogSetting::KEY_ANONYMIZE_USERNAMES, $anonymize);
        $meshlog->saveSettings();
        $results = array('status' => 'OK');
    } else {
        $results = array(
            'status' => 'OK',
            'settings' => array(
                'anonymize_usernames' => $meshlog->getConfig(MeshLogSetting::KEY_ANONYMIZE_USERNAMES, 0)
            )
        );
    }

    if (sizeof($errors)) {
        $results = array(
            'status' => 'error',
            'error' => implode("\n", $errors)
        );
    }

    header('Content-Type: application/json');
    echo json_encode($results);
?>