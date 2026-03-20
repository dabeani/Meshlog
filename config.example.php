<?php

$config = array(
    'db' => array(
        'host' => '%DB_HOST%',
        'database' => '%DB_NAME%',
        'user' => '%DB_USER%',
        'password' => '%DB_PASSWORD%',
    ),
    'map' => array(
        'lat'  => 51.5074,   // default map center latitude
        'lon'  => -0.1278,   // default map center longitude
        'zoom' => 10,        // default zoom level
    ),
    'mqtt' => array(
        'enabled' => false,
        'debug' => false, // true prints detailed MQTT topic/reporter resolution logs
        'transport' => 'tcp', // tcp, ssl, ws, wss
        'host' => '127.0.0.1',
        'port' => 1883,
        'topic' => 'meshcore/+/+/packets', // <prefix>/<iata>/<reporter_public_key>/packets
        'client_id' => 'meshlog-mqtt',
        'username' => '',
        'password' => '',
        'keepalive' => 30,
        'qos' => 0,
        'path' => '/mqtt',
    ),
    'ntp' => array(
        'enabled' => true,
        'host' => 'pool.ntp.org',
        'port' => 123,
        'timeout_ms' => 1500,
        'cache_ttl' => 300,
        'warning_threshold_seconds' => 300,
    )
);

?>
