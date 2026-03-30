# Changelog

All notable changes to MeshLog are recorded here, in reverse chronological order.

---

## [v1.1.0] — WebUI Parity Map Update (2026-03-30)

### iOS App — Fixes & Improvements

- **Marker type parity with WebUI** — iOS now normalizes both numeric and string node types, so chat nodes, repeaters, rooms, sensors, and reporter-backed collectors no longer collapse to the same marker icon.
- **Map detail card parity** — selected-device cards now show WebUI-matching identity and status context: type, live/stale state, public key, first seen, coordinates, reporter flag, hash metadata, repeater clock warning, and the latest route summary.
- **Explicit repeater neighbor activation** — selecting a repeater no longer draws neighbor lines automatically; neighbor overlays now appear only after pressing `Show Neighbors` in the device card.
- **Stronger neighbor visibility** — neighbor links use a brighter dual-stroke dashed style so they remain readable on both light and dark map themes.
- **Live route overlay parity** — the iOS map now consumes the same live packet stream as the Live Feed and reconstructs per-report source → relay → reporter chains, so packet paths appear on the map as packets arrive.
- **Animated route rendering** — live route lines, selected trail reporter links, and neighbor overlays now use animated dashed strokes for clearer movement and better route readability.
- **Release metadata alignment** — app release numbers were synchronized to `1.1.0 (build 4)` across iOS project settings and API config output.

---

## [Unreleased] — iOS App (2026-03-29 – 2026-03-30)

Changes made during the active iOS development session that have not yet been tagged.

### iOS App — New Features

- **Local notifications** — push alerts for new device first-seen and new direct/channel message events; toggleable per-type in Settings with iOS permission flow.
- **Per-device trail on main map** — each device in the popup can independently show/hide its GPS advertisement trail as a dashed red polyline; persisted across sessions via AppStorage.
- **Trail point inspector** — tapping a red trail dot shows a top-overlay panel with device name, GPS coordinates, and a list of every collector that heard that position (name, distance in km, SNR).
- **Repeater listener lines** — blue dashed lines drawn from the selected trail dot to each located collector.
- **Trail dot zoom-scaling** — trail dots enlarge proportionally to the current map zoom level (clamped 12–28 pt) with an invisible oversized hit-target for reliable tapping.
- **Device search on map** — magnifying-glass toolbar button opens a live-filter search bar; tapping a result pans the map to that device and opens its detail card; matches by name or public-key prefix.
- **Tap-to-jump from Live Feed** — tapping a packet row with a known device jumps to the Map tab and centres the camera on that device.
- **WebUI-matching badge colours** — Live Feed inline meta-badges use the same colour scheme as the web interface (green for scope, grey for hash-size, grey-blue for metrics).
- **GPX export** — Trail tab in the device popup exports the advertisement trail as a `.gpx` file via iOS ShareSheet.
- **MeshCore Austria app icon** — all 13 AppIcon sizes generated from the project logo.

### iOS App — Fixes & Improvements

- **Neighbour links rewritten** — now correctly iterates per-report paths, appends the reporter contact as the final endpoint, and loads reporter records on startup to resolve `reporterId → Contact` via public key.
- **Immediate packet type filtering** — toggling a packet type on/off instantly shows/hides matching rows from the buffer without waiting for a network round-trip; stream is restarted with 250 ms debounce.
- **Live Feed labels all showed "Unknown"** — fixed by falling back to legacy `name` / `public_key` field names in addition to `contact_name` / `contact_public_key` when decoding packets.
- **Admin Channels/Reporters blank** — PHP returns `0`/`1` integers; added flexible `init(from:)` in `Reporter.authorized` and `Channel.enabled` accepting Bool, Int, or String.
- **Collectors list never loaded** — replaced a silent `try?`-swallowed fetch with a proper loading/error/retry flow; falls back to deriving collector IDs from contact `reporter_ids`.
- **Map toolbar buttons unresponsive** — detail card was in a full-screen ZStack with inverted hit-testing; replaced with bottom-aligned `.overlay` modifier so toolbar buttons are never blocked.
- **Trail tab blocked after jump-to-map** — same hit-testing regression; removed the ZStack entirely.
- **Dark/light toggle applied to map tiles** — added `.environment(\.colorScheme, ...)` to the MapKit `Map` view so tile colour scheme actually switches.
- **Labels toggle applies to all node types** — non-repeater nodes were hard-coded to always show labels; now the label toggle reads `@AppStorage` directly inside `NodeAnnotationView` so MapKit annotation-view caching cannot suppress the change.
- **Startup network warnings reduced** — stream restarts are debounced (250 ms) and deduplicated by config key to avoid rapid cancel/restart cycles at launch.
- **`bullseye` SF Symbol replaced** — substituted `scope` which is available in the iOS target's SF Symbol set.

### Backend — New API Endpoints (supporting iOS app)

- `GET /api/v1/live` — SSE stream endpoint delivering real-time packets; falls back to long-poll; supports `types` and `collector_ids` query params.
- `GET /api/v1/auth/` — token-based login for admin-level API access.
- `GET /api/v1/contact_stats/` — per-contact packet statistics.
- `GET /api/v1/contact_advertisements/` — advertisement history for trail visualisation.
- Expanded `/api/v1/all/` to include `TEL` and `SYS` packet types.

---

## [v0.17] — Stats Rollups (2026-03-24)

### New Features

- **Global stats dashboard** — new Stats tab showing advertisement activity across the whole mesh for the last 1 h, 24 h, and 36 h with a Y-axis chart.
- **Stats rollup tables** (`migration 017`) — `advertisement_rollups` and `contact_rollups` pre-aggregate hourly/daily stats to avoid full table scans.
- **Persist stats history across purge** — rollup rows are retained when the autopurge job runs.

### Fixes

- Registered migration 016 in `setup.php`.
- Fixed stats query for schemas without the `packet_type` column (pre-migration-014 installs).

---

## [v0.16] — Route Type & Map Trail (2026-03-24)

### New Features

- **Route type tracking** (`migration 016`) — added `route_type TINYINT` to all `*_reports` tables; values 0–3 map to TRANSPORT_FLOOD, FLOOD, DIRECT, TRANSPORT_DIRECT.
- **Main-map chat route trail** — chat-client advertisement paths drawn on the live Leaflet map as a route trail layer.
- **Chat client trail mini-map** — device popup shows a scrollable mini-map of the selected contact's advertisement history.
- **Resolve chat route points to repeaters** — trail path hops are resolved to actual repeater marker positions using `findNearestContact`.

---

## [v0.15] — Admin Stats, Purge Fix & UI Reorganisation (2026-03-22 – 2026-03-24)

### New Features

- **Live admin stats dashboard** — `/admin` now shows real-time packet counters and per-reporter activity.
- **Retention-based autopurge fix** — purge now correctly uses the configured retention window instead of deleting all records.
- **Collector receipt markers on map** — when a reporter is also a located contact, a receipt icon marker is shown at its map position.
- **SNR heat layer** (`reverted`) — zero-hop ADV packets triggered a Leaflet heat layer; reverted in same session after evaluation.
- **Auto-centre on user location** — map auto-pans to the browser's geolocation on first load.
- **CTRL packets in device stats** — packet-type breakdown now includes `PAYLOAD_TYPE_CONTROL` (type 11).
- **Neighbours toggle in device popup** — "Show Neighbors" / "Hide Neighbors" button added to the map popup general tab.
- **Backend-backed contact stats popup** — Stats tab in the map popup loads live data from `/api/v1/contact_stats/`.
- **Y-axis labels** on the stats chart (max, 0, packet count).

### UI Changes

- Reorganised Settings tab: Live Feed and Channel filters moved to dedicated sections; Collectors filter integrated inline.
- Device List filters moved to a separate Devices tab panel.
- Merged right-panel into tabbed left sidebar.
- Pan map to device on popup open.

### Fixes

- Fixed numerous "Show Neighbors" button reliability issues (event delegation, Leaflet DomEvent, case-sensitive public-key mismatch, `m.reports` undefined guard, guaranteed fallback path for repeater relay nodes).
- Fixed `display:flex` overriding the `hidden` attribute on the heat-layer legend.
- Fixed heat layer using latest contact position instead of per-packet ADV GPS.
- Fixed double-comma JS syntax error in `_defaultContactPacketStats`.
- Fixed popup tab switching (`options.tab` vs `options.activeTab`).
- Fixed popup tabs not responding to clicks on first render and after re-selection.
- Bumped `MeshLog` version constant to 15; added auto-redirect to `setup.php` on pending migrations.
- Removed stale-date colour styling from Devices tab dot badges.
- Restored Live tab dot colours.
- Resolved ESLint `empty-catch` warnings; remediated npm audit vulnerability.

---

## [v0.15] — Raw Packet Contact Link (2026-03-29 web backfill)

### Migration

- `migration 015` — added `contact_id` foreign key to `raw_packets` so RAW packets can be linked to a known contact.

---

## [v0.14] — Report Sender Timestamp (2026-03-22 area)

### Migration

- `migration 014` — added `sender_at DATETIME` to all `*_reports` tables, storing when the originating node claimed it sent the packet.

---

## [v0.13] — System Reports (2026-03-22 area)

### New Features

- `SYS` packet type support — `system_reports` table stores self-report / status packets from reporter nodes.

### Migration

- `migration 013` — creates the `system_reports` table.

---

## [v0.12] — Transport Scope (2026-03-22 area)

### Migration

- `migration 012` — added `scope` column to advertisement/message report tables, storing transport scope (0=local, 1=mesh).

---

## [v0.11] — Audit Log (2026-03-22 area)

### New Features

- Admin actions (reporter add/edit/delete, settings changes) are recorded in an `audit_log` table with user, action, and timestamp.

### Migration

- `migration 011` — creates the `audit_log` table.

---

## [v0.10] — Reporter Hash Size (late 2025)

### Migration

- `migration 010` — added `hash_size TINYINT DEFAULT 1` to `reporters`, enabling per-reporter MultiByte hash configuration.

---

## [v0.9] — Channel PSK (late 2025)

### New Features

- Channel group messages can be decrypted using AES-128-ECB when a PSK is configured per channel.
- MQTT decoder attempts GRP_TXT (`payload_type 5`) decryption against all enabled channels with a known PSK.

### Migration

- `migration 009` — added `psk VARCHAR(64)` to `channels`.

---

## [v0.8] — Channel Hash Expansion (late 2025)

### Migration

- `migration 008` — widened `channels.hash` from `VARCHAR(16)` to `VARCHAR(64)` to accommodate longer PSK hashes.

---

## [v0.7] — MultiByte Hash Size (late 2025)

### New Features

- Support for MeshCore MultiByte hashes (1, 2, or 3 bytes per hop hash).
- `hash_size` stored per advertisement, message, channel message, and raw packet.

### Migration

- `migration 007` — added `hash_size TINYINT DEFAULT 1` to `advertisements`, `direct_messages`, `channel_messages`, and `raw_packets`.

---

## [v0.6] — Contact Last Heard (2025-12-30)

### New Features

- `contacts.last_heard_at` timestamp updated on every ADV/MSG/PUB ingest.
- API responses include `last_heard_at` for all contacts.
- Improved contact query performance.
- Telemetry format fixes.

### Migration

- `migration 006` — added `last_heard_at DATETIME` to `contacts`.

---

## [v0.5] — Reporter Style Options (2025-12-20)

### New Features

- Reporter nodes can have a display style (colour, icon) configurable in the admin panel; colour is shown in the Devices list.
- Binary lookup (`findBy`) now uses indexed search for string columns.
- Username colourisation in the Live Feed.
- Reporter name shown in expanded packet reports list.

### Migration

- `migration 005` — added `style` JSON column to `reporters`.

---

## [v0.4] — Raw Packet Logging (2025-11-18 – 2025-11-23)

### New Features

- `RAW` packet type ingested from both HTTP (`log.php`) and MQTT paths; stored in `raw_packets` with header, payload, path, SNR, and decoded flag.
- MQTT binary decoder: `PAYLOAD_TYPE_ADVERT (4)` decoded to ADV structured array; other types fall back to RAW storage.
- REST API endpoint `GET /api/v1/raw_packets/` with time-window filtering.
- Contact and path GPX export from the map popup.
- Device hash shown in marker tooltip.
- Names in GPX export are HTML-escaped.

### Migration

- `migration 004` — creates the `raw_packets` table.

---

## [v0.3] — Packet Reports (2025-11-08)

### New Features

- Separate `*_reports` sub-tables decouple reception metadata (reporter, path, SNR, received_at) from packet identity — supports multiple collectors hearing the same packet.
- Relay path visualisation on map: coloured arrows per collector showing the packet route.
- Contact search box added to the map.
- Various style changes and improvements.

### Migration

- `migration 003` — creates `advertisement_reports`, `direct_message_reports`, `channel_message_reports`.

---

## [v0.2] — Telemetry (2025-10-13)

### New Features

- `TEL` packet type ingested and stored; displayed in-device-popup telemetry section.
- Per-channel group message AES-128 decryption skeleton.
- Admin colour picker for reporters.
- Channel filter in Live Feed; settings persisted to `localStorage`.
- Contact type filter (Repeater / Client / Room / Sensor) in Devices panel.
- Shortest-path dedup for relay lines with duplicate hashes.
- Sort contacts by First Seen, Last Heard, Name, or Hash.
- Linkifyjs integration (PR #4) — URLs in messages are clickable.

### Migration

- `migration 002` — creates the `telemetry` table.

---

## [v0.1] — Admin, Settings & Multi-version Migrations (2025-10-16 – 2025-10-23)

### New Features

- Web-based `setup.php` database setup / migration runner with login guard.
- Admin panel (`/admin`) — add, edit, delete reporters; view and purge records.
- Key-value `settings` table storing global config (retention, auth tokens etc.).
- PHP migration framework: numbered `migrations/*.php` scripts, no-op-safe re-run, multi-version upgrade support.

### Migration

- `migration 001` — creates `admin_users` and `settings` tables.

---

## [v0.0] — Initial Release (2025-08-19 – 2025-09-27)

### Foundation

- PHP + MySQL web application receiving MeshCore packet logs over HTTP (`log.php`) and MQTT (`mqtt.php`).
- Initial database schema: `reporters`, `contacts`, `advertisements`, `direct_messages`, `channel_messages`, `channels`.
- Live Feed displaying ADV, MSG, and PUB packets as they arrive with real-time Leaflet map.
- Relay path polylines between source node and repeater hops on the map.
- Reporter admin page (`/admin/reporters`) for authorising collector nodes.
- Docker Compose deployment (`docker/docker-compose.yaml`) with Nginx, PHP-FPM, MySQL, supervisord, and MQTT worker (PR #1).
- Rename groups → channels throughout.
- Always normalise advertisement coordinates to decimal degrees.
- Hide contacts with `0, 0` location.
- `config.example.php` template; `assets/` directory for CSS, JS, favicons, and images.

---

*This changelog was generated from the full git commit history on 2026-03-30.*
