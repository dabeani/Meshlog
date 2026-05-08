<?php

function getParam($key, $fallback=null) {
    if (isset($_POST[$key])) return $_POST[$key];
    if (isset($_GET[$key]))  return $_GET[$key];
    return $fallback;
}

function meshlogLoadConfig($baseDir = __DIR__) {
    $rootDir = realpath($baseDir . '/../../');
    if ($rootDir === false) {
        $rootDir = dirname(__DIR__, 2);
    }

    $config = array();
    $configFile = $rootDir . '/config.php';
    if (file_exists($configFile)) {
        require $configFile;
    }

    if (!isset($config) || !is_array($config)) {
        $config = array();
    }

    $db = isset($config['db']) && is_array($config['db']) ? $config['db'] : array();
    $config['db'] = array(
        'host' => $db['host'] ?? (getenv('DB_HOST') ?: 'mariadb'),
        'database' => $db['database'] ?? (getenv('DB_NAME') ?: 'meshcore'),
        'user' => $db['user'] ?? (getenv('DB_USER') ?: 'meshcore'),
        'password' => $db['password'] ?? (getenv('DB_PASS') ?: 'meshcore'),
    );

    if (!isset($config['map']) || !is_array($config['map'])) {
        $config['map'] = array();
    }

    if (!isset($config['ntp']) || !is_array($config['ntp'])) {
        $config['ntp'] = array();
    }

    return $config;
}

?>