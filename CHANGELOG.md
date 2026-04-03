# Changelog

All notable changes to MeshLog are recorded here, in reverse chronological order.

---

## [v1.0.5] — Topo Map Source & Channel Stats (2026-04-04)

### WebUI / Backend — New Features

- **OpenTopoMap layer option** — the map layer switcher now includes a `Topo` source backed by OpenTopoMap tiles, and popup mini-maps follow the same selected base layer.
- **Channel statistics in Stats** — the database-backed stats response now includes per-channel message totals and unique sender counts for the selected `1h`, `24h`, or `36h` window.
- **Stats panel channel activity section** — WebUI Stats now renders channel message totals for the active time window alongside the existing advertisement and collector rollups.

### iOS App — New Features

- **Three-mode map source toggle** — the Map toolbar now cycles between Dark, Light, and Topo sources, with Topo rendered from OpenTopoMap tiles.
- **Channel activity in Stats** — the iOS Statistics screen now shows per-channel message totals and unique sender counts for the selected `1h`, `24h`, or `36h` window.

## [v1.0.4] — Reporter Format, Status, and Channel Filtering (2026-04-02)

### WebUI / Backend — New Features

- **Per-reporter MQTT format selection** — Admin → Reporter Devices now includes a format selector per reporter (`meshlog` default, `letsmesh`) and persists it in the database.
- **Per-reporter optional IATA binding** — Admin now supports an optional IATA code per reporter; MQTT ingest validates topic/payload IATA against the configured value when present.
- **Reporter format + IATA migration** — `migration 018` adds `report_format` and `iata_code` columns to `reporters`, and setup/admin update flow now applies it as part of schema upgrades.
- **LetsMesh ingest support** — MQTT decoder adds LetsMesh payload normalization for structured packet types plus RAW fallback, with topic/payload metadata capture.
- **Reporter status snapshot ingestion** — MQTT `.../status` topic payloads (MeshLog and LetsMesh shapes) are normalized and cached in reporter metadata.
- **Map device detail: Collector Status** — device popup detail now shows collector status information (basic identity and radio/health metrics) for reporter devices.
- **Collector status presentation cleanup** — status rows with empty/unknown values are suppressed, and fields are grouped into **Basic** and **Radio / Health** blocks.

### iOS App — New Features

- **Collector status in map detail** — reporter status from API is decoded and rendered in the iOS map device detail view with relevant system/radio fields.
- **Live/Channels all-vs-selected filtering** — users can switch between showing all channels or only selected channels in Live → Channels.
- **Settings-level live channel filter controls** — simple All/Selected toggle and channel multi-select controls were added in Settings, reusing the same persisted preferences as Live.

---

## [v1.0.3] — Channels History & Map Legend Clarity (2026-04-02)

### iOS App — New Features

- **Live Channels backward history paging** — opening a channel and scrolling to the oldest visible message now fetches older PUB packets from the database (`before_ms` paging) until the oldest available channel history is reached.
- **Openable Map legend** — the Map now includes a toolbar legend toggle that opens a concise in-map reference card explaining bubble colors, active-route highlighting, badges, and node-type icons.

### iOS App — Fixes & Improvements

- **Channels detail view no longer truncates** — Live → Channels now keeps all currently loaded channel messages scrollable in the channel detail pane instead of trimming to a short recent subset.
- **Channel read/unread persistence hardening** — channel read cursors are now stored with stable alias keys and continuously advanced while a channel is open, so unread counters stay consistent across app updates, reconnects, and channel key-shape changes.
- **Legend line semantics clarified** — the Map legend now explicitly explains blue dashed neighbor links versus red dashed GPS movement trails, so line meaning is clear directly in-app.
- **Release metadata alignment** — app release numbers were synchronized to `1.0.3 (build 6)` across iOS project settings.

---

## [v1.0.2] — Live Map Animation & Code Cleanup (2026-03-31)

### iOS App — New Features

- **Channels drill-down mode in Live tab** — the Channels view now opens as a compact channel list by default; tapping a channel switches into that channel-only message stream instead of expanding all channels at once.
- **Unread channel badges** — channel rows now show a red unread counter for new PUB messages until that channel is opened.
- **Map opens on device location** — initial map focus now prefers the Apple device's current location (with permission), avoiding unrelated default regions.

### iOS App — Fixes & Improvements

- **Admin login placeholder visibility** — username/password placeholder text now uses explicit high-contrast color on dark input backgrounds.
- **Global UI colour pass** — app section titles, segmented controls, and selected bottom-tab state were normalized to white-focused styling for better readability and consistency.
- **Collector naming in Settings** — the Collectors list now resolves and displays contact/device names instead of showing only reporter public keys.
- **Unified textbox styling** — all text inputs (Login, Settings admin fields, Devices search, Map search) now share one consistent dark style and prompt contrast.
- **Devices tab map jump** — tapping a device row now jumps directly to the Map tab and focuses that device when coordinates are available.
- **Live Feed channel/scope parity** — PUB rows now include both channel label and transport scope badges to align with WebUI packet metadata visibility.
- **Live Feed distance badge** — packets with coordinates now show computed distance from the phone, with user-selectable `km`/`mi` units in Settings.
- **Stats correctness alignment** — removed unsupported `6h` client option (backend supports `1h`, `24h`, `36h`) to prevent misleading values from server-side fallback.
- **Stats page redesign** — replaced generic key rendering with validated rollup metrics: report totals, device/collector counts, route breakdown, collector ranking, and timeline charts.
- **Stats clarity add-ons** — added a last-updated timestamp and inline route-metrics legend for quick interpretation of direct/flood/relayed/no-hop/unknown counters.
- **Channel label formatting parity** — Live Feed and Live → Channels now normalize channel names so `Public` is shown without a `#`, and non-public channels always render with exactly one leading `#` (no double-prefix `##`).
- **Persistent channel read state** — Live → Channels now persists per-channel last-read packet positions so unread counters survive app restarts and reopening a channel resumes from the last-read point.
- **Configurable SNR thresholds (WebUI colors)** — SNR badge colors now keep fixed WebUI color mapping while the dB threshold cutoffs are editable in Settings (Excellent/Good/Fair/Weak).
- **Channel message hyperlinks** — URLs in Live → Channels message bodies are now link-styled and directly tappable to open in the browser.
- **Stronger live map packet animation** — path flashes are now rendered with brighter/thicker foreground lines and larger traveling-dot emphasis for clearer packet-route visibility.
- **Affected-node flash highlighting** — devices on the active packet route are now temporarily highlighted (halo + ring) while the map animation is running.
- **Background notification continuity improvements** — Live monitoring no longer always tears down on scene deactivation when notification toggles are enabled; limited background execution now keeps packet processing alive longer for local alerts.
- **Dynamic app version in Settings** — the About section now reads app version/build from the bundled Info.plist instead of using a hardcoded value.
- **SNR settings simplified** — removed the SNR color mode switch; WebUI colors are now the default/fixed behavior and users can directly tune only the SNR threshold values.
- **Live Feed channel badge colors** — PUB channel badges in Live → Feed now use per-channel deterministic colors (matching the visual style used in Live → Channels).
- **Map flash foreground pass** — live packet flash rendering was boosted again (thicker/brighter line + larger traveling marker) and pinned to top overlay priority for stronger foreground visibility.
- **Affected-node name reveal during flashes** — devices on an active packet path now force-show their names during highlight windows, even when global map labels are turned off.
- **Highlighted-node render guarantee** — active path nodes are now excluded from map downsampling so affected devices remain visible while the animation runs.
- **Highlighted-name top-layer rendering** — names for actively highlighted path devices are now rendered in a dedicated top overlay layer so they can no longer be hidden behind other device markers.
- **Full highlighted-marker foreground overlay** — active route bubbles now render as complete top-layer overlays during packet animations, so the highlighted device icon, its surrounding text, and the animated path presentation stay visually above the normal map marker layer.
- **Map animation cache pass** — repeater clock warnings and highlighted top-layer route markers are now precomputed when contact/report data changes, reducing repeated filtering and warning recalculation during the 30 fps map animation overlay.
- **Repeater clock-warning bubble badge** — repeater markers with backend `time_sync` warnings now show a direct `!` badge on the map, and the detail card prefers the same NTP/reference-clock warning text used by the WebUI before falling back to local ADV drift estimation.
- **Live Feed full-history scrolling** — reaching the bottom of Live → Feed now requests older packets from the database and keeps extending history until the oldest available packet is reached.
- **Settings-capped history chunking** — each backward history request is capped by the Live Feed Items setting (max 500), so older packets are loaded incrementally instead of in one large burst.
- **SSE history compatibility update** — `api/v1/live/stream.php` now uses the same load-more limit rules (up to 500, invalid values normalized) as the polling endpoint so history pagination behaves consistently across both transport modes.
- **Live Feed scroll stability during load-more** — packet rows now use a stable per-type/per-id identity key when extending the list, preventing SwiftUI row reuse collisions that could cause sudden jumps to the tail while older chunks are appended.
- **Live Feed anchor-preserving pagination** — when the end-trigger loads more history, the current row is now used as a scroll anchor so the viewport stays at the same place and users can manually continue scrolling to the next load point.

- **Live hop-by-hop path animation** — when any packet (ADV, MSG, PUB) arrives via the live stream, the full relay chain (source → repeater hops → reporter) is animated on the map with a bright cyan traveling dot, a glow line and a fading outer envelope, matching the WebUI's packet-arrival animation behavior.
- **Expanding ring at destination** — the animation ends with a pulsing ring at the reporter position to mark packet arrival visually.
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

- **Live stream extended to ADV + MSG + PUB** — the map route-overlay stream was ADV-only; it now also receives MSG and PUB packets so path animations trigger for all packet types with a resolvable relay chain.
- **25 fps animation loop** — a dedicated `animationFrameTask` drives smooth dot interpolation at ~25 fps and stops itself automatically when no animations are active.
- **Max 5 concurrent flashes** — caps simultaneous path animations to keep the map readable; oldest flash is evicted when a new one arrives.
- **Packet buffer raised to 60** — `maxStoredRecentPackets` raised from 24 to 60 to retain more recent packets for route and neighbour overlay rebuilds.
- **Replaced `DispatchQueue.main.async` with `await MainActor.run`** — `loadAll()` and `loadConfig()` now use structured Swift Concurrency, removing potential reentrancy and data-race risks.
- **Removed trivial computed-property wrappers** — `pathPolylines`, `neighborPolylines`, and `mainTrailPoints` were single-line pass-throughs to `rendered*` state; eliminated in favour of direct references.
- **Simplified `formattedAbsoluteTime()`** — the function was re-parsing and re-serialising a date string through the same formatter (a no-op); now returns the raw string directly or `"-"` for empty input.
- **Animations cleared on tab/scene deactivation** — `livePacketFlashes` is purged and `animationFrameTask` is cancelled whenever the map tab is hidden or the app backgrounds, preventing stale dots on re-entry.

---

## [v1.0.1] — WebUI Parity Map Update (2026-03-30)

### iOS App — Fixes & Improvements

- **Marker type parity with WebUI** — iOS now normalizes both numeric and string node types, so chat nodes, repeaters, rooms, sensors, and reporter-backed collectors no longer collapse to the same marker icon.
- **Map detail card parity** — selected-device cards now show WebUI-matching identity and status context: type, live/stale state, public key, first seen, coordinates, reporter flag, hash metadata, repeater clock warning, and the latest route summary.
- **Explicit repeater neighbor activation** — selecting a repeater no longer draws neighbor lines automatically; neighbor overlays now appear only after pressing `Show Neighbors` in the device card.
- **Stronger neighbor visibility** — neighbor links use a brighter dual-stroke dashed style so they remain readable on both light and dark map themes.
- **Live route overlay parity** — the iOS map now consumes the same live packet stream as the Live Feed and reconstructs per-report source → relay → reporter chains, so packet paths appear on the map as packets arrive.
- **Animated route rendering** — live route lines, selected trail reporter links, and neighbor overlays now use animated dashed strokes for clearer movement and better route readability.
- **Release metadata alignment** — app release numbers were synchronized to `1.1.0 (build 4)` across iOS project settings and API config output.

---

### iOS App — Fixes & Improvements

- **Map panning/render performance pass** — route and neighbor overlays are now cached and refreshed with debounce instead of rebuilding continuously during redraw; visible node annotations are adaptively downsampled at wider zoom levels to keep movement smooth.
- **Manual route visibility only** — removed implicit global route rendering; route lines now appear only for devices explicitly toggled via each device card's Show/Hide Routes control (multi-select supported, persisted in AppStorage).
- **Map toolbar toggle feedback** — top map controls now show a short-lived status banner (enabled/disabled) so state changes are visible immediately.
- **Map search keyboard/session stabilisation** — added explicit focus-state management and delayed open/close for the search overlay to prevent invalid RTI keyboard session operations and reduce keyboard layout warnings.
- **Map lifecycle gating during tab switches** — map surface and route streaming now activate only when the Map tab is active and the app scene is foregrounded, with delayed mount to avoid zero-size CAMetal layer churn.
- **Lazy tab instantiation at app startup** — non-selected tabs (including Map) are no longer eagerly created on launch, reducing startup MapKit/network noise and improving cold-start responsiveness.
- **Networking startup hardening** — API networking moved off URLSession.shared to a dedicated session configured with waitsForConnectivity to reduce unconnected nw_connection churn during startup and reconnect phases.
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
