# Meshlog Docker Stack

A ready-to-use Docker stack for running **Meshlog** with `nginx`, `php-fpm`, and `MariaDB`.

---

## 📦 Overview

This stack provides:

- **nginx** (serving Meshlog on port `80`)
- **php-fpm** (running PHP application backend)
- **MariaDB** (with automatic initialization from `migrations/000_initial_setup.sql` on first start)
- **MQTT worker** (`php mqtt.php`) started automatically by supervisor in the backend container

Logs from all services are forwarded to container `stdout/stderr`, so you can monitor everything with `docker logs`.

---

## 🚀 Quickstart

### 1. Install Docker

```bash
curl -fsSL https://get.docker.com -o get-docker.sh
sudo sh ./get-docker.sh
```

### 2. Clone the repository

```bash
git clone https://github.com/Anrijs/Meshlog.git
cd ./Meshlog/docker
```

### 3. Configure environment variables

Edit the .env file inside the ```docker``` directory.
Example configuration:

```env
DB_NAME=meshcore
DB_USER=meshcore
DB_PASS=meshcore
DB_ROOT_PASS=meshcore
TIMEZONE=Europe/Vienna
WEB_PORT=80
LIVE_WS_PORT=8081
MAP_LAT=51.5074
MAP_LON=-0.1278
MAP_ZOOM=10
MQTT_ENABLED=false
MQTT_DEBUG=false
MQTT_TRANSPORT=tcp
MQTT_HOST=127.0.0.1
MQTT_PORT=1883
MQTT_TOPIC=meshcore/+/+/packets
MQTT_CLIENT_ID=auto
MQTT_USERNAME=
MQTT_PASSWORD=
MQTT_KEEPALIVE=30
MQTT_QOS=0
MQTT_PATH=/mqtt
MQTT_TIMEOUT=5
```

`MAP_LAT`, `MAP_LON`, and `MAP_ZOOM` set the default map center written into `config.php` during container startup.

When `MQTT_ENABLED=true`, the backend container automatically runs the MQTT worker on startup.
Firmware HTTP logging via `log.php` remains available at the same time.
Set `MQTT_DEBUG=true` to enable detailed MQTT topic/reporter resolution logs from `mqtt.php`.
`MQTT_CLIENT_ID=auto` derives a unique client ID per container to avoid broker disconnects caused by client-ID collisions.

### 4. Build and start the stack

```bash
sudo docker compose up -d --build
```

If your host hits a Docker Compose v2 metadata bug (for example:
`open /tmp/.tmp-compose-build-metadataFile-...: no such file or directory`),
use this command instead:

```bash
sudo COMPOSE_BAKE=false BUILDX_NO_DEFAULT_ATTESTATIONS=1 docker compose up -d --build
```

If the host still fails, force the legacy build path:

```bash
sudo DOCKER_BUILDKIT=0 COMPOSE_DOCKER_CLI_BUILD=0 docker compose up -d --build
```

Check Compose plugin version with:

```bash
docker compose version
```

Stop the stack:

```bash
sudo docker compose down
```

## 🗄️ Database Initialization
- On first start, the stack automatically imports `migrations/000_initial_setup.sql` into MariaDB.
- Database schema will be created.

## ➕ Adding the First Reporter (Logger)

After the stack is running, insert the first reporter into the database (see details in project's ```README.md```).

```bash
set -a; source .env; set +a

sudo docker exec -i mariadb mariadb -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
INSERT INTO reporters (name, public_key, lat, lon, auth, authorized, color)
VALUES ('ANR-Log', 'LOGGER_NODE_PUBLIC_KEY', '56.0', '27.0', 'SomeSecret', '1', 'red');
SELECT * FROM reporters WHERE name='ANR-Log';
"
```

## 🌐 Access

The Meshlog web interface is available at:
```
http://<your-server-ip>:<WEB_PORT>
```

The live WebSocket daemon is available at:
```
ws://<your-server-ip>:<LIVE_WS_PORT>/ws/live
```

The normal WebUI still uses the same site origin and nginx upgrade path (`/ws/live`) on `WEB_PORT`; the dedicated `LIVE_WS_PORT` mapping is mainly useful for direct transport testing.

## ⚠️ Warning

The stack must be reverse-proxied enabling ```https``` support for the logger firmware to access it (logger firmware supports only ```https```).
