<?php
/**
 * CLI-only migration runner.
 * Runs all pending DB migrations and exits with code 0 on success, 1 on failure.
 * Called from docker/build/entrypoint.sh at container boot.
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit(1);
}

$appRoot = dirname(__DIR__);

require_once "$appRoot/lib/migration.class.php";

// Include every migration file in order.
$migrationFiles = glob("$appRoot/migrations/[0-9][0-9][0-9]_*.php");
sort($migrationFiles);
foreach ($migrationFiles as $file) {
    require_once $file;
}

// Build ordered list of Migration_NNN classes.
$migrationClasses = array();
foreach ($migrationFiles as $file) {
    $base = basename($file, '.php');
    $num  = substr($base, 0, 3);
    $class = 'Migration_' . $num;
    if (class_exists($class)) {
        $migrationClasses[] = $class;
    }
}

// Load config (written by entrypoint before this script runs).
$configFile = "$appRoot/config.php";
if (!file_exists($configFile)) {
    fwrite(STDERR, "[migrate] config.php not found — aborting\n");
    exit(1);
}
require_once $configFile;

if (!isset($config['db'])) {
    fwrite(STDERR, "[migrate] config.php has no db section — aborting\n");
    exit(1);
}

$db = $config['db'];

// Retry loop: MariaDB healthcheck passes before this runs, but give it a few
// extra seconds in case of a slow first-boot InnoDB initialisation.
$pdo = null;
$attempts = 0;
while ($pdo === null && $attempts < 10) {
    try {
        $pdo = new PDO(
            "mysql:host={$db['host']};dbname={$db['database']};charset=utf8mb4",
            $db['user'],
            $db['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (PDOException $e) {
        $attempts++;
        fwrite(STDERR, "[migrate] DB not ready (attempt $attempts/10): " . $e->getMessage() . "\n");
        sleep(3);
    }
}

if ($pdo === null) {
    fwrite(STDERR, "[migrate] Could not connect to database after 10 attempts — aborting\n");
    exit(1);
}

// Determine current schema version (settings table may not exist yet on first boot).
$current_version = -1;
try {
    $stmt = $pdo->query("SELECT `value` FROM `settings` WHERE `name` = 'DB_VERSION' LIMIT 1");
    $row = $stmt ? $stmt->fetch(PDO::FETCH_NUM) : null;
    if ($row) $current_version = intval($row[0]);
} catch (PDOException $e) {
    // settings table doesn't exist yet — that's fine, migration 000 creates it.
}

// Run pending migrations.
$ran = 0;
foreach ($migrationClasses as $class) {
    $migration = new $class();
    if (!$migration->isPending($current_version)) continue;

    echo "[migrate] Running " . $migration->getName() . " ...\n";
    $result = $migration->run($pdo, $current_version);

    if (!$result['success']) {
        fwrite(STDERR, "[migrate] FAILED: " . ($result['message'] ?? 'no message') . "\n");
        exit(1);
    }

    $current_version = $migration->to;
    $ran++;
    if (!empty($result['message'])) {
        echo "[migrate] " . $result['message'] . "\n";
    }
}

if ($ran === 0) {
    echo "[migrate] Schema is up to date (version $current_version)\n";
} else {
    echo "[migrate] Done — applied $ran migration(s), schema now at version $current_version\n";
}
exit(0);
