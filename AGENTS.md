# AGENTS.md — MeshLog Structural Coding Guidelines

This file captures architectural investigations, coding conventions, and
guidelines for AI agents and human contributors working on this codebase.

---

## 1. Project Overview

**MeshLog** is a PHP + MySQL web application that ingests and displays packet
logs from the [MeshCore](https://github.com/Anrijs/MeshCore/tree/logger) mesh
radio network. It supports two ingest paths:

| Path | Entry point | Auth |
|------|-------------|------|
| HTTP (firmware logger) | `log.php` | `Authorization: Bearer <token>` |
| MQTT (meshcoretomqtt bridge) | `mqtt.php` (CLI worker) | Reporter `public_key` + `authorized=1` in DB |

---

## 2. Directory Structure

```
/
├── config.example.php          # Config template (copy → config.php)
├── log.php                     # HTTP ingest endpoint
├── mqtt.php                    # MQTT worker (CLI only, infinite reconnect loop)
├── setup.php                   # Web-based DB setup / migration runner
├── index.php                   # Main web UI entry point
│
├── lib/                        # Core library classes
│   ├── meshlog.class.php       # Central orchestrator (MeshLog)
│   ├── meshlog.mqtt_decoder.class.php   # MeshCore binary / JSON MQTT decoder
│   ├── meshlog.mqtt_client.class.php    # MQTT transport wrapper
│   ├── meshlog.entity.class.php         # Base DB entity (CRUD helpers)
│   ├── meshlog.reporter.class.php       # Reporter (gateway node)
│   ├── meshlog.contact.class.php        # Contact (observed node)
│   ├── meshlog.advertisement.class.php  # ADV packet entity
│   ├── meshlog.direct_message.class.php # MSG packet entity
│   ├── meshlog.channel_message.class.php# PUB (group) message entity
│   ├── meshlog.channel.class.php        # Channel entity
│   ├── meshlog.telemetry.class.php      # TEL packet entity
│   ├── meshlog.raw_packet.class.php     # RAW packet entity
│   ├── meshlog.report.class.php         # Report sub-tables (1-to-many)
│   ├── meshlog.setting.class.php        # Key-value settings
│   ├── meshlog.user.class.php           # Admin users
│   └── utils.php                        # Utils::time2str(), Utils::get()
│
├── api/v1/                     # REST API (JSON output)
├── admin/                      # Admin UI + API
├── migrations/                 # Numbered DB migrations (000–007)
└── docker/                     # Docker Compose deployment files
```

---

## 3. Data-Flow Diagrams

### 3a. HTTP Firmware Path

```
Firmware (MeshCore logger)
  → HTTP POST /log.php  { "type":"ADV", "reporter":"<pubkey>", ... }
      → MeshLog::insert($data)
          → authorize() — checks HTTP_AUTHORIZATION + reporter public_key
          → insertForReporter($data, $reporter)
              switch($data['type']) {
                  ADV  → insertAdvertisement()
                  MSG  → insertDirectMessage()
                  PUB  → insertGroupMessage()
                  SYS  → insertSelfReport()
                  TEL  → insertTelemetry()
                  RAW  → insertRawPacket()
              }
```

### 3b. MQTT Path (meshcoretomqtt binary packets)

```
meshcoretomqtt (Python bridge)
  → MQTT topic meshcore/<IATA>/<PUBLIC_KEY>/packets
    payload { "type":"PACKET", "packet_type":"4", "raw":"<hex>",
              "path":"AB->CD", "SNR":"10", "hash":"<hex16>",
              "timestamp":"2025-03-16T00:07:11", "origin_id":"<pubkey>", ... }

mqtt.php (CLI worker, infinite loop)
  → MeshLog::insertMqtt($topic, $payload)
      → MeshLogMqttDecoder::decode($topic, $payload)
          if type == "PACKET":
              if packet_type == 4 (ADVERT):
                  decodeAdvertPacket() → ADV structured array  ← NEW
                  → insertForReporter()  (same path as HTTP)
              else:
                  → RAW structured array  (fallback)
                  → insertRawPacket()
          elif type in STRUCTURED_TYPES (ADV/MSG/PUB/SYS/TEL/RAW):
              normalise timestamps → same structured array as HTTP path
              → insertForReporter()
```

### 3c. Pre-decoded Structured MQTT (firmware or custom bridges)

Some MQTT publishers (including the logger firmware when configured for MQTT)
send the same pre-decoded JSON as the HTTP firmware path.  These are handled
by the `STRUCTURED_TYPES` branch of `MeshLogMqttDecoder::decode()` and flow
through `insertForReporter()` identically to the HTTP path.

---

## 4. MeshCore Binary Packet Format

### 4a. Outer Packet Layout

```
[header (1 B)][transport_codes (4 B, optional)][path_len (1 B)][path (N B)][payload]
```

**Header byte** (bits inside one byte):

| Bits | Field | Meaning |
|------|-------|---------|
| 7–6 | — | unused |
| 5–2 | `payload_type` | `(header >> 2) & 0x0F` — see table below |
| 1–0 | `route_type` | 0x00 TRANSPORT_FLOOD, 0x01 FLOOD, 0x02 DIRECT, 0x03 TRANSPORT_DIRECT |

**Transport codes** (4 bytes) are present only for `ROUTE_TYPE_TRANSPORT_FLOOD` (0x00)
and `ROUTE_TYPE_TRANSPORT_DIRECT` (0x03). They are skipped during decode.

**`path_len` byte** (immediately after header or transport codes):

| Bits | Field | Meaning |
|------|-------|---------|
| 7–6 | `hash_size - 1` | 00 = 1-byte hashes, 01 = 2-byte, 10 = 3-byte; value 11 is reserved |
| 5–0 | `hop_count` | number of relay nodes in the path |

Actual path byte length = `hop_count × hash_size`.
Each hop hash is stored as raw bytes (not hex); decoded to lowercase hex string in MeshLog.

**Payload type constants** (defined in `MeshLogMqttDecoder`):

| Constant | Value | Description |
|----------|-------|-------------|
| `PAYLOAD_TYPE_TXT_MSG` | 2 | Direct text message (AES-128 encrypted) |
| `PAYLOAD_TYPE_ADVERT` | 4 | Node advertisement (unencrypted) |
| `PAYLOAD_TYPE_GRP_TXT` | 5 | Group/channel text (AES-128 encrypted) |

### 4b. ADVERT Payload (`payload_type = 4`)

Minimum 101 bytes. Layout:

```
[pubkey (32 B)] [timestamp (4 B, LE uint32, Unix s)] [signature (64 B, Ed25519)]
[appdata_flags (1 B)]
  [latitude  (4 B, LE int32, micro-degrees)]   ← only if flags bit 4 set
  [longitude (4 B, LE int32, micro-degrees)]   ← only if flags bit 4 set
  [feature1  (2 B)]                             ← only if flags bit 5 set (reserved)
  [feature2  (2 B)]                             ← only if flags bit 6 set (reserved)
  [name      (variable, rest of appdata)]       ← only if flags bit 7 set
```

**`appdata_flags` byte**:

| Bit(s) | Field | Meaning |
|--------|-------|---------|
| 3–0 | `node_type` | 1=chat, 2=repeater, 3=room_server, 4=sensor |
| 4 | `has_location` | lat/lon fields follow |
| 5 | `has_feature1` | reserved 2-byte field follows |
| 6 | `has_feature2` | reserved 2-byte field follows |
| 7 | `has_name` | name string follows (to end of payload, strip trailing `\0`) |

The `pubkey` becomes the contact's `public_key` (uppercase hex, 64 chars).
Latitude/longitude are divided by 1 000 000 to get decimal degrees.

### 4c. GRP_TXT Payload (`payload_type = 5`, AES-128 encrypted)

Outer structure (plaintext header + ciphertext):

```
[channel_hash (1 B)]   SHA256(PSK_bytes)[0] — plaintext channel identifier
[mac          (2 B)]   HMAC-SHA256(secret_32, ciphertext) truncated to 2 bytes
[ciphertext   (N×16 B)] AES-128-ECB( secret[0:16], plaintext_zero_padded )
```

Decrypted plaintext layout:

```
[timestamp (4 B, LE uint32, Unix s)] [flags (1 B, 0=plain)] [text (variable, "Name: msg\0")]
```

Decryption uses channel PSKs from the `channels` table; only enabled channels
with a known PSK are attempted.  If decryption fails or no matching channel is
found the packet is stored as a RAW entry.

### 4d. TXT_MSG (`payload_type = 2`, AES-128 encrypted)

`PAYLOAD_TYPE_TXT_MSG` is a direct (unicast) encrypted message.
MeshLog does **not** attempt decryption — it is always stored as a RAW packet.

---

## 5. Entity Classes — Conventions

All entity classes extend `MeshLogEntity` and follow the same pattern:

| Method | Purpose |
|--------|---------|
| `fromJson($data, $meshlog)` | Construct from HTTP/MQTT ingest payload |
| `fromDb($data, $meshlog)` | Construct from a PDO `fetch(FETCH_ASSOC)` row |
| `isValid()` | Validate fields before save — **always returns `true`**, logs errors |
| `asArray($secret)` | Serialise to array for API output |
| `getParams()` | Returns `["column" => [$value, PDO_TYPE]]` map for INSERT/UPDATE |
| `save($meshlog)` | INSERT or UPDATE via `getParams()` |

### Key Conventions

- **`Utils::time2str($ms)`** converts a PHP `int` (seconds or milliseconds)
  to `"Y-m-d H:i:s.mmm"`.  It returns `null` for non-int inputs.  Always cast
  timestamps to `int` before passing.
- **Timestamps** in ingest payloads are stored in **milliseconds** as PHP ints
  after passing through `MeshLogMqttDecoder::normalizeTimestampMs()`.
- **`isValid()` always returns `true`** in the current code; error messages are
  sent to `error_log()` only.  A false-y check on `isValid()` will never abort
  a save.
- **`public_key` storage**: contacts use `varchar(64)` (32-byte hex key);
  reporters use `varchar(200)`.  Always store keys as **uppercase hex**.
- **`hash` field** in advertisements/messages: `varchar(16)` (8-byte hex),
  used for deduplication within a rolling time window.

---

## 6. MQTT Decoder — Key Rules

File: `lib/meshlog.mqtt_decoder.class.php`

- Reporter key is resolved from the MQTT **topic** first
  (`<prefix>/<iata>/<pubkey>/(status|packets|debug)`), then from the JSON
  payload (`origin_id`, `public_key`, `pubkey`, `reporter`).
- The `attempted_reporter` in the returned `_mqtt` metadata is always the
  uppercase hex key used for DB lookup.
- A valid reporter key must be hex-only, even length, ≥ 4 chars
  (`MIN_REPORTER_KEY_LENGTH`).
- Binary `PACKET` payloads set `packet_type` = `(header_byte >> 2) & 0x0F`.
  The decoder checks `packet_type === PAYLOAD_TYPE_ADVERT (4)` and tries
  `decodeAdvertPacket()` before falling back to RAW storage.
- `normalizeTimestampMs()` accepts Unix seconds, milliseconds, or ISO 8601
  strings; returns an `int` in **milliseconds**, or `$fallback` on failure.

---

## 7. Ingest Routing — `insertForReporter()` Switch

```php
switch ($data['type']) {
    case 'ADV': insertAdvertisement($data, $reporter);    break;
    case 'MSG': insertDirectMessage($data, $reporter);    break;
    case 'PUB': insertGroupMessage($data, $reporter);     break;
    case 'SYS': insertSelfReport($data, $reporter);       break;
    case 'TEL': insertTelemetry($data, $reporter);        break;
    case 'RAW': insertRawPacket($data, $reporter);        break;
}
```

Every ingest path (HTTP or MQTT, binary or pre-decoded JSON) must end up
calling `insertForReporter()` with a payload that matches the expected JSON
schema for its type.

---

## 8. Database Schema (current, post-migration-008)

| Table | Key Columns |
|-------|-------------|
| `reporters` | `id`, `public_key varchar(200)`, `authorized tinyint`, `auth varchar(200)` |
| `contacts` | `id`, `public_key varchar(64) UNIQUE`, `name`, `hash_size`, `last_heard_at` |
| `advertisements` | `id`, `contact_id`, `hash varchar(16)`, `name`, `lat`, `lon`, `type`, `flags`, `hash_size TINYINT DEFAULT 1`, `sent_at` |
| `advertisement_reports` | `id`, `advertisement_id`, `reporter_id`, `path`, `snr`, `received_at` |
| `direct_messages` | `id`, `contact_id`, `hash varchar(16)`, `name`, `message`, `hash_size TINYINT DEFAULT 1`, `sent_at` |
| `direct_message_reports` | `id`, `direct_message_id`, `reporter_id`, `path`, `snr`, `received_at` |
| `channel_messages` | `id`, `contact_id`, `channel_id`, `hash varchar(16)`, `name`, `message`, `hash_size TINYINT DEFAULT 1`, `sent_at` |
| `channel_message_reports` | `id`, `channel_message_id`, `reporter_id`, `path`, `snr`, `received_at` |
| `channels` | `id`, `hash varchar(64) UNIQUE`, `name`, `enabled` |
| `telemetry` | `id`, `contact_id`, `reporter_id`, `data json`, `sent_at`, `received_at` |
| `raw_packets` | `id`, `reporter_id`, `header`, `path`, `payload varbinary(256)`, `snr`, `decoded`, `hash_size TINYINT DEFAULT 1`, `received_at` |
| `settings` | `id`, `name`, `value` |

`hash_size` defaults to `1` for all pre-migration-007 rows and all firmware paths
that do not encode MultiByte hashes.  The `channels.hash` column was widened from
`varchar(16)` to `varchar(64)` in migration-008 to accomodate longer PSK hashes.

Deduplication of ADV/MSG/PUB is done in code via
`findBy("hash", ..., extra_constraint 'created_at > minage')`.

---

## 9. Testing

The repository has **no PHPUnit or dedicated test suite**.  The only automated
check is PHP syntax linting:

```bash
find . -name "*.php" | xargs php -l
```

Run this after every change.  All files must report "No syntax errors detected".

---

## 10. Adding a New Packet Type (guide for agents)

1. Add a `PAYLOAD_TYPE_*` constant to `MeshLogMqttDecoder`.
2. Add a `decode*Packet()` private static method in the same class.
3. Call it from the `PACKET` branch in `decode()`, guarded by
   `if ($packetType === static::PAYLOAD_TYPE_*)`.
4. Return a structured array with the same keys expected by the corresponding
   `insert*()` method in `MeshLog`.
5. Ensure `time.sender`, `time.local`, `time.server` are PHP `int` values in
   **milliseconds** (use `normalizeTimestampMs()` or direct arithmetic).
6. Run `find . -name "*.php" | xargs php -l` to lint.

---

## 11. Reporter Authorization

- **HTTP path**: requires matching `public_key` + `Authorization: Bearer <auth>` header + `authorized=1`.
- **MQTT path**: requires only matching `public_key` + `authorized=1` (no auth token check).
- The `auth` field in a reporter record can be left empty for MQTT-only reporters.

---

## 12. Docker / Deployment Notes

- `docker/docker-compose.yaml` version `"3.8"`.
- Web port is configurable via `WEB_PORT` in `docker/.env` (default 80).
- The backend container auto-starts `mqtt.php` via **supervisord**.
- MQTT settings are injected into `config.php` from backend environment
  variables in the container entrypoint (`docker/build/entrypoint.sh`).
