<?php

function getParam($key, $fallback=null) {
    if (isset($_POST[$key])) return $_POST[$key];
    if (isset($_GET[$key]))  return $_GET[$key];
    return $fallback;
}

function meshlogFindProjectRoot($baseDir = __DIR__) {
    $dir = realpath($baseDir);
    if ($dir === false) {
        $dir = $baseDir;
    }

    while (is_string($dir) && strlen($dir) > 1) {
        if (file_exists($dir . '/lib/meshlog.class.php')) {
            return $dir;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
    }

    return dirname(__DIR__, 2);
}

function meshlogLoadConfig($baseDir = __DIR__) {
    $rootDir = meshlogFindProjectRoot($baseDir);

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