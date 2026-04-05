<?php
require_once __DIR__ . '/../lib/meshlog.class.php';
require_once __DIR__ . '/../config.php';

session_start();

$meshlog = new MeshLog(array_merge($config['db'], array('ntp' => $config['ntp'] ?? array())));
$user = null;

if (isset($_SESSION['user'])) {
    $user = $_SESSION['user'];
}

if (isset($_POST['logout']) || isset($_GET['logout'])) {
    if (isset($_SESSION['user'])) {
        $logoutUser = $_SESSION['user'];
        $meshlog->auditLog(
            \MeshLogAuditLog::EVENT_LOGOUT,
            is_object($logoutUser) ? $logoutUser->name : ($logoutUser['name'] ?? 'unknown')
        );
    }
    session_destroy();
    $user = null;
    header('Location: .');
    exit;
}

if (!$user && isset($_POST['login'])) {
    $username = $_POST['username'];
    $password = $_POST['password'];

    $login = MeshLogUser::login($meshlog, $username, $password);
    if ($login) {
        $user = array(
            'id' => $login->getId(),
            'name' => $login->name,
            'permissions' => $login->permissions,
        );
        $_SESSION['user'] = $login;
        $meshlog->auditLog(\MeshLogAuditLog::EVENT_LOGIN_OK, $login->name);
    } else {
        $meshlog->auditLog(\MeshLogAuditLog::EVENT_LOGIN_FAIL, htmlspecialchars($username, ENT_QUOTES, 'UTF-8'), 'invalid password');
    }
}

// If logged in but DB upgrade is pending, redirect to setup.php to run migrations.
if ($user && $meshlog->updateAvailable()) {
    header('Location: ../setup.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MeshLog Admin</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
        integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
        crossorigin=""/>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
        crossorigin=""></script>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        :root {
            --admin-panel: rgba(20, 26, 36, 0.92);
            --admin-line: rgba(145, 175, 205, 0.18);
            --admin-line-strong: rgba(145, 175, 205, 0.28);
            --admin-text: #dce7f3;
            --admin-muted: #8da0b5;
            --admin-accent: #6cc7b8;
            --admin-shadow: 0 24px 60px rgba(0, 0, 0, 0.35);
        }

        body {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            background:
                radial-gradient(circle at top left, rgba(108, 199, 184, 0.18), transparent 22%),
                radial-gradient(circle at top right, rgba(105, 146, 216, 0.16), transparent 24%),
                linear-gradient(180deg, #0f141b, #151d28 46%, #0f141b);
            color: var(--admin-text);
        }

        .admin-header {
            background: rgba(10, 14, 20, 0.82);
            backdrop-filter: blur(18px);
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 12px 24px;
            border-bottom: 1px solid var(--admin-line);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .admin-title {
            font-size: 1.15em;
            color: #f4fbff;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .admin-nav {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            margin-left: auto;
            align-items: center;
        }

        .admin-nav-btn {
            color: var(--admin-text);
            text-decoration: none;
            border: 1px solid var(--admin-line);
            background: rgba(255, 255, 255, 0.03);
            padding: 7px 14px;
            border-radius: 999px;
            cursor: pointer;
            font-size: 0.88rem;
            margin-left: 0;
            transition: border-color 0.15s ease, background 0.15s ease, color 0.15s ease;
        }

        .admin-nav-btn:hover {
            border-color: var(--admin-line-strong);
            background: rgba(255, 255, 255, 0.07);
            color: #fff;
            transform: none;
        }

        .admin-nav-btn.active {
            background: rgba(108, 199, 184, 0.16);
            border-color: rgba(146, 223, 210, 0.42);
            color: #cff5ec;
        }

        .admin-nav-sep {
            width: 1px;
            height: 20px;
            background: var(--admin-line);
            margin: 0 4px;
        }

        .admin-body {
            width: 100%;
            max-width: 1680px;
            margin: 0 auto;
            box-sizing: border-box;
            padding: 24px;
        }

        .admin-page {
            display: none;
        }

        .admin-page.active {
            display: block;
        }

        .admin-section {
            background: var(--admin-panel);
            border: 1px solid var(--admin-line);
            border-radius: 18px;
            box-shadow: var(--admin-shadow);
            margin-bottom: 20px;
        }

        .section-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 14px 18px;
            background: linear-gradient(180deg, rgba(255,255,255,0.05), rgba(255,255,255,0));
            border-bottom: 1px solid var(--admin-line);
            color: #f0f6ff;
            font-size: 1.05em;
            border-radius: 18px 18px 0 0;
        }

        .section-kicker {
            color: var(--admin-muted);
            font-size: 0.85rem;
            font-weight: normal;
        }

        .section-body {
            padding: 16px 18px 18px;
            overflow-x: auto;
        }

        table {
            border-collapse: collapse;
            width: 100%;
        }

        th {
            text-align: left;
            color: var(--admin-muted);
            border-bottom: 1px solid var(--admin-line);
            padding: 10px 8px;
            white-space: nowrap;
            font-weight: 600;
        }

        td {
            padding: 7px 8px;
            vertical-align: middle;
        }

        tbody tr:hover {
            background: rgba(255, 255, 255, 0.025);
        }

        tr.add-row td {
            padding-top: 18px;
            border-top: 1px solid var(--admin-line);
        }

        input[type=text],
        input[type=password],
        input[type=number],
        select {
            background: rgba(9, 12, 18, 0.92);
            border: 1px solid var(--admin-line);
            color: var(--admin-text);
            padding: 6px 10px;
            border-radius: 8px;
            box-sizing: border-box;
        }

        input[type=text]:focus,
        input[type=password]:focus,
        input[type=number]:focus,
        select:focus {
            border-color: var(--admin-accent);
            outline: none;
            box-shadow: 0 0 0 3px rgba(108, 199, 184, 0.14);
        }

        button {
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid var(--admin-line);
            color: var(--admin-text);
            padding: 6px 12px;
            border-radius: 999px;
            cursor: pointer;
            margin-left: 4px;
            transition: background 0.15s ease, border-color 0.15s ease;
        }

        button:first-child {
            margin-left: 0;
        }

        button:hover {
            background: rgba(255, 255, 255, 0.10);
            color: #fff;
            border-color: var(--admin-line-strong);
            transform: none;
        }

        button:disabled {
            opacity: 0.6;
            cursor: wait;
        }

        .button-primary {
            background: linear-gradient(135deg, rgba(108, 199, 184, 0.24), rgba(105, 146, 216, 0.22));
            border-color: rgba(146, 223, 210, 0.4);
        }

        .button-danger {
            border-color: rgba(220, 100, 80, 0.4);
        }

        .button-danger:hover {
            background: rgba(220, 100, 80, 0.18);
            border-color: rgba(220, 100, 80, 0.6);
        }

        .help-inline {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .help-trigger {
            width: 20px;
            height: 20px;
            min-width: 20px;
            padding: 0;
            margin-left: 4px;
            border-radius: 50%;
            font-weight: 700;
            font-size: 0.75rem;
            line-height: 1;
        }

        .logger-name {
            margin-left: 6px;
            padding: 3px 8px;
            font-size: 0.8rem;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.04);
        }

        .reporter-key,
        .channel-hash {
            font-family: monospace;
            font-size: 0.82rem;
            letter-spacing: 0.02em;
        }

        /* Settings page */
        .settings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 14px;
        }

        .setting-card {
            border: 1px solid var(--admin-line);
            background: rgba(255, 255, 255, 0.03);
            border-radius: 14px;
            padding: 16px;
            display: grid;
            gap: 10px;
        }

        .setting-card-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
        }

        .setting-label {
            color: #f0f6ff;
            font-size: 0.98rem;
        }

        .setting-key {
            color: var(--admin-muted);
            font-size: 0.75rem;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            margin-bottom: 3px;
        }

        .setting-desc {
            color: var(--admin-muted);
            font-size: 0.88em;
            line-height: 1.45;
        }

        .setting-input-wrap {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .setting-input-wrap input[type=number],
        .setting-input-wrap input[type=text] {
            flex: 1;
        }

        .setting-hint {
            color: #9dc0b6;
            font-size: 0.82rem;
        }

        .settings-actions {
            display: flex;
            justify-content: flex-end;
            margin-top: 18px;
            padding-top: 14px;
            border-top: 1px solid var(--admin-line);
        }

        /* Stats page */
        .stats-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 14px;
        }

        .stats-kpis {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 10px;
            margin-bottom: 14px;
        }

        .stats-kpi {
            border: 1px solid var(--admin-line);
            border-radius: 12px;
            padding: 10px 12px;
            background: rgba(255, 255, 255, 0.025);
        }

        .stats-kpi-label {
            color: var(--admin-muted);
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .stats-kpi-value {
            color: #f5fbff;
            font-size: 1.05rem;
            margin-top: 4px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }

        .stats-card {
            border: 1px solid var(--admin-line);
            border-radius: 12px;
            padding: 12px;
            background: rgba(255, 255, 255, 0.02);
            min-width: 0;
        }

        .stats-card h3 {
            margin: 0 0 10px;
            color: #f0f6ff;
            font-size: 0.95rem;
            font-weight: 600;
        }

        .stats-table-wrap {
            overflow-x: auto;
        }

        .stats-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.84rem;
        }

        .stats-table th,
        .stats-table td {
            padding: 6px 8px;
            border-bottom: 1px solid var(--admin-line);
            white-space: nowrap;
            text-align: left;
        }

        .stats-table tr:last-child td {
            border-bottom: none;
        }

        .stats-muted {
            color: var(--admin-muted);
            font-size: 0.84rem;
        }

        /* Help modal */
        .admin-modal {
            position: fixed;
            inset: 0;
            display: grid;
            place-items: center;
            background: rgba(7, 10, 15, 0.72);
            z-index: 5000;
            padding: 20px;
        }

        .admin-modal[hidden] {
            display: none;
        }

        .admin-modal-card {
            width: min(540px, 100%);
            background: #111924;
            border: 1px solid var(--admin-line-strong);
            border-radius: 20px;
            box-shadow: var(--admin-shadow);
            padding: 22px;
            position: relative;
            z-index: 5001;
        }

        .admin-modal-card h2 {
            color: #f5fbff;
            margin-bottom: 10px;
            font-size: 1.1rem;
        }

        .admin-modal-card p {
            color: var(--admin-muted);
            line-height: 1.6;
            white-space: pre-line;
        }

        .admin-modal-actions {
            display: flex;
            justify-content: flex-end;
            margin-top: 18px;
        }

        /* Audit log table */
        .audit-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.88rem;
        }
        .audit-table th {
            text-align: left;
            padding: 7px 10px;
            border-bottom: 1px solid var(--admin-line-strong);
            color: var(--admin-muted);
            font-weight: 600;
            white-space: nowrap;
        }
        .audit-table td {
            padding: 6px 10px;
            border-bottom: 1px solid var(--admin-line);
            vertical-align: top;
            word-break: break-word;
        }
        .audit-table tr:last-child td { border-bottom: none; }
        .audit-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.78rem;
            font-weight: 700;
            white-space: nowrap;
        }
        .audit-badge-login\.ok      { background:#1a3d2e; color:#5cde9a; }
        .audit-badge-login\.fail    { background:#3d1a1a; color:#e06060; }
        .audit-badge-logout         { background:#1a2c3d; color:#6aabde; }
        .audit-badge-purge\.manual  { background:#2d2215; color:#e0a84a; }
        .audit-badge-purge\.auto    { background:#1d2a1d; color:#7ec87e; }
        .audit-badge-settings\.save { background:#1e1d34; color:#a89de0; }
        .audit-badge-error          { background:#3d1a1a; color:#e06060; }
        .audit-badge-default        { background:#1e2430; color:#9dc0b6; }
        .audit-pagination {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 14px;
            justify-content: flex-end;
            font-size: 0.88rem;
            color: var(--admin-muted);
        }

        /* Table input auto-sizing */
        table td input[type=text],
        table td input[type=number] {
            width: 100%;
            box-sizing: border-box;
            min-width: 60px;
        }

        .admin-table {
            min-width: 100%;
        }

        .admin-devices-table {
            min-width: 1480px;
        }

        .admin-channels-table {
            min-width: 760px;
        }

        .admin-devices-table td,
        .admin-devices-table th,
        .admin-channels-table td,
        .admin-channels-table th {
            white-space: nowrap;
        }

        .admin-devices-table input.reporter-name-input {
            min-width: 150px;
        }

        .admin-devices-table input.reporter-key {
            min-width: 280px;
        }

        .admin-devices-table input.coord-input {
            min-width: 92px;
            max-width: 110px;
        }

        .admin-devices-table input.auth-token-input {
            min-width: 180px;
        }

        .admin-devices-table select.hash-size-select {
            min-width: 96px;
        }

        .admin-devices-table select.report-format-select {
            min-width: 122px;
        }

        .admin-devices-table input.reporter-iata {
            min-width: 74px;
            max-width: 86px;
            text-transform: uppercase;
        }

        .style-cell {
            white-space: nowrap;
        }

        .style-cell input[type=color] {
            vertical-align: middle;
            margin-right: 6px;
        }

        .status-cell {
            min-width: 150px;
        }

        .sync-cell {
            min-width: 220px;
        }

        .sync-state {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 700;
            white-space: nowrap;
        }

        .sync-state.ok {
            background: rgba(60, 170, 115, 0.16);
            color: #81e3a6;
            border: 1px solid rgba(60, 170, 115, 0.35);
        }

        .sync-state.warn {
            background: rgba(216, 180, 32, 0.16);
            color: #f0d465;
            border: 1px solid rgba(216, 180, 32, 0.35);
        }

        .sync-state.unknown {
            background: rgba(255, 255, 255, 0.05);
            color: var(--admin-muted);
            border: 1px solid var(--admin-line);
        }

        .sync-note {
            font-size: 0.78rem;
            color: var(--admin-muted);
            margin-top: 4px;
            white-space: normal;
            line-height: 1.4;
        }

        .reporter-key { min-width: 180px; }

        .device-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 700;
            white-space: nowrap;
        }

        .device-status.connected {
            background: rgba(60, 170, 115, 0.16);
            color: #81e3a6;
            border: 1px solid rgba(60, 170, 115, 0.35);
        }

        .device-status.disconnected,
        .device-status.never-seen {
            background: rgba(190, 105, 85, 0.14);
            color: #ef9c8c;
            border: 1px solid rgba(190, 105, 85, 0.32);
        }

        .device-status-note {
            font-size: 0.78rem;
            color: var(--admin-muted);
            margin-top: 4px;
            white-space: nowrap;
        }

        /* Device map */
        #admin-device-map {
            height: 360px;
            margin-top: 18px;
            border-radius: 14px;
            overflow: hidden;
            border: 1px solid var(--admin-line);
        }

        .admin-map-hint {
            font-size: 0.82rem;
            color: var(--admin-muted);
            margin-top: 8px;
        }

        /* Reporter marker dot */
        .admin-reporter-dot {
            width: 14px;
            height: 14px;
            border-radius: 50%;
            box-shadow: 0 0 6px rgba(0,0,0,0.5);
        }

        .admin-map-marker .marker-pin {
            transform: scale(0.84);
            transform-origin: center bottom;
        }

        @keyframes reporterBlink {
            0%, 100% { opacity: 1; transform: scale(1); }
            40%       { opacity: 0.3; transform: scale(1.7); }
        }

        .reporter-blink .admin-reporter-dot {
            animation: reporterBlink 0.6s ease-in-out 4;
        }

        @media (max-width: 900px) {
            .admin-header {
                flex-wrap: wrap;
            }
            .admin-nav {
                margin-left: 0;
                order: 3;
                width: 100%;
            }
            .admin-body {
                padding: 14px;
            }
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<?php if (!$user): ?>
    <div id="login">
        <section>
            <h1>Login</h1>
            <form action="" method="post">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input id="username" name="username" type="text">
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input id="password" name="password" type="password">
                </div>
                <div class="form-group right">
                    <input type="submit" name="login" value="Login">
                </div>
            </form>
        </section>
    </div>
<?php else: ?>
    <header class="admin-header">
        <span class="admin-title">MeshLog Admin</span>
        <nav class="admin-nav">
            <button type="button" class="admin-nav-btn" data-page="devices">Devices</button>
            <button type="button" class="admin-nav-btn" data-page="channels">Channels</button>
            <button type="button" class="admin-nav-btn" data-page="settings">Settings</button>
            <button type="button" class="admin-nav-btn" data-page="audit">Audit Log</button>
            <button type="button" class="admin-nav-btn" data-page="stats">Stats</button>
            <div class="admin-nav-sep"></div>
            <a href="?logout" class="admin-nav-btn">Log out</a>
        </nav>
    </header>

    <div class="admin-body">

        <!-- PAGE: Devices -->
        <div class="admin-page" id="page-devices">
            <section class="admin-section">
                <div class="section-title">
                    <span>Reporter Devices</span>
                    <span class="section-kicker">Authorization, MQTT format selection, optional IATA binding, location, style, and hash-byte configuration per device</span>
                </div>
                <div class="section-body">
                    <table class="admin-table admin-devices-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th><span class="help-inline">Device Name <button type="button" class="help-trigger" data-help-title="Device Name" data-help-body="Human-readable label shown in the UI and admin panel for this reporter.">?</button></span></th>
                                <th><span class="help-inline">Public Key <button type="button" class="help-trigger" data-help-title="Reporter Public Key" data-help-body="Must match the key used by the logger firmware or the MQTT topic. MeshLog uses it to authorize and associate incoming packets with this device.">?</button></span></th>
                                <th><span class="help-inline">MQTT Format <button type="button" class="help-trigger" data-help-title="MQTT Format" data-help-body="Select how this reporter publishes MQTT payloads. MeshLog is the default format; LetsMesh enables alternate payload field mapping.">?</button></span></th>
                                <th><span class="help-inline">IATA <button type="button" class="help-trigger" data-help-title="Reporter IATA" data-help-body="Optional MQTT topic region code binding (2-8 alphanumeric). If set, incoming packets must match this IATA from topic/payload.">?</button></span></th>
                                <th><span class="help-inline">Hash Bytes <button type="button" class="help-trigger" data-help-title="Reporter Hash Bytes" data-help-body="Routing hash prefix width configured on this node: 1, 2, or 3 bytes. Stored as metadata — used for display and hash-size detection when the packet field is absent.">?</button></span></th>
                                <th>Lat</th>
                                <th>Lon</th>
                                <th><span class="help-inline">Auth Token <button type="button" class="help-trigger" data-help-title="Auth Token" data-help-body="Bearer token for the HTTP firmware ingest path. Leave empty for MQTT-only reporters.">?</button></span></th>
                                <th><span class="help-inline">Style <button type="button" class="help-trigger" data-help-title="Style Colors" data-help-body="Left color picker sets the fill/text color, right sets the border/stroke color. The label preview updates live.">?</button></span></th>
                                <th><span class="help-inline">Enabled <button type="button" class="help-trigger" data-help-title="Reporter Enabled" data-help-body="Only enabled reporters are accepted for ingest. Disable a device without deleting its historic data.">?</button></span></th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="reporters"></tbody>
                    </table>
                </div>
            </section>
            <section class="admin-section" style="margin-top:18px;">
                <div class="section-title">
                    <span>Device Map</span>
                    <span class="section-kicker">Reporter positions — markers pulse when a packet arrives. Click the map to pre-fill Lat/Lon in the add-row above.</span>
                </div>
                <div class="section-body">
                    <div id="admin-device-map"></div>
                    <p class="admin-map-hint">Click anywhere on the map to copy those coordinates into the &ldquo;Add&rdquo; row&rsquo;s Lat/Lon fields.</p>
                </div>
            </section>
        </div>

        <!-- PAGE: Channels -->
        <div class="admin-page" id="page-channels">
            <section class="admin-section">
                <div class="section-title">
                    <span>Channels</span>
                    <span class="section-kicker">Channel hashes, names, pre-shared keys (for GRP_TXT decryption), and visibility</span>
                </div>
                <div class="section-body">
                    <table class="admin-table admin-channels-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th><span class="help-inline">Hash <button type="button" class="help-trigger" data-help-title="Channel Hash" data-help-body="Plaintext first-byte identifier that matches incoming GRP_TXT packets to this channel. Visible even when packet payload is encrypted.">?</button></span></th>
                                <th>Name</th>
                                <th><span class="help-inline">PSK (hex or base64) <button type="button" class="help-trigger" data-help-title="Channel PSK" data-help-body="Optional pre-shared key used to decrypt AES-128 GRP_TXT/GRP_DATA packets. Enter as 32/64 hex chars (16/32 bytes) — the format shown in LetsMesh and MeshCore apps — or as base64. Leave empty for #hashtag channels (key is auto-derived from the name) or to store packets as raw.">?</button></span></th>
                                <th><span class="help-inline">Enabled <button type="button" class="help-trigger" data-help-title="Channel Enabled" data-help-body="Disabled channels are hidden from the live feed and their messages are not shown to users. Historic data is preserved.">?</button></span></th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="channels"></tbody>
                    </table>
                </div>
            </section>
        </div>

        <!-- PAGE: Settings -->
        <div class="admin-page" id="page-settings">
            <section class="admin-section">
                <div class="section-title">
                    <span>System Settings</span>
                    <span class="section-kicker">Retention, duplicate-grouping window, and privacy controls</span>
                </div>
                <div class="section-body">
                    <div class="settings-grid" id="settings-grid"></div>
                    <div class="settings-actions">
                        <button type="button" id="purge-now-btn" title="Run data purge immediately using current retention settings">Purge Now</button>
                        <span id="purge-status" style="font-size:0.85rem;color:#9dc0b6;margin-left:10px;"></span>
                        <button type="button" class="button-primary" id="setting-save-btn">Save Settings</button>
                    </div>
                </div>
            </section>
        </div>

        <!-- PAGE: Audit Log -->
        <div class="admin-page" id="page-audit">
            <section class="admin-section">
                <div class="section-title">
                    <span>Audit Log</span>
                    <span class="section-kicker">Login attempts, purge jobs, and system errors</span>
                </div>
                <div class="section-body">
                    <table class="audit-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>When</th>
                                <th>Event</th>
                                <th>Actor</th>
                                <th>IP</th>
                                <th>Detail</th>
                            </tr>
                        </thead>
                        <tbody id="audit-tbody"></tbody>
                    </table>
                    <div class="audit-pagination">
                        <button type="button" id="audit-prev-btn" disabled>&larr; Prev</button>
                        <span id="audit-page-info"></span>
                        <button type="button" id="audit-next-btn">Next &rarr;</button>
                    </div>
                </div>
            </section>
        </div>

        <!-- PAGE: Stats -->
        <div class="admin-page" id="page-stats">
            <section class="admin-section">
                <div class="section-title">
                    <span>Database Statistics</span>
                    <span class="section-kicker">Live database footprint, ingest throughput, and retention state</span>
                </div>
                <div class="section-body">
                    <div class="stats-toolbar">
                        <div class="stats-muted">Last update: <span id="stats-last-update">-</span></div>
                        <div>
                            <label for="stats-window-hours" class="stats-muted">Window</label>
                            <select id="stats-window-hours">
                                <option value="1">1h</option>
                                <option value="6">6h</option>
                                <option value="24" selected>24h</option>
                                <option value="72">72h</option>
                                <option value="168">7d</option>
                            </select>
                            <button type="button" id="stats-refresh-btn">Refresh</button>
                        </div>
                    </div>

                    <div class="stats-kpis">
                        <div class="stats-kpi">
                            <div class="stats-kpi-label">Database Size</div>
                            <div class="stats-kpi-value" id="stats-kpi-db-size">-</div>
                        </div>
                        <div class="stats-kpi">
                            <div class="stats-kpi-label">Tracked Tables</div>
                            <div class="stats-kpi-value" id="stats-kpi-table-count">-</div>
                        </div>
                        <div class="stats-kpi">
                            <div class="stats-kpi-label">Rows in Window</div>
                            <div class="stats-kpi-value" id="stats-kpi-window-rows">-</div>
                        </div>
                        <div class="stats-kpi">
                            <div class="stats-kpi-label">Last Auto Purge</div>
                            <div class="stats-kpi-value" id="stats-kpi-last-purge">-</div>
                        </div>
                        <div class="stats-kpi">
                            <div class="stats-kpi-label">Auto-Purge Timer</div>
                            <div class="stats-kpi-value" id="stats-kpi-auto-purge">-</div>
                        </div>
                    </div>

                    <div class="stats-grid">
                        <div class="stats-card">
                            <h3>Retention (days)</h3>
                            <div id="stats-retention" class="stats-muted">-</div>
                        </div>

                        <div class="stats-card">
                            <h3>Ingest Breakdown (window)</h3>
                            <div class="stats-table-wrap">
                                <table class="stats-table">
                                    <thead><tr><th>Type</th><th>Count</th></tr></thead>
                                    <tbody id="stats-ingest-tbody"></tbody>
                                </table>
                            </div>
                        </div>

                        <div class="stats-card">
                            <h3>Top Reporters (window)</h3>
                            <div class="stats-table-wrap">
                                <table class="stats-table">
                                    <thead><tr><th>#</th><th>Name</th><th>Public Key</th><th>Packets</th></tr></thead>
                                    <tbody id="stats-reporters-tbody"></tbody>
                                </table>
                            </div>
                        </div>

                        <div class="stats-card">
                            <h3>Raw Packet Types (window)</h3>
                            <div class="stats-table-wrap">
                                <table class="stats-table">
                                    <thead><tr><th>Packet Type</th><th>Count</th></tr></thead>
                                    <tbody id="stats-raw-types-tbody"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="stats-card" style="margin-top:14px;">
                        <h3>Table Statistics</h3>
                        <div class="stats-table-wrap">
                            <table class="stats-table">
                                <thead>
                                    <tr>
                                        <th>Table</th>
                                        <th>Rows</th>
                                        <th>Rows in Window</th>
                                        <th>Size</th>
                                        <th>Oldest</th>
                                        <th>Newest</th>
                                    </tr>
                                </thead>
                                <tbody id="stats-tables-tbody"></tbody>
                            </table>
                        </div>
                    </div>

                    <div class="stats-muted" id="stats-error" style="margin-top:10px;"></div>
                </div>
            </section>
        </div>

    </div><!-- /.admin-body -->

    <!-- Help modal (shared) -->
    <div class="admin-modal" id="help-modal" hidden>
        <div class="admin-modal-card" role="dialog" aria-modal="true" aria-labelledby="help-modal-title">
            <h2 id="help-modal-title">Help</h2>
            <p id="help-modal-body"></p>
            <div class="admin-modal-actions">
                <button type="button" class="button-primary" id="help-modal-close">Close</button>
            </div>
        </div>
    </div>

    <script>
        /* ── Tabs ────────────────────────────────────────────────────── */
        const PAGES = ['devices', 'channels', 'settings', 'audit', 'stats'];
        const loadedPages = new Set();

        function switchPage(name) {
            if (!PAGES.includes(name)) name = 'devices';
            PAGES.forEach(p => {
                document.getElementById(`page-${p}`).classList.toggle('active', p === name);
            });
            document.querySelectorAll('.admin-nav-btn[data-page]').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.page === name);
            });
            history.replaceState(null, '', `#${name}`);

            if (name === 'stats') {
                startStatsAutoRefresh();
            } else {
                stopStatsAutoRefresh();
            }

            if (!loadedPages.has(name)) {
                loadedPages.add(name);
                if (name === 'devices')  loadReporters();
                if (name === 'channels') loadChannels();
                if (name === 'settings') loadSettings();
                if (name === 'audit')    loadAudit(0);
                if (name === 'stats')    loadStats();
            }
        }

        document.querySelectorAll('.admin-nav-btn[data-page]').forEach(btn => {
            btn.addEventListener('click', () => switchPage(btn.dataset.page));
        });

        /* ── Shared helpers ──────────────────────────────────────────── */
        function getError(result) {
            if ((result.status ?? '?') === 'OK') return false;
            return result.error ?? 'Unknown error';
        }

        /* ── Help modal ──────────────────────────────────────────────── */
        const helpModal      = document.getElementById('help-modal');
        const helpModalTitle = document.getElementById('help-modal-title');
        const helpModalBody  = document.getElementById('help-modal-body');

        function openHelpModal(title, body) {
            helpModalTitle.innerText = title;
            helpModalBody.innerText  = body;
            helpModal.hidden = false;
        }

        function closeHelpModal() { helpModal.hidden = true; }

        document.addEventListener('click', (e) => {
            const trigger = e.target.closest('.help-trigger[data-help-title]');
            if (trigger) { openHelpModal(trigger.dataset.helpTitle, trigger.dataset.helpBody); return; }
            if (e.target === helpModal) closeHelpModal();
        });
        document.getElementById('help-modal-close').addEventListener('click', closeHelpModal);
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && !helpModal.hidden) closeHelpModal();
        });

        /* ── Reporter helpers ────────────────────────────────────────── */
        const reporters = document.getElementById('reporters');
        let addRowLatInput = null;
        let addRowLonInput = null;
        let addRowPublicKeyInput = null;
        let latestReporterRows = [];

        function makeInputCell(row, value, type = 'text') {
            const td = row.insertCell();
            const input = document.createElement('input');
            input.type = type;
            if (type === 'checkbox') {
                input.checked = value === 1 || value === '1' || value === true;
            } else {
                input.value = value ?? '';
            }
            input.oninput = () => { input.style.color = '#1976D2'; };
            td.append(input);
            return input;
        }

        function makeColorInput(cell, value, onchange) {
            const input = document.createElement('input');
            input.type = 'color';
            input.value = value;
            input.oninput = (e) => onchange(e.target.value);
            cell.append(input);
            return input;
        }

        function relativeTimeFromSql(sqlValue) {
            if (!sqlValue) return 'Never seen';
            const parsed = Date.parse(String(sqlValue).replace(' ', 'T'));
            if (!Number.isFinite(parsed)) return sqlValue;
            const seconds = Math.max(0, Math.floor((Date.now() - parsed) / 1000));
            if (seconds < 60) return `${seconds}s ago`;
            if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
            if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`;
            return `${Math.floor(seconds / 86400)}d ago`;
        }

        function renderReporterStatusCell(cell, reporter) {
            cell.innerHTML = '';
            const state = reporter.connection_state ?? 'never-seen';
            const badge = document.createElement('span');
            badge.className = `device-status ${state}`;
            badge.innerText = state === 'connected' ? 'Connected' : state === 'disconnected' ? 'Disconnected' : 'Never seen';
            cell.append(badge);

            if (!reporter.isAddRow) {
                const note = document.createElement('div');
                note.className = 'device-status-note';
                note.innerText = reporter.last_heard_at ? `Last heard ${relativeTimeFromSql(reporter.last_heard_at)}` : 'No packets received yet';
                cell.append(note);
            }
        }

        function formatSyncDuration(ms) {
            const totalMs = Math.abs(Number(ms) || 0);
            if (totalMs < 1000) return `${totalMs} ms`;

            const totalSeconds = totalMs / 1000;
            if (totalSeconds < 60) return `${totalSeconds.toFixed(totalSeconds >= 10 ? 0 : 1)} s`;

            const totalMinutes = totalSeconds / 60;
            if (totalMinutes < 60) return `${totalMinutes.toFixed(totalMinutes >= 10 ? 0 : 1)} min`;

            const totalHours = totalMinutes / 60;
            return `${totalHours.toFixed(totalHours >= 10 ? 0 : 1)} h`;
        }

        function renderReporterSyncCell(cell, reporter) {
            cell.innerHTML = '';
            const sync = reporter.time_sync ?? null;
            const badge = document.createElement('span');
            const note = document.createElement('div');

            note.className = 'sync-note';

            if (!sync || !sync.available) {
                badge.className = 'sync-state unknown';
                badge.innerText = 'Unknown';
                note.innerText = 'No recent repeater advertisement with a usable sender timestamp.';
                cell.append(badge, note);
                return;
            }

            const driftMs = Number(sync.drift_ms ?? 0);
            const thresholdMs = Number(sync.threshold_ms ?? 0);
            const direction = driftMs >= 0 ? 'ahead' : 'behind';

            badge.className = `sync-state ${sync.warning ? 'warn' : 'ok'}`;
            badge.innerText = `${sync.warning ? 'Warning' : 'In sync'} · ${formatSyncDuration(driftMs)}`;

            note.innerText = `Repeater is ${formatSyncDuration(driftMs)} ${direction}. Threshold: ${formatSyncDuration(thresholdMs)}.`;
            cell.append(badge, note);
        }

        function getReporterTypeLabel(type) {
            if (type === 2) return 'Repeater';
            if (type === 3) return 'Room';
            if (type === 4) return 'Sensor';
            return 'Chat';
        }

        function createAdminReporterIcon(reporter) {
            const type = Number(reporter.contact_type ?? 1);
            let iconUrl = '../assets/img/person.svg';
            if (type === 2) iconUrl = '../assets/img/tower.svg';
            else if (type === 3) iconUrl = '../assets/img/group.svg';
            else if (type === 4) iconUrl = '../assets/img/sensor.svg';

            let style = { color: '#6cc7b8', stroke: '#6cc7b8' };
            try {
                const parsed = JSON.parse(reporter.style ?? '{}');
                style.color = parsed.color ?? style.color;
                style.stroke = parsed.stroke ?? style.color;
            } catch (_) {}

            const pinClass = `marker-pin${reporter.connection_state === 'disconnected' || reporter.connection_state === 'never-seen' ? ' ghosted' : ''}`;
            const html = `<div class="admin-map-marker"><div class="${pinClass}"></div><img class="marker-icon-img" src="${iconUrl}" alt="${getReporterTypeLabel(type)}"></div>`;
            return L.divIcon({
                className: 'custom-div-icon',
                html,
                iconSize: [30, 42],
                iconAnchor: [15, 42],
                popupAnchor: [0, -42],
            });
        }

        function makeHashSizeInput(row, value = 1) {
            const td = row.insertCell();
            const select = document.createElement('select');
            [1, 2, 3].forEach(size => {
                const opt = document.createElement('option');
                opt.value = String(size);
                opt.innerText = `${size} byte${size === 1 ? '' : 's'}`;
                select.append(opt);
            });
            select.value = String(value ?? 1);
            select.onchange = () => { select.style.color = '#1976D2'; };
            td.append(select);
            return select;
        }

        function makeReporterFormatInput(row, value = 'meshlog') {
            const td = row.insertCell();
            const select = document.createElement('select');
            select.classList.add('report-format-select');

            [
                { value: 'meshlog', label: 'MeshLog' },
                { value: 'letsmesh', label: 'LetsMesh' }
            ].forEach(optData => {
                const opt = document.createElement('option');
                opt.value = optData.value;
                opt.innerText = optData.label;
                select.append(opt);
            });

            select.value = (value === 'letsmesh') ? 'letsmesh' : 'meshlog';
            select.onchange = () => { select.style.color = '#1976D2'; };
            td.append(select);
            return select;
        }

        /* ── Reporters ───────────────────────────────────────────────── */
        function loadReporters() {
            fetch('api/reporters/', { method: 'GET' })
            .then(r => r.json())
            .then(result => {
                reporters.innerHTML = '';
                result.objects.forEach(obj => addReporterRow(obj));
                addReporterRow({
                    id: 'Add', name: 'New Logger', public_key: '',
                    report_format: 'meshlog', iata_code: '',
                    hash_size: 1, auth: '', lat: '0.00000', lon: '0.00000',
                    authorized: 1, style: '{"color":"#ff0000"}',
                    isAddRow: true
                });
                initDeviceMap(result.objects);
            });
        }

        function addReporterRow(reporter) {
            const row = reporters.insertRow();
            row.dataset.id = reporter.id;
            if (reporter.isAddRow) row.classList.add('add-row');

            row.insertCell().innerText = reporter.isAddRow ? '' : reporter.id;

            let style = { color: '#ff0000' };
            try { style = JSON.parse(reporter.style ?? '{}'); }
            catch { style = { color: reporter.style ?? '#ff0000' }; }

            const name      = makeInputCell(row, reporter.name);
            name.classList.add('reporter-name-input');
            const publicKey = makeInputCell(row, reporter.public_key);
            publicKey.classList.add('reporter-key');
            const reportFormat = makeReporterFormatInput(row, reporter.report_format ?? 'meshlog');
            const iataCode  = makeInputCell(row, reporter.iata_code ?? '');
            iataCode.classList.add('reporter-iata');
            const hashSize  = makeHashSizeInput(row, reporter.hash_size ?? 1);
            hashSize.classList.add('hash-size-select');
            const lat       = makeInputCell(row, reporter.lat);
            const lon       = makeInputCell(row, reporter.lon);
            lat.classList.add('coord-input');
            lon.classList.add('coord-input');
            const auth      = makeInputCell(row, reporter.auth);
            auth.classList.add('auth-token-input');

            const styleCell = row.insertCell();
            styleCell.classList.add('style-cell');
            const preview = document.createElement('span');
            preview.innerText  = reporter.name;
            preview.className  = 'logger-name';
            preview.style.color  = style.color ?? '#ff0000';
            preview.style.border = `solid 1px ${style.stroke ?? style.color ?? '#ff0000'}`;
            const colorPicker  = makeColorInput(styleCell, style.color ?? '#ff0000', v => { preview.style.color = v; });
            const strokePicker = makeColorInput(styleCell, style.stroke ?? style.color ?? '#ff0000', v => { preview.style.border = `solid 1px ${v}`; });
            styleCell.append(preview);

            const enabled     = makeInputCell(row, reporter.authorized, 'checkbox');
            const statusCell  = row.insertCell();
            statusCell.classList.add('status-cell');
            renderReporterStatusCell(statusCell, reporter);
            const actionsCell = row.insertCell();

            const collectReporter = () => ({
                id: reporter.id,
                name: name.value,
                public_key: publicKey.value,
                report_format: reportFormat.value,
                iata_code: iataCode.value,
                hash_size: hashSize.value,
                lat: lat.value,
                lon: lon.value,
                auth: auth.value,
                authorized: enabled.checked ? 1 : 0,
                style: JSON.stringify({ color: colorPicker.value, stroke: strokePicker.value })
            });

            if (reporter.isAddRow) {
                addRowLatInput = lat;
                addRowLonInput = lon;
                addRowPublicKeyInput = publicKey;
                publicKey.addEventListener('input', () => updateSelectionPreviewMarker());
                lat.addEventListener('input', () => updateSelectionPreviewMarker());
                lon.addEventListener('input', () => updateSelectionPreviewMarker());
                const addBtn = document.createElement('button');
                addBtn.className = 'button-primary';
                addBtn.innerText = 'Add';
                addBtn.onclick = () => saveReporter(collectReporter(), true);
                actionsCell.append(addBtn);
                return;
            }

            const saveBtn   = document.createElement('button');
            const deleteBtn = document.createElement('button');
            saveBtn.innerText   = 'Save';
            deleteBtn.innerText = 'Delete';
            deleteBtn.className = 'button-danger';
            saveBtn.onclick   = () => saveReporter(collectReporter(), false);
            deleteBtn.onclick = () => deleteReporter(reporter.id);
            actionsCell.append(saveBtn, deleteBtn);
        }

        function saveReporter(reporter, add) {
            const body = {
                name: reporter.name,
                public_key: reporter.public_key,
                report_format: reporter.report_format,
                iata_code: reporter.iata_code,
                hash_size: reporter.hash_size,
                lat: reporter.lat,
                lon: reporter.lon,
                auth: reporter.auth,
                authorized: reporter.authorized,
                style: reporter.style,
            };
            if (add) { body.add = 1; }
            else { body.edit = 1; body.id = reporter.id; }

            fetch('api/reporters/', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams(body)
            })
            .then(r => r.json())
            .then(result => {
                if (getError(result)) { alert(getError(result)); return; }
                if (add) { location.reload(true); return; }
                reporters.querySelector(`tr[data-id="${reporter.id}"]`)
                    ?.querySelectorAll('input, select')
                    .forEach(i => { i.style.color = ''; });
            });
        }

        function deleteReporter(id) {
            if (!confirm('Delete this reporter?')) return;
            fetch('api/reporters/', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ delete: 1, id })
            })
            .then(r => r.json())
            .then(result => {
                if (getError(result)) { alert(getError(result)); return; }
                reporters.querySelector(`tr[data-id="${id}"]`)?.remove();
                if (adminMarkers[id]) { adminMarkers[id].marker.remove(); delete adminMarkers[id]; }
            });
        }

        /* ── Device Map ──────────────────────────────────────────────── */
        let adminMap        = null;
        let adminMarkers    = {};      // reporterId => { marker, lastHeard }
        let pollInterval    = null;
        let selectionMarker = null;

        function initDeviceMap(reportersList) {
            latestReporterRows = reportersList.slice();
            if (!adminMap) {
                adminMap = L.map('admin-device-map', { zoomControl: true });
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '\u00a9 <a href="https://openstreetmap.org/copyright">OpenStreetMap</a>'
                }).addTo(adminMap);

                // Click on map → prefill lat/lon in Add row
                adminMap.on('click', (e) => {
                    if (!addRowLatInput || !addRowLonInput) return;
                    addRowLatInput.value  = e.latlng.lat.toFixed(5);
                    addRowLonInput.value  = e.latlng.lng.toFixed(5);
                    addRowLatInput.style.color = '#1976D2';
                    addRowLonInput.style.color = '#1976D2';
                    updateSelectionPreviewMarker();
                });
            }

            // Clear existing markers
            Object.values(adminMarkers).forEach(m => m.marker.remove());
            adminMarkers = {};

            const coords = [];
            reportersList.forEach(r => {
                const lat = parseFloat(r.lat);
                const lon = parseFloat(r.lon);
                if (!isFinite(lat) || !isFinite(lon) || (Math.abs(lat) < 0.001 && Math.abs(lon) < 0.001)) return;

                const marker = L.marker([lat, lon], { icon: createAdminReporterIcon(r) })
                    .bindPopup(`<b>${r.name}</b><br>${getReporterTypeLabel(Number(r.contact_type ?? 1))} • ${r.connection_state ?? 'never-seen'}<br>${lat.toFixed(5)}, ${lon.toFixed(5)}<br><small style="color:#888">${r.public_key.slice(0, 16)}\u2026</small>`)
                    .addTo(adminMap);

                adminMarkers[r.id] = { marker, lastHeard: null };
                coords.push([lat, lon]);
            });

            if (coords.length > 0) {
                adminMap.fitBounds(coords, { padding: [40, 40], maxZoom: 13 });
            }
            setTimeout(() => adminMap.invalidateSize(), 100);

            // Start polling for new packets to trigger blink
            if (pollInterval) clearInterval(pollInterval);
            pollInterval = setInterval(pollReporterActivity, 15000);
            pollReporterActivity();   // immediate first run
            updateSelectionPreviewMarker();
        }

        function getSelectionPreviewReporter() {
            const publicKey = (addRowPublicKeyInput?.value ?? '').trim().toUpperCase();
            const matched = latestReporterRows.find(r => (r.public_key ?? '').toUpperCase() === publicKey);
            return {
                name: 'Selected Location',
                public_key: publicKey,
                style: matched?.style ?? '{"color":"#6cc7b8"}',
                contact_type: matched?.contact_type ?? 1,
                connection_state: 'connected',
            };
        }

        function updateSelectionPreviewMarker() {
            if (!adminMap || !addRowLatInput || !addRowLonInput) return;
            const lat = parseFloat(addRowLatInput.value);
            const lon = parseFloat(addRowLonInput.value);
            if (!isFinite(lat) || !isFinite(lon)) return;

            const previewReporter = getSelectionPreviewReporter();
            if (!selectionMarker) {
                selectionMarker = L.marker([lat, lon], { icon: createAdminReporterIcon(previewReporter) }).addTo(adminMap);
            } else {
                selectionMarker.setLatLng([lat, lon]);
                selectionMarker.setIcon(createAdminReporterIcon(previewReporter));
            }

            selectionMarker.bindPopup(`<b>Selected Location</b><br>${getReporterTypeLabel(Number(previewReporter.contact_type ?? 1))}<br>${lat.toFixed(5)}, ${lon.toFixed(5)}`);
        }

        function pollReporterActivity() {
            fetch('api/reporters/')
            .then(r => r.json())
            .then(result => {
                latestReporterRows = result.objects ?? [];
                (result.objects ?? []).forEach(r => {
                    const m = adminMarkers[r.id];
                    const row = reporters.querySelector(`tr[data-id="${r.id}"]`);
                    const statusCell = row?.querySelector('.status-cell') ?? null;
                    if (statusCell) renderReporterStatusCell(statusCell, r);
                    if (!m) return;

                    const newHeard = r.last_heard_at ?? r.contact?.last_heard_at ?? null;
                    if (newHeard && newHeard !== m.lastHeard && m.lastHeard !== null) {
                        // New activity — blink the marker
                        const container = m.marker.getElement();
                        if (container) {
                            container.classList.remove('reporter-blink');
                            // force reflow so animation restarts
                            void container.offsetWidth;
                            container.classList.add('reporter-blink');
                            setTimeout(() => container.classList.remove('reporter-blink'), 2500);
                        }
                    }
                    m.marker.setIcon(createAdminReporterIcon(r));
                    const markerLatLng = m.marker.getLatLng();
                    m.marker.bindPopup(`<b>${r.name}</b><br>${getReporterTypeLabel(Number(r.contact_type ?? 1))} • ${r.connection_state ?? 'never-seen'}<br>${markerLatLng.lat.toFixed(5)}, ${markerLatLng.lng.toFixed(5)}<br><small style="color:#888">${r.public_key.slice(0, 16)}\u2026</small>`);
                    m.lastHeard = newHeard;
                });
                updateSelectionPreviewMarker();
            })
            .catch(() => {});
        }

        /* ── Channels ────────────────────────────────────────────────── */
        const channelsTable = document.getElementById('channels');

        function loadChannels() {
            fetch('api/channels/', { method: 'GET' })
            .then(r => r.json())
            .then(result => {
                channelsTable.innerHTML = '';
                result.objects.forEach(obj => addChannelRow(obj));
                addChannelRow({ id: 'Add', hash: '', name: '', psk: '', enabled: 1, isAddRow: true });
            });
        }

        function addChannelRow(channel) {
            const row = channelsTable.insertRow();
            row.dataset.id = channel.id;
            if (channel.isAddRow) row.classList.add('add-row');

            row.insertCell().innerText = channel.isAddRow ? '' : channel.id;

            const hashCell = row.insertCell();
            const hash = document.createElement('input');
            hash.type = 'text'; hash.value = channel.hash ?? '';
            hash.className = 'channel-hash';
            hash.oninput = () => { hash.style.color = '#1976D2'; };
            hashCell.append(hash);

            const nameCell = row.insertCell();
            const name = document.createElement('input');
            name.type = 'text'; name.value = channel.name ?? '';
            name.oninput = () => { name.style.color = '#1976D2'; };
            nameCell.append(name);

            const pskCell = row.insertCell();
            const psk = document.createElement('input');
            psk.type = 'text'; psk.value = channel.psk ?? '';
            psk.placeholder = 'Hex (32/64 chars) or Base64 PSK';
            psk.style.minWidth = '240px';
            psk.oninput = () => { psk.style.color = '#1976D2'; };
            pskCell.append(psk);

            const enabledCell = row.insertCell();
            const enabled = document.createElement('input');
            enabled.type = 'checkbox';
            enabled.checked = channel.enabled === 1 || channel.enabled === '1';
            enabledCell.append(enabled);

            const actionsCell = row.insertCell();
            const collectChannel = () => ({
                id: channel.id, hash: hash.value, name: name.value,
                psk: psk.value, enabled: enabled.checked ? 1 : 0,
            });

            if (channel.isAddRow) {
                const addBtn = document.createElement('button');
                addBtn.className = 'button-primary';
                addBtn.innerText = 'Add';
                addBtn.onclick = () => {
                    const d = collectChannel();
                    fetch('api/channels/', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({ add: 1, hash: d.hash, name: d.name, psk: d.psk, enabled: d.enabled })
                    })
                    .then(r => r.json())
                    .then(result => {
                        if (getError(result)) { alert(getError(result)); }
                        else { loadChannels(); }
                    });
                };
                actionsCell.append(addBtn);
                return;
            }

            const saveBtn   = document.createElement('button');
            const deleteBtn = document.createElement('button');
            saveBtn.innerText   = 'Save';
            deleteBtn.innerText = 'Delete';
            deleteBtn.className = 'button-danger';

            saveBtn.onclick = () => {
                const d = collectChannel();
                fetch('api/channels/', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ edit: 1, id: d.id, hash: d.hash, name: d.name, psk: d.psk, enabled: d.enabled })
                })
                .then(r => r.json())
                .then(result => {
                    if (getError(result)) { alert(getError(result)); }
                    else { row.querySelectorAll('input').forEach(i => { i.style.color = ''; }); }
                });
            };

            deleteBtn.onclick = () => {
                const msgCount = channel.message_count ?? 0;
                let doForce = false;
                if (msgCount > 0) {
                    if (!confirm(`Channel has ${msgCount} messages. Delete channel and all its messages?`)) return;
                    doForce = true;
                } else {
                    if (!confirm('Delete this channel?')) return;
                }
                const body = { delete: 1, id: channel.id };
                if (doForce) body.force = 1;
                fetch('api/channels/', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams(body)
                })
                .then(r => r.json())
                .then(result => {
                    if (getError(result)) { alert(getError(result)); }
                    else { row.remove(); }
                });
            };

            actionsCell.append(saveBtn, deleteBtn);
        }

        /* ── Settings ────────────────────────────────────────────────── */
        const settingsGrid       = document.getElementById('settings-grid');
        const saveSettingsButton = document.getElementById('setting-save-btn');

        const SETTINGS_DEFINITIONS = [
            {
                key: 'TIME_SYNC_WARNING_THRESHOLD',
                label: 'Repeater time-sync warning threshold',
                type: 'number',
                placeholder: '300',
                unit: 'seconds',
                description: 'Show the yellow warning badge when a repeater clock differs from the platform UTC reference by at least this amount.',
                help: 'Default: 300 seconds (5 minutes).\n\nMeshLog compares the latest repeater advertisement timestamp with the platform UTC reference derived from NTP. When the absolute drift reaches this threshold, the repeater gets a yellow ! badge in the UI and tooltip.',
                formatHint: (v) => formatDurationHint(v, 'Warn when drift exceeds')
            },
            {
                key: 'MAX_CONTACT_AGE',
                label: 'Contact visibility age',
                type: 'number',
                placeholder: '1814400',
                unit: 'seconds',
                description: 'After this many seconds of silence, a contact is hidden from the contact list. Data is never deleted — only the view is filtered.',
                help: 'Default: 1814400 seconds (21 days).\n\nContacts older than this threshold are hidden from the contact overview. Existing packet data is preserved; this only changes visibility in the UI.',
                formatHint: (v) => formatDurationHint(v, 'Hidden after')
            },
            {
                key: 'MAX_GROUPING_AGE',
                label: 'Duplicate grouping window',
                type: 'number',
                placeholder: '21600',
                unit: 'seconds',
                description: 'Packets with the same hash arriving within this window are grouped as one logical entry instead of creating duplicates.',
                help: 'Default: 21600 seconds (6 hours).\n\nApplies to ADV, MSG, and PUB packets. If the same hash is seen again within this window, it is added as an additional report on the existing entry.',
                formatHint: (v) => formatDurationHint(v, 'Groups duplicates within')
            },
            {
                key: 'ANONYMIZE_USERNAMES',
                label: 'Anonymize usernames',
                type: 'checkbox',
                description: 'Replace usernames and @mentions in direct and channel messages with placeholders in API output and the UI.',
                help: 'When enabled, names and @mentions in stored messages are replaced with XXXXXX in all API and UI output. Useful when the installation is accessible to people who should not see callsigns.',
                formatHint: (v) => v ? 'Usernames will be hidden' : 'Usernames shown as received'
            },
            {
                key: 'DATA_RETENTION_ADV',
                label: 'Advertisement retention',
                type: 'number',
                placeholder: '7',
                unit: 'days',
                description: 'Advertisements older than this many days are automatically deleted (reports included). Set to 0 to keep forever.',
                help: 'Default: 7 days.\n\nIncludes all associated advertisement_reports rows. Purge runs automatically at most once per hour during ingest. Use "Purge Now" to run immediately.\n\nSet to 0 to disable automatic deletion.',
                formatHint: (v) => formatRetentionHint(v)
            },
            {
                key: 'DATA_RETENTION_MSG',
                label: 'Message retention',
                type: 'number',
                placeholder: '7',
                unit: 'days',
                description: 'Direct messages and channel messages older than this many days are deleted. Set to 0 to keep forever.',
                help: 'Default: 7 days.\n\nApplies to both direct_messages and channel_messages including their report sub-rows. Set to 0 to disable automatic deletion.',
                formatHint: (v) => formatRetentionHint(v)
            },
            {
                key: 'DATA_RETENTION_RAW',
                label: 'Raw packet retention',
                type: 'number',
                placeholder: '7',
                unit: 'days',
                description: 'Raw (undecoded / encrypted) packets older than this many days are deleted. Set to 0 to keep forever.',
                help: 'Default: 7 days.\n\nRaw packets accumulate quickly on busy nodes. Set to 0 to keep all raw packets indefinitely.',
                formatHint: (v) => formatRetentionHint(v)
            },
        ];

        function formatRetentionHint(value) {
            const days = parseInt(value ?? 0, 10);
            if (!Number.isFinite(days) || days === 0) return 'Disabled - data kept forever';
            if (days === 1) return 'Delete after 1 day';
            return `Delete after ${days} days`;
        }

        function formatDurationHint(value, prefix) {
            const seconds = parseInt(value ?? 0, 10);
            if (!Number.isFinite(seconds) || seconds < 1) return `${prefix}: disabled or invalid`;
            const units = [['day', 86400], ['hour', 3600], ['minute', 60], ['second', 1]];
            const parts = [];
            let remaining = seconds;
            for (const [label, size] of units) {
                if (remaining < size && parts.length === 0 && size !== 1) continue;
                const count = Math.floor(remaining / size);
                if (count > 0 || (size === 1 && parts.length === 0)) {
                    parts.push(`${count} ${label}${count === 1 ? '' : 's'}`);
                    remaining -= count * size;
                }
                if (parts.length === 2) break;
            }
            return `${prefix} ${parts.join(' ')}`;
        }

        function renderSettings(settings) {
            settingsGrid.innerHTML = '';
            SETTINGS_DEFINITIONS.forEach(def => {
                const card = document.createElement('div');
                card.className = 'setting-card';

                const head = document.createElement('div');
                head.className = 'setting-card-head';

                const textWrap = document.createElement('div');
                const keyEl = document.createElement('div');
                keyEl.className = 'setting-key';
                keyEl.innerText = def.key;
                const labelEl = document.createElement('div');
                labelEl.className = 'setting-label';
                labelEl.innerText = def.label;
                textWrap.append(keyEl, labelEl);

                const helpBtn = document.createElement('button');
                helpBtn.type = 'button';
                helpBtn.className = 'help-trigger';
                helpBtn.innerText = '?';
                helpBtn.title = `Help: ${def.label}`;
                helpBtn.onclick = () => openHelpModal(def.label, def.help);
                head.append(textWrap, helpBtn);

                const descEl = document.createElement('div');
                descEl.className = 'setting-desc';
                descEl.innerText = def.description;

                const inputWrap = document.createElement('div');
                inputWrap.className = 'setting-input-wrap';

                const input = document.createElement('input');
                input.dataset.settingKey  = def.key;
                input.dataset.settingType = def.type;

                if (def.type === 'checkbox') {
                    input.type    = 'checkbox';
                    input.checked = Number(settings[def.key] ?? 0) === 1;
                } else {
                    input.type        = def.type;
                    input.placeholder = def.placeholder ?? '';
                    input.value       = settings[def.key] ?? '';
                }

                const hint = document.createElement('div');
                hint.className = 'setting-hint';
                const updateHint = () => {
                    hint.innerText = def.formatHint(def.type === 'checkbox' ? input.checked : input.value);
                };
                updateHint();
                input.addEventListener('input',  updateHint);
                input.addEventListener('change', updateHint);

                inputWrap.append(input);
                if (def.unit && def.type !== 'checkbox') {
                    const unitEl = document.createElement('span');
                    unitEl.className = 'setting-hint';
                    unitEl.innerText = def.unit;
                    inputWrap.append(unitEl);
                }

                card.append(head, descEl, inputWrap, hint);
                settingsGrid.append(card);
            });
        }

        function loadSettings() {
            fetch('api/settings/', { method: 'GET' })
            .then(r => r.json())
            .then(result => { renderSettings(result.settings ?? {}); });
        }

        function saveSettings() {
            saveSettingsButton.disabled = true;
            const body = { save: 1 };
            settingsGrid.querySelectorAll('[data-setting-key]').forEach(input => {
                if (input.dataset.settingType === 'checkbox') {
                    body[input.dataset.settingKey] = input.checked ? 1 : 0;
                } else {
                    body[input.dataset.settingKey] = input.value;
                }
            });
            fetch('api/settings/', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams(body)
            })
            .then(r => r.json())
            .then(result => {
                saveSettingsButton.disabled = false;
                if (getError(result)) { alert(getError(result)); return; }
                saveSettingsButton.innerText = 'Saved ✓';
                setTimeout(() => { saveSettingsButton.innerText = 'Save Settings'; }, 1500);
            })
            .catch(() => { saveSettingsButton.disabled = false; });
        }

        saveSettingsButton.addEventListener('click', saveSettings);

        const purgeNowButton = document.getElementById('purge-now-btn');
        const purgeStatus   = document.getElementById('purge-status');
        function purgeNow() {
            purgeNowButton.disabled = true;
            purgeStatus.innerText = 'Purging…';
            fetch('api/purge/', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ purge: 1 })
            })
            .then(r => r.json())
            .then(result => {
                purgeNowButton.disabled = false;
                if (getError(result)) { purgeStatus.innerText = 'Error: ' + getError(result); return; }
                purgeStatus.innerText = result.message ?? 'Done';
                setTimeout(() => { purgeStatus.innerText = ''; }, 4000);
            })
            .catch(() => { purgeNowButton.disabled = false; purgeStatus.innerText = 'Request failed'; });
        }
        purgeNowButton.addEventListener('click', purgeNow);

        /* ── Audit Log ───────────────────────────────────────────────── */
        const auditTbody   = document.getElementById('audit-tbody');
        const auditPageInfo = document.getElementById('audit-page-info');
        const auditPrevBtn  = document.getElementById('audit-prev-btn');
        const auditNextBtn  = document.getElementById('audit-next-btn');
        const AUDIT_LIMIT   = 50;
        let   auditOffset   = 0;
        let   auditTotal    = 0;

        const AUDIT_BADGE_MAP = {
            'login.ok':     'login\\.ok',
            'login.fail':   'login\\.fail',
            'logout':       'logout',
            'purge.manual': 'purge\\.manual',
            'purge.auto':   'purge\\.auto',
            'reporter.save': 'settings\\.save',
            'reporter.delete': 'error',
            'channel.save': 'settings\\.save',
            'channel.delete': 'error',
            'error':        'error',
        };

        function auditBadgeClass(event) {
            return 'audit-badge-' + (AUDIT_BADGE_MAP[event] ?? 'default').replace('.', '\\.');
        }

        function renderAudit(rows, total, offset) {
            auditTotal  = total;
            auditOffset = offset;
            auditTbody.innerHTML = '';
            if (!rows.length) {
                const tr = auditTbody.insertRow();
                const td = tr.insertCell();
                td.colSpan = 6;
                td.style.textAlign = 'center';
                td.style.color = 'var(--admin-muted)';
                td.innerText = 'No audit entries yet.';
                auditPrevBtn.disabled = true;
                auditNextBtn.disabled = true;
                auditPageInfo.innerText = '';
                return;
            }
            rows.forEach(row => {
                const tr = auditTbody.insertRow();
                tr.insertCell().innerText = row.id;
                tr.insertCell().innerText = row.created_at ?? '';
                const evCell = tr.insertCell();
                const badge = document.createElement('span');
                badge.className = 'audit-badge audit-badge-' + (row.event ?? 'default');
                badge.innerText = row.event ?? '';
                evCell.append(badge);
                tr.insertCell().innerText = row.actor  ?? '';
                tr.insertCell().innerText = row.ip     ?? '';
                tr.insertCell().innerText = row.detail ?? '';
            });
            const page    = Math.floor(offset / AUDIT_LIMIT) + 1;
            const pages   = Math.max(1, Math.ceil(total / AUDIT_LIMIT));
            auditPageInfo.innerText = `Page ${page} / ${pages}  (${total} entries)`;
            auditPrevBtn.disabled = offset <= 0;
            auditNextBtn.disabled = offset + AUDIT_LIMIT >= total;
        }

        function loadAudit(offset) {
            fetch(`api/audit/?limit=${AUDIT_LIMIT}&offset=${offset}`)
                .then(r => r.json())
                .then(result => {
                    if (getError(result)) { auditTbody.innerHTML = '<tr><td colspan="6">Error: ' + getError(result) + '</td></tr>'; return; }
                    renderAudit(result.objects ?? [], result.total ?? 0, offset);
                })
                .catch(() => { auditTbody.innerHTML = '<tr><td colspan="6">Request failed</td></tr>'; });
        }

        auditPrevBtn.addEventListener('click', () => loadAudit(Math.max(0, auditOffset - AUDIT_LIMIT)));
        auditNextBtn.addEventListener('click', () => loadAudit(auditOffset + AUDIT_LIMIT));

        /* ── Stats ───────────────────────────────────────────────────── */
        const statsWindowSelect = document.getElementById('stats-window-hours');
        const statsRefreshBtn = document.getElementById('stats-refresh-btn');
        const statsLastUpdate = document.getElementById('stats-last-update');
        const statsError = document.getElementById('stats-error');
        const statsKpiDbSize = document.getElementById('stats-kpi-db-size');
        const statsKpiTableCount = document.getElementById('stats-kpi-table-count');
        const statsKpiWindowRows = document.getElementById('stats-kpi-window-rows');
        const statsKpiLastPurge = document.getElementById('stats-kpi-last-purge');
        const statsKpiAutoPurge = document.getElementById('stats-kpi-auto-purge');
        const statsRetention = document.getElementById('stats-retention');
        const statsIngestTbody = document.getElementById('stats-ingest-tbody');
        const statsReportersTbody = document.getElementById('stats-reporters-tbody');
        const statsRawTypesTbody = document.getElementById('stats-raw-types-tbody');
        const statsTablesTbody = document.getElementById('stats-tables-tbody');
        let statsAutoRefreshTimer = null;

        function formatBytes(bytes) {
            const num = Number(bytes ?? 0);
            if (!Number.isFinite(num) || num <= 0) return '0 B';
            const units = ['B', 'KB', 'MB', 'GB', 'TB'];
            let v = num;
            let i = 0;
            while (v >= 1024 && i < units.length - 1) {
                v /= 1024;
                i += 1;
            }
            const digits = v >= 100 || i === 0 ? 0 : 1;
            return `${v.toFixed(digits)} ${units[i]}`;
        }

        function formatLocalDateTime(sqlOrUnix) {
            if (!sqlOrUnix) return '-';
            if (typeof sqlOrUnix === 'number' || /^\d+$/.test(String(sqlOrUnix))) {
                const d = new Date(Number(sqlOrUnix) * 1000);
                if (Number.isFinite(d.getTime())) return d.toLocaleString();
            }
            const parsed = Date.parse(String(sqlOrUnix).replace(' ', 'T'));
            if (!Number.isFinite(parsed)) return String(sqlOrUnix);
            return new Date(parsed).toLocaleString();
        }

        function formatDurationCompact(secondsValue) {
            const seconds = Math.max(0, Math.floor(Number(secondsValue) || 0));
            if (seconds < 60) return `${seconds}s`;
            const minutes = Math.floor(seconds / 60);
            const remainingSeconds = seconds % 60;
            if (minutes < 60) return remainingSeconds === 0 ? `${minutes}m` : `${minutes}m ${remainingSeconds}s`;
            const hours = Math.floor(minutes / 60);
            const remainingMinutes = minutes % 60;
            return remainingMinutes === 0 ? `${hours}h` : `${hours}h ${remainingMinutes}m`;
        }

        function renderSimpleRows(tbody, rows, emptyText, rowRenderer) {
            tbody.innerHTML = '';
            if (!rows.length) {
                const tr = tbody.insertRow();
                const td = tr.insertCell();
                td.colSpan = 4;
                td.className = 'stats-muted';
                td.innerText = emptyText;
                return;
            }
            rows.forEach((row, idx) => rowRenderer(row, idx));
        }

        function renderStats(result) {
            const tables = result.tables ?? [];
            const totalWindowRows = tables.reduce((sum, t) => sum + Number(t.rows_in_window ?? 0), 0);

            statsKpiDbSize.innerText = formatBytes(result.database?.total_bytes ?? 0);
            statsKpiTableCount.innerText = String(result.database?.table_count ?? tables.length ?? 0);
            statsKpiWindowRows.innerText = String(totalWindowRows);
            statsKpiLastPurge.innerText = formatLocalDateTime(result.retention?.last_purge_at_unix ?? 0);
            statsLastUpdate.innerText = result.generated_at ?? new Date().toLocaleString();

            const ret = result.retention ?? {};
            const autoPurge = ret.auto_purge ?? {};
            statsRetention.innerText = `ADV: ${ret.advertisements_days ?? 0} days | MSG: ${ret.messages_days ?? 0} days | RAW: ${ret.raw_packets_days ?? 0} days`;

            if (!autoPurge.enabled) {
                statsKpiAutoPurge.innerText = 'Disabled';
            } else if (autoPurge.state === 'cooldown') {
                const remaining = formatDurationCompact(autoPurge.cooldown_remaining_seconds ?? 0);
                const nextAt = formatLocalDateTime(autoPurge.next_auto_purge_at_unix ?? 0);
                statsKpiAutoPurge.innerText = `Next in ${remaining} (${nextAt})`;
            } else {
                statsKpiAutoPurge.innerText = 'Ready now (on next ingest)';
            }

            renderSimpleRows(statsIngestTbody, result.ingest_breakdown ?? [], 'No ingest data in selected window.', (row) => {
                const tr = statsIngestTbody.insertRow();
                tr.insertCell().innerText = row.type ?? '';
                tr.insertCell().innerText = String(row.count ?? 0);
            });

            renderSimpleRows(statsRawTypesTbody, result.raw_packet_type_breakdown ?? [], 'No raw packet types in selected window.', (row) => {
                const tr = statsRawTypesTbody.insertRow();
                tr.insertCell().innerText = String(row.packet_type ?? '-');
                tr.insertCell().innerText = String(row.count ?? 0);
            });

            renderSimpleRows(statsReportersTbody, result.top_reporters ?? [], 'No reporter activity in selected window.', (row, idx) => {
                const tr = statsReportersTbody.insertRow();
                tr.insertCell().innerText = String(idx + 1);
                tr.insertCell().innerText = row.name ?? '';
                tr.insertCell().innerText = row.public_key ?? '';
                tr.insertCell().innerText = String(row.packets_in_window ?? 0);
            });

            renderSimpleRows(statsTablesTbody, tables, 'No table stats available.', (row) => {
                const tr = statsTablesTbody.insertRow();
                tr.insertCell().innerText = row.name ?? '';
                tr.insertCell().innerText = String(row.rows ?? 0);
                tr.insertCell().innerText = row.rows_in_window != null ? String(row.rows_in_window) : '-';
                tr.insertCell().innerText = formatBytes(row.bytes ?? 0);
                tr.insertCell().innerText = row.oldest_at ?? '-';
                tr.insertCell().innerText = row.newest_at ?? '-';
            });
        }

        function loadStats() {
            statsRefreshBtn.disabled = true;
            statsError.innerText = '';
            const hours = Number(statsWindowSelect.value || 24);
            fetch(`api/stats/?window_hours=${encodeURIComponent(hours)}`)
                .then(r => r.json())
                .then(result => {
                    statsRefreshBtn.disabled = false;
                    if (getError(result)) {
                        statsError.innerText = 'Error: ' + getError(result);
                        return;
                    }
                    renderStats(result);
                })
                .catch(() => {
                    statsRefreshBtn.disabled = false;
                    statsError.innerText = 'Request failed';
                });
        }

        function startStatsAutoRefresh() {
            if (statsAutoRefreshTimer) return;
            statsAutoRefreshTimer = setInterval(() => {
                if (location.hash.slice(1) === 'stats') {
                    loadStats();
                }
            }, 10000);
        }

        function stopStatsAutoRefresh() {
            if (!statsAutoRefreshTimer) return;
            clearInterval(statsAutoRefreshTimer);
            statsAutoRefreshTimer = null;
        }

        statsRefreshBtn.addEventListener('click', loadStats);
        statsWindowSelect.addEventListener('change', loadStats);

        /* ── Init ────────────────────────────────────────────────────── */
        const initialPage = PAGES.includes(location.hash.slice(1)) ? location.hash.slice(1) : 'devices';
        switchPage(initialPage);
    </script>
<?php endif ?>
</body>
</html>
