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
        header("Location: .");
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
                'permissions' => $login->permissions
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
        body { display: flex; flex-direction: column; min-height: 100vh; }
        .admin-header {
            background: #1a1a1a;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 20px;
            border-bottom: 1px solid #444;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .admin-header .admin-title { font-size: 1.2em; color: #ccc; letter-spacing: 0.05em; }
        .admin-header a { color: #888; text-decoration: none; }
        .admin-header a:hover { color: #fff; }
        .admin-body { padding: 20px 24px; max-width: 1400px; width: 100%; box-sizing: border-box; }
        .admin-section {
            background: #1e1e1e;
            border: 1px solid #333;
            border-radius: 6px;
            margin-bottom: 24px;
            overflow: hidden;
        }
        .admin-section .section-title {
            padding: 10px 16px;
            background: #252525;
            border-bottom: 1px solid #333;
            color: #bbb;
            font-size: 1.1em;
        }
        .admin-section .section-body { padding: 12px 16px; overflow-x: auto; }
        table { border-collapse: collapse; width: 100%; }
        th {
            text-align: left;
            color: #999;
            border-bottom: 1px solid #333;
            padding: 6px 8px;
            white-space: nowrap;
        }
        td { padding: 4px 8px; vertical-align: middle; }
        tbody tr:hover { background: #252525; }
        tr.disabled td { opacity: 0.5; text-decoration: line-through; }
        input[type=text], input[type=password] {
            background: #2a2a2a;
            border: 1px solid #444;
            color: #ccc;
            padding: 3px 6px;
            border-radius: 3px;
            box-sizing: border-box;
        }
        input[type=text]:focus, input[type=password]:focus { border-color: #888; outline: none; }
        button {
            background: #2e2e2e;
            border: 1px solid #555;
            color: #ccc;
            padding: 3px 10px;
            border-radius: 3px;
            cursor: pointer;
            margin-left: 4px;
        }
        button:hover { background: #444; color: #fff; }
        .rcolor {
            display: inline-block;
            height: 1rem;
            width: 1rem;
            border: solid 1px #222;
            border-radius: 1rem;
        }
        input[type=color] {
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            border: none;
            width: 1.5rem;
            height: 1.5rem;
            border-radius: 1.5rem;
            background: none;
            padding: 0;
            cursor: pointer;
            vertical-align: bottom;
            margin-left: 8px;
        }
        input[type="color"]::-webkit-color-swatch-wrapper { padding: 0; }
        input[type="color"]::-webkit-color-swatch { border: none; border-radius: 0; }
        input[type="color"]::-moz-color-swatch { border: none; }
        .logger-name {
            margin-left: 8px;
            padding: 2px;
            font-size: 0.8rem;
            border-radius: 0.35rem;
        }
        .setting-row { display: flex; align-items: center; gap: 12px; padding: 8px 0; border-bottom: 1px solid #2a2a2a; }
        .setting-row:last-child { border-bottom: none; }
        .setting-label { min-width: 220px; color: #aaa; }
        .setting-desc { color: #666; font-size: 0.9em; flex: 1; }
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
        <a href="?logout">Log out</a>
    </header>
    <div class="admin-body">
    <div class="admin-section">
        <div class="section-title">Reporters</div>
        <div class="section-body">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Name</th>
                    <th>Public Key</th>
                    <th>Lat</th>
                    <th>Lon</th>
                    <th>Auth</th>
                    <th>Style</th>
                    <th>Auth?</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="reporters"></tbody>
        </table>
        </div>
    </div>

    <script>
        const reporters = document.getElementById("reporters");

        function loadReporters() {
            fetch('api/reporters/', {
                method: "GET",
                headers: {
                    "Content-Type": "application/x-www-form-urlencoded"
                },
            })
            .then(response => response.json())
            .then(result => {
                reporters.innerHTML = '';
                result.objects.forEach(obj => { addReporter(reporters, obj) });
                addReporter(reporters, {
                    id: 'Add',
                    name: 'New Logger',
                    public_key: '',
                    auth: '',
                    lat: '0.00000',
                    lon: '0.00000',
                    authorized: 1,
                    style: '{"color": "#ff0000"}',
                    header: 'Add New new Reporter'
                });
            });
        }

        function makeColorInput(cell, value, onchange) {
            picker = document.createElement("input");
            picker.type = 'color';
            picker.value = value;
            picker.oninput = (e) => {
                onchange(e.target.value);
            }
            cell.append(picker);
            return picker;
        }

        function makeInputCell(row, value, type='text') {
            let td = row.insertCell();
            let input = document.createElement("input");
            td.append(input);

            const isColor = type == 'color';
            let picker = false;

            if (isColor) {
                type = 'text';
                picker = document.createElement("input");
                picker.type = 'color';
                picker.value = value;
                picker.oninput = (e) => {
                    input.value = e.target.value;
                    input.style.color = '#1976D2';
                }
                td.append(picker);
            }

            input.oninput = (e) => {
                input.style.color = '#1976D2';
                if (isColor && picker) {
                    picker.value = e.target.value;
                }
            }
            input.type = type;
            if (type == 'checkbox') {
                input.checked = value;
            } else {
                input.value = value;
            }
            return input;
        }

        function addReporter(table, reporter) {
            if (reporter.header ?? false) {
                let h = document.createElement("h2");
                h.innerText = reporter.header;

                let hrow = table.insertRow();
                let hcell = hrow.insertCell();
                hcell.colSpan = 9;
                hcell.append(h);
            }

            const id = reporter['id'];
            let row = table.insertRow();
            row.dataset.id = id;
            let td1 = row.insertCell();
            td1.innerText = id;

            let style = 0;
            try {
                style = JSON.parse(reporter['style']);
            } catch {
                console.log(reporter);
                style = {color: reporter['style'] };
            }

            let name = makeInputCell(row, reporter['name']);
            let key = makeInputCell(row, reporter['public_key']);
            let lat = makeInputCell(row, reporter['lat']);
            let lon = makeInputCell(row, reporter['lon']);
            let auth = makeInputCell(row, reporter['auth']);

            lat.style.maxWidth = '80px';
            lon.style.maxWidth = '80px';

            let tdstyle = row.insertCell();
            let psample = document.createElement("span")
            psample.innerText = reporter['name'];
            psample.classList.add('logger-name');
            psample.style.color = style['color'];
            psample.style.border = `solid 1px ${style['stroke'] ?? style['color']}`;
            let pcolor = makeColorInput(tdstyle, style['color'], value => {
                psample.style.color = value;
            });
            let pstroke = makeColorInput(tdstyle, style['stroke'] ?? style['color'], value => {
                psample.style.border = `solid 1px ${value}`;
            });
            tdstyle.append(psample);

            let authorized = makeInputCell(row, reporter['authorized'], 'checkbox');
            let td2 = row.insertCell();

            let getReporter = () => { 
                return {
                    id: id,
                    name: name.value,
                    public_key: key.value,
                    lat: lat.value,
                    lon: lon.value,
                    auth: auth.value,
                    authorized: authorized.checked ? 1 : 0,
                    style: JSON.stringify({
                        color: pcolor.value,
                        stroke: pstroke.value
                    })
                }
            };

            if (id == 'Add') {
                let btnAdd = document.createElement("button");
                btnAdd.innerText = "Add";
                btnAdd.onclick = () => {
                    saveReporter(getReporter(), true);
                }
                td2.append(btnAdd);
            } else {
                let btnSave = document.createElement("button");
                let btnDelete = document.createElement("button");
                btnSave.innerText = "Save";
                btnDelete.innerText = "Delete";
                td2.append(btnSave);
                td2.append(btnDelete);

                btnSave.onclick = () => {
                    saveReporter(getReporter());
                }

                btnDelete.onclick = () => {
                    deleteReporter(getReporter());
                }
            }
        }

        function getError(result) {
            const status = result.status ?? '?';
            if (status == 'OK') return false;
            return result['error'] ?? 'Unknown error';
        }

        function saveReporter(reporter, add=false) {
            let data = {
                name: reporter.name,
                public_key: reporter.public_key,
                lat: reporter.lat,
                lon: reporter.lon,
                auth: reporter.auth,
                authorized: reporter.authorized,
                style: reporter.style
            };

            if (add) {
                data.add = 1;
            } else {
                data.edit = 1;
                data.id = reporter.id;
            }

            fetch('api/reporters/', {
                method: "POST",
                headers: {
                    "Content-Type": "application/x-www-form-urlencoded"
                },
                body: new URLSearchParams(data) // encodes as POST form data
            })
            .then(response => response.json())
            .then(result => {
                const error = getError(result);
                if (error) {
                    alert(error);
                } else {
                    if (add) {
                        location.reload(true);
                    }
                    // clear  color
                    let row = reporters.querySelector(`tr[data-id="${reporter.id}"]`);
                    let inputs = row.querySelectorAll('input');
                    for (const input of inputs) {
                        input.style.color = '';
                    }
                }
            });
        }

        function deleteReporter(reporter) {
            const data = {
                delete: 1,
                id: reporter.id,
            };

            fetch('api/reporters/', {
                method: "POST",
                headers: {
                    "Content-Type": "application/x-www-form-urlencoded"
                },
                body: new URLSearchParams(data) // encodes as POST form data
            })
            .then(response => response.json())
            .then(result => {
                const error = getError(result);
                if (error) {
                    alert(error);
                } else {
                    let row = reporters.querySelector(`tr[data-id="${reporter.id}"]`);
                    row.remove();
                }
            });
        }

        loadReporters();
    </script>

    <div class="admin-section">
        <div class="section-title">Channels</div>
        <div class="section-body">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Hash</th>
                    <th>Name</th>
                    <th title="Base64-encoded PSK used by MeshCore for AES-128 group channel decryption">PSK (base64)</th>
                    <th>Enabled</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="channels"></tbody>
        </table>
        </div>
    </div>

    <script>
        const channelsTable = document.getElementById("channels");

        function loadChannels() {
            fetch('api/channels/', {
                method: "GET",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
            })
            .then(response => response.json())
            .then(result => {
                channelsTable.innerHTML = '';
                result.objects.forEach(obj => { addChannelRow(channelsTable, obj) });
                addChannelRow(channelsTable, {
                    id: 'Add',
                    hash: '',
                    name: '',
                    psk: '',
                    enabled: 1,
                    header: 'Add New Channel'
                });
            });
        }

        function addChannelRow(table, channel) {
            if (channel.header) {
                let h = document.createElement("h2");
                h.innerText = channel.header;
                let hrow = table.insertRow();
                let hcell = hrow.insertCell();
                hcell.colSpan = 6;
                hcell.append(h);
            }

            const id = channel['id'];
            let row = table.insertRow();
            row.dataset.id = id;

            let td1 = row.insertCell();
            td1.innerText = id;

            let hashCell = row.insertCell();
            let hashInput = document.createElement("input");
            hashInput.type = "text";
            hashInput.value = channel['hash'] ?? '';
            hashInput.oninput = () => { hashInput.style.color = '#1976D2'; };
            hashCell.append(hashInput);

            let nameCell = row.insertCell();
            let nameInput = document.createElement("input");
            nameInput.type = "text";
            nameInput.value = channel['name'] ?? '';
            nameInput.oninput = () => { nameInput.style.color = '#1976D2'; };
            nameCell.append(nameInput);

            let pskCell = row.insertCell();
            let pskInput = document.createElement("input");
            pskInput.type = "text";
            pskInput.value = channel['psk'] ?? '';
            pskInput.placeholder = 'Base64-encoded PSK (16 or 32 bytes)';
            pskInput.style.minWidth = '260px';
            pskInput.oninput = () => { pskInput.style.color = '#1976D2'; };
            pskCell.append(pskInput);

            let enabledCell = row.insertCell();
            let enabledInput = document.createElement("input");
            enabledInput.type = "checkbox";
            enabledInput.checked = channel['enabled'] === 1;
            enabledCell.append(enabledInput);

            let td2 = row.insertCell();

            let getData = () => ({
                id: id,
                hash: hashInput.value,
                name: nameInput.value,
                psk: pskInput.value,
                enabled: enabledInput.checked ? 1 : 0,
            });

            if (id === 'Add') {
                let btnAdd = document.createElement("button");
                btnAdd.innerText = "Add";
                btnAdd.onclick = () => {
                    const d = getData();
                    fetch('api/channels/', {
                        method: "POST",
                        headers: { "Content-Type": "application/x-www-form-urlencoded" },
                        body: new URLSearchParams({ add: 1, hash: d.hash, name: d.name, psk: d.psk, enabled: d.enabled })
                    })
                    .then(r => r.json())
                    .then(result => {
                        if (getError(result)) { alert(getError(result)); } else { loadChannels(); }
                    });
                };
                td2.append(btnAdd);
            } else {
                let btnSave = document.createElement("button");
                let btnDelete = document.createElement("button");
                btnSave.innerText = "Save";
                btnDelete.innerText = "Delete";
                td2.append(btnSave);
                td2.append(btnDelete);

                btnSave.onclick = () => {
                    const d = getData();
                    fetch('api/channels/', {
                        method: "POST",
                        headers: { "Content-Type": "application/x-www-form-urlencoded" },
                        body: new URLSearchParams({ edit: 1, id: d.id, hash: d.hash, name: d.name, psk: d.psk, enabled: d.enabled })
                    })
                    .then(r => r.json())
                    .then(result => {
                        if (getError(result)) { alert(getError(result)); }
                        else {
                            let inputs = row.querySelectorAll('input');
                            inputs.forEach(i => { i.style.color = ''; });
                        }
                    });
                };

                btnDelete.onclick = () => {
                    const msgCount = channel['message_count'] ?? 0;
                    let doForce = false;
                    if (msgCount > 0) {
                        const ok = confirm(`Channel has ${msgCount} messages. Delete and remove those messages? Click OK to force delete.`);
                        if (!ok) return;
                        doForce = true;
                    } else {
                        const ok = confirm('Delete channel?');
                        if (!ok) return;
                    }

                    const body = { delete: 1, id: id };
                    if (doForce) body.force = 1;

                    fetch('api/channels/', {
                        method: "POST",
                        headers: { "Content-Type": "application/x-www-form-urlencoded" },
                        body: new URLSearchParams(body)
                    })
                    .then(r => r.json())
                    .then(result => {
                        if (getError(result)) { alert(getError(result)); }
                        else { row.remove(); }
                    });
                };
            }
        }

        loadChannels();
    </script>

    <div class="admin-section">
        <div class="section-title">Settings</div>
        <div class="section-body" id="settings-body">
            <div class="setting-row">
                <span class="setting-label">Anonymize Usernames</span>
                <span class="setting-desc">Replace usernames and @mentions in channel &amp; direct messages with XXXXXX</span>
                <input type="checkbox" id="setting-anonymize">
                <button id="setting-save-btn" onclick="saveSettings()">Save</button>
            </div>
        </div>
    </div>
    </div>

    <script>
        function loadSettings() {
            fetch('api/settings/', {
                method: "GET",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
            })
            .then(response => response.json())
            .then(result => {
                document.getElementById('setting-anonymize').checked = result.settings.anonymize_usernames == 1;
            });
        }

        function saveSettings() {
            const btn = document.getElementById('setting-save-btn');
            const checked = document.getElementById('setting-anonymize').checked;
            btn.disabled = true;
            fetch('api/settings/', {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: new URLSearchParams({ save: 1, anonymize_usernames: checked ? 1 : 0 })
            })
            .then(r => r.json())
            .then(result => {
                btn.disabled = false;
                if (getError(result)) { alert(getError(result)); }
                else {
                    btn.innerText = 'Saved ✓';
                    setTimeout(() => { btn.innerText = 'Save'; }, 1500);
                }
            })
            .catch(() => { btn.disabled = false; });
        }

        loadSettings();
    </script>
<?php endif ?>
</body>
</html>