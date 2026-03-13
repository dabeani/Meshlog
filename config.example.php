<?php

$config = array(
    'db' => array(
        'host' => '%DB_HOST%',
        'database' => '%DB_NAME%',
        'user' => '%DB_USER%',
        'password' => '%DB_PASSWORD%',
    ),
    'mqtt' => array(
        'enabled' => false,
        'transport' => 'tcp', // tcp, ssl, ws, wss
        'host' => '127.0.0.1',
        'port' => 1883,
        'topic' => 'meshcore/+/+/packets',
        'client_id' => 'meshlog-mqtt',
        'username' => '',
        'password' => '',
        'keepalive' => 30,
        'qos' => 0,
        'path' => '/mqtt',
    )
);

?>
