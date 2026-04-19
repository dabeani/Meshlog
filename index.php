<?php
$faviconVersion = @filemtime(__DIR__ . '/assets/favicon/faviconw.ico') ?: time();
$jsVersion = @filemtime(__DIR__ . '/assets/js/meshlog.js') ?: time();
$cssVersion = @filemtime(__DIR__ . '/assets/css/style.css') ?: time();

$config = array();
$configFile = __DIR__ . '/config.php';
if (file_exists($configFile)) {
    require $configFile;
}

$mapConfig = $config['map'] ?? array();
$mapLat = floatval($mapConfig['lat'] ?? 51.5074);
$mapLon = floatval($mapConfig['lon'] ?? -0.1278);
$mapZoom = intval($mapConfig['zoom'] ?? 10);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link id="favicon" rel="icon" type="image/x-icon" href="assets/favicon/faviconw.ico?v=<?= $faviconVersion ?>">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
        integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
        crossorigin=""/>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
        crossorigin=""></script>
    <script src="https://cdn.jsdelivr.net/npm/leaflet-polylineoffset@1.1.1/leaflet.polylineoffset.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/linkifyjs@4.3.2/dist/linkify.min.js"
        integrity="sha256-3RgHec0J2nciPAIndkHOdN/WMH98UhLzLRsf+2MOiiY="
        crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/linkify-string@4.3.2/dist/linkify-string.min.js"
        integrity="sha256-b6wRq6tXNDnatickDjAMTffu2ZO2lsaV5Aivm+oh2s4="
        crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/leaflet-polylinedecorator@1.6.0/dist/leaflet.polylineDecorator.min.js"></script>
    <script src="https://unpkg.com/leaflet.heat@0.2.0/dist/leaflet-heat.js"></script>
    <script src="assets/js/meshlog.js?v=<?= $jsVersion ?>"></script>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= $cssVersion ?>">
    <title>MeshCore Log v1.99.1 (forked)</title>
</head>
<body>

<div id="error"></div>
<div id="container">
<div id="leftbar">
    <div id="sidebar-tabs">
        <button class="sidebar-tab active" data-tab="live">Live</button>
        <button class="sidebar-tab" data-tab="devices">Devices</button>
        <button class="sidebar-tab" data-tab="stats">Stats</button>
        <button class="sidebar-tab" data-tab="settings">Settings</button>
    </div>
    <div id="tab-live" class="sidebar-tab-panel">
        <div id="logs"></div>
    </div>
    <div id="tab-devices" class="sidebar-tab-panel" hidden>
        <section class="settings-panel">
            <div class="settings-panel-title">Device List</div>
            <div class="settings-panel-subtitle">Configure sorting and visibility in the Devices tab list.</div>
            <div class="settings" id="settings-contacts"></div>
        </section>
        <div id="contacts"></div>
    </div>
    <div id="tab-stats" class="sidebar-tab-panel" hidden>
        <div id="general-stats"></div>
    </div>
    <div id="tab-settings" class="sidebar-tab-panel" hidden>
        <section class="settings-panel">
            <div class="settings-panel-title">Live Feed</div>
            <div class="settings-panel-subtitle">Control what appears in the live packet stream.</div>
            <div id="settings-types"></div>
        </section>
        <section class="settings-panel" id="settings-reporters-panel" hidden>
            <div class="settings-panel-title">Collectors</div>
            <div class="settings-panel-subtitle">Collector filter controls are integrated in Live Feed.</div>
            <div id="settings-reporters"></div>
        </section>
        <section class="settings-card settings-about-card">
            <div class="settings-card-heading">
                <div class="settings-card-title">About &amp; Admin</div>
            </div>
            <div class="settings-about-body">
                <span class="settings-about-version">MeshLog Web v1.99 (forked)</span>
                <a href="admin/" class="btn" style="text-align:center;text-decoration:none">Open Admin Panel</a>
            </div>
        </section>
    </div>
</div>
<div id="midbar">
    <div class="resize-bar" id="leftdrag"><button type="button" class="collapse-toggle" id="toggle-leftbar" title="Hide sidebar" aria-label="Hide sidebar">◀</button></div>
    <div id="map"></div>
    <div id="warning" hidden></div>
</div>

<div id="context-menu" class="menu">
</div>
</div>

<!-- App help dialog (opened by ? buttons in the filter panels) -->
<div id="app-help-modal" hidden>
    <div class="app-help-card" role="dialog" aria-modal="true" aria-labelledby="app-help-title">
        <div class="app-help-header">
            <span id="app-help-title"></span>
            <button type="button" id="app-help-close" title="Close">✕</button>
        </div>
        <p id="app-help-body"></p>
    </div>
</div>

<script>

// Setup linkifyjs default options (default to ouse page)
linkify.options.defaults = {...linkify.options.defaults, defaultProtocol: "https", target: "_blank"}

// resize bars

class Bar {
    constructor(id, width) {
        this.tmpWidth = undefined;
        this.dom = document.getElementById(id);
        this.savedWidth = width;
        this.setWidth(width);
    }

    setWidth(w, remember=true) {
        this.width = w;
        if (remember && w > 0.5) {
            this.savedWidth = w;
        }
        this.dom.style.width = `${w}%`;
        this.dom.classList.toggle('bar-collapsed', w <= 0.5);
    }

    setTmpWidth(w) {
        if (this.tmpWidth == undefined) {
            this.tmpWidth = this.width;
            this.setWidth(w, false);
        }
    }

    resetWidth() {
        if (this.tmpWidth != undefined) {
            this.setWidth(this.tmpWidth, false);
            this.tmpWidth = undefined;
        }
    }
}

class Drags {
    constructor(id) {
        this.pairs = [];
        this.container = document.getElementById(id);

        self = this;

        this.container.addEventListener("mousemove", function (e) {
            e.preventDefault();

            let pair = undefined;
            for (var i=0;i<self.pairs.length;i++) {
                if (self.pairs[i].drag) {
                    pair = self.pairs[i];
                    break;
                }
            }

            if (!pair) return;

            let split = ( e.x - pair.x0) / pair.width;

            if (split < 0.05) split = 0.05;

            let ppLeft = pair.sum * split; // % left
            let ppRight = pair.sum - ppLeft;

            pair.left.setWidth(ppLeft);
            pair.right.setWidth(ppRight);
            
            map.invalidateSize();
        });

        this.container.addEventListener("mouseup", function (e) {
            self.cancelDrag();
        });
    }

    add(pair) {
        pair.bind(this);
        this.pairs.push(pair);
    }

    cancelDrag() {
        for (var i=0;i<this.pairs.length;i++) {
            this.pairs[i].drag = false;
        }
    }

    isDraging() {
        for (var i=0;i<this.pairs.length;i++) {
            if (this.pairs[i].drag) return true;
        }
        return false;
    }
}

class DragPair {
    constructor(id, left, right) {
        this.drag = false;
        this.left = left;
        this.right = right;
        this.bar = document.getElementById(id);
        this.calc();

        const self = this;

        this.bar.addEventListener("mousedown", function (e) {
            if (self.drags) self.drags.cancelDrag();
            self.calc();
            self.drag = true;
        });
    }

    calc() {
        this.x0 = this.left.dom.getBoundingClientRect().left;
        this.x1 = this.right.dom.getBoundingClientRect().right;
        this.width = this.x1 - this.x0;

        this.sum = this.percent2num(this.left.dom.style.width) + this.percent2num(this.right.dom.style.width);
    }

    percent2num(percent) {
        return parseFloat(percent.replaceAll("%","").trim());
    }

    bind(drags) {
        this.drags = drags;
    }
}

const leftBar = new Bar("leftbar", 33);
const middleBar = new Bar("midbar", 67);

const dragLeft = new DragPair("leftdrag", leftBar, middleBar);
const toggleLeftBar = document.getElementById('toggle-leftbar');

function syncCollapseButton(button, collapsed, direction, title) {
    button.innerText = collapsed ? (direction === '◀' ? '▶' : '◀') : direction;
    button.title = title;
    button.setAttribute('aria-label', title);
    button.parentElement.classList.toggle('compact', collapsed);
}

function setLeftCollapsed(collapsed) {
    const total = leftBar.width + middleBar.width;
    if (collapsed) {
        if (leftBar.width > 0.5) {
            leftBar.savedWidth = leftBar.width;
        }
        leftBar.setWidth(0, false);
        middleBar.setWidth(total, false);
    } else {
        const restored = Math.min(Math.max(leftBar.savedWidth ?? 33, 18), Math.max(total - 18, 18));
        leftBar.setWidth(restored, false);
        middleBar.setWidth(total - restored, false);
    }
    Settings.set('layout.leftbar.collapsed', collapsed);
    syncCollapseButton(toggleLeftBar, collapsed, '◀', collapsed ? 'Show live feed sidebar' : 'Hide live feed sidebar');
    if (typeof map !== 'undefined' && map) map.invalidateSize();
}

toggleLeftBar.addEventListener('click', () => {
    setLeftCollapsed(!Settings.getBool('layout.leftbar.collapsed', false));
});

function resize() {
    if (window.innerWidth <= 900) {
        leftBar.setTmpWidth(100);
        middleBar.setTmpWidth(100);
    } else {
        leftBar.resetWidth();
        middleBar.resetWidth();
    }
}

window.addEventListener("resize", function() {
    resize();
});

const drags = new Drags("container");
drags.add(dragLeft);

setLeftCollapsed(Settings.getBool('layout.leftbar.collapsed', false));

// Sidebar tab switching
(function() {
    const tabs   = document.querySelectorAll('.sidebar-tab');
    const panels = document.querySelectorAll('.sidebar-tab-panel');
    function activateTab(name) {
        tabs.forEach(b => b.classList.toggle('active', b.dataset.tab === name));
        panels.forEach(p => { p.hidden = p.id !== 'tab-' + name; });
        Settings.set('layout.sidebar.tab', name);
        if (name === 'stats' && window.meshlog && typeof window.meshlog.refreshGeneralStatsPanel === 'function') {
            window.meshlog.refreshGeneralStatsPanel();
        }
    }
    tabs.forEach(btn => btn.addEventListener('click', () => activateTab(btn.dataset.tab)));
    activateTab(Settings.get('layout.sidebar.tab', 'live'));
})();

resize();

const formatedTimestamp = (d=new Date())=> {
  const date = d.toISOString().split('T')[0];
  const time = d.toTimeString().split(' ')[0];
  return `${date} ${time}`
}

var map = L.map('map', { zoomControl: true }).setView([<?= json_encode($mapLat) ?>, <?= json_encode($mapLon) ?>], <?= json_encode($mapZoom) ?>);

function focusMapOnUserLocation() {
    if (!('geolocation' in navigator)) {
        return;
    }

    navigator.geolocation.getCurrentPosition(
        function(position) {
            var latitude = Number(position.coords.latitude);
            var longitude = Number(position.coords.longitude);

            if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
                return;
            }

            var targetZoom = Math.max(map.getZoom(), 12);
            map.setView([latitude, longitude], targetZoom);
        },
        function(_error) {
            // Keep configured map center when geolocation is unavailable or denied.
        },
        {
            enableHighAccuracy: true,
            timeout: 10000,
            maximumAge: 60000,
        }
    );
}

focusMapOnUserLocation();

var _TILE_LAYERS = {
    dark:  { url: 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',
             opts: { maxZoom: 19, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors &copy; <a href="https://carto.com/attributions">CARTO</a>' } },
    light: { url: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
             opts: { maxZoom: 19, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors' } },
    topo:  { url: 'https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png',
             opts: { subdomains: 'abc', maxZoom: 17, attribution: 'Map data: &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors, <a href="https://viewfinderpanoramas.org">SRTM</a> | Map style: &copy; <a href="https://opentopomap.org">OpenTopoMap</a> (CC-BY-SA)' } }
};
var _activeTileLayer = null;
function _setTileLayer(name) {
    if (_activeTileLayer) map.removeLayer(_activeTileLayer);
    var cfg = _TILE_LAYERS[name] || _TILE_LAYERS.dark;
    _activeTileLayer = L.tileLayer(cfg.url, cfg.opts).addTo(map);
    Settings.set('map.layer', _TILE_LAYERS[name] ? name : 'dark');
    document.querySelectorAll('.map-menu-layer-btn, .map-layer-btn').forEach(function(b) {
        b.classList.toggle('active', b.dataset.layer === (_TILE_LAYERS[name] ? name : 'dark'));
    });
}

var _UnifiedMapMenu = L.Control.extend({
    options: { position: 'topright' },
    onAdd: function() {
        var container = L.DomUtil.create('div', 'map-menu-container');

        var panel = L.DomUtil.create('div', 'map-menu-panel');

        // Search section
        var searchSection = L.DomUtil.create('div', 'map-menu-section map-menu-search-row');
        var searchInput = L.DomUtil.create('input', 'map-search-input');
        searchInput.type = 'text';
        searchInput.placeholder = 'Search devices...';
        searchInput.id = 'map-menu-search-input';

        var toggleBtn = L.DomUtil.create('button', 'map-menu-toggle-btn');
        toggleBtn.type = 'button';
        toggleBtn.innerHTML = '+';
        toggleBtn.title = 'Open map options';

        searchSection.appendChild(searchInput);
        searchSection.appendChild(toggleBtn);
        panel.appendChild(searchSection);

        var extraSection = L.DomUtil.create('div', 'map-menu-extra');

        // Layer selection section
        var layerSection = L.DomUtil.create('div', 'map-menu-section');
        var layerTitle = L.DomUtil.create('span', 'map-menu-title');
        layerTitle.textContent = 'Map Layer';
        layerSection.appendChild(layerTitle);

        var layerButtons = L.DomUtil.create('div', 'map-menu-layer-buttons');
        ['dark', 'light', 'topo'].forEach(function(layer) {
            var btn = L.DomUtil.create('button', 'map-menu-layer-btn');
            btn.dataset.layer = layer;
            btn.textContent = layer.charAt(0).toUpperCase() + layer.slice(1);
            btn.addEventListener('click', function() { _setTileLayer(layer); });
            layerButtons.appendChild(btn);
        });
        layerSection.appendChild(layerButtons);
        extraSection.appendChild(layerSection);

        // Legend section
        var legendSection = L.DomUtil.create('div', 'map-menu-section');
        var legendTitle = L.DomUtil.create('span', 'map-menu-title');
        legendTitle.textContent = 'Legend';
        legendSection.appendChild(legendTitle);

        var legend = L.DomUtil.create('div', 'map-legend');
        var legendItems = [
            { marker: '#4ea4c4', text: 'Device / Node' },
            { marker: '#d87dff', text: 'Highlighted' },
            { line: '#4ea4c4', text: 'Static route' },
            { line: '#00d9e9', text: 'Active route' },
            { line: '#ff3030', text: 'GPS trail' }
        ];

        legendItems.forEach(function(item) {
            var div = L.DomUtil.create('div', 'map-legend-item');
            if (item.marker) {
                var marker = L.DomUtil.create('div', 'map-legend-marker');
                marker.style.backgroundColor = item.marker;
                div.appendChild(marker);
            } else if (item.line) {
                var line = L.DomUtil.create('div', 'map-legend-line');
                line.style.backgroundColor = item.line;
                div.appendChild(line);
            }
            var text = L.DomUtil.create('span');
            text.textContent = item.text;
            div.appendChild(text);
            legend.appendChild(div);
        });
        legendSection.appendChild(legend);
        extraSection.appendChild(legendSection);
        panel.appendChild(extraSection);

        // Toggle behavior
        var isCollapsed = true;
        panel.classList.add('collapsed');
        toggleBtn.addEventListener('click', function() {
            isCollapsed = !isCollapsed;
            panel.classList.toggle('collapsed', isCollapsed);
            if (isCollapsed) {
                toggleBtn.innerHTML = '+';
                toggleBtn.title = 'Open map options';
            } else {
                toggleBtn.innerHTML = '-';
                toggleBtn.title = 'Close map options';
            }
        });

        L.DomEvent.disableClickPropagation(container);
        L.DomEvent.disableScrollPropagation(container);
        L.DomEvent.disableClickPropagation(panel);
        L.DomEvent.disableScrollPropagation(panel);
        container.appendChild(panel);

        return container;
    }
});

new _UnifiedMapMenu().addTo(map);
var _savedLayer = Settings.get('map.layer', 'dark');
_setTileLayer(_savedLayer === 'light' || _savedLayer === 'topo' ? _savedLayer : 'dark');

var meshlog = new MeshLog(
    map,
    "logs",
    "contacts",
    "settings-types",
    "settings-reporters",
    "settings-contacts",
    "warning",
    "error",
    "context-menu",
);
meshlog.loadAll();
meshlog.setAutorefresh(10000);
if (document.querySelector('.sidebar-tab.active')?.dataset.tab === 'stats') {
    meshlog.refreshGeneralStatsPanel();
}

// App help dialog
(function() {
    const modal = document.getElementById('app-help-modal');
    const titleEl = document.getElementById('app-help-title');
    const bodyEl  = document.getElementById('app-help-body');
    window.openAppHelp = function(title, body) {
        titleEl.innerText = title;
        bodyEl.innerText  = body;
        modal.hidden = false;
    };
    function close() { modal.hidden = true; }
    document.getElementById('app-help-close').addEventListener('click', close);
    modal.addEventListener('click', (e) => { if (e.target === modal) close(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !modal.hidden) close(); });
})();

</script>
</body>
</html>
