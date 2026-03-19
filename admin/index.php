<?php
require_once __DIR__ . '/../lib/meshlog.class.php';
require_once __DIR__ . '/../config.php';

session_start();

$meshlog = new MeshLog($config['db']);
$user = null;

if (isset($_SESSION['user'])) {
    $user = $_SESSION['user'];
}

if (isset($_POST['logout']) || isset($_GET['logout'])) {
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
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MeshLog Admin</title>
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
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            padding: 14px 24px;
            border-bottom: 1px solid var(--admin-line);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .admin-brand {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .admin-title {
            font-size: 1.3em;
            color: #f4fbff;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .admin-subtitle {
            color: var(--admin-muted);
            font-size: 0.95em;
        }

        .admin-nav {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-left: auto;
        }

        .admin-nav a {
            color: var(--admin-text);
            text-decoration: none;
            border: 1px solid var(--admin-line);
            background: rgba(255, 255, 255, 0.03);
            padding: 7px 12px;
            border-radius: 999px;
            transition: border-color 0.18s ease, background 0.18s ease;
        }

        .admin-nav a:hover {
            border-color: var(--admin-line-strong);
            background: rgba(255, 255, 255, 0.07);
        }

        .admin-body {
            width: 100%;
            max-width: 1680px;
            margin: 0 auto;
            box-sizing: border-box;
            padding: 24px;
        }

        .admin-overview {
            display: grid;
            grid-template-columns: 2.3fr 1fr;
            gap: 18px;
            margin-bottom: 18px;
        }

        .admin-hero,
        .admin-summary,
        .admin-section {
            background: var(--admin-panel);
            border: 1px solid var(--admin-line);
            border-radius: 18px;
            box-shadow: var(--admin-shadow);
        }

        .admin-hero {
            padding: 22px 24px;
        }

        .admin-hero h1 {
            color: #f5fbff;
            font-size: 1.8rem;
            margin-bottom: 10px;
        }

        .admin-hero p {
            color: var(--admin-muted);
            line-height: 1.5;
            max-width: 70ch;
        }

        .admin-summary {
            padding: 18px;
            display: grid;
            gap: 12px;
            align-content: start;
        }

        .admin-summary-card {
            border: 1px solid var(--admin-line);
            border-radius: 14px;
            padding: 14px 16px;
            background: rgba(255, 255, 255, 0.03);
        }

        .admin-summary-label {
            display: block;
            color: var(--admin-muted);
            font-size: 0.85rem;
            margin-bottom: 6px;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .admin-summary-value {
            color: #f6fbff;
            font-size: 1.4rem;
        }

        .pill-note {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 10px;
            border-radius: 999px;
            border: 1px solid rgba(146, 223, 210, 0.25);
            background: rgba(108, 199, 184, 0.08);
            color: #c6ece5;
            font-size: 0.86rem;
        }

        .admin-grid {
            display: grid;
            gap: 18px;
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
            font-size: 1.1em;
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

        .admin-table-toolbar {
            margin-bottom: 14px;
        }

        .admin-table-note {
            color: var(--admin-muted);
            font-size: 0.92rem;
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
            padding: 8px;
            vertical-align: middle;
        }

        tbody tr:hover {
            background: rgba(255, 255, 255, 0.03);
        }

        input[type=text],
        input[type=password],
        input[type=number],
        select {
            background: rgba(9, 12, 18, 0.92);
            border: 1px solid var(--admin-line);
            color: var(--admin-text);
            padding: 8px 10px;
            border-radius: 10px;
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
            padding: 8px 12px;
            border-radius: 999px;
            cursor: pointer;
            margin-left: 4px;
            transition: background 0.18s ease, border-color 0.18s ease, transform 0.18s ease;
        }

        button:hover {
            background: rgba(255, 255, 255, 0.10);
            color: #fff;
            border-color: var(--admin-line-strong);
            transform: translateY(-1px);
        }

        button:disabled {
            opacity: 0.6;
            cursor: wait;
            transform: none;
        }

        .button-primary {
            background: linear-gradient(135deg, rgba(108, 199, 184, 0.24), rgba(105, 146, 216, 0.22));
            border-color: rgba(146, 223, 210, 0.4);
        }

        .help-inline {
            display: inline-flex;
            align-items: center;
        }

        .help-trigger {
            width: 22px;
            height: 22px;
            min-width: 22px;
            padding: 0;
            margin-left: 6px;
            border-radius: 50%;
            font-weight: 700;
            line-height: 1;
        }

        .logger-name {
            margin-left: 8px;
            padding: 4px 8px;
            font-size: 0.8rem;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.04);
        }

        .reporter-key,
        .channel-hash {
            font-family: monospace;
            letter-spacing: 0.02em;
        }

        .settings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 14px;
        }

        .setting-card {
            border: 1px solid var(--admin-line);
            background: rgba(255, 255, 255, 0.03);
            border-radius: 16px;
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
            font-size: 1rem;
        }

        .setting-key {
            color: var(--admin-muted);
            font-size: 0.78rem;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .setting-desc {
            color: var(--admin-muted);
            font-size: 0.92em;
            line-height: 1.45;
        }

        .setting-input-wrap {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .setting-input-wrap input[type=number],
        .setting-input-wrap input[type=text] {
            width: 100%;
        }

        .setting-hint {
            color: #9dc0b6;
            font-size: 0.84rem;
        }

        .settings-actions {
            display: flex;
            justify-content: flex-end;
            margin-top: 16px;
        }

        .admin-modal {
            position: fixed;
            inset: 0;
            display: grid;
            place-items: center;
            background: rgba(7, 10, 15, 0.72);
            z-index: 200;
            padding: 20px;
        }

        .admin-modal[hidden] {
            display: none;
        }

        .admin-modal-card {
            width: min(560px, 100%);
            background: #111924;
            border: 1px solid var(--admin-line-strong);
            border-radius: 20px;
            box-shadow: var(--admin-shadow);
            padding: 22px;
        }

        .admin-modal-card h2 {
            color: #f5fbff;
            margin-bottom: 12px;
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

        @media (max-width: 1200px) {
            .admin-overview {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 900px) {
            .admin-header {
                flex-wrap: wrap;
            }

            .admin-nav {
                margin-left: 0;
            }

            .admin-body {
                padding: 16px;
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
        <div class="admin-brand">
            <span class="admin-title">MeshLog Admin</span>
            <span class="admin-subtitle">System settings, reporter devices, and channel controls</span>
        </div>
        <nav class="admin-nav">
            <a href="#settings-section">Settings</a>
            <a href="#reporters-section">Devices</a>
            <a href="#channels-section">Channels</a>
            <a href="?logout">Log out</a>
        </nav>
    </header>

    <div class="admin-body">
        <section class="admin-overview">
            <div class="admin-hero">
                <h1>Admin controls with room for operational context</h1>
                <p>
                    Configure retention and grouping behavior, manage each reporting device, and maintain channels from one screen.
                    Question-mark buttons open inline documentation so operators do not need to remember what each field changes.
                </p>
            </div>
            <div class="admin-summary">
                <div class="admin-summary-card">
                    <span class="admin-summary-label">What is configurable</span>
                    <span class="admin-summary-value">Retention, grouping, devices, channels</span>
                </div>
                <div class="admin-summary-card">
                    <span class="admin-summary-label">Per-device fields</span>
                    <span class="admin-summary-value">Name, key, auth, hash bytes, style</span>
                </div>
                <div class="pill-note">Tip: question-mark buttons explain what each option changes.</div>
            </div>
        </section>

        <div class="admin-grid">
            <section class="admin-section" id="reporters-section">
                <div class="section-title">
                    <span>Reporter Devices</span>
                    <span class="section-kicker">HTTP/MQTT logger definitions used for authorization and feed styling</span>
                </div>
                <div class="section-body">
                    <div class="admin-table-toolbar">
                        <span class="admin-table-note">Device Name and Public Key remain the primary identifiers. Hash Bytes stores the reporter’s configured 1/2/3-byte path prefix size.</span>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th><span class="help-inline">Device Name <button type="button" class="help-trigger" data-help-title="Device Name" data-help-body="Human-readable device label used in the admin panel and throughout the UI for reporter identification.">?</button></span></th>
                                <th><span class="help-inline">Public Key <button type="button" class="help-trigger" data-help-title="Reporter Public Key" data-help-body="This must match the reporter key used by the logger firmware or the MQTT topic. MeshLog uses it to authorize and associate incoming packets with this device.">?</button></span></th>
                                <th><span class="help-inline">Hash Bytes <button type="button" class="help-trigger" data-help-title="Reporter Hash Bytes" data-help-body="Expected routing hash prefix width for this reporter: 1, 2, or 3 bytes. This is stored as device metadata so operators can document how the node is configured.">?</button></span></th>
                                <th>Lat</th>
                                <th>Lon</th>
                                <th><span class="help-inline">Auth <button type="button" class="help-trigger" data-help-title="Reporter Auth Token" data-help-body="Bearer token used by the HTTP firmware logger path. MQTT reporters can keep this empty if they only ingest through MQTT.">?</button></span></th>
                                <th>Style</th>
                                <th><span class="help-inline">Enabled <button type="button" class="help-trigger" data-help-title="Reporter Enabled" data-help-body="Only enabled reporters are accepted for ingest. Disable a device here without deleting its historic data.">?</button></span></th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="reporters"></tbody>
                    </table>
                </div>
            </section>

            <section class="admin-section" id="settings-section">
                <div class="section-title">
                    <span>System Settings</span>
                    <span class="section-kicker">Retention, grouping, and privacy defaults</span>
                </div>
                <div class="section-body">
                    <div class="settings-grid" id="settings-grid"></div>
                    <div class="settings-actions">
                        <button type="button" class="button-primary" id="setting-save-btn">Save Settings</button>
                    </div>
                </div>
            </section>

            <section class="admin-section" id="channels-section">
                <div class="section-title">
                    <span>Channels</span>
                    <span class="section-kicker">Hashes, names, shared keys, and visibility</span>
                </div>
                <div class="section-body">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th><span class="help-inline">Hash <button type="button" class="help-trigger" data-help-title="Channel Hash" data-help-body="Plaintext channel identifier used to match group messages to a channel. This stays visible even when the actual group payload is encrypted.">?</button></span></th>
                                <th>Name</th>
                                <th><span class="help-inline">PSK (base64) <button type="button" class="help-trigger" data-help-title="Channel PSK" data-help-body="Optional Base64-encoded preshared key used to decrypt GRP_TXT packets for this channel.">?</button></span></th>
                                <th>Enabled</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="channels"></tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>

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
        const reporters = document.getElementById('reporters');
        const channelsTable = document.getElementById('channels');
        const settingsGrid = document.getElementById('settings-grid');
        const saveSettingsButton = document.getElementById('setting-save-btn');
        const helpModal = document.getElementById('help-modal');
        const helpModalTitle = document.getElementById('help-modal-title');
        const helpModalBody = document.getElementById('help-modal-body');
        const helpModalClose = document.getElementById('help-modal-close');

        const SETTINGS_DEFINITIONS = [
            {
                key: 'MAX_CONTACT_AGE',
                label: 'Contact visibility age',
                type: 'number',
                placeholder: '1814400',
                unit: 'seconds',
                description: 'Controls how long contacts stay visible in the contact list after their last heard timestamp.',
                help: 'Contacts older than this threshold are hidden from the contact overview. Existing data is not deleted; this only changes visibility in the UI.',
                formatHint: (value) => formatDurationHint(value, 'Visible for')
            },
            {
                key: 'MAX_GROUPING_AGE',
                label: 'Duplicate grouping window',
                type: 'number',
                placeholder: '21600',
                unit: 'seconds',
                description: 'Defines the time window for grouping duplicate ADV, MSG, and PUB entries by hash.',
                help: 'If a packet with the same hash arrives again inside this window, MeshLog groups it into the same logical message instead of creating a new one.',
                formatHint: (value) => formatDurationHint(value, 'Groups duplicates for')
            },
            {
                key: 'ANONYMIZE_USERNAMES',
                label: 'Anonymize usernames',
                type: 'checkbox',
                description: 'Replaces usernames and @mentions in stored direct and channel messages with placeholders.',
                help: 'Use this when you want the UI and API output to preserve message flow while reducing personally identifying text in names and mentions.',
                formatHint: (value) => value ? 'Username anonymization is active' : 'Usernames are shown as received'
            },
        ];

        function formatDurationHint(value, prefix) {
            const seconds = parseInt(value ?? 0, 10);
            if (!Number.isFinite(seconds) || seconds < 1) return `${prefix} disabled or invalid`;

            const units = [
                ['day', 86400],
                ['hour', 3600],
                ['minute', 60],
                ['second', 1],
            ];

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

        function getError(result) {
            const status = result.status ?? '?';
            if (status === 'OK') return false;
            return result.error ?? 'Unknown error';
        }

        function openHelpModal(title, body) {
            helpModalTitle.innerText = title;
            helpModalBody.innerText = body;
            helpModal.hidden = false;
        }

        function closeHelpModal() {
            helpModal.hidden = true;
        }

        function makeInputCell(row, value, type = 'text') {
            const td = row.insertCell();
            const input = document.createElement('input');
            input.type = type;
            if (type === 'checkbox') {
                input.checked = value === 1 || value === '1' || value === true;
            } else {
                input.value = value ?? '';
            }
            input.oninput = () => {
                input.style.color = '#1976D2';
            };
            td.append(input);
            return input;
        }

        function makeColorInput(cell, value, onchange) {
            const input = document.createElement('input');
            input.type = 'color';
            input.value = value;
            input.oninput = (event) => onchange(event.target.value);
            cell.append(input);
            return input;
        }

        function makeHashSizeInput(row, value = 1) {
            const td = row.insertCell();
            const select = document.createElement('select');
            [1, 2, 3].forEach(size => {
                const option = document.createElement('option');
                option.value = String(size);
                option.innerText = `${size} byte${size === 1 ? '' : 's'}`;
                select.append(option);
            });
            select.value = String(value ?? 1);
            select.onchange = () => {
                select.style.color = '#1976D2';
            };
            td.append(select);
            return select;
        }

        function loadReporters() {
            fetch('api/reporters/', {
                method: 'GET',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            })
            .then(response => response.json())
            .then(result => {
                reporters.innerHTML = '';
                result.objects.forEach(obj => addReporterRow(obj));
                addReporterRow({
                    id: 'Add',
                    name: 'New Logger',
                    public_key: '',
                    hash_size: 1,
                    auth: '',
                    lat: '0.00000',
                    lon: '0.00000',
                    authorized: 1,
                    style: '{"color":"#ff0000"}',
                    header: 'Add New Reporter'
                });
            });
        }

        function addReporterRow(reporter) {
            if (reporter.header) {
                const headerRow = reporters.insertRow();
                const headerCell = headerRow.insertCell();
                headerCell.colSpan = 10;
                const heading = document.createElement('h2');
                heading.innerText = reporter.header;
                headerCell.append(heading);
            }

            const row = reporters.insertRow();
            row.dataset.id = reporter.id;
            row.insertCell().innerText = reporter.id;

            let style = { color: '#ff0000' };
            try {
                style = JSON.parse(reporter.style ?? '{}');
            } catch (error) {
                style = { color: reporter.style ?? '#ff0000' };
            }

            const name = makeInputCell(row, reporter.name);
            const publicKey = makeInputCell(row, reporter.public_key);
            publicKey.classList.add('reporter-key');
            const hashSize = makeHashSizeInput(row, reporter.hash_size ?? 1);
            const lat = makeInputCell(row, reporter.lat);
            const lon = makeInputCell(row, reporter.lon);
            lat.style.maxWidth = '80px';
            lon.style.maxWidth = '80px';
            const auth = makeInputCell(row, reporter.auth);

            const styleCell = row.insertCell();
            const preview = document.createElement('span');
            preview.innerText = reporter.name;
            preview.className = 'logger-name';
            preview.style.color = style.color ?? '#ff0000';
            preview.style.border = `solid 1px ${style.stroke ?? style.color ?? '#ff0000'}`;
            const colorInput = makeColorInput(styleCell, style.color ?? '#ff0000', (value) => {
                preview.style.color = value;
            });
            const strokeInput = makeColorInput(styleCell, style.stroke ?? style.color ?? '#ff0000', (value) => {
                preview.style.border = `solid 1px ${value}`;
            });
            styleCell.append(preview);

            const enabled = makeInputCell(row, reporter.authorized, 'checkbox');
            const actionsCell = row.insertCell();

            const collectReporter = () => ({
                id: reporter.id,
                name: name.value,
                public_key: publicKey.value,
                hash_size: hashSize.value,
                lat: lat.value,
                lon: lon.value,
                auth: auth.value,
                authorized: enabled.checked ? 1 : 0,
                style: JSON.stringify({ color: colorInput.value, stroke: strokeInput.value })
            });

            if (reporter.id === 'Add') {
                const addButton = document.createElement('button');
                addButton.innerText = 'Add';
                addButton.onclick = () => saveReporter(collectReporter(), true);
                actionsCell.append(addButton);
                return;
            }

            const saveButton = document.createElement('button');
            const deleteButton = document.createElement('button');
            saveButton.innerText = 'Save';
            deleteButton.innerText = 'Delete';
            saveButton.onclick = () => saveReporter(collectReporter(), false);
            deleteButton.onclick = () => deleteReporter(reporter.id);
            actionsCell.append(saveButton, deleteButton);
        }

        function saveReporter(reporter, add) {
            const body = {
                name: reporter.name,
                public_key: reporter.public_key,
                hash_size: reporter.hash_size,
                lat: reporter.lat,
                lon: reporter.lon,
                auth: reporter.auth,
                authorized: reporter.authorized,
                style: reporter.style,
            };
            if (add) {
                body.add = 1;
            } else {
                body.edit = 1;
                body.id = reporter.id;
            }

            fetch('api/reporters/', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams(body)
            })
            .then(response => response.json())
            .then(result => {
                const error = getError(result);
                if (error) {
                    alert(error);
                    return;
                }
                if (add) {
                    location.reload(true);
                    return;
                }
                const row = reporters.querySelector(`tr[data-id="${reporter.id}"]`);
                row.querySelectorAll('input, select').forEach(input => {
                    input.style.color = '';
                });
            });
        }

        function deleteReporter(id) {
            fetch('api/reporters/', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ delete: 1, id })
            })
            .then(response => response.json())
            .then(result => {
                const error = getError(result);
                if (error) {
                    alert(error);
                    return;
                }
                reporters.querySelector(`tr[data-id="${id}"]`)?.remove();
            });
        }

        function loadChannels() {
            fetch('api/channels/', {
                method: 'GET',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            })
            .then(response => response.json())
            .then(result => {
                channelsTable.innerHTML = '';
                result.objects.forEach(obj => addChannelRow(obj));
                addChannelRow({
                    id: 'Add',
                    hash: '',
                    name: '',
                    psk: '',
                    enabled: 1,
                    header: 'Add New Channel'
                });
            });
        }

        function addChannelRow(channel) {
            if (channel.header) {
                const headerRow = channelsTable.insertRow();
                const headerCell = headerRow.insertCell();
                headerCell.colSpan = 6;
                const heading = document.createElement('h2');
                heading.innerText = channel.header;
                headerCell.append(heading);
            }

            const row = channelsTable.insertRow();
            row.dataset.id = channel.id;
            row.insertCell().innerText = channel.id;

            const hashCell = row.insertCell();
            const hash = document.createElement('input');
            hash.type = 'text';
            hash.value = channel.hash ?? '';
            hash.className = 'channel-hash';
            hash.oninput = () => { hash.style.color = '#1976D2'; };
            hashCell.append(hash);

            const nameCell = row.insertCell();
            const name = document.createElement('input');
            name.type = 'text';
            name.value = channel.name ?? '';
            name.oninput = () => { name.style.color = '#1976D2'; };
            nameCell.append(name);

            const pskCell = row.insertCell();
            const psk = document.createElement('input');
            psk.type = 'text';
            psk.value = channel.psk ?? '';
            psk.placeholder = 'Base64-encoded PSK (16 or 32 bytes)';
            psk.style.minWidth = '260px';
            psk.oninput = () => { psk.style.color = '#1976D2'; };
            pskCell.append(psk);

            const enabledCell = row.insertCell();
            const enabled = document.createElement('input');
            enabled.type = 'checkbox';
            enabled.checked = channel.enabled === 1 || channel.enabled === '1';
            enabledCell.append(enabled);

            const actionsCell = row.insertCell();
            const collectChannel = () => ({
                id: channel.id,
                hash: hash.value,
                name: name.value,
                psk: psk.value,
                enabled: enabled.checked ? 1 : 0,
            });

            if (channel.id === 'Add') {
                const addButton = document.createElement('button');
                addButton.innerText = 'Add';
                addButton.onclick = () => {
                    const data = collectChannel();
                    fetch('api/channels/', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({ add: 1, hash: data.hash, name: data.name, psk: data.psk, enabled: data.enabled })
                    })
                    .then(response => response.json())
                    .then(result => {
                        if (getError(result)) { alert(getError(result)); }
                        else { loadChannels(); }
                    });
                };
                actionsCell.append(addButton);
                return;
            }

            const saveButton = document.createElement('button');
            const deleteButton = document.createElement('button');
            saveButton.innerText = 'Save';
            deleteButton.innerText = 'Delete';

            saveButton.onclick = () => {
                const data = collectChannel();
                fetch('api/channels/', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ edit: 1, id: data.id, hash: data.hash, name: data.name, psk: data.psk, enabled: data.enabled })
                })
                .then(response => response.json())
                .then(result => {
                    if (getError(result)) { alert(getError(result)); }
                    else {
                        row.querySelectorAll('input').forEach(input => {
                            input.style.color = '';
                        });
                    }
                });
            };

            deleteButton.onclick = () => {
                const msgCount = channel.message_count ?? 0;
                let doForce = false;
                if (msgCount > 0) {
                    const ok = confirm(`Channel has ${msgCount} messages. Delete and remove those messages? Click OK to force delete.`);
                    if (!ok) return;
                    doForce = true;
                } else {
                    const ok = confirm('Delete channel?');
                    if (!ok) return;
                }

                const body = { delete: 1, id: channel.id };
                if (doForce) body.force = 1;

                fetch('api/channels/', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams(body)
                })
                .then(response => response.json())
                .then(result => {
                    if (getError(result)) { alert(getError(result)); }
                    else { row.remove(); }
                });
            };

            actionsCell.append(saveButton, deleteButton);
        }

        function renderSettings(settings) {
            settingsGrid.innerHTML = '';
            SETTINGS_DEFINITIONS.forEach(definition => {
                const card = document.createElement('div');
                card.className = 'setting-card';

                const head = document.createElement('div');
                head.className = 'setting-card-head';

                const textWrap = document.createElement('div');
                const key = document.createElement('div');
                key.className = 'setting-key';
                key.innerText = definition.key;

                const label = document.createElement('div');
                label.className = 'setting-label';
                label.innerText = definition.label;

                textWrap.append(key, label);

                const helpButton = document.createElement('button');
                helpButton.type = 'button';
                helpButton.className = 'help-trigger';
                helpButton.innerText = '?';
                helpButton.onclick = () => openHelpModal(definition.label, definition.help);

                head.append(textWrap, helpButton);

                const desc = document.createElement('div');
                desc.className = 'setting-desc';
                desc.innerText = definition.description;

                const inputWrap = document.createElement('div');
                inputWrap.className = 'setting-input-wrap';
                const input = document.createElement('input');
                input.dataset.settingKey = definition.key;
                input.dataset.settingType = definition.type;

                if (definition.type === 'checkbox') {
                    input.type = 'checkbox';
                    input.checked = Number(settings[definition.key] ?? 0) === 1;
                } else {
                    input.type = definition.type;
                    input.placeholder = definition.placeholder ?? '';
                    input.value = settings[definition.key] ?? '';
                }

                const hint = document.createElement('div');
                hint.className = 'setting-hint';
                const updateHint = () => {
                    hint.innerText = definition.formatHint(definition.type === 'checkbox' ? input.checked : input.value);
                };
                updateHint();
                input.addEventListener('input', updateHint);
                input.addEventListener('change', updateHint);

                inputWrap.append(input);
                if (definition.unit && definition.type !== 'checkbox') {
                    const unit = document.createElement('span');
                    unit.className = 'setting-hint';
                    unit.innerText = definition.unit;
                    inputWrap.append(unit);
                }

                card.append(head, desc, inputWrap, hint);
                settingsGrid.append(card);
            });
        }

        function loadSettings() {
            fetch('api/settings/', {
                method: 'GET',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            })
            .then(response => response.json())
            .then(result => {
                renderSettings(result.settings ?? {});
            });
        }

        function saveSettings() {
            saveSettingsButton.disabled = true;
            const body = { save: 1 };
            document.querySelectorAll('[data-setting-key]').forEach(input => {
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
            .then(response => response.json())
            .then(result => {
                saveSettingsButton.disabled = false;
                if (getError(result)) {
                    alert(getError(result));
                    return;
                }
                saveSettingsButton.innerText = 'Saved ✓';
                setTimeout(() => {
                    saveSettingsButton.innerText = 'Save Settings';
                }, 1500);
            })
            .catch(() => {
                saveSettingsButton.disabled = false;
            });
        }

        document.addEventListener('click', (event) => {
            const trigger = event.target.closest('.help-trigger[data-help-title]');
            if (trigger) {
                openHelpModal(trigger.dataset.helpTitle ?? 'Help', trigger.dataset.helpBody ?? '');
                return;
            }
            if (event.target === helpModal) {
                closeHelpModal();
            }
        });

        helpModalClose.addEventListener('click', closeHelpModal);
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !helpModal.hidden) {
                closeHelpModal();
            }
        });
        saveSettingsButton.addEventListener('click', saveSettings);

        loadReporters();
        loadChannels();
        loadSettings();
    </script>
<?php endif ?>
</body>
</html>
