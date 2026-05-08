<?php

function getParam($key, $fallback=null) {
    if (isset($_POST[$key])) return $_POST[$key];
    if (isset($_GET[$key]))  return $_GET[$key];
    return $fallback;
}

function meshlogParseEnvFile($path) {
    $env = array();
    if (!is_readable($path)) {
        return $env;
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return $env;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $eqPos = strpos($line, '=');
        if ($eqPos === false) {
            continue;
        }

        $key = trim(substr($line, 0, $eqPos));
        $value = trim(substr($line, $eqPos + 1));
        if ($key === '') {
            continue;
        }

        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }

        $env[$key] = $value;
    }

    return $env;
}

function meshlogPickValue($aliases, $envMap = array(), $fallback = null) {
    foreach ($aliases as $key) {
        $val = getenv($key);
        if ($val !== false && $val !== null && $val !== '') {
            return $val;
        }
        if (isset($envMap[$key]) && $envMap[$key] !== '') {
            return $envMap[$key];
        }
    }
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

    $envMap = array();
    foreach (array($rootDir . '/.env', $rootDir . '/docker/.env') as $envFile) {
        $envMap = array_merge($envMap, meshlogParseEnvFile($envFile));
    }

    $db = isset($config['db']) && is_array($config['db']) ? $config['db'] : array();
    $resolvedHost = $db['host'] ?? meshlogPickValue(array('DB_HOST', 'MYSQL_HOST', 'MARIADB_HOST', 'DBHOST'), $envMap, 'mariadb');
    $resolvedDb = $db['database'] ?? meshlogPickValue(array('DB_NAME', 'MYSQL_DATABASE', 'MARIADB_DATABASE'), $envMap, 'meshcore');
    $resolvedUser = $db['user'] ?? meshlogPickValue(array('DB_USER', 'MYSQL_USER', 'MARIADB_USER'), $envMap, 'meshcore');
    $resolvedPass = $db['password'] ?? meshlogPickValue(array('DB_PASS', 'DB_PASSWORD', 'MYSQL_PASSWORD', 'MARIADB_PASSWORD'), $envMap, 'meshcore');

    $config['db'] = array(
        'host' => $resolvedHost,
        'database' => $resolvedDb,
        'user' => $resolvedUser,
        'password' => $resolvedPass,
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