#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="/var/www/html"

echo "$TIMEZONE" > /etc/timezone || true
cp "/usr/share/zoneinfo/$TIMEZONE" /etc/localtime || true

if [[ ! -f "$APP_ROOT/config.php" ]]; then
	cp "$APP_ROOT/config.example.php" "$APP_ROOT/config.php"
fi

php -r '
$cfgFile = "'"$APP_ROOT"'/config.php";
if (!file_exists($cfgFile)) { exit(1); }
require $cfgFile;

if (!isset($config) || !is_array($config)) { $config = array(); }
if (!isset($config["db"]) || !is_array($config["db"])) { $config["db"] = array(); }
if (!isset($config["mqtt"]) || !is_array($config["mqtt"])) { $config["mqtt"] = array(); }

$config["db"]["host"] = getenv("DB_HOST") ?: "mariadb";
$config["db"]["database"] = getenv("DB_NAME") ?: "meshcore";
$config["db"]["user"] = getenv("DB_USER") ?: "meshcore";
$config["db"]["password"] = getenv("DB_PASS") ?: "meshcore";

$config["mqtt"]["enabled"] = filter_var(getenv("MQTT_ENABLED") ?: "false", FILTER_VALIDATE_BOOLEAN);
$config["mqtt"]["debug"] = filter_var(getenv("MQTT_DEBUG") ?: "false", FILTER_VALIDATE_BOOLEAN);
$config["mqtt"]["transport"] = getenv("MQTT_TRANSPORT") ?: "tcp";
$config["mqtt"]["host"] = getenv("MQTT_HOST") ?: "127.0.0.1";
$config["mqtt"]["port"] = intval(getenv("MQTT_PORT") ?: "1883");
$config["mqtt"]["topic"] = getenv("MQTT_TOPIC") ?: "meshcore/+/+/packets";
$config["mqtt"]["client_id"] = getenv("MQTT_CLIENT_ID") ?: "meshlog-mqtt";
$config["mqtt"]["username"] = getenv("MQTT_USERNAME") ?: "";
$config["mqtt"]["password"] = getenv("MQTT_PASSWORD") ?: "";
$config["mqtt"]["keepalive"] = intval(getenv("MQTT_KEEPALIVE") ?: "30");
$config["mqtt"]["qos"] = intval(getenv("MQTT_QOS") ?: "0");
$config["mqtt"]["path"] = getenv("MQTT_PATH") ?: "/mqtt";
$config["mqtt"]["timeout"] = intval(getenv("MQTT_TIMEOUT") ?: "5");

$out = "<?php\n\n\$config = " . var_export($config, true) . ";\n\n?>\n";
file_put_contents($cfgFile, $out);
'

exec "$@"
