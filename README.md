# MeshLog Web
Web side for [MeshCore logger firmware](https://github.com/Anrijs/MeshCore/tree/logger)

## Requirements
- PHP
- MySQL or MariaDB

## Installation
1. Setup MySQL database
2. Open `setup.php`, follow instructions
3. Flash logger node [MeshCore logger firmware](https://github.com/Anrijs/MeshCore/tree/logger) (Xiao S3 and T3S3 are currently supported)
4. Connect to logger node via serial and set configuration:
  - `log url https://<your_site>/meshlog/log.php` Where data is sent (should point to `log.php` file)
  - `log report 1800` Self-report interval, can be 0 to disable
  - `log auth SomeSecret` Secret used for web authorization
 - `wifi ssid YourWifiSSID`
 - `wifi password YourWifiPassword`
 - `set name  Node Name`
 - `set lat xx.xxxxx`
 - `set lon xx.xxxxx`
 - `reboot` Apply changes
5. Add reporter to database by going to `admin/`
   - `Public Key` must match the logger node public key (`reporter` field in HTTP logs, `origin_id` in MQTT ingest).
   - `Auth` must match the logger `log auth` value. MeshLog expects this in HTTP `Authorization: Bearer <secret>` from the logger firmware.
   - For MQTT ingest (`php mqtt.php`), authorization header is not used, but the reporter must still exist and be `authorized = 1`.
   - You can leave `Auth` empty for MQTT-only reporters.

## MQTT ingest (optional)
MeshLog can ingest MeshCore packet logs from MQTT (for example from [meshcoretomqtt](https://github.com/Cisien/meshcoretomqtt)) while keeping firmware HTTP logging active.

1. Configure MQTT in `config.php` (see `config.example.php`)
   - `enabled`: `true` to activate MQTT ingest
   - `transport`: `tcp`, `ssl`, `ws`, or `wss`
   - `host`, `port`, `topic`
   - `username` / `password` for authenticated brokers (or leave empty for anonymous)
2. Ensure each MQTT publisher `origin_id` exists as a reporter `public_key` in MeshLog.
   - Add it from `admin/` with:
     - `public_key = origin_id`
     - `authorized = checked`
     - optional `auth` (only needed for HTTP logger ingest)
3. Run MQTT worker:
   - `php mqtt.php`

The worker listens to packet topics and stores them as RAW packets. MQTT `path` values are normalized and hash prefix size is detected for 1/2/3-byte routing hashes (MeshCore 1.14 compatible).
