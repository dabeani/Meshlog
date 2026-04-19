class Settings {
    static cookiePrefix = 'meshlog_';

    static getCookieKey(key) {
        return `${this.cookiePrefix}${key}`;
    }

    static readCookie(key) {
        const cookieKey = encodeURIComponent(this.getCookieKey(key));
        const parts = document.cookie ? document.cookie.split('; ') : [];
        for (let i = 0; i < parts.length; i++) {
            const [name, ...valueParts] = parts[i].split('=');
            if (name === cookieKey) {
                return decodeURIComponent(valueParts.join('='));
            }
        }
        return undefined;
    }

    static readLegacyLocalStorage(key) {
        try {
            const value = localStorage.getItem(key);
            if (value !== null) {
                this.writeCookie(key, value);
                return value;
            }
        } catch (error) {
            console.debug('localStorage unavailable', error);
        }
        return undefined;
    }

    static writeCookie(key, value) {
        const cookieKey = encodeURIComponent(this.getCookieKey(key));
        const cookieValue = encodeURIComponent(String(value));
        document.cookie = `${cookieKey}=${cookieValue}; path=/; max-age=31536000; SameSite=Lax`;
    }

    static get(key, def=undefined) {
        let value = this.readCookie(key);
        if (value === undefined) {
            value = this.readLegacyLocalStorage(key);
        }
        if (value === undefined && def !== undefined) {
            this.set(key, def);
            return String(def);
        }
        return value;
    }

    static getBool(key, def=undefined) {
        const value = this.get(key, def);
        return value === 'true' || value === '1' || value === true;
    }

    static set(key, value) {
        this.writeCookie(key, value);
        try {
            localStorage.setItem(key, String(value));
        } catch (error) {
            console.debug('localStorage unavailable', error);
        }
    }
}

function parseMeshlogTimestamp(value) {
    if (!value || typeof value !== 'string') return NaN;

    const match = value.trim().match(
        /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})(?:\.(\d{1,3}))?$/
    );
    if (!match) {
        const fallback = new Date(value).getTime();
        return Number.isFinite(fallback) ? fallback : NaN;
    }

    const [, year, month, day, hour, minute, second, millis = '0'] = match;
    return new Date(
        Number(year),
        Number(month) - 1,
        Number(day),
        Number(hour),
        Number(minute),
        Number(second),
        Number(millis.padEnd(3, '0'))
    ).getTime();
}

/**
 * Parse a routing path string into an array of lowercase hex hash strings.
 * Handles both comma-separated MQTT binary format ("ab,cd") and the
 * arrow-separated HTTP firmware format ("AB->CD->EF").
 */
function parsePath(pathStr) {
    if (!pathStr) return [];
    const parts = pathStr.includes('->') ? pathStr.split('->') : pathStr.split(',');
    return parts.map(h => h.trim().replace(/[^0-9a-fA-F]/g, '').toLowerCase()).filter(Boolean);
}

function str2hue(str) {
    let hash = 0x811c9dc5n;
    for (let i = 0; i < str.length; i++) {
        hash = BigInt.asIntN(32, hash ^ BigInt(str.charCodeAt(i)));
        hash = BigInt.asIntN(32, hash * 0x01000193n);
    }

    return Number(hash & 0xFFFFFFFFn) % 360;
}

function getChannelTheme(seed) {
    const basis = seed && seed.length > 0 ? seed : 'channel';
    const hue = str2hue(basis);
    return {
        hue,
        solid: `hsl(${hue}deg, 68%, 46%)`,
        background: `hsla(${hue}deg, 72%, 52%, 0.18)`,
        border: `hsla(${hue}deg, 84%, 70%, 0.44)`,
        text: `hsl(${hue}deg, 88%, 78%)`,
    };
}

function applyPresentation(element, meta = {}, fallbackTitle = '') {
    if (!element) return;

    element.title = meta.title ?? fallbackTitle;
    if (meta.title ?? fallbackTitle) {
        element.setAttribute('aria-label', meta.title ?? fallbackTitle);
    }

    if (meta.style) {
        Object.entries(meta.style).forEach(([key, value]) => {
            element.style[key] = value;
        });
    }
}

function getSnrBadgePresentation(snr, title = '') {
    if (!Number.isFinite(snr)) {
        return { title };
    }

    let qualityLabel = 'Poor';

    if (snr >= 9) {
        qualityLabel = 'Excellent';
        return {
            title: title ? `${title} (${qualityLabel})` : qualityLabel,
            style: {
                color: '#ecfff4',
                background: '#20583a',
                borderColor: '#3ea86c'
            }
        };
    }

    if (snr >= 3) {
        qualityLabel = 'Good';
        return {
            title: title ? `${title} (${qualityLabel})` : qualityLabel,
            style: {
                color: '#e9fffc',
                background: '#245764',
                borderColor: '#46a8c2'
            }
        };
    }

    if (snr >= -3) {
        qualityLabel = 'Fair';
        return {
            title: title ? `${title} (${qualityLabel})` : qualityLabel,
            style: {
                color: '#fff7e3',
                background: '#6d5522',
                borderColor: '#c79a3a'
            }
        };
    }

    if (snr >= -10) {
        qualityLabel = 'Weak';
        return {
            title: title ? `${title} (${qualityLabel})` : qualityLabel,
            style: {
                color: '#fff0e3',
                background: '#7a4520',
                borderColor: '#d47d39'
            }
        };
    }

    return {
        title: title ? `${title} (${qualityLabel})` : qualityLabel,
        style: {
            color: '#ffeceb',
            background: '#6d2730',
            borderColor: '#d35a69'
        }
    };
}

class MeshLogObject {
    static idPrefix = "";

    constructor(meshlog, data) {
        this._meshlog = meshlog;
        this.data = {}; // db data
        this.flags = {};
        this.dom = null;
        this.highlight = false;
        this.time = 0; // created_at
        this.merge(data);
    }

    merge(data) {
        // App shouldn't change data. It is updated on new advertisements
        this.data = {...this.data, ...data};
        this.time = parseMeshlogTimestamp(data.created_at);
    }

    // override
    createDom(recreate = false) {}
    updateDom() {}

    setHighlight(highlight) {
        if (this.highlight === highlight) return;
        this.highlight = highlight;
        if (this.dom) this.updateDom();
    }

    static onclick(e) {}
    static onmouseover(e) {}
    static onmouseout(e) {}
    static oncontextmenu(e) {}
}

class MeshLogReporter extends MeshLogObject {
    constructor(meshlog, data) {
        super(meshlog, data);
        this.contact_id = -1;
        this.getContactId();
    }

    getStyle() {
        return JSON.parse(this.data.style);
    }

    getContactId() {
        if (this.contact_id == -1) {
            // Fast path: API already provides contact_id directly
            if (this.data.contact_id) {
                this.contact_id = this.data.contact_id;
            } else {
                // Fallback: case-insensitive lookup (reporters may store keys in different case)
                let contact = Object.values(this._meshlog.contacts)
                    .find(obj => obj.data.public_key.toLowerCase() == this.data.public_key.toLowerCase());
                if (contact) {
                    this.contact_id = contact.data.id;
                }
            }
        }

        return this.contact_id;
    }

    getTimeSync() {
        return this.data.time_sync ?? null;
    }

    getParsedReporterData() {
        const raw = this.data?.data;
        if (raw && typeof raw === 'object') return raw;
        if (typeof raw !== 'string' || raw.trim() === '') return {};
        try {
            const parsed = JSON.parse(raw);
            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (_error) {
            return {};
        }
    }

    getStatus() {
        if (this.data?.reporter_status && typeof this.data.reporter_status === 'object') {
            return this.data.reporter_status;
        }

        const parsedData = this.getParsedReporterData();
        const status = parsedData.reporter_status ?? null;
        return status && typeof status === 'object' ? status : null;
    }

    hasTimeSyncWarning() {
        return !!this.getTimeSync()?.warning;
    }
}

class MeshLogChannel extends MeshLogObject {
    constructor(meshlog, data) {
        super(meshlog, data);
    }

    getBadgeTheme() {
        return getChannelTheme(`${this.data.hash ?? ''}:${this.data.name ?? ''}`);
    }

    getBadgeTitle() {
        const adminEnabled = !(this.data.enabled === 0 || this.data.enabled === '0' || this.data.enabled === false);
        if (!adminEnabled) {
            return `${this.data.name}: disabled in admin`;
        }
        return `Toggle live-feed visibility for channel ${this.data.name}`;
    }

    isEnabled() {
        // Respect the admin-side enabled flag first.  If the admin has disabled this
        // channel (enabled == 0 or "0") it must be hidden regardless of the user's
        // per-browser live-feed toggle.
        const adminEnabled = this.data.enabled;
        if (adminEnabled === 0 || adminEnabled === "0" || adminEnabled === false) return false;
        return Settings.getBool(`channels.${this.data.id}.enabled`, true);
    }

    createDom(recreate = false) {
        if (this.dom && !recreate) return this.dom;

        if (this.dom && this.dom.container && this.dom.container.parentNode) {
            this.dom.container.parentNode.removeChild(this.dom.container);
            this.dom = null;
        }

        const self = this;

        const adminEnabled = !(this.data.enabled === 0 || this.data.enabled === '0' || this.data.enabled === false);
        let toggle = this._meshlog.__createBadgeToggle(
            this.data.name,
            `channels.${this.data.id}.enabled`,
            this.isEnabled(),
            (e) => {
                this.enabled = e.target.checked;
                self._meshlog.__onTypesChanged();
            },
            {
                title: this.getBadgeTitle(),
                theme: this.getBadgeTheme(),
                disabled: !adminEnabled,
            }
        );

        this.dom = {
            cb: toggle.button,
            toggle,
        };

        return this.dom;
    }

    updateDom() {
        if (!this.dom?.toggle) return;
        this.dom.toggle.setActive(this.isEnabled());
        this.dom.toggle.setDisabled(this.data.enabled === 0 || this.data.enabled === '0' || this.data.enabled === false);
    }
}

class MeshLogContact extends MeshLogObject {
    constructor(meshlog, data) {
        super(meshlog, data);
        this.adv = null;
        this.last = null;
        this.telemetry = null;
        this.marker = null;
        this.markerPane = null;
        this.markerTooltip = undefined;

        this.flags.dupe = false;
        this.hash = data.public_key.substr(0, 2 * data.hash_size).toLowerCase();
        this.neighbors_visible = false;

        if (data.advertisement) {
            this.adv = new MeshLogAdvertisement(meshlog, data.advertisement);
            this.last = this.adv;
            delete data.advertisement;
        }

        if (data.telemetry) {
            this.telemetry = data.telemetry; // todo: use object
            delete data.telemetry;
        }
    }

    merge(data) {
        super.merge(data);
        // Recompute this.hash whenever hash_size changes so all displays stay in sync.
        if (data.hash_size !== undefined) {
            this.hash = this.data.public_key.substr(0, 2 * this.data.hash_size).toLowerCase();
        }
    }

    hasValidCoordinates() {
        const lat = Number(this.adv?.data?.lat);
        const lon = Number(this.adv?.data?.lon);
        return Number.isFinite(lat) && Number.isFinite(lon) && !(lat === 0 && lon === 0);
    }

    static onclick(e) {
        this.expanded = !this.expanded;
        this.updateDom();
        // Pan map to device when expanding, if it has valid coordinates
        if (this.expanded && this._meshlog?.map && this.adv?.data) {
            const lat = Number(this.adv.data.lat);
            const lon = Number(this.adv.data.lon);
            if (Number.isFinite(lat) && Number.isFinite(lon) && !(lat === 0 && lon === 0)) {
                this._meshlog.map.panTo([lat, lon]);
            }
        }
    }

    static onmouseover(e) {
        this.setHighlight(true);
        const reporter = this.isReporter();
        // Show marker
        const descid = `c_${this.data.id}`;
        this._meshlog.layer_descs[descid] = {
            paths: [],
            markers: new Set().add(this.data.id),
            warnings: [],
            preview: {
                title: this.adv?.data?.name ?? this.data.public_key,
                subtitle: 'Live node focus',
                accent: reporter ? (reporter.getStyle().color ?? '#d87dff') : '#d87dff',
                chips: [
                    `[${this.hash}]`,
                    this.isRepeater() ? 'Repeater' : this.isRoom() ? 'Room' : this.isSensor() ? 'Sensor' : 'Chat'
                ],
                footer: this.last?.data?.created_at ? `Last heard ${this.last.data.created_at}` : ''
            }
        }
        this._meshlog.updatePaths();
    }

    static onmouseout(e) {
        this.setHighlight(false);
        const descid = `c_${this.data.id}`;
        delete this._meshlog.layer_descs[descid];
        this._meshlog.updatePaths();
    }

    static oncontextmenu(e) {
        e.preventDefault();

        this._meshlog.dom_contextmenu
        const menu = this._meshlog.dom_contextmenu;

        while (menu.hasChildNodes()) {
            menu.removeChild(menu.lastChild);
        }

        let saveGpx = (data, name) => {
            let gpxContent = `<?xml version="1.0" encoding="UTF-8"?>\n`;
            gpxContent += `<gpx version="1.1" creator="Meshlog">\n`;
            gpxContent += data;
            gpxContent += `</gpx>`;

            const blob = new Blob([gpxContent], { type: "application/gpx+xml" });
            const url = URL.createObjectURL(blob);
            const a = document.createElement("a");
            a.href = url;
            a.download = name;
            a.click();
        };

        const c = this;

        let miGpx = document.createElement("div");
        miGpx.classList.add('menu-item');
        miGpx.innerText = "Export to GPX";
        miGpx.onclick = (e) => {
            if (c.adv) {
                const wpt = `<wpt lat="${c.adv.data.lat}" lon="${c.adv.data.lon}"><name>${escapeXml(c.data.name)}</name></wpt>\n`;
                saveGpx(wpt, `meshlog_contact_${c.data.id}.gpx`);
            }
        };

        let miAll = document.createElement("div");
        miAll.classList.add('menu-item');
        miAll.innerText = "Export all to GPX";
        miAll.onclick = (e) => {
            let wpt = '';
            Object.entries(c._meshlog.contacts).forEach(([k,v]) => {
                if (v.adv && (v.adv.lat != 0 || v.adv.lon != 0)) {
                    wpt += `<wpt lat="${v.adv.data.lat}" lon="${v.adv.data.lon}"><name>${escapeXml(v.data.name)}</name></wpt>\n`;
                }
            });
            saveGpx(wpt, `meshlog_contacts.gpx`);
        };

        menu.appendChild(miGpx);
        menu.appendChild(miAll);

        menu.style.display = 'block';
        menu.style.left = `${e.pageX}px`;
        menu.style.top = `${e.pageY}px`;
    }

    getLayerDescPrefix() {
        return `nb${this.data.id}:`;
    }

    showNeighbors(rx=true, tx=true, markers=false) {
        if (this.isClient()) return; // not supported

        const getReporterContact = (reporter) => {
            if (!reporter) return null;
            const reporterContactId = reporter.getContactId();
            if (!Number.isFinite(reporterContactId) || reporterContactId < 0) return null;
            return this._meshlog.contacts[reporterContactId] ?? null;
        };

        const resolveUniqueHopContact = (hashPrefix, nearLat, nearLon) => {
            if (!hashPrefix) return null;
            const nearest = this._meshlog.findNearestContact(nearLat, nearLon, hashPrefix, true);
            if (!nearest?.result) return null;
            if (Number(nearest.matches) !== 1) return null;
            return nearest.result;
        };

        let contactPairs = {
            pairs: {},
            addPair: (src, dst) => {
                if (!src || !dst) return;
                if (!src.adv || !dst.adv) return;

                const srcLat = Number(src.adv.data.lat);
                const srcLon = Number(src.adv.data.lon);
                const dstLat = Number(dst.adv.data.lat);
                const dstLon = Number(dst.adv.data.lon);
                if (!Number.isFinite(srcLat) || !Number.isFinite(srcLon)) return;
                if (!Number.isFinite(dstLat) || !Number.isFinite(dstLon)) return;
                if (srcLat === 0 && srcLon === 0) return;
                if (dstLat === 0 && dstLon === 0) return;

                const key = `${src.data.id}_${dst.data.id}`;

                if (!contactPairs.pairs.hasOwnProperty(key)) {
                    contactPairs.pairs[key] = {
                        count: 1,
                        src,
                        dst,
                    };
                } else {
                    contactPairs.pairs[key].count += 1;
                }
            }
        };

        Object.entries(this._meshlog.messages).forEach(([k,m]) => {
            let src_id = m.data.contact_id;
            let src = this._meshlog.contacts[src_id] ?? false;

            if (!src) return;

            if (!m.reports) return;
            m.reports.forEach(r => {
                let path = r.data.path ?? undefined;
                if (path == undefined) return; 
                let hashes = parsePath(path);

                let reporter = this._meshlog.reporters[r.data.reporter_id] ?? false;
                if (!reporter) return;
                

                // If message is from this contact, neighbor is first path hash
                if (src === this) {
                    if (hashes.length == 0) {
                        // direct to reporter
                        if (reporter.getContactId() == -1) return;

                        contactPairs.addPair(src, this._meshlog.contacts[reporter.getContactId()]);
                    } else {
                        const nextHop = resolveUniqueHopContact(
                            hashes[0],
                            Number(this.adv.data.lat),
                            Number(this.adv.data.lon)
                        );
                        if (nextHop) {
                            contactPairs.addPair(src, nextHop);
                        }
                    }
                } else {
                    if (hashes.length == 0) return;
                    let idx = hashes.indexOf(this.hash);
                    if (idx < 0) return;

                    const reporterContact = getReporterContact(reporter);
                    const thisLat = Number(this.adv?.data?.lat);
                    const thisLon = Number(this.adv?.data?.lon);
                    if (!Number.isFinite(thisLat) || !Number.isFinite(thisLon)) return;

                    const prevContact = idx === 0
                        ? src
                        : resolveUniqueHopContact(hashes[idx - 1], thisLat, thisLon);
                    const nextContact = idx === hashes.length - 1
                        ? reporterContact
                        : resolveUniqueHopContact(hashes[idx + 1], thisLat, thisLon);

                    if (prevContact) { contactPairs.addPair(prevContact, this); }
                    if (nextContact) { contactPairs.addPair(this, nextContact); }
                }
            });
        });

        if (Object.keys(contactPairs.pairs).length < 1) {
            this.hideNeighbors();
            return;
        }

        Object.entries(contactPairs.pairs).forEach(([k,p]) => {
            let isTx = p.src == this;
            if (isTx && !tx) return;
            if (!isTx && !rx) return;

            let key = `${this.getLayerDescPrefix()}${k}`;
            this._meshlog.layer_descs[key] = {
                paths: [
                    new MeshLogLinkLayer(
                        {
                            lat: p.src.adv.data.lat,
                            lon: p.src.adv.data.lon,
                            contact_id: p.src.data.id
                        },
                        {
                            lat: p.dst.adv.data.lat,
                            lon: p.dst.adv.data.lon,
                            contact_id: p.dst.data.id
                        },
                        {
                            data: {
                                id: 0
                            },
                            getStyle: () => {
                                return {
                                    color: isTx ? 'red' : 'blue',
                                    strokeColor: 'white',
                                    strokeWeight: '1px'
                                };
                            }
                        },
                        false
                    )
                ],
                markers: markers ? new Set([p.src.data.id, p.dst.data.id]) : new Set([this.data.id]),
                warnings: []
            }
        });

        this._meshlog.updatePaths();
        this.neighbors_visible = true;
    }

    hideNeighbors() {
        let prefix = this.getLayerDescPrefix();
        Object.keys(this._meshlog.layer_descs).forEach(k => {
            if (k.startsWith(prefix)) {
                delete this._meshlog.layer_descs[k];
            }
        });
        this._meshlog.updatePaths();
        this.neighbors_visible = false;
    }

    toggleNeighbors() {
        if (this.isClient()) return false;

        if (this.neighbors_visible) {
            this.hideNeighbors();
        } else {
            this.showNeighbors();
        }

        return this.neighbors_visible;
    }

    showCollectorLinks() {
        const contactLat = Number(this.adv?.data?.lat);
        const contactLon = Number(this.adv?.data?.lon);
        if (!Number.isFinite(contactLat) || !Number.isFinite(contactLon)) return false;
        if (contactLat === 0 && contactLon === 0) return false;

        const reporterIdsRaw = Array.isArray(this.data?.reporter_ids) ? this.data.reporter_ids : [];
        const reporterIds = Array.from(new Set(reporterIdsRaw.map(Number).filter(id => Number.isFinite(id) && id > 0)));

        const paths = [];
        const markers = new Set([this.data.id]);

        for (const rid of reporterIds) {
            const reporter = this._meshlog.reporters[rid];
            if (!reporter) continue;
            const reporterContactId = Number(reporter.getContactId());
            if (!Number.isFinite(reporterContactId) || reporterContactId < 0) continue;
            const reporterContact = this._meshlog.contacts[reporterContactId];
            if (!reporterContact?.adv?.data) continue;
            const rLat = Number(reporterContact.adv.data.lat);
            const rLon = Number(reporterContact.adv.data.lon);
            if (!Number.isFinite(rLat) || !Number.isFinite(rLon)) continue;
            if (rLat === 0 && rLon === 0) continue;
            markers.add(reporterContactId);
            paths.push(new MeshLogLinkLayer(
                { lat: contactLat, lon: contactLon, contact_id: this.data.id },
                { lat: rLat,       lon: rLon,       contact_id: reporterContactId },
                reporter,
                false
            ));
        }

        const key = `cl_${this.data.id}`;
        delete this._meshlog.layer_descs[key];

        if (paths.length === 0) {
            this._meshlog.updatePaths();
            return false;
        }

        this._meshlog.layer_descs[key] = { paths, markers, warnings: [] };
        this._meshlog.updatePaths();
        return true;
    }

    hideCollectorLinks() {
        delete this._meshlog.layer_descs[`cl_${this.data.id}`];
        this._meshlog.updatePaths();
    }

    toggleCollectorLinks() {
        if (this._meshlog.layer_descs[`cl_${this.data.id}`]) {
            this.hideCollectorLinks();
            return false;
        }
        return this.showCollectorLinks();
    }

    createDom(recreate = false) {
        if (this.dom && !recreate) return this.dom;

        if (this.dom && this.dom.container && this.dom.container.parentNode) {
            this.dom.container.parentNode.removeChild(this.dom.container);
            this.dom = null;
        }

        let divContainer = document.createElement("div");
        let divContact = document.createElement("div");
        let divDetails = document.createElement("div");

        divContact.classList.add("log-entry");
        divContact.instance = this;
        divDetails.hidden = true;

        let imType = document.createElement("img");
        let spDate = document.createElement("span");
        let spHash = document.createElement("span");
        let spTimeSync = document.createElement("span");
        let spName = document.createElement("span");
        let spTelemetry = document.createElement("span");

        imType.classList.add(...['ti']);
        spDate.classList.add(...['sp', 'c']);
        spHash.classList.add(...['sp', 'prio-4']);
        spTimeSync.classList.add('time-sync-badge');
        spTimeSync.hidden = true;
        spName.classList.add(...['sp', 't']);
        spTelemetry.classList.add(...['sp', 'sm']);

        divContact.append(spDate);
        divContact.append(imType);
        divContact.append(spHash);
        divContact.append(spTimeSync);
        divContact.append(spName);
        divContact.append(spTelemetry);

        let divDetailsType = document.createElement("div");
        let divDetailsFirst = document.createElement("div");
        let divDetailsKey = document.createElement("div");
        let divDetailsTelemetry = document.createElement("div");
        let btnShowNeighbors = document.createElement("button");
        let btnShowRoutes = document.createElement("button");

        divDetails.append(divDetailsType);
        divDetails.append(divDetailsFirst);
        divDetails.append(divDetailsKey);
        divDetails.append(divDetailsTelemetry);

        if (!this.isClient()) {
            divDetails.append(btnShowNeighbors);
        } else {
            divDetails.append(btnShowRoutes);
        }

        divContainer.append(divContact);
        divContainer.append(divDetails);

        btnShowNeighbors.classList.add('btn');
        btnShowNeighbors.classList.add('contact-neighbors-btn');
        btnShowNeighbors.dataset.contactId = `${this.data.id}`;
        btnShowNeighbors.innerText = "Show Neighbors";
        btnShowNeighbors.onclick = (event) => {
            event.preventDefault();
            event.stopPropagation();
            if (this.toggleNeighbors()) {
                event.target.innerText = "Hide Neighbors";
                event.target.classList.add("active");
            } else {
                event.target.innerText = "Show Neighbors";
                event.target.classList.remove("active");
            }
        };

        btnShowRoutes.classList.add('btn');
        btnShowRoutes.classList.add('contact-routes-btn');
        btnShowRoutes.dataset.contactId = `${this.data.id}`;
        btnShowRoutes.innerText = "Routes";
        btnShowRoutes.onclick = (event) => {
            event.preventDefault();
            event.stopPropagation();
            if (this._meshlog.toggleRouteTrail(this)) {
                event.target.innerText = "Hide Routes";
                event.target.classList.add("active");
            } else {
                event.target.innerText = "Routes";
                event.target.classList.remove("active");
            }
        };

        this.dom = {
            container: divContainer,
            contact: divContact,
            details: divDetails,

            contactDate: spDate,
            contactHash: spHash,
            contactTimeSync: spTimeSync,
            contactName: spName,
            contactIcon: imType,
            contactTelemetry: spTelemetry,

            detailsType: divDetailsType,
            detailsFirst: divDetailsFirst,
            detailsKey: divDetailsKey,
            detailsTelemetry: divDetailsTelemetry,
            btnShowNeighbors: btnShowNeighbors,
            btnShowRoutes: btnShowRoutes
        };

        divContact.instance = this;
        return this.dom;
    }

    getReporterTimeSync() {
        const reporter = this.isReporter();
        return reporter?.getTimeSync?.() ?? reporter?.data?.time_sync ?? null;
    }

    // Client-side estimate of node clock drift using the ADV packet's own timestamp
    // versus when the server received it.  Both timestamps are stored in the server's
    // local timezone so their difference is timezone-independent (the offset cancels).
    // Returns drift in ms (positive = node ahead of server, negative = behind), or null.
    _getAdvTimeDriftMs() {
        const sentAt   = parseMeshlogTimestamp(this.adv?.data?.sent_at);
        const recvAt   = parseMeshlogTimestamp(this.adv?.data?.created_at);
        if (!Number.isFinite(sentAt) || !Number.isFinite(recvAt)) return null;
        return sentAt - recvAt;
    }

    hasReporterTimeSyncWarning() {
        if (!this.isRepeater()) return false;
        // Server-side: reporter with NTP-aware time sync data (most accurate).
        const ts = this.getReporterTimeSync();
        if (ts?.available) return !!ts.warning;
        // Client-side fallback: compare the ADV "sent_at" to "received_at".
        // The ADV timestamps are both stored in the server's local timezone, so the
        // subtraction is timezone-independent and gives the raw device clock drift.
        // A 5-minute threshold matches the server-side default (TIME_SYNC_WARNING_THRESHOLD).
        const driftMs = this._getAdvTimeDriftMs();
        if (driftMs === null) return false;
        return Math.abs(driftMs) >= 300000;
    }

    formatTimeSyncDrift(ms) {
        const totalMs = Math.abs(Number(ms) || 0);
        if (totalMs < 1000) return `${totalMs} ms`;

        const totalSeconds = totalMs / 1000;
        if (totalSeconds < 60) {
            return `${totalSeconds.toFixed(totalSeconds >= 10 ? 0 : 1)} s`;
        }

        const totalMinutes = totalSeconds / 60;
        if (totalMinutes < 60) {
            return `${totalMinutes.toFixed(totalMinutes >= 10 ? 0 : 1)} min`;
        }

        const totalHours = totalMinutes / 60;
        return `${totalHours.toFixed(totalHours >= 10 ? 0 : 1)} h`;
    }

    getTimeSyncWarningText() {
        if (!this.isRepeater()) return '';
        // Reporter path: server-computed with NTP reference.
        const timeSync = this.getReporterTimeSync();
        if (timeSync?.available) {
            const driftMs = Number(timeSync.drift_ms ?? 0);
            const direction = driftMs >= 0 ? 'ahead of' : 'behind';
            const drift = this.formatTimeSyncDrift(driftMs);
            return `Repeater clock is ${drift} ${direction} UTC (NTP reference). Time sync needed.`;
        }
        // Contact fallback: client-side estimate from ADV timestamps.
        const driftMs = this._getAdvTimeDriftMs();
        if (driftMs === null) return 'Repeater clock status unknown.';
        if (Math.abs(driftMs) < 300000) return '';
        const direction = driftMs >= 0 ? 'ahead of' : 'behind';
        const drift = this.formatTimeSyncDrift(driftMs);
        return `Repeater clock is ~${drift} ${direction} server reception time. Time sync likely needed.`;
    }

    getContactTypeLabel() {
        if (this.isClient()) return 'Chat';
        if (this.isRepeater()) return 'Repeater';
        if (this.isRoom()) return 'Room';
        if (this.isSensor()) return 'Sensor';
        return 'Unknown';
    }

    isRouteTrailVisible() {
        return this._meshlog.isRouteTrailVisible(this.data.id);
    }

    getMarkerStatusLabel() {
        if (this.isVeryExpired()) return 'Inactive';
        if (this.isExpired()) return 'Stale';
        return 'Live';
    }

    getMarkerTelemetryLines() {
        if (!this.telemetry || this.telemetry.length < 1) return [];

        const channels = {};
        for (let i = 0; i < this.telemetry.length; i++) {
            const sensor = this.telemetry[i];
            if (!channels.hasOwnProperty(sensor.channel)) {
                channels[sensor.channel] = {};
            }
            if (!channels[sensor.channel].hasOwnProperty(sensor.name)) {
                channels[sensor.channel][sensor.name] = [];
            }
            channels[sensor.channel][sensor.name].push(sensor.value);
        }

        const lines = [];
        const addMeasurement = (src, dst, key, scale, precision, unit) => {
            if (!src.hasOwnProperty(key)) return;
            if (!src[key] || src[key].length < 1) return;
            let val = (src[key][0] / scale).toFixed(precision);
            let str = `${val}`;
            if (unit) str += ` ${unit}`;
            dst.push(str);
        };

        Object.entries(channels).forEach(([ch, data], index) => {
            const meas = [];
            addMeasurement(data, meas, 'voltage', 1, 2, 'V');
            addMeasurement(data, meas, 'current', 1, 3, 'mA');
            addMeasurement(data, meas, 'temperature', 1, 2, '°C');
            addMeasurement(data, meas, 'humidity', 2, 1, '%');
            addMeasurement(data, meas, 'pressure', 1, 1, 'hPa');
            if (meas.length > 0) {
                lines.push(`Ch${index + 1}: ${meas.join(', ')}`);
            }
        });

        return lines;
    }

    formatPopupTimestamp(value) {
        if (!value) return '-';
        const time = parseMeshlogTimestamp(value);
        if (!Number.isFinite(time)) return String(value);
        return new Date(time).toLocaleString();
    }

    formatUptimeSeconds(value) {
        const seconds = Number(value);
        if (!Number.isFinite(seconds) || seconds < 0) return '-';

        const days = Math.floor(seconds / 86400);
        const hours = Math.floor((seconds % 86400) / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        if (days > 0) return `${days}d ${hours}h`;
        if (hours > 0) return `${hours}h ${minutes}m`;
        return `${minutes}m`;
    }

    getReportedBySectionHtml() {
        const reporterIdsRaw = Array.isArray(this.data?.reporter_ids) ? this.data.reporter_ids : [];
        const reporterIds = Array.from(new Set(reporterIdsRaw
            .map((id) => Number(id))
            .filter((id) => Number.isFinite(id) && id > 0)));

        if (reporterIds.length < 1) {
            return `
                <div class="device-popup-section">
                    <div class="device-popup-section-title">Reported By</div>
                    <div class="device-popup-note">No collector report data available yet.</div>
                </div>
            `;
        }

        const entries = reporterIds.map((reporterId) => {
            const reporter = this._meshlog.reporters[reporterId] ?? null;
            if (!reporter) {
                return {
                    label: `Collector #${reporterId}`,
                    subtitle: '',
                    badgeColor: '#4ea4c4',
                    badgeText: `#${reporterId}`,
                };
            }

            const reporterName = reporter?.data?.name || '';
            const reporterContactId = Number(reporter.getContactId());
            const reporterContact = Number.isFinite(reporterContactId) && reporterContactId > 0
                ? (this._meshlog.contacts[reporterContactId] ?? null)
                : null;
            const contactName = reporterContact?.adv?.data?.name || '';
            const label = (contactName || reporterName || '').trim() || `Collector #${reporterId}`;

            let subtitle = '';
            if (reporter?.data?.public_key) {
                subtitle = String(reporter.data.public_key).slice(0, 16);
            }

            const style = (reporter?.getStyle && typeof reporter.getStyle === 'function')
                ? reporter.getStyle()
                : {};
            const badgeColor = style?.color ?? '#4ea4c4';
            const badgeText = reporter?.data?.name
                ? 'Collector'
                : `#${reporterId}`;

            return { label, subtitle, badgeColor, badgeText };
        });

        entries.sort((a, b) => a.label.localeCompare(b.label));
        const title = reporterIds.length === 1
            ? 'Reported By (1 collector)'
            : `Reported By (${reporterIds.length} collectors)`;

        const rowsHtml = entries.map((entry) => {
            const subtitleHtml = entry.subtitle
                ? `<div class="device-popup-note">${escapeXml(entry.subtitle)}</div>`
                : '';
            return `
                <div class="device-popup-list-row">
                    <div class="device-popup-reporter-row">
                        <span class="device-popup-badge">
                            <span class="collector-stats-swatch" style="background:${escapeXml(entry.badgeColor)}"></span>
                            ${escapeXml(entry.badgeText)}
                        </span>
                        <span>${escapeXml(entry.label)}</span>
                    </div>
                    ${subtitleHtml}
                </div>
            `;
        }).join('');

        const hasCoords = Number.isFinite(Number(this.adv?.data?.lat))
            && Number.isFinite(Number(this.adv?.data?.lon))
            && !(Number(this.adv?.data?.lat) === 0 && Number(this.adv?.data?.lon) === 0);
        const linksVisible = !!this._meshlog.layer_descs?.[`cl_${this.data.id}`];
        const mapBtnHtml = hasCoords
            ? `<button type="button" class="device-popup-link-map-btn${linksVisible ? ' device-popup-tab-active' : ''}" data-contact-id="${this.data.id}" data-popup-action="collector-links">${linksVisible ? 'Hide from map' : 'Show on map'}</button>`
            : '';

        return `
            <div class="device-popup-section">
                <div class="device-popup-section-head">
                    <div class="device-popup-section-title">${escapeXml(title)}</div>
                    ${mapBtnHtml}
                </div>
                ${rowsHtml}
            </div>
        `;
    }

    getReporterStatusSectionHtml() {
        const reporter = this.isReporter();
        if (!reporter?.getStatus) return '';

        const status = reporter.getStatus();
        if (!status || typeof status !== 'object') return '';

        const stats = (status.stats && typeof status.stats === 'object') ? status.stats : {};
        const timestampRaw = status.updated_at ?? status.timestamp ?? status.timestamp_ms ?? null;
        const timestamp = typeof timestampRaw === 'number'
            ? new Date(timestampRaw).toLocaleString()
            : this.formatPopupTimestamp(timestampRaw);

        const isMeaningfulValue = (value) => {
            if (value === null || value === undefined) return false;
            if (typeof value === 'number') return Number.isFinite(value);
            if (typeof value !== 'string') return true;
            const normalized = value.trim().toLowerCase();
            return normalized !== '' && normalized !== '-' && normalized !== 'unknown';
        };

        const createRows = () => {
            const rows = [];
            const addRow = (key, value) => {
                if (!isMeaningfulValue(value)) return;
                rows.push([key, value]);
            };
            return { rows, addRow };
        };

        const basic = createRows();
        const addBasicRow = (key, value) => {
            basic.addRow(key, value);
        };

        addBasicRow('Status', status.status ?? null);
        addBasicRow('Updated', timestamp);
        addBasicRow('Origin', status.origin ?? null);
        addBasicRow('Origin ID', status.origin_id ?? null);
        addBasicRow('IATA', status.iata ?? null);
        addBasicRow('Model', status.model ?? null);
        addBasicRow('Firmware', status.firmware_version ?? null);
        addBasicRow('Client', status.client_version ?? null);

        const health = createRows();
        const addHealthRow = (key, value) => {
            health.addRow(key, value);
        };

        addHealthRow('Radio', status.radio ?? null);

        const rssiValue = Number.isFinite(Number(status.rssi))
            ? `${Number(status.rssi)} dBm`
            : (Number.isFinite(Number(stats.last_rssi)) ? `${Number(stats.last_rssi)} dBm` : null);
        addHealthRow('RSSI', rssiValue);

        const snrValue = Number.isFinite(Number(stats.last_snr))
            ? `${Number(stats.last_snr)} dB`
            : null;
        addHealthRow('SNR', snrValue);

        const batteryValue = Number.isFinite(Number(stats.battery_mv))
            ? `${Number(stats.battery_mv)} mV`
            : null;
        addHealthRow('Battery', batteryValue);

        const uptimeFormatted = this.formatUptimeSeconds(status.uptime ?? stats.uptime_secs ?? null);
        addHealthRow('Uptime', uptimeFormatted === '-' ? null : uptimeFormatted);

        const queueValue = Number.isFinite(Number(stats.queue_len))
            ? String(Number(stats.queue_len))
            : null;
        addHealthRow('Queue', queueValue);

        const errorsValue = Number.isFinite(Number(stats.errors))
            ? String(Number(stats.errors))
            : null;
        addHealthRow('Errors', errorsValue);

        const renderRows = (rows) => rows
            .map(([key, value]) => `<div class="device-popup-row"><span class="device-popup-key">${escapeXml(String(key))}</span><span class="device-popup-value">${escapeXml(String(value ?? '-'))}</span></div>`)
            .join('');

        const sections = [];
        if (basic.rows.length > 0) {
            sections.push(`
                <div class="device-popup-section-title">Basic</div>
                ${renderRows(basic.rows)}
            `);
        }
        if (health.rows.length > 0) {
            sections.push(`
                <div class="device-popup-section-title">Radio / Health</div>
                ${renderRows(health.rows)}
            `);
        }

        if (sections.length === 0) return '';

        return `
            <div class="device-popup-section">
                <div class="device-popup-section-title">Collector Status</div>
                ${sections.join('')}
            </div>
        `;
    }

    getAdvertisementTrailSectionHtml() {
        if (!this.isClient()) return '';

        const trail = this._meshlog.getContactAdvertisementTrail(this.data.id);
        const mapId = `device-popup-adv-map-${this.data.id}`;
        const hasPoints = Array.isArray(trail.points) && trail.points.length > 0;

        let note = '';
        if (trail.hasError) {
            note = 'Unable to load advertisement coordinates right now.';
        } else if (trail.isLoading && !hasPoints) {
            note = 'Loading advertisement coordinates...';
        } else if (!hasPoints) {
            note = 'No advertisements with GPS coordinates recorded.';
        } else {
            note = `${trail.points.length} advertisement GPS point${trail.points.length === 1 ? '' : 's'}. Hover or click a dot for date/time.`;
        }

        return `
            <div class="device-popup-section">
                <div class="device-popup-section-title">Advertisement Trail</div>
                <div id="${mapId}" class="device-popup-mini-map${hasPoints ? '' : ' device-popup-mini-map-empty'}" data-contact-id="${this.data.id}"></div>
                <div class="device-popup-note">${escapeXml(note)}</div>
            </div>
        `;
    }

    getRouteTrailButtonHtml() {
        if (!this.isClient()) return '';
        const activeClass = this.isRouteTrailVisible() ? ' device-popup-tab-active' : '';
        const label = this.isRouteTrailVisible() ? 'Hide Routes' : 'Routes';
        return `<div class="device-popup-section"><button type="button" class="device-popup-neighbors-btn${activeClass}" data-contact-id="${this.data.id}" data-popup-action="routes">${label}</button></div>`;
    }

    getDevicePopupGeneralHtml() {
        const coords = Number.isFinite(Number(this.adv?.data?.lat)) && Number.isFinite(Number(this.adv?.data?.lon))
            ? `${Number(this.adv.data.lat).toFixed(5)}, ${Number(this.adv.data.lon).toFixed(5)}`
            : 'Unknown';
        const trailHtml = this.getAdvertisementTrailSectionHtml();
        const telemetryLines = this.getMarkerTelemetryLines();
        const telemetryHtml = telemetryLines.length > 0
            ? `<div class="device-popup-section"><div class="device-popup-section-title">Telemetry</div>${telemetryLines.map(line => `<div class="device-popup-list-row">${escapeXml(line)}</div>`).join('')}</div>`
            : '';
        const timeSyncHtml = this.hasReporterTimeSyncWarning()
            ? `<div class="device-popup-section device-popup-warning"><div class="device-popup-section-title">Clock Warning</div><div class="device-popup-note">${escapeXml(this.getTimeSyncWarningText())}</div></div>`
            : '';
        const reporterHtml = this.isReporter()
            ? `<div class="device-popup-row"><span class="device-popup-key">Reporter</span><span class="device-popup-value">Yes</span></div>`
            : '';
        const reportedByHtml = this.getReportedBySectionHtml();
        const reporterStatusHtml = this.getReporterStatusSectionHtml();
        const routeTrailButtonHtml = this.getRouteTrailButtonHtml();
        const neighborsHtml = !this.isClient()
            ? `<div class="device-popup-section"><button type="button" class="device-popup-neighbors-btn${this.neighbors_visible ? ' device-popup-tab-active' : ''}" data-contact-id="${this.data.id}" data-popup-action="neighbors">${this.neighbors_visible ? 'Hide Neighbors' : 'Show Neighbors'}</button></div>`
            : '';

        return `
            <div class="device-popup-section">
                <div class="device-popup-row"><span class="device-popup-key">Last heard</span><span class="device-popup-value">${escapeXml(this.last?.data?.created_at ?? '-')}</span></div>
                <div class="device-popup-row"><span class="device-popup-key">First seen</span><span class="device-popup-value">${escapeXml(this.data?.created_at ?? '-')}</span></div>
                <div class="device-popup-row"><span class="device-popup-key">Coordinates</span><span class="device-popup-value">${escapeXml(coords)}</span></div>
                ${reporterHtml}
            </div>
            <div class="device-popup-section">
                <div class="device-popup-section-title">Identity</div>
                <div class="device-popup-key-block">Public Key</div>
                <div class="device-popup-mono">${escapeXml(this.data?.public_key ?? '-')}</div>
            </div>
            ${reportedByHtml}
            ${reporterStatusHtml}
            ${routeTrailButtonHtml}
            ${trailHtml}
            ${telemetryHtml}
            ${timeSyncHtml}
            ${neighborsHtml}
        `;
    }

    getDevicePopupStatsHtml(statsWindowHours = 24) {
        const stats = this._meshlog.getContactPacketStats(this.data.id, statsWindowHours);
        const windowButtons = [1, 24, 36].map(hours => {
            const active = hours === statsWindowHours ? ' device-popup-range-active' : '';
            return `<button type="button" class="device-popup-range${active}" data-contact-id="${this.data.id}" data-popup-action="range" data-popup-value="${hours}">${hours}h</button>`;
        }).join('');

        const renderValue = (value) => {
            if (stats.isLoading && !stats.hasData) return 'Loading...';
            return escapeXml(String(value));
        };

        const noteLine = stats.hasError
            ? 'Unable to load long-term database stats right now.'
            : (stats.isLoading && !stats.hasData
                ? 'Loading long-term database history for this device.'
                : stats.note);

        const longTermLines = [
            `History source: ${renderValue(stats.sourceLabel)}`,
            `Packet history span: ${renderValue(stats.loadedSpanLabel)}`,
            `Packets total: ${renderValue(stats.totalLoaded)}`,
            `Last 1h: ${renderValue(stats.last1h)} packets`,
            `Last 24h: ${renderValue(stats.last24h)} packets`,
            `Last 36h: ${renderValue(stats.last36h)} packets`,
            `Newest packet: ${renderValue(stats.newestLabel)}`,
            `Oldest packet: ${renderValue(stats.oldestLabel)}`,
            `Packet mix: ${renderValue(stats.packetMixLabel)}`,
            ...(stats.reporterId != null ? [`Collector (reporter id): ${renderValue(stats.reporterId)}`] : []),
        ];

        return `
            <div class="device-popup-section device-popup-section-stats-head">
                <div class="device-popup-section-title">Activity Summary</div>
                ${longTermLines.map(line => `<div class="device-popup-list-row">${line}</div>`).join('')}
            </div>
            <div class="device-popup-section">
                <div class="device-popup-chart-head">
                    <div class="device-popup-section-title">Packets in last ${statsWindowHours}h</div>
                    <div class="device-popup-range-group">${windowButtons}</div>
                </div>
                <div class="device-popup-note">${escapeXml(noteLine)}</div>
                ${stats.chartSvg}
            </div>
        `;
    }

    getMarkerTooltip(options = {}) {
        const activeTab = options.tab ?? 'general';
        const statsWindowHours = Number(options.statsWindowHours ?? 24);
        const generalActive = activeTab === 'general' ? ' device-popup-tab-active' : '';
        const statsActive   = activeTab === 'stats'   ? ' device-popup-tab-active' : '';
        const healthActive  = activeTab === 'health'  ? ' device-popup-tab-active' : '';
        let bodyHtml;
        if (activeTab === 'stats') {
            bodyHtml = this.getDevicePopupStatsHtml(statsWindowHours);
        } else if (activeTab === 'health') {
            bodyHtml = this._meshlog.getDevicePopupHealthHtml(this.data.id);
        } else {
            bodyHtml = this.getDevicePopupGeneralHtml();
        }

        return `
            <div class="device-popup-card">
                <div class="device-popup-header">
                    <div class="device-popup-title">${escapeXml(this.adv.data.name)}</div>
                    <div class="device-popup-subtitle">[${escapeXml(this.hash)}]</div>
                </div>
                <div class="device-popup-badges">
                    <span class="device-popup-badge">${escapeXml(this.getContactTypeLabel())}</span>
                    <span class="device-popup-badge device-popup-badge-status">${escapeXml(this.getMarkerStatusLabel())}</span>
                </div>
                <div class="device-popup-tabs" data-contact-id="${this.data.id}">
                    <button type="button" class="device-popup-tab${generalActive}" data-contact-id="${this.data.id}" data-popup-action="tab" data-popup-value="general">General</button>
                    <button type="button" class="device-popup-tab${statsActive}" data-contact-id="${this.data.id}" data-popup-action="tab" data-popup-value="stats">Stats</button>
                    <button type="button" class="device-popup-tab${healthActive}" data-contact-id="${this.data.id}" data-popup-action="tab" data-popup-value="health">Health</button>
                </div>
                <div class="device-popup-panel">${bodyHtml}</div>
            </div>
        `;
    }

    addToMap(map) {
        if (this.marker) return;
        this.map = map;

        if (!this.hasValidCoordinates()) {
            return
        }

        let iconUrl = 'assets/img/tower.svg';
        let kl = 'marker-pin';
        const timeSyncWarning = this.hasReporterTimeSyncWarning();

        if (this.isReporter()) {
            iconUrl = 'assets/img/receipt.svg';
        } else if (this.isClient()) {
            iconUrl = 'assets/img/person.svg';
        } else if (this.isRepeater()) {
            iconUrl = 'assets/img/tower.svg';
        } else if (this.isRoom()) {
            iconUrl = 'assets/img/group.svg';
        } else if (this.isSensor()) {
            iconUrl = 'assets/img/sensor.svg';
        } else {
            iconUrl = 'assets/img/unknown.svg';
        }

        const extractEmoji = (str) => {
            const emojiRegex = /\p{Extended_Pictographic}/u;
            const match = str.match(emojiRegex);
            return match ? match[0] : '';
        }

        let innerIcon;
        let emoji = extractEmoji(this.adv.data.name);
        if (emoji) {
            innerIcon = document.createElement('span');
            innerIcon.innerText = emoji;
        } else {
            innerIcon = document.createElement('img');
            innerIcon.src = iconUrl;
        }

        let warningBadge = null;
        if (timeSyncWarning) {
            warningBadge = document.createElement('span');
            warningBadge.classList.add('marker-warning-badge', 'time-sync-badge');
            warningBadge.textContent = '!';
        }

        let icdivroot = document.createElement("div");
        let icdivch1 = document.createElement("div");
        icdivch1.classList.add(kl);
        icdivroot.appendChild(icdivch1);
        icdivroot.appendChild(innerIcon);
        if (warningBadge) {
            icdivroot.appendChild(warningBadge);
        }

        innerIcon.classList.add('marker-icon-img');

        if (!this.isClient()) {
            if (this.isVeryExpired()) {
                icdivch1.classList.add("missing");
            } else if (this.isExpired()) {
                icdivch1.classList.add("ghosted");
            }
        }

        let icon = L.divIcon({
            className: 'custom-div-icon',
            html: icdivroot,
            iconSize: [30, 42],
            iconAnchor: [15, 42]
        });

        // Keep a live DOM reference so updateMarker() can add/remove the
        // time-sync badge without recreating the whole icon.
        this.markerIconRoot = icdivroot;

        this.marker = L.marker(
            [this.adv.data.lat, this.adv.data.lon],
            {
                icon: icon,
                pane: this.markerPane ?? this._meshlog.getMarkerPaneName(false)
            }
        ).addTo(map);
        this.marker.bindTooltip(this.getMiniTooltip(), {
            className: 'mini-tooltip',
            direction: 'auto',
            offset: [0, -10],
            sticky: false,
            interactive: false,
        });
        this.marker.on('click', () => {
            MeshLogContact._onMarkerClick.call(this, { target: this.marker });
        });
        this.marker.on('mouseover', () => {
            MeshLogContact._onMarkerMouseOver.call(this, { target: this.marker });
        });
        this.marker.on('mouseout', () => {
            MeshLogContact._onMarkerMouseOut.call(this, { target: this.marker });
        });
        this.markerPane = this.marker.options.pane;
        this.updateTooltip(this.markerTooltip);
    }

    getMiniTooltip() {
        return `<span class="mini-tooltip-label">${this.adv?.data?.name ?? this.data.public_key}</span>`;
    }

    static _onMarkerMouseOver(e) {
        // Keep the hover tooltip stable by reusing one bound tooltip instance.
        try {
            if (this._meshlog.selectedMarkerId === this.data.id) return;
            this._markerHoverActive = true;
            if (this._markerHoverCloseTimer) {
                clearTimeout(this._markerHoverCloseTimer);
                this._markerHoverCloseTimer = null;
            }
            const tooltip = this.marker.getTooltip();
            if (tooltip) {
                tooltip.setContent(this.getMiniTooltip());
            } else {
                this.marker.bindTooltip(this.getMiniTooltip(), {
                    className: 'mini-tooltip',
                    direction: 'auto',
                    offset: [0, -10],
                    sticky: false,
                    interactive: false,
                });
            }
            this.marker.openTooltip();
        } catch (err) { void err; }
    }

    static _onMarkerMouseOut(e) {
        try {
            if (this._meshlog.selectedMarkerId === this.data.id) return;
            this._markerHoverActive = false;
            if (this._markerHoverCloseTimer) {
                clearTimeout(this._markerHoverCloseTimer);
            }
            this._markerHoverCloseTimer = setTimeout(() => {
                if (this._meshlog.selectedMarkerId === this.data.id) return;
                if (this._markerHoverActive) return;
                this.marker.closeTooltip();
            }, 90);
        } catch (err) { void err; }
    }

    static _onMarkerClick(e) {
        try {
            const wasSelected = (this._meshlog.selectedMarkerId === this.data.id);
            if (wasSelected) {
                this._meshlog.clearSelection();
                return;
            }
            this._meshlog.focusContact(this);
        } catch (err) { void err; }
    }

    setMarkerPane(active) {
        if (!this.marker) return;

        const targetPane = this._meshlog.getMarkerPaneName(active);
        if (this.markerPane === targetPane) return;

        const wasTooltipOpen = !!this.marker.getTooltip() && this.marker.isTooltipOpen();
        // Ensure any open tooltip is closed and unbound before removing the marker
        this.marker?.closeTooltip();
        this.marker?.unbindTooltip();
        this.marker.remove();
        this.marker.options.pane = targetPane;
        this.marker.addTo(this._meshlog.map);
        this.markerPane = targetPane;
        this.updateTooltip(this.markerTooltip);
        if (wasTooltipOpen) {
            this.marker.openTooltip();
        }
    }

    updateTooltip(tooltip = undefined) {
        if (this.marker) {
            // Do not bind the full information as a tooltip (large box) —
            // we will show a compact mini-tooltip on hover and a popup on click.
            this.marker.unbindTooltip();
            if (tooltip === undefined) {
                tooltip = this.getMarkerTooltip();
            }
            this.markerTooltip = tooltip; // keep content for popups on click
        }
    }

    showLabel(show) {
        if (!this.marker) return;
        if (show) {
            try {
                const tooltip = this.marker.getTooltip();
                if (tooltip) {
                    tooltip.setContent(this.getMiniTooltip());
                } else {
                    this.marker.bindTooltip(this.getMiniTooltip(), {
                        className: 'mini-tooltip',
                        direction: 'auto',
                        offset: [0, -10],
                        sticky: false,
                        interactive: false,
                    });
                }
                this.marker.openTooltip();
            } catch (err) { void err; }
        } else {
            try {
                this.marker.closeTooltip();
            } catch (err) { void err; }
        }
    }

    __removeEmojis(str) {
            return str.replace(
                /([\u200D\uFE0F]|[\u2600-\u27BF]|\uD83C[\uDC00-\uDFFF]|\uD83D[\uDC00-\uDFFF]|\uD83E[\uDD00-\uDFFF])/g,
                ''
            );
    }

    updateDom() {
        if (!this.dom) return;
        if (!this.adv) return;

        let hashstr = this.hash;

        this.dom.container.dataset.type = this.adv.data.type;
        this.dom.container.dataset.time = this.last.time;
        this.dom.container.dataset.name = this.__removeEmojis(this.adv.data.name).trim();
        this.dom.container.dataset.hash = hashstr;
        this.dom.container.dataset.first_seen = parseMeshlogTimestamp(this.data.created_at);
        this.dom.details.hidden = !this.expanded;
        if (this.dom.btnShowNeighbors) {
            this.dom.btnShowNeighbors.innerText = this.neighbors_visible ? "Hide Neighbors" : "Show Neighbors";
            this.dom.btnShowNeighbors.classList.toggle("active", this.neighbors_visible);
        }
        if (this.dom.btnShowRoutes) {
            this.dom.btnShowRoutes.innerText = this.isRouteTrailVisible() ? "Hide Routes" : "Routes";
            this.dom.btnShowRoutes.classList.toggle("active", this.isRouteTrailVisible());
        }

        this.dom.contactDate.classList.remove("prio-5", "prio-6");
        this.dom.contactHash.classList.remove("prio-4", "prio-5");


        let type = '';
        if (this.isReporter()) {
            this.dom.contactIcon.src = "assets/img/receipt.svg";
            type = 'Collector';
        } else if (this.isClient()) {
            this.dom.contactIcon.src = "assets/img/person.svg";
            type = 'Chat';
        } else if (this.isRepeater()) {
            this.dom.contactIcon.src = "assets/img/tower.svg";
            type = 'Repeater';
        } else if (this.isRoom()) {
            this.dom.contactIcon.src = "assets/img/group.svg";
            type = 'Room';
        }  else if (this.isSensor()) {
            this.dom.contactIcon.src = "assets/img/sensor.svg";
            type = 'Sensor';
        } else {
            this.dom.contactIcon.src = "assets/img/unknown.svg";
        }

        this.dom.detailsType.innerHTML = `<span class="detail-name">Type:</span> <span class="detail-value">${type}</span>`;
        this.dom.detailsFirst.innerHTML = `<span class="detail-name">First Seen:</span> <span class="detail-value">${this.data.created_at}</span>`;
        this.dom.detailsKey.innerHTML = `<span class="detail-name">Public Key:</span> <span class="detail-value">${this.data.public_key}</span>`;

        this.dom.contactName.innerText = this.adv.data.name;
        this.dom.contactDate.innerText = this.last.data.created_at;
        this.dom.contactHash.innerText = `[${hashstr}]`;

        const timeSyncWarning = this.hasReporterTimeSyncWarning();
        this.dom.contactTimeSync.hidden = !timeSyncWarning;
        this.dom.contactTimeSync.textContent = timeSyncWarning ? '!' : '';
        createTooltip(this.dom.contactTimeSync, timeSyncWarning ? this.getTimeSyncWarningText() : '');

        if (this.telemetry) {
            let channels = {};
            for (let i=0;i<this.telemetry.length;i++) {
                const sensor = this.telemetry[i];

                if (!channels.hasOwnProperty(sensor.channel)) {
                    channels[sensor.channel] = {};
                }

                if (!channels[sensor.channel].hasOwnProperty(sensor.name)) {
                    channels[sensor.channel][sensor.name] = [];
                }

                channels[sensor.channel][sensor.name].push(sensor.value);
            }

            // build detail text
            let detail = [];
            let short = '';

            const addMeasurement = (src, dst, key, scale, precision, unit) => {
                if (!src.hasOwnProperty(key)) return;
                if (src[key].size < 1) return;

                let val = (src[key][0] / scale).toFixed(precision);
                let str = `${val}`;
                if (unit) str += ` ${unit}`;
                dst.push(str);
            }

            Object.entries(channels).forEach(([ch,data]) => {
                let meas = [];

                addMeasurement(data, meas, "voltage", 1, 2, "V");
                addMeasurement(data, meas, "current", 1, 3, "mA");
                addMeasurement(data, meas, "temperature", 1, 2,  "°C");
                addMeasurement(data, meas, "humidity", 2, 1, " %");
                addMeasurement(data, meas, "pressure", 1, 1, " hPa");

                if (data.hasOwnProperty("voltage")) {
                    short = `${data["voltage"][0].toFixed(2)} V`;
                }

                detail.push(meas);
            });

            const result = detail
                .filter(arr => arr.length > 0) // remove empty arrays
                .map((arr, i) => `Ch${i + 1}: ${arr.join(', ')}`) // format each remaining array
                .join('<br>'); // join lines

            if (result.length > 0) {
                this.dom.detailsTelemetry.innerHTML = `<span class="detail-name">Telemetry:</span> <span class="detail-value">${result}</span>`;
                this.dom.contactTelemetry.innerHTML = short;

            } else {
                this.dom.detailsTelemetry.innerHTML = '';
                this.dom.contactTelemetry.innerHTML = '';
            }
        }

        if (this.highlight) {
            this.dom.contactName.classList.add("chighlight");
        } else {
            this.dom.contactName.classList.remove("chighlight");
        }

    }

    updateMarker() {
        if (!this._meshlog?.map) return;

        if (!this.hasValidCoordinates()) {
            if (this.marker) {
                try {
                    this.marker.closeTooltip();
                    this.marker.unbindTooltip();
                    this.marker.remove();
                } catch (_error) {
                    void _error;
                }
                this.marker = null;
                this.markerPane = null;
                this.markerTooltip = undefined;
                this.markerIconRoot = null;
            }
            return;
        }

        if (!this.marker) {
            this.addToMap(this._meshlog.map);
            if (!this.marker || !this.markerIconRoot) return;
        }

        const lat = Number(this.adv.data.lat);
        const lon = Number(this.adv.data.lon);
        const current = this.marker.getLatLng();
        if (!current || current.lat !== lat || current.lng !== lon) {
            this.marker.setLatLng([lat, lon]);
        }

        if (!this.markerIconRoot) return;

        // Sync the time-sync warning badge (the red "!" on the map bubble).
        // This mirrors what updateDom() does for the sidebar badge: the icon DOM
        // is live (Leaflet reuses it across pane moves) so we can mutate it
        // directly without recreating the marker.
        const timeSyncWarning = this.hasReporterTimeSyncWarning();
        const badge = this.markerIconRoot.querySelector('.time-sync-badge');
        if (timeSyncWarning && !badge) {
            const newBadge = document.createElement('span');
            newBadge.classList.add('marker-warning-badge', 'time-sync-badge');
            newBadge.textContent = '!';
            this.markerIconRoot.appendChild(newBadge);
            this.updateTooltip(); // refresh tooltip text to include warning
        } else if (!timeSyncWarning && badge) {
            badge.remove();
            this.updateTooltip(); // refresh tooltip text to remove warning
        }
    }

    update() {
        this.updateDom();
        this.updateMarker();
    }

    // Returns true when this contact should be shown based on the active
    // collector (reporter) filter.  Uses data.reporter_ids provided by the
    // contacts API — the set of all reporters that have ever heard this node.
    isAllowedByCollectorFilter() {
        const raw = Settings.readCookie('reporterFilter.selected');
        if (raw === undefined) return true;  // no preference → show all
        if (!raw) return false;              // all deselected → show none
        const allowed = new Set(raw.split(',').map(s => s.trim()));
        const ids = this.data.reporter_ids ?? [];
        if (ids.length === 0) return true; // no reporter data yet → keep visible
        return ids.some(rid => allowed.has(String(rid)));
    }

    // Show or hide the Leaflet map marker based on the collector filter.
    // Safe to call any time; no-ops when no marker exists.
    syncMarkerVisibility() {
        if (!this.marker) return;
        const allowed = this.isAllowedByCollectorFilter();
        const onMap   = this._meshlog.map.hasLayer(this.marker);
        if (allowed && !onMap) {
            this.marker.addTo(this._meshlog.map);
            this.updateTooltip(); // re-bind tooltip after re-add
        } else if (!allowed && onMap) {
            // Close/unbind any tooltips before removing the marker to avoid lingering boxes
            this.marker?.closeTooltip();
            this.marker?.unbindTooltip();
            this.marker.remove();
        }
    }

    isClient() {
        return this.adv && this.adv.data.type == 1;
    }

    isRepeater() {
        return this.adv && this.adv.data.type == 2;
    }

    isRoom() {
        return this.adv && this.adv.data.type == 3;
    }

    isSensor() {
        return this.adv && this.adv.data.type == 4;
    }

    isReporter() {
        return this._meshlog.isReporter(this.data.public_key);
    }

    pathTag() { return 'c'; }

    checkHash(hash) {
        let chash = this.data.public_key.substr(0, hash.length);
        return chash.toUpperCase() === hash.toUpperCase();
    }

    isExpired() {
        if (!this.last) return true;

        let age = new Date().getTime() - this.last.time;
        return age > (3 * 24 * 60 * 60 * 1000); // 3 days
    }

    isVeryExpired() {
        if (!this.last) return true;

        let age = new Date().getTime() - this.last.time;
        return age > (7 * 24 * 60 * 60 * 1000); // 7 days
    }
}

// --- Hop-hash chip helpers (live feed path display) ---

let _hopTooltipEl = null;

function _getHopTooltipEl() {
    if (!_hopTooltipEl) {
        _hopTooltipEl = document.createElement('div');
        _hopTooltipEl.id = 'hop-name-tooltip';
        document.body.appendChild(_hopTooltipEl);
    }
    return _hopTooltipEl;
}

function _showHopTooltip(name, anchor) {
    const el = _getHopTooltipEl();
    el.textContent = name;
    el.style.display = 'block';
    const rect = anchor.getBoundingClientRect();
    el.style.left = `${rect.left}px`;
    el.style.top = `${rect.top - 28}px`;
}

function _hideHopTooltip() {
    if (_hopTooltipEl) _hopTooltipEl.style.display = 'none';
}

function _findAnyContactByHash(meshlog, hash) {
    for (const c of Object.values(meshlog.contacts)) {
        if (c.checkHash(hash)) return c;
    }
    return null;
}

class MeshLogReport {
    constructor(meshlog, data, contact_id, parent) {
        this._meshlog = meshlog;
        this.data = data;
        this.dom = null;
        this.contact_id = contact_id;
        this.polyline = [];
        this.parent = parent;
    }

    getPathLayerId() {
        const parentTag = this.parent?.getPathTag ? this.parent.getPathTag() : 'PKT';
        return `r_${parentTag}_${this.data.id}`;
    }

    showPath(animated = false) {
        let sender = this._meshlog.contacts[this.contact_id] ?? false;
        let receiver = this._meshlog.reporters[this.data.reporter_id];
        this._meshlog.showPath(this.getPathLayerId(), this.data.path, sender, receiver, this.getPreviewData(), animated);
    }

    hidePath() {
        this._meshlog.hidePath(this.getPathLayerId());
    }

    getPreviewData() {
        const sender = this._meshlog.contacts[this.contact_id] ?? false;
        const reporter = this._meshlog.reporters[this.data.reporter_id] ?? false;
        const hops = parsePath(this.data.path).length;
        const packetType = this.parent?.getPathTag ? this.parent.getPathTag() : 'PKT';

        return {
            title: `${packetType} live route`,
            subtitle: `${sender?.adv?.data?.name ?? sender?.data?.name ?? this.parent?.data?.name ?? 'Unknown sender'} → ${reporter?.data?.name ?? 'Unknown reporter'}`,
            accent: reporter ? (reporter.getStyle().color ?? '#4ea4c4') : '#4ea4c4',
            chips: [
                hops > 0 ? `${hops} hop${hops === 1 ? '' : 's'}` : 'Direct',
                this.data.snr ? `SNR ${this.data.snr}` : null
            ].filter(Boolean),
            footer: this.data.path ? `Path ${this.data.path}` : 'Path direct'
        };
    }

    createDom(recreate = false) {
        if (this.dom && !recreate) return this.dom;

        if (this.dom && this.dom.container && this.dom.container.parentNode) {
            this.dom.container.parentNode.removeChild(this.dom.container);
            this.dom = null;
        }

        let reporter = this._meshlog.reporters[this.data.reporter_id] ?? false;
        if (!reporter) return null;

        let divReport = document.createElement("div");
        let spDate = document.createElement("span");
        let spDot = document.createElement("span");
        let spPath = document.createElement("span");
        let spSnr = document.createElement("span");

        divReport.classList.add('log-entry');
        divReport.instance = this;
        spDate.classList.add(...['sp', 'cc']);
        spDot.classList.add(...['dot']);
        spPath.classList.add(...['sp']);
        spSnr.classList.add(...['sp']);

        let textColor = reporter.getStyle().color;
        let strokeColor = reporter.getStyle().stroke ?? textColor;
        let strokeWeight = reporter.getStyle().weight ?? '1px';
        spDot.innerText = reporter.data.name;
        spDot.style.color = textColor;
        spDot.style.border = `solid ${strokeWeight} ${strokeColor}`;

        spDate.innerText = this.data['created_at'].split(' ').pop();
        spSnr.innerText = this.data['snr'];

        const hashes = parsePath(this.data.path ?? '');
        if (hashes.length === 0) {
            spPath.textContent = 'direct';
        } else {
            hashes.forEach((hash, idx) => {
                if (idx > 0) spPath.appendChild(document.createTextNode(','));
                const chip = document.createElement('span');
                chip.className = 'hop-hash-chip';
                chip.textContent = hash;
                chip.addEventListener('mouseenter', (e) => {
                    const contact = _findAnyContactByHash(this._meshlog, hash);
                    const name = contact?.adv?.data?.name ?? contact?.data?.name ?? null;
                    if (name) _showHopTooltip(name, e.currentTarget);
                });
                chip.addEventListener('mouseleave', () => _hideHopTooltip());
                chip.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    const contact = _findAnyContactByHash(this._meshlog, hash);
                    if (contact) this._meshlog.focusContact(contact);
                });
                spPath.appendChild(chip);
            });
        }

        divReport.append(spDate);
        divReport.append(spDot);
        divReport.append(spPath);
        // divReport.append(spSnr);

        this.dom = {
            container: divReport
        }

        return this.dom;
    }

    static onmouseover(e) {
        if (this.parent) this.parent.setHighlight(true);
        this.showPath(true);
        this._meshlog.updatePaths();
    }

    static onmouseout(e) {
        if (this.parent) this.parent.setHighlight(false);
        if (this.parent.dom.input.show.checked) {
            this.showPath(false);
            this._meshlog.updatePaths();
            return;
        }
        this.hidePath();
        this._meshlog.updatePaths();
    }

    static oncontextmenu(e) {
        e.preventDefault();

        this._meshlog.dom_contextmenu
        const menu = this._meshlog.dom_contextmenu;

        while (menu.hasChildNodes()) {
            menu.removeChild(menu.lastChild);
        }

        // get paths

        let trk = '';
        let wpt = '';
        Object.entries(this._meshlog.layer_descs).forEach(([k,d]) => {
            for (const p of d.paths) {
                if (!trk) trk += `<trkpt lat="${p.from.lat}" lon="${p.from.lon}"></trkpt>\n`;
                trk += `<trkpt lat="${p.to.lat}" lon="${p.to.lon}"></trkpt>\n`;
            }

            for (const m of d.markers) {
                let c = this._meshlog.contacts[m];
                if (c && c.adv) {
                    wpt += `<wpt lat="${c.adv.data.lat}" lon="${c.adv.data.lon}"><name>${escapeXml(c.data.name)}</name></wpt>\n`;
                }
            }
        });

        trk = `<trk><trkseg>\n${trk}</trkseg></trk>`;

        let miGpx = document.createElement("div");
        miGpx.classList.add('menu-item');
        miGpx.innerText = "Export to GPX";
        miGpx.onclick = (e) => {
            let gpxContent = `<?xml version="1.0" encoding="UTF-8"?>\n`;
            gpxContent += `<gpx version="1.1" creator="Meshlog">\n`;

            gpxContent += wpt;
            gpxContent += trk;
            gpxContent += `</gpx>`;

            const blob = new Blob([gpxContent], { type: "application/gpx+xml" });
            const url = URL.createObjectURL(blob);
            const a = document.createElement("a");
            a.href = url;
            a.download = "location.gpx";
            a.click();
        };

        menu.appendChild(miGpx);

        menu.style.display = 'block';
        menu.style.left = `${e.pageX}px`;
        menu.style.top = `${e.pageY}px`;
    }
}

class MeshLogReportedObject extends MeshLogObject {
    constructor(meshlog, data) {
        let reports = data.reports ?? [];
        delete data.reports;

        super(meshlog, data);
        this.dom = null;
        this.expanded = false;
        this.time = parseMeshlogTimestamp(data.created_at);
        this.reports = [];

        for (let i=0; i<reports.length; i++) {
            let report = reports[i];
            this.reports.push(new MeshLogReport(meshlog, report, data.contact_id, this));
        }
    }

    merge(data) {
        let reports = data.reports ?? null;
        if (reports) delete data.reports;

        super.merge(data);

        if (reports) {
            this.reports = [];
            for (let i = 0; i < reports.length; i++) {
                this.reports.push(new MeshLogReport(this._meshlog, reports[i], this.data.contact_id, this));
            }
        }
    }

    // Override!
    getId()   { return `?_${this.data.id}`; }
    getDate() { return {text: "Not Implemented", classList: []}; } // date - 2025-10-10 10:00:00
    getTag()  { return {text: "Not Implemented", classList: []}; } // tag  - [PUBLIC] 
    getName() { return {text: "Not Implemented", classList: []}; } // name - Anrijs
    getText() { return {text: "Not Implemented", classList: []}; } // text - Hello mesh!
    isVisible() { return false; }

    getPathTag() { return "unk"; }

    resolveHashSize() {
        const dataHashSize = parseInt(this.data.hash_size ?? 0, 10);
        let derivedHashSize = 0;

        if (this.reports && this.reports.length > 0) {
            for (let i = 0; i < this.reports.length; i++) {
                const path = this.reports[i].data.path ?? '';
                if (!path) continue;
                const first = parsePath(path)[0] ?? '';
                const candidate = Math.floor(first.length / 2);
                if (candidate >= 1 && candidate <= 3) {
                    derivedHashSize = candidate;
                    break;
                }
            }
        }

        if (derivedHashSize >= 1 && derivedHashSize <= 3) return derivedHashSize;
        if (dataHashSize >= 1 && dataHashSize <= 3) return dataHashSize;
        return 0;
    }

    getHashSizeBadgeText() {
        const hashSize = this.resolveHashSize();
        if (hashSize >= 1 && hashSize <= 3) {
            return `${hashSize}b`;
        }
        return null;
    }

    getHashSizeBadgeTitle() {
        const hashSize = this.resolveHashSize();
        if (hashSize >= 1 && hashSize <= 3) {
            return `Routing hash prefix size: ${hashSize} byte${hashSize === 1 ? '' : 's'}`;
        }
        return '';
    }

    resolveScope() {
        const dataScope = parseInt(this.data.scope ?? NaN, 10);
        let derivedScope = NaN;

        if (this.reports && this.reports.length > 0) {
            for (let i = 0; i < this.reports.length; i++) {
                const candidate = parseInt(this.reports[i].data.scope ?? NaN, 10);
                if (Number.isFinite(candidate) && candidate >= 0 && candidate <= 255) {
                    derivedScope = candidate;
                    break;
                }
            }
        }

        if (Number.isFinite(derivedScope)) return derivedScope;
        if (Number.isFinite(dataScope) && dataScope >= 0 && dataScope <= 255) return dataScope;
        return null;
    }

    getScopeBadgeText() {
        const scope = this.resolveScope();
        if (scope === null || scope <= 0) return '*';
        return `${scope}`;
    }

    getScopeBadgeTitle() {
        const scope = this.resolveScope();
        if (scope === null || scope <= 0) {
            return 'Region scope: * (not set or wildcard)';
        }
        return `Region scope transport code: ${scope}`;
    }

    resolveHopRange() {
        if (!this.reports || this.reports.length === 0) return null;

        let minHops = Infinity;
        let maxHops = -Infinity;

        for (let i = 0; i < this.reports.length; i++) {
            const path = this.reports[i].data.path ?? '';
            const hops = parsePath(path).length;
            if (hops < minHops) minHops = hops;
            if (hops > maxHops) maxHops = hops;
        }

        if (!Number.isFinite(minHops) || !Number.isFinite(maxHops)) return null;
        return { min: minHops, max: maxHops };
    }

    getHopBadgeText() {
        const range = this.resolveHopRange();
        if (!range) return null;
        if (range.min === range.max) {
            return range.min === 0 ? 'dir' : `${range.min}h`;
        }
        return `${range.min}-${range.max}h`;
    }

    getHopBadgeTitle() {
        const range = this.resolveHopRange();
        if (!range) return '';
        if (range.min === range.max) {
            return range.min === 0
                ? 'Observed route length: direct reception with no relays'
                : `Observed route length: ${range.min} hop${range.min === 1 ? '' : 's'}`;
        }
        return `Observed route length range: ${range.min} to ${range.max} hops across grouped receptions`;
    }

    resolveBestSnr() {
        if (!this.reports || this.reports.length === 0) return null;

        let bestSnr = null;
        for (let i = 0; i < this.reports.length; i++) {
            const candidate = Number(this.reports[i].data.snr);
            if (!Number.isFinite(candidate)) continue;
            if (bestSnr === null || candidate > bestSnr) {
                bestSnr = candidate;
            }
        }

        return bestSnr;
    }

    formatSnr(value) {
        if (!Number.isFinite(value)) return '';
        return Number.isInteger(value) ? `${value}` : value.toFixed(1);
    }

    getSnrBadgeText() {
        const bestSnr = this.resolveBestSnr();
        if (!Number.isFinite(bestSnr)) return null;
        return `${this.formatSnr(bestSnr)}dB`;
    }

    getSnrBadgeTitle() {
        const bestSnr = this.resolveBestSnr();
        if (!Number.isFinite(bestSnr)) return '';
        return `Best observed SNR across grouped receptions: ${this.formatSnr(bestSnr)} dB`;
    }

    getSnrBadgePresentation() {
        return getSnrBadgePresentation(this.resolveBestSnr(), this.getSnrBadgeTitle());
    }

    getReportCountBadgeText() {
        const count = this.reports?.length ?? 0;
        if (count <= 0) return null;
        return `${count}rx`;
    }

    getReportCountBadgeTitle() {
        const count = this.reports?.length ?? 0;
        if (count <= 0) return '';
        return `Grouped receptions: heard by ${count} reporter${count === 1 ? '' : 's'}`;
    }

    updateMetaIndicators() {
        if (!this.dom) return;

        const hashSizeBadge = this.getHashSizeBadgeText();
        if (this.dom.hashSize) {
            this.dom.hashSize.innerText = hashSizeBadge ?? '';
            this.dom.hashSize.hidden = !hashSizeBadge;
            applyPresentation(this.dom.hashSize, { title: this.getHashSizeBadgeTitle() });
        }

        if (this.dom.scope) {
            this.dom.scope.innerText = this.getScopeBadgeText();
            applyPresentation(this.dom.scope, { title: this.getScopeBadgeTitle() });
        }

        if (this.dom.hops) {
            const hopBadge = this.getHopBadgeText();
            this.dom.hops.innerText = hopBadge ?? '';
            this.dom.hops.hidden = !hopBadge;
            applyPresentation(this.dom.hops, { title: this.getHopBadgeTitle() });
        }

        if (this.dom.snr) {
            const snrBadge = this.getSnrBadgeText();
            this.dom.snr.innerText = snrBadge ?? '';
            this.dom.snr.hidden = !snrBadge;
            applyPresentation(this.dom.snr, this.getSnrBadgePresentation());
        }

        if (this.dom.reportCount) {
            const reportCountBadge = this.getReportCountBadgeText();
            this.dom.reportCount.innerText = reportCountBadge ?? '';
            this.dom.reportCount.hidden = !reportCountBadge;
            applyPresentation(this.dom.reportCount, { title: this.getReportCountBadgeTitle() });
        }

        if (this.dom.prefix) {
            this.dom.prefix.textContent = '';
            this.dom.prefix.classList.remove('warn-icon');
            this.dom.prefix.removeAttribute('title');

            let sentAt = parseMeshlogTimestamp(this.data.sent_at);
            let receivedAt = NaN;
            if (this.reports && this.reports.length > 0 && Number.isFinite(sentAt)) {
                let minDelta = Infinity;
                for (let i = 0; i < this.reports.length; i++) {
                    let candidate = parseMeshlogTimestamp(this.reports[i].data.received_at);
                    if (!Number.isFinite(candidate)) continue;
                    let delta = Math.abs(sentAt - candidate);
                    if (delta < minDelta) {
                        minDelta = delta;
                        receivedAt = candidate;
                    }
                }
            }
            if (!Number.isFinite(receivedAt)) {
                receivedAt = parseMeshlogTimestamp(this.data.created_at);
            }

            if (Number.isFinite(sentAt) && Number.isFinite(receivedAt) && Math.abs(sentAt - receivedAt) > 1000 * 60 * 60 * 24 * 7) {
                this.dom.prefix.textContent = '⚠️';
                this.dom.prefix.classList.add('warn-icon');
                createTooltip(this.dom.prefix, `Clock out of sync. Sender time: ${this.data.sent_at}`);
            }
        }
    }

    createDom(recreate = false) {
        if (this.dom && !recreate) return this.dom;

        if (this.dom && this.dom.container && this.dom.container.parentNode) {
            this.dom.container.parentNode.removeChild(this.dom.container);
            this.dom = null;
        }

        // Containers
        let divContainer = document.createElement("div");
        let divLog = document.createElement("div");
        let divReports = document.createElement("div");
        divContainer.dataset.time = this.time;
        divContainer.dataset.type = this.data.type;

        divLog.classList = 'log-entry';
        divLog.instance = this;
        divReports.classList = 'log-entry-reports';
        divReports.hidden = true;

        divContainer.append(divLog);
        divContainer.append(divReports);

        // Lines
        let divLine1 = document.createElement("div");
        let divLine2 = document.createElement("div");
        divLine1.classList.add('log-entry-info');
        divLine2.classList.add('log-entry-msg');
        divLog.append(divLine1);
        divLog.append(divLine2);

        // Text values
        let spDate = document.createElement("span");
        let spTag = document.createElement("span");
        let spPrefix = document.createElement("span");
        let spName = document.createElement("span");
        let spText = document.createElement("span");

        let date = this.getDate();
        let tag = this.getTag();
        let name = this.getName();
        let text = this.getText();

        spDate.classList.add(...['sp', 'c']);
        spDate.classList.add(...date.classList);
        spDate.innerText = date.text;

        let spHashSize = document.createElement("span");
        let hashSizeBadge = this.getHashSizeBadgeText();
        spHashSize.classList.add('sp', 'hash-size-badge');
        spHashSize.innerText = hashSizeBadge ?? '';
        spHashSize.hidden = !hashSizeBadge;
        applyPresentation(spHashSize, { title: this.getHashSizeBadgeTitle() });

        let spScope = document.createElement("span");
        spScope.classList.add('sp', 'scope-badge');
        spScope.innerText = this.getScopeBadgeText();
        applyPresentation(spScope, { title: this.getScopeBadgeTitle() });

        let spHops = document.createElement("span");
        let hopBadge = this.getHopBadgeText();
        spHops.classList.add('sp', 'metric-badge');
        spHops.innerText = hopBadge ?? '';
        spHops.hidden = !hopBadge;
        applyPresentation(spHops, { title: this.getHopBadgeTitle() });

        let spSnr = document.createElement("span");
        let snrBadge = this.getSnrBadgeText();
        spSnr.classList.add('sp', 'metric-badge');
        spSnr.innerText = snrBadge ?? '';
        spSnr.hidden = !snrBadge;
        applyPresentation(spSnr, this.getSnrBadgePresentation());

        let spReportCount = document.createElement("span");
        let reportCountBadge = this.getReportCountBadgeText();
        spReportCount.classList.add('sp', 'metric-badge');
        spReportCount.innerText = reportCountBadge ?? '';
        spReportCount.hidden = !reportCountBadge;
        applyPresentation(spReportCount, { title: this.getReportCountBadgeTitle() });

        spTag.classList.add(...['sp', 'tag']);
        spTag.classList.add(...tag.classList);
        spTag.innerText = tag.text;
        applyPresentation(spTag, tag, tag.text);

        spName.classList.add(...['sp', 't']);
        spName.classList.add(...name.classList);
        spName.innerText = name.text;
        spName.style.background = name.background ?? '';

        spText.classList.add(...['sp']);
        spText.classList.add(...text.classList);
        spText.innerHTML = text.text.linkify();

        if (text.text) {
            // message (chat-like: direct or channel)
            divLine1.append(spDate);
            divLine1.append(spHashSize);
            divLine1.append(spHops);
            divLine1.append(spSnr);
            divLine1.append(spReportCount);
            divLine1.append(spPrefix);

            // Channel messages should show the scope next to the channel tag;
            // direct messages show the scope in the info line as before.
            if (this instanceof MeshLogChannelMessage) {
                divLine1.append(spTag);
                divLine1.append(spScope);
            } else {
                divLine1.append(spScope);
                divLine1.append(spTag);
            }

            divLine2.append(spName);
            divLine2.append(spText);
        } else {
            // advert — do not show region scope in advert lines
            divLine1.append(spDate);
            divLine1.append(spHashSize);
            divLine1.append(spHops);
            divLine1.append(spSnr);
            divLine1.append(spReportCount);
            // divLine1.append(spPrefix);
            // divLine1.append(spTag);
            divLine1.append(spName);
        }

        // Right
        let inputShow = document.createElement("input");
        inputShow.type = "checkbox";
        inputShow.classList.add(...['log-entry-cehckbox']);
        divLine1.appendChild(inputShow);

        inputShow.onclick = (e) => {
            e.stopPropagation();
            if (e.target.checked) {
                for (let i=0;i<this.reports.length;i++) {
                    this.reports[i].showPath(false);
                }
            } else {
                for (let i=0;i<this.reports.length;i++) {
                    this.reports[i].hidePath();
                }
            }
            this._meshlog.updatePaths();
        }

        this.dom = {
            container: divContainer,
            log: divLog,
            reports: divReports,
            date: spDate,
            tag: spTag,
            name: spName,
            text: spText,
            prefix: spPrefix,
            hashSize: spHashSize,
            scope: spScope,
            hops: spHops,
            snr: spSnr,
            reportCount: spReportCount,
            input: {
                show: inputShow
            }
        };

        this.updateMetaIndicators();

        return this.dom;
    }

    updateDom() {
        const date = this.getDate();
        const tag = this.getTag();
        const name = this.getName();
        const text = this.getText();

        this.dom.date.className = 'sp c';
        this.dom.date.classList.add(...date.classList);
        this.dom.date.innerText = date.text;

        this.dom.tag.className = 'sp tag';
        this.dom.tag.classList.add(...tag.classList);
        this.dom.tag.innerText = tag.text;
        this.dom.tag.style.cssText = '';
        applyPresentation(this.dom.tag, tag, tag.text);

        this.dom.name.className = 'sp t';
        this.dom.name.classList.add(...name.classList);
        this.dom.name.innerText = name.text;
        this.dom.name.style.background = name.background ?? '';

        this.dom.text.className = 'sp';
        this.dom.text.classList.add(...text.classList);
        this.dom.text.innerHTML = (text.text ?? '').linkify();

        if (this.highlight) {
            this.dom.log.classList.add("highlight");
        } else {
            this.dom.log.classList.remove("highlight");
        }

        this.dom.container.hidden = !this.isVisible();
        this.dom.reports.hidden = !this.expanded;
        this.updateMetaIndicators();

        if (this.expanded) {
            for (let i=0; i<this.reports.length; i++) {
                let report = this.reports[i];
                if (!this._meshlog.isReporterAllowed(report.data.reporter_id)) continue;
                let dom = report.createDom(false);
                if (dom) {
                    this.dom.reports.append(dom.container);
                }
            }
        } else {
            while (this.dom.reports.firstChild) {
                this.dom.reports.removeChild(this.dom.reports.firstChild);
            }
        }
    }

    isAdvertisement() { return this instanceof MeshLogAdvertisement; }
    isChannelMessage() { return this instanceof MeshLogChannelMessage; }
    isDirectMessage() { return this instanceof MeshLogDirectMessage; }

    static onclick(e) {
        this.expanded = !this.expanded;
        this.updateDom();

        // Show paths and fit the map to the route
        const ids = this.reports.map(r => r.getPathLayerId());
        for (let i = 0; i < this.reports.length; i++) {
            this.reports[i].showPath(false);
        }
        this._meshlog.fitToLayerDescs(ids);
        this._meshlog.updatePaths();
    }

    static onmouseover(e) {
        this.setHighlight(true);
        // show paths
        for (let i=0;i<this.reports.length;i++) {
            this.reports[i].showPath(true);
        }
        this._meshlog.updatePaths();
    }

    static onmouseout(e) {
        this.setHighlight(false);
        // hide path
        if (this.dom.input.show.checked) {
            for (let i=0;i<this.reports.length;i++) {
                this.reports[i].showPath(false);
            }
            this._meshlog.updatePaths();
            return;
        }
        for (let i=0;i<this.reports.length;i++) {
            this.reports[i].hidePath();
        }
        this._meshlog.updatePaths();
    }
}

class MeshLogAdvertisement extends MeshLogReportedObject {
    static idPrefix = "a";
    getId()   { return `a_${this.data.id}`; }
    getDate() { return {text: this.data.created_at, classList: []}; }
    getTag()  {
        return {
            text: 'ADVERT',
            classList: ['type-badge', 'type-badge-adv'],
            title: 'Advertisement frame from a node'
        };
    }
    getName() { return {text: this.data.name, classList: []}; }
    getText() { return {text: "", classList: []}; }
    getPathTag() { return "ADV"; }
    isVisible() {
        if (!Settings.getBool('messageTypes.advertisements', true)) return false;
        return this.reports.length === 0 || this.reports.some(r => this._meshlog.isReporterAllowed(r.data.reporter_id));
    }
}

class MeshLogChannelMessage extends MeshLogReportedObject {
    static idPrefix = "c";
    getTag()  {
        let chid = this.data.channel_id;
        let ch = this._meshlog.channels[chid] ?? false;
        let chname = ch ? ch.data.name : `Channel ${chid}`;
        let theme = getChannelTheme(ch ? `${ch.data.hash}:${ch.data.name}` : chname);
        return {
            text: chname,
            classList: ['tag-badge', 'channel-tag'],
            title: `Channel message in ${chname}. Color matches the channel filter badge.`,
            style: {
                background: theme.background,
                borderColor: theme.border,
                color: theme.text,
            }
        };
    }

    getId()   { return `c_${this.data.id}`; }
    getDate() { return {text: this.data.created_at, classList: []}; }
    getName() { return {text: `${this.data.name}`, classList: ['t-bright'], background: str2color(this.data.name)}; }
    getText() { return {text: this.data.message, classList: ['t-white']}; }
    getPathTag() { return "MSG"; }
    isVisible() {
        let chid = this.data.channel_id;
        let ch = this._meshlog.channels[chid] ?? false;
        if (ch) ch = ch.isEnabled();
        if (!(Settings.getBool('messageTypes.channel', true) && ch)) return false;
        return this.reports.length === 0 || this.reports.some(r => this._meshlog.isReporterAllowed(r.data.reporter_id));
    }
}

class MeshLogDirectMessage extends MeshLogReportedObject {
    static idPrefix = "d";
    getTag()  {
        let text = '→ unknown';
        if (this.reports.length > 0) {
            let repid = this.reports[0].data.reporter_id;
            let reporter = this._meshlog.reporters[repid] ?? false;
            if (reporter) {
                text = `→ ${reporter.data.name}`;
            }
        }
        return {
            text: text,
            classList: ['tag-badge', 'tag-badge-direct'],
            title: 'Direct message destination or receiving reporter'
        };
    }

    getId()   { return `d_${this.data.id}`; }
    getDate() { return {text: this.data.created_at, classList: []}; }
    getName() { return {text: `${this.data.name}`, classList: ['t-bright'], background: str2color(this.data.name) }; }
    getText() { return {text: this.data.message, classList: ['t-white']}; }
    getPathTag() { return "DIR"; }
    isVisible() {
        if (!Settings.getBool('messageTypes.direct', false)) return false;
        return this.reports.length === 0 || this.reports.some(r => this._meshlog.isReporterAllowed(r.data.reporter_id));
    }
}

class MeshLogTelemetryMessage extends MeshLogObject {
    static idPrefix = "t";

    constructor(meshlog, data) {
        super(meshlog, data);
        this.dom = null;
        this.time = parseMeshlogTimestamp(data.created_at);
    }

    getTelemetryRows() {
        try {
            const parsed = typeof this.data.data === 'string'
                ? JSON.parse(this.data.data || '[]')
                : (this.data.data ?? []);
            return Array.isArray(parsed) ? parsed : [];
        } catch (_error) {
            return [];
        }
    }

    getSummary() {
        const rows = this.getTelemetryRows();
        if (rows.length < 1) return 'Telemetry update';

        return rows
            .filter(row => row && row.name)
            .slice(0, 3)
            .map(row => `${row.name}: ${row.value}`)
            .join(', ');
    }

    createDom(recreate = false) {
        if (this.dom && !recreate) return this.dom;

        if (this.dom && this.dom.container && this.dom.container.parentNode) {
            this.dom.container.parentNode.removeChild(this.dom.container);
            this.dom = null;
        }

        const divContainer = document.createElement('div');
        const divLog = document.createElement('div');
        const divLine1 = document.createElement('div');
        const divLine2 = document.createElement('div');

        divContainer.dataset.time = this.time;
        divContainer.dataset.type = 'TEL';
        divLog.classList = 'log-entry';
        divLog.instance = this;
        divLine1.classList.add('log-entry-info');
        divLine2.classList.add('log-entry-msg');
        divLog.append(divLine1, divLine2);
        divContainer.append(divLog);

        const spDate = document.createElement('span');
        spDate.classList.add('sp', 'c');
        spDate.innerText = this.data.created_at;
        divLine1.append(spDate);

        const spTag = document.createElement('span');
        spTag.classList.add('sp', 'tag', 'type-badge');
        spTag.innerText = 'TEL';
        applyPresentation(spTag, { title: 'Telemetry packet with sensor readings' });
        divLine1.append(spTag);

        const reporter = this._meshlog.reporters[this.data.reporter_id] ?? null;
        if (reporter) {
            const spDot = document.createElement('span');
            spDot.classList.add('dot');
            const style = reporter.getStyle();
            const textColor = style.color;
            const strokeColor = style.stroke ?? textColor;
            const strokeWeight = style.weight ?? '1px';
            spDot.innerText = reporter.data.name;
            spDot.style.color = textColor;
            spDot.style.border = `solid ${strokeWeight} ${strokeColor}`;
            divLine1.append(spDot);
        }

        const contact = this.data.contact_id ? this._meshlog.contacts[this.data.contact_id] ?? null : null;
        const spName = document.createElement('span');
        spName.classList.add('sp', 't');
        spName.innerText = contact?.adv?.data?.name ?? contact?.data?.name ?? 'Unknown node';
        divLine1.append(spName);

        const spText = document.createElement('span');
        spText.classList.add('m');
        spText.innerText = this.getSummary();
        divLine2.append(spText);

        this.dom = { container: divContainer, log: divLog };
        return this.dom;
    }

    updateDom() {
        if (!this.dom) return;
        this.dom.container.hidden = !this.isVisible();
    }

    isVisible() {
        return Settings.getBool('messageTypes.telemetry', false)
            && this._meshlog.isReporterAllowed(this.data.reporter_id);
    }
}

class MeshLogSystemReportMessage extends MeshLogObject {
    static idPrefix = "s";

    constructor(meshlog, data) {
        super(meshlog, data);
        this.dom = null;
        this.time = parseMeshlogTimestamp(data.created_at);
    }

    formatUptime(seconds) {
        const value = Number(seconds);
        if (!Number.isFinite(value) || value < 0) return '';
        const days = Math.floor(value / 86400);
        const hours = Math.floor((value % 86400) / 3600);
        const minutes = Math.floor((value % 3600) / 60);
        if (days > 0) return `${days}d ${hours}h`;
        if (hours > 0) return `${hours}h ${minutes}m`;
        return `${minutes}m`;
    }

    getSummary() {
        const parts = [];
        if (this.data.version) parts.push(`v${this.data.version}`);
        if (Number.isFinite(Number(this.data.rssi))) parts.push(`RSSI ${this.data.rssi}`);
        if (Number.isFinite(Number(this.data.heap_free)) && Number.isFinite(Number(this.data.heap_total))) {
            parts.push(`heap ${this.data.heap_free}/${this.data.heap_total}`);
        }
        if (Number.isFinite(Number(this.data.uptime))) parts.push(`uptime ${this.formatUptime(this.data.uptime)}`);
        return parts.join(' · ') || 'System report';
    }

    createDom(recreate = false) {
        if (this.dom && !recreate) return this.dom;

        if (this.dom && this.dom.container && this.dom.container.parentNode) {
            this.dom.container.parentNode.removeChild(this.dom.container);
            this.dom = null;
        }

        const divContainer = document.createElement('div');
        const divLog = document.createElement('div');
        const divLine1 = document.createElement('div');
        const divLine2 = document.createElement('div');

        divContainer.dataset.time = this.time;
        divContainer.dataset.type = 'SYS';
        divLog.classList = 'log-entry';
        divLog.instance = this;
        divLine1.classList.add('log-entry-info');
        divLine2.classList.add('log-entry-msg');
        divLog.append(divLine1, divLine2);
        divContainer.append(divLog);

        const spDate = document.createElement('span');
        spDate.classList.add('sp', 'c');
        spDate.innerText = this.data.created_at;
        divLine1.append(spDate);

        const spTag = document.createElement('span');
        spTag.classList.add('sp', 'tag', 'type-badge');
        spTag.innerText = 'SYS';
        applyPresentation(spTag, { title: 'System/self report from a reporter device' });
        divLine1.append(spTag);

        const reporter = this._meshlog.reporters[this.data.reporter_id] ?? null;
        const spName = document.createElement('span');
        spName.classList.add('sp', 't');
        spName.innerText = reporter?.data?.name ?? 'Unknown reporter';
        divLine1.append(spName);

        const spText = document.createElement('span');
        spText.classList.add('m');
        spText.innerText = this.getSummary();
        divLine2.append(spText);

        this.dom = { container: divContainer, log: divLog };
        return this.dom;
    }

    updateDom() {
        if (!this.dom) return;
        this.dom.container.hidden = !this.isVisible();
    }

    isVisible() {
        return Settings.getBool('messageTypes.system', false)
            && this._meshlog.isReporterAllowed(this.data.reporter_id);
    }
}

class MeshLogRawPacket extends MeshLogObject {
    static idPrefix = "r";

    constructor(meshlog, data) {
        super(meshlog, data);
        this.dom = null;
        this.time = parseMeshlogTimestamp(data.created_at);
    }

    getPayloadType() {
        return (parseInt(this.data.header ?? 0, 10) >> 2) & 0x0F;
    }

    getTypeLabel() {
        switch (this.getPayloadType()) {
            case 0:  return 'REQ';   // PAYLOAD_TYPE_REQ      (encrypted request)
            case 1:  return 'RESP';  // PAYLOAD_TYPE_RESPONSE  (encrypted response)
            case 2:  return 'MSG';   // PAYLOAD_TYPE_TXT_MSG   (encrypted direct message)
            case 3:  return 'ACK';   // PAYLOAD_TYPE_ACK       (4-byte CRC acknowledgment)
            case 4:  return 'ADV';   // PAYLOAD_TYPE_ADVERT    (node advertisement fallback)
            case 5:  return 'PUB';   // PAYLOAD_TYPE_GRP_TXT   (group text, undecryptable)
            case 6:  return 'GRP DATA'; // PAYLOAD_TYPE_GRP_DATA (group datagram)
            case 7:  return 'ANON';  // PAYLOAD_TYPE_ANON_REQ  (anonymous request)
            case 8:  return 'PATH';  // PAYLOAD_TYPE_PATH      (returned path)
            case 9:  return 'TRACE'; // PAYLOAD_TYPE_TRACE     (path trace with SNR)
            case 10: return 'MULTI'; // PAYLOAD_TYPE_MULTIPART (multi-part fragment)
            case 11: return 'CTRL';  // PAYLOAD_TYPE_CONTROL   (control / discovery)
            case 15: return 'CUST';  // PAYLOAD_TYPE_RAW_CUSTOM (app-defined)
            default: return 'PKT';
        }
    }

    // Returns the decoded metadata JSON (parsed from hex payload) when decoded=true.
    _getDecodedMeta() {
        if (!this.data.decoded) return null;
        const hex = this.data.payload ?? '';
        if (!hex) return null;
        try {
            const bytes = new Uint8Array(hex.match(/.{1,2}/g).map(b => parseInt(b, 16)));
            return JSON.parse(new TextDecoder().decode(bytes));
        } catch (_e) {
            return null;
        }
    }

    // Returns a human-readable summary of decoded packet metadata, or null.
    _getDecodedText() {
        const meta = this._getDecodedMeta();
        if (!meta) return null;
        switch (this.getPayloadType()) {
            case 0: // REQ
            case 1: // RESP
                if (meta.dest_hash !== undefined) {
                    return `\u2192\u00A0${meta.dest_hash}\u2002\u2190\u00A0${meta.src_hash ?? '?'}`;
                }
                return null;
            case 3: // ACK
                return meta.crc ? `CRC\u00A0${meta.crc}` : null;
            case 7: // ANON_REQ
                if (meta.dest_hash !== undefined) {
                    const from = meta.sender_pubkey ? meta.sender_pubkey.slice(0, 8) : '?';
                    return `\u2192\u00A0${meta.dest_hash}\u2002from\u00A0${from}`;
                }
                return null;
            case 8: // PATH
                if (meta.returned_path !== undefined) {
                    const pts = (meta.returned_path ?? []);
                    const pathStr = pts.length ? pts.join('\u2192') : 'direct';
                    return `path\u00A0${pathStr}\u2002extra\u00A0${meta.extra_type ?? '?'}`;
                }
                return null;
            case 11: { // CTRL
                const names = {8: 'DISC_REQ', 9: 'DISC_RESP'};
                const subName = names[meta.sub_type] ?? `sub_type\u00A0${meta.sub_type ?? '?'}`;
                const parts = [subName];
                if (meta.type_filter !== undefined) parts.push(`filter\u00A0${meta.type_filter}`);
                if (meta.tag !== undefined) parts.push(`tag\u00A0${meta.tag}`);
                if (meta.node_type !== undefined) parts.push(`node\u00A0${meta.node_type}`);
                if (meta.discover_snr !== undefined) parts.push(`snr\u00A0${meta.discover_snr}`);
                if (meta.pubkey_prefix !== undefined) parts.push(`key\u00A0${meta.pubkey_prefix.slice(0, 8)}`);
                return parts.join('\u2002');
            }
            default:
                return null;
        }
    }

    getHashSizeBadgeText() {
        const hashSize = this.resolveHashSize();
        if (hashSize >= 1 && hashSize <= 3) {
            return `${hashSize}b`;
        }
        return null;
    }

    getHashSizeBadgeTitle() {
        const hashSize = this.resolveHashSize();
        if (hashSize >= 1 && hashSize <= 3) {
            return `Raw packet path hash prefix size: ${hashSize} byte${hashSize === 1 ? '' : 's'}`;
        }
        return '';
    }

    resolveScope() {
        const scope = parseInt(this.data.scope ?? NaN, 10);
        if (Number.isFinite(scope) && scope >= 0 && scope <= 255) return scope;
        return null;
    }

    getScopeBadgeText() {
        const scope = this.resolveScope();
        if (scope === null || scope <= 0) return '*';
        return `${scope}`;
    }

    getScopeBadgeTitle() {
        const scope = this.resolveScope();
        if (scope === null || scope <= 0) {
            return 'Region scope: * (not set or wildcard)';
        }
        return `Region scope transport code: ${scope}`;
    }

    getHopBadgeText() {
        const hops = parsePath(this.data.path ?? '').length;
        return hops === 0 ? 'dir' : `${hops}h`;
    }

    getHopBadgeTitle() {
        const hops = parsePath(this.data.path ?? '').length;
        return hops === 0
            ? 'Observed route length: direct reception with no relays'
            : `Observed route length: ${hops} hop${hops === 1 ? '' : 's'}`;
    }

    getSnrBadgeText() {
        const snr = Number(this.data.snr);
        if (!Number.isFinite(snr)) return null;
        return `${Number.isInteger(snr) ? snr : snr.toFixed(1)}dB`;
    }

    getSnrBadgeTitle() {
        const snr = Number(this.data.snr);
        if (!Number.isFinite(snr)) return '';
        return `Observed SNR at receiving reporter: ${Number.isInteger(snr) ? snr : snr.toFixed(1)} dB`;
    }

    getSnrBadgePresentation() {
        return getSnrBadgePresentation(Number(this.data.snr), this.getSnrBadgeTitle());
    }

    getReportCountBadgeText() {
        return '1rx';
    }

    getReportCountBadgeTitle() {
        return 'Stored raw packet heard by 1 reporter';
    }

    resolveHashSize() {
        const dataHashSize = parseInt(this.data.hash_size ?? 0, 10);
        const path = this.data.path ?? '';
        if (path) {
            const first = parsePath(path)[0] ?? '';
            const derivedHashSize = Math.floor(first.length / 2);
            if (derivedHashSize >= 1 && derivedHashSize <= 3) {
                return derivedHashSize;
            }
        }
        if (dataHashSize >= 1 && dataHashSize <= 3) return dataHashSize;
        return 0;
    }

    createDom(recreate = false) {
        if (this.dom && !recreate) return this.dom;

        if (this.dom && this.dom.container && this.dom.container.parentNode) {
            this.dom.container.parentNode.removeChild(this.dom.container);
            this.dom = null;
        }

        let divContainer = document.createElement("div");
        let divLog = document.createElement("div");
        let divLine = document.createElement("div");

        divContainer.dataset.time = this.time;
        divLog.classList = 'log-entry';
        divLog.instance = this;
        divLine.classList.add('log-entry-info');

        divLog.append(divLine);
        divContainer.append(divLog);

        let spDate = document.createElement("span");
        spDate.classList.add('sp', 'c');
        spDate.innerText = this.data.created_at;
        divLine.append(spDate);

        let spHashSize = document.createElement("span");
        let hashSizeBadge = this.getHashSizeBadgeText();
        spHashSize.classList.add('sp', 'hash-size-badge');
        spHashSize.innerText = hashSizeBadge ?? '';
        spHashSize.hidden = !hashSizeBadge;
        applyPresentation(spHashSize, { title: this.getHashSizeBadgeTitle() });
        divLine.append(spHashSize);

        let spScope = document.createElement("span");
        spScope.classList.add('sp', 'scope-badge');
        spScope.innerText = this.getScopeBadgeText();
        applyPresentation(spScope, { title: this.getScopeBadgeTitle() });

        let spHops = document.createElement("span");
        spHops.classList.add('sp', 'metric-badge');
        spHops.innerText = this.getHopBadgeText();
        applyPresentation(spHops, { title: this.getHopBadgeTitle() });
        divLine.append(spHops);

        let spSnr = document.createElement("span");
        let snrBadge = this.getSnrBadgeText();
        spSnr.classList.add('sp', 'metric-badge');
        spSnr.innerText = snrBadge ?? '';
        spSnr.hidden = !snrBadge;
        applyPresentation(spSnr, this.getSnrBadgePresentation());
        divLine.append(spSnr);

        let spReportCount = document.createElement("span");
        spReportCount.classList.add('sp', 'metric-badge');
        spReportCount.innerText = this.getReportCountBadgeText();
        applyPresentation(spReportCount, { title: this.getReportCountBadgeTitle() });
        divLine.append(spReportCount);

        let spTag = document.createElement("span");
        spTag.classList.add('sp', 'tag', 'type-badge', 'type-badge-raw');
        spTag.innerText = this.getTypeLabel();
        applyPresentation(spTag, { title: 'Stored raw packet subtype derived from the packet header' }, this.getTypeLabel());
        // Do not show region scope for raw packets here; scope is only relevant for chat messages
        divLine.append(spTag);

        let reporter = this._meshlog.reporters[this.data.reporter_id] ?? false;
        if (reporter) {
            let spDot = document.createElement("span");
            spDot.classList.add('dot');
            let style = reporter.getStyle();
            let textColor = style.color;
            let strokeColor = style.stroke ?? textColor;
            let strokeWeight = style.weight ?? '1px';
            spDot.innerText = reporter.data.name;
            spDot.style.color = textColor;
            spDot.style.border = `solid ${strokeWeight} ${strokeColor}`;
            divLine.append(spDot);
        }

        let spPath = document.createElement("span");
        spPath.classList.add('sp');
        spPath.innerText = this.data.path || "direct";
        divLine.append(spPath);

        // Second line: decoded payload summary (only when PHP decoder stored metadata)
        const decodedText = this._getDecodedText();
        if (decodedText) {
            const divDecoded = document.createElement('div');
            divDecoded.classList.add('log-entry-msg', 'raw-decoded-line');
            const spDecoded = document.createElement('span');
            spDecoded.classList.add('sp', 'raw-decoded-text');
            spDecoded.textContent = decodedText;
            divDecoded.append(spDecoded);
            divLog.append(divDecoded);
        }

        this.dom = { container: divContainer, log: divLog };
        return this.dom;
    }

    updateDom() {
        if (!this.dom) return;
        this.dom.container.hidden = !this.isVisible();
    }

    isVisible() {
        if (!Settings.getBool('messageTypes.raw', false)) return false;
        if (!this._meshlog.isReporterAllowed(this.data.reporter_id)) return false;
        // Per-subtype filter (defaults to true — all subtypes shown when master Raw is on)
        return Settings.getBool(`messageTypes.rawtype.${this.getPayloadType()}`, true);
    }
}

class MeshLogLinkLayer {
    constructor(from, to, reporter, circle) {
        this.from = from;
        this.to = to;
        this.reporter = reporter;
        this.circle = circle;
    }
}


class MeshLog {

    static MAX_TRANSIENT_ROUTE_ANIMATIONS = 8;
    static MARKER_PANE_BACKGROUND = 'meshlog-marker-background';
    static MARKER_PANE_ROUTE = 'meshlog-marker-route';
    static ROUTE_PANE = 'meshlog-route-lines';
    static ROUTE_POINT_PANE = 'meshlog-route-points';
    static LIVE_ROUTE_PANE = 'meshlog-live-route-lines';

    constructor(map, logsid, contactsid, stypesid, sreportersid, scontactsid, warningid, errorid, contextmenuid) {
        this.reporters = {};
        this.contacts = {};
        this.channels = {};

        this.messages = {};

        this.map = map;
        this.layer_descs = {};
        this.link_layers = L.layerGroup([]);
        this.route_trail_layers = L.layerGroup([]);
        this.visible_markers = new Set();
        this._initialLoad = true;
        this.visible_contacts = {};
        this.links = {};
        this.canvas_renderer = L.canvas({ padding: 0.5 });
        this.routeAnimationFrames = [];
        this.transientRouteAnimations = [];
        this._initMapPanes();
        this.dom_logs = document.getElementById(logsid);
        this.dom_contacts = document.getElementById(contactsid);
        this.dom_warning = document.getElementById(warningid);
        this.dom_error = document.getElementById(errorid);
        this.dom_contextmenu = document.getElementById(contextmenuid);
        this.timer = false;
        this.autorefresh = 0;
        this.decor = true;

        // epoch of newest object
        this.latest = 0;
        this.window_active = true;
        this.new_messages = {};

        const self = this;

        window.onfocus = function () {
            self.window_active = true;
            self.clearNotifications();
        };

         
         window.onblur = function () {
            self.window_active = false;
         };
         

        this.dom_settings_types = document.getElementById(stypesid);
        this.dom_settings_reporters = document.getElementById(sreportersid);
        this.dom_settings_contacts = document.getElementById(scontactsid);
        this.dom_general_stats = document.getElementById('general-stats');

        this.__init_filter_layout();

        this.dom_contacts.addEventListener('click', this.handleMouseEvent);
        this.dom_contacts.addEventListener('mouseover', this.handleMouseEvent);
        this.dom_contacts.addEventListener('mouseout', this.handleMouseEvent);
        this.dom_contacts.addEventListener("contextmenu", this.handleMouseEvent);

        this.dom_logs.addEventListener('click', this.handleMouseEvent);
        this.dom_logs.addEventListener('mouseover', this.handleMouseEvent);
        this.dom_logs.addEventListener('mouseout', this.handleMouseEvent);
        this.dom_logs.addEventListener("contextmenu", this.handleMouseEvent);

        const menu = this.dom_contextmenu;
        document.addEventListener('click', function () {
            menu.style.display = 'none'; // Hide when clicking anywhere
        });

        this.__init_message_types();
        this.__init_contact_order();
        this.__init_contact_types();
        this.__init_warnings();
        // Old search control disabled - search now integrated into unified map menu at top-right
        // this._initMapSearchControl();
        this._initMapMenuSearch();

        this.link_layers.addTo(this.map);
        this.route_trail_layers.addTo(this.map);
        this.popupUiState = { tab: 'general', statsWindowHours: 24 };
        this.contactPacketStatsCache = new Map();
        this.contactHealthCache = new Map();
        this.contactAdvertisementTrailCache = new Map();
        this.generalStatsCache = new Map();
        this.generalStatsWindowHours = this._normalizeStatsWindowHours(Number(Settings.get('stats.window.hours', 24)));
        this._heatmapEnabled = Settings.get('heatmap.enabled', '0') === '1';
        this.heatmapWindowHours = this._normalizeStatsWindowHours(Number(Settings.get('heatmap.window.hours', 24)));
        this._heatmapLayer = null;
        this._coverageEnabled = Settings.get('coverage.enabled', '0') === '1';
        this._coverageWindowHours = this._normalizeCoverageWindowHours(Number(Settings.get('coverage.window.hours', 168)));
        this._coverageLayer = null;
        this.activePopupContactId = null;
        this.activeContactTrailMap = null;
        this.activeContactTrailContactId = null;
        this.activeRouteTrailContactId = null;
        this.activeRouteTrailPointLayerId = null;
        this._boundPopupControlPointerHandler = (event) => this._handlePopupControlPointer(event);
        document.addEventListener('pointerdown', this._boundPopupControlPointerHandler, true);
        document.addEventListener('click', this._boundPopupControlPointerHandler, true);
        this._initChartBarTooltip();
        this._popupStatsRefreshTimer = setInterval(() => {
            if (this.selectedMarkerId && this.popupUiState?.tab === 'stats') {
                this.updateSelectedContactPopup();
            }
        }, 60 * 60 * 1000);

        // Close lingering marker tooltips when the map is interactively moved or zoomed.
        // Use a debounced prune to avoid heavy DOM churn during continuous events.
        const prune = () => { this.closeAllMarkerTooltips(true); };
        ['zoomstart','movestart','zoomend','moveend','viewreset'].forEach(ev => {
            this.map.on(ev, prune);
        });

        // Clear selection when clicking on the map background
        this.map.on('click', () => { this.clearSelection(); this.closeAllMarkerTooltips(true); });

        this.last = '2025-01-01 00:00:00';
    }

    _initMapSearchControl() {
        // Old standalone search control - kept disabled since search is now integrated into unified menu
        // This method is preserved for backward compatibility but no longer called
        const control = L.control({ position: 'topleft' });

        control.onAdd = () => {
            const root = document.createElement('div');
            root.className = 'map-search-control leaflet-bar';

            const input = document.createElement('input');
            input.type = 'search';
            input.className = 'map-search-input';
            input.placeholder = 'Search node';
            input.autocomplete = 'off';
            input.spellcheck = false;

            const results = document.createElement('div');
            results.className = 'map-search-results';
            results.hidden = true;

            root.append(input);
            root.append(results);

            L.DomEvent.disableClickPropagation(root);
            L.DomEvent.disableScrollPropagation(root);

            input.addEventListener('input', () => {
                this._renderMapSearchResults(input.value);
            });

            input.addEventListener('focus', () => {
                if (input.value.trim()) {
                    this._renderMapSearchResults(input.value);
                }
            });

            input.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    this._hideMapSearchResults();
                    input.blur();
                    return;
                }

                if (event.key !== 'Enter') return;
                event.preventDefault();

                const items = this._getSearchableContacts(input.value);
                if (items.length < 1) return;
                this.previewContact(items[0]);
                this._hideMapSearchResults();
            });

            this._mapSearch = {
                control,
                root,
                input,
                results,
            };

            return root;
        };

        control.addTo(this.map);
    }

    _initMapMenuSearch() {
        const menuSearchInput = document.getElementById('map-menu-search-input');
        if (!menuSearchInput) return;

        // Mount the dropdown on <body> so it escapes overflow:auto clipping on the panel
        const results = document.createElement('div');
        results.className = 'map-search-results map-menu-search-dropdown';
        results.hidden = true;
        document.body.appendChild(results);

        this._mapMenuSearch = { input: menuSearchInput, results, activeIndex: -1 };

        const reposition = () => {
            const r = menuSearchInput.getBoundingClientRect();
            results.style.position = 'fixed';
            results.style.top  = (r.bottom + 5) + 'px';
            results.style.left = r.left + 'px';
            results.style.width = r.width + 'px';
        };

        const hide = () => {
            results.hidden = true;
            results.innerHTML = '';
            this._mapMenuSearch.activeIndex = -1;
        };

        const pickContact = (contact) => {
            this.focusContact(contact);
            menuSearchInput.value = '';
            hide();
        };

        menuSearchInput.addEventListener('input', () => {
            this._renderMapSearchResultsInMenu(menuSearchInput.value, reposition);
        });

        menuSearchInput.addEventListener('focus', () => {
            if (menuSearchInput.value.trim()) {
                this._renderMapSearchResultsInMenu(menuSearchInput.value, reposition);
            }
        });

        menuSearchInput.addEventListener('keydown', (e) => {
            const items = results.querySelectorAll('.map-search-result');

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                this._mapMenuSearch.activeIndex = Math.min(
                    this._mapMenuSearch.activeIndex + 1, items.length - 1
                );
                this._updateMenuSearchActiveItem(items);
                return;
            }
            if (e.key === 'ArrowUp') {
                e.preventDefault();
                this._mapMenuSearch.activeIndex = Math.max(
                    this._mapMenuSearch.activeIndex - 1, -1
                );
                this._updateMenuSearchActiveItem(items);
                return;
            }
            if (e.key === 'Enter') {
                e.preventDefault();
                const idx = this._mapMenuSearch.activeIndex;
                const contactId = idx >= 0 && items[idx]
                    ? Number(items[idx].dataset.contactId)
                    : Number(items[0]?.dataset.contactId ?? NaN);
                const contact = Number.isFinite(contactId) ? (this.contacts[contactId] ?? null) : null;
                if (contact) pickContact(contact);
                return;
            }
            if (e.key === 'Escape') {
                e.preventDefault();
                hide();
                menuSearchInput.blur();
            }
        });

        // Close on click outside
        document.addEventListener('pointerdown', (e) => {
            if (!menuSearchInput.contains(e.target) && !results.contains(e.target)) {
                hide();
            }
        }, { capture: true });

        this._mapMenuSearchPick = pickContact;
    }

    _updateMenuSearchActiveItem(items) {
        items.forEach((el, i) => {
            el.classList.toggle('map-search-result-active', i === this._mapMenuSearch.activeIndex);
            if (i === this._mapMenuSearch.activeIndex) {
                el.scrollIntoView({ block: 'nearest' });
            }
        });
    }

    _renderMapSearchResultsInMenu(query, reposition) {
        const s = this._mapMenuSearch;
        if (!s?.results) return;

        const needle = this._normalizeSearchText(query);
        if (!needle) {
            s.results.hidden = true;
            s.results.innerHTML = '';
            s.activeIndex = -1;
            return;
        }

        const MAX_RESULTS = 8;
        const items = this._getSearchableContacts(query).slice(0, MAX_RESULTS);

        if (items.length === 0) {
            s.results.hidden = true;
            s.results.innerHTML = '';
            s.activeIndex = -1;
            return;
        }

        s.activeIndex = -1;
        s.results.innerHTML = '';

        for (const contact of items) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'map-search-result';
            btn.dataset.contactId = String(contact.data.id);

            const name = document.createElement('span');
            name.className = 'map-search-result-name';
            name.textContent = contact.adv?.data?.name ?? contact.data.public_key ?? '—';

            const meta = document.createElement('span');
            meta.className = 'map-search-result-meta';
            const parts = [];
            if (contact.hash) parts.push(contact.hash);
            const lat = Number(contact.adv?.data?.lat);
            const lon = Number(contact.adv?.data?.lon);
            if (Number.isFinite(lat) && Number.isFinite(lon) && !(lat === 0 && lon === 0)) {
                parts.push(`${lat.toFixed(3)}, ${lon.toFixed(3)}`);
            }
            meta.textContent = parts.join(' · ');

            btn.append(name, meta);
            btn.addEventListener('pointerdown', (e) => {
                e.preventDefault(); // keep input focused until pick
                if (this._mapMenuSearchPick) this._mapMenuSearchPick(contact);
            });
            s.results.appendChild(btn);
        }

        // Position then show — avoids flash at wrong location
        if (typeof reposition === 'function') reposition();
        s.results.hidden = false;
    }

    _normalizeSearchText(value) {
        return String(value ?? '').trim().toLowerCase();
    }

    _getSearchableContacts(query) {
        const needle = this._normalizeSearchText(query);
        if (!needle) return [];

        return Object.values(this.contacts)
            .filter(contact => contact?.adv && contact?.marker)
            .filter(contact => {
                const name = this._normalizeSearchText(contact.adv?.data?.name);
                const key = this._normalizeSearchText(contact.data?.public_key);
                const hash = this._normalizeSearchText(contact.hash);
                return name.includes(needle) || key.includes(needle) || hash.includes(needle);
            })
            .sort((a, b) => {
                const aName = this._normalizeSearchText(a.adv?.data?.name);
                const bName = this._normalizeSearchText(b.adv?.data?.name);
                return aName.localeCompare(bName);
            });
    }

    _hideMapSearchResults() {
        if (!this._mapSearch?.results) return;
        this._mapSearch.results.hidden = true;
        this._mapSearch.results.innerHTML = '';
    }

    _handlePopupControlPointer(event) {
        const target = event.target instanceof Element ? event.target : null;
        if (!target) return;

        const popupActionButton = target.closest('[data-popup-action]');
        if (popupActionButton) {
            event.preventDefault();
            event.stopPropagation();
            this.handlePopupAction(
                Number(popupActionButton.dataset.contactId),
                popupActionButton.dataset.popupAction,
                popupActionButton.dataset.popupValue
            );
            return;
        }

        const generalStatsRangeButton = target.closest('.general-stats-range');
        if (generalStatsRangeButton) {
            event.preventDefault();
            event.stopPropagation();
            this.setGeneralStatsWindowHours(Number(generalStatsRangeButton.dataset.hours));
            return;
        }

    }

    _initChartBarTooltip() {
        const tip = document.createElement('div');
        tip.id = 'chart-bar-tooltip';
        tip.setAttribute('aria-hidden', 'true');
        document.body.appendChild(tip);
        this._chartBarTooltipEl = tip;

        document.addEventListener('pointermove', (event) => {
            if (!(event.target instanceof Element)) return;
            const hit = event.target.closest('[data-chart-tooltip]');
            if (hit) {
                tip.textContent = hit.dataset.chartTooltip;
                const vw = window.innerWidth;
                const vh = window.innerHeight;
                let left = event.clientX + 14;
                let top  = event.clientY - 32;
                // After making visible, clamp to viewport
                tip.style.display = '';
                const tw = tip.offsetWidth;
                const th = tip.offsetHeight;
                if (left + tw > vw - 6) left = event.clientX - tw - 14;
                if (top < 6) top = event.clientY + 20;
                if (top + th > vh - 6) top = event.clientY - th - 6;
                tip.style.left = left + 'px';
                tip.style.top  = top + 'px';
            } else {
                tip.style.display = 'none';
            }
        }, { passive: true });

        // Hide when pointer leaves the document (e.g. moves to browser chrome)
        document.addEventListener('pointerleave', () => { tip.style.display = 'none'; });
    }

    handlePopupAction(contactId, action, value) {
        const targetContactId = Number.isFinite(contactId) ? contactId : Number(this.activePopupContactId);
        if (!Number.isFinite(targetContactId)) return;
        const contact = this.contacts[targetContactId] ?? null;
        if (!contact?.adv) return;

        if (action === 'tab') {
            const validTabs = ['general', 'stats', 'health'];
            this.popupUiState = {
                ...this.popupUiState,
                tab: validTabs.includes(value) ? value : 'general',
            };
            if (value === 'health') {
                this._loadContactHealth(targetContactId);
            }
            this.openContactPopup(contact);
            return;
        }

        if (action === 'range') {
            const hours = Number(value);
            if (![1, 24, 36].includes(hours)) return;
            this.popupUiState = {
                ...this.popupUiState,
                tab: 'stats',
                statsWindowHours: hours,
            };
            this.openContactPopup(contact);
            return;
        }

        if (action === 'neighbors') {
            contact.toggleNeighbors();
            this.popupUiState = {
                ...this.popupUiState,
                tab: 'general',
            };
            this.openContactPopup(contact);
            return;
        }

        if (action === 'collector-links') {
            contact.toggleCollectorLinks();
            this.popupUiState = {
                ...this.popupUiState,
                tab: 'general',
            };
            this.openContactPopup(contact);
            return;
        }

        if (action === 'routes') {
            this.toggleRouteTrail(contact);
            this.popupUiState = {
                ...this.popupUiState,
                tab: 'general',
            };
            this.openContactPopup(contact);
        }
    }

    _renderMapSearchResults(query) {
        if (!this._mapSearch?.results) return;

        const results = this._mapSearch.results;
        const items = this._getSearchableContacts(query);
        results.innerHTML = '';

        if (!query.trim() || items.length < 1) {
            results.hidden = true;
            return;
        }

        items.forEach(contact => {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'map-search-result';
            item.innerHTML = `
                <span class="map-search-result-name">${contact.adv?.data?.name ?? contact.data?.public_key ?? 'Unknown'}</span>
                <span class="map-search-result-meta">[${contact.hash}] ${contact.getContactTypeLabel()}</span>
            `;
            const activatePreview = (event) => {
                event.preventDefault();
                event.stopPropagation();
                this.previewContact(contact);
                this._hideMapSearchResults();
            };
            item.addEventListener('mousedown', activatePreview);
            item.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
            });
            results.append(item);
        });

        results.hidden = false;
    }

    focusContact(contact) {
        if (!contact?.marker || !contact?.adv) return;

        this.clearSelection();
        this.selectedMarkerId = contact.data.id;
        this.popupUiState = { tab: 'general', statsWindowHours: 24 };

        try {
            contact.marker.closeTooltip();
            if (contact._markerHoverCloseTimer) {
                clearTimeout(contact._markerHoverCloseTimer);
                contact._markerHoverCloseTimer = null;
            }
        } catch (_) { void _; }

        const latLng = [Number(contact.adv.data.lat), Number(contact.adv.data.lon)];
        const targetZoom = Math.max(this.map.getZoom(), 12);
        this.map.flyTo(latLng, targetZoom, { duration: 0.35 });

        this.openContactPopup(contact);

        this.visible_markers.clear();
        this.visible_markers.add(contact.data.id);
        this.fadeMarkers();
        contact.showLabel(true);

        if (this._mapSearch?.input) {
            this._mapSearch.input.value = contact.adv?.data?.name ?? '';
        }
    }

    previewContact(contact) {
        if (!contact?.marker || !contact?.adv) return;

        this.clearSelection();
        this.previewFocusedContactId = contact.data.id;

        try {
            contact.marker.closeTooltip();
            if (contact._markerHoverCloseTimer) {
                clearTimeout(contact._markerHoverCloseTimer);
                contact._markerHoverCloseTimer = null;
            }
        } catch (_) { void _; }

        const latLng = [Number(contact.adv.data.lat), Number(contact.adv.data.lon)];
        const targetZoom = Math.max(this.map.getZoom(), 12);
        let labelOpened = false;
        const openLabel = () => {
            if (labelOpened) return;
            labelOpened = true;
            if (this.previewFocusedContactId !== contact.data.id) return;
            contact.showLabel(true);
        };

        this.map.once('moveend', openLabel);
        this.map.once('zoomend', openLabel);
        setTimeout(openLabel, 420);
        this.map.flyTo(latLng, targetZoom, { duration: 0.35 });

        if (this._mapSearch?.input) {
            this._mapSearch.input.value = contact.adv?.data?.name ?? '';
        }
    }

    openContactPopup(contact) {
        if (!contact?.adv) return;
        this.activePopupContactId = Number(contact.data.id);
        const latLng = [Number(contact.adv.data.lat), Number(contact.adv.data.lon)];
        this._destroyActiveContactTrailMap();
        if (!this.activeContactPopup) {
            this.activeContactPopup = L.popup({
                className: 'compact-marker-popup',
                maxWidth: 360,
                closeOnClick: false,
                autoClose: false,
                interactive: true,
                bubblingMouseEvents: false,
            });
            const wirePopupControls = () => {
                requestAnimationFrame(() => {
                    const popupElement = this.activeContactPopup?.getElement?.();
                    if (!popupElement) return;
                    L.DomEvent.disableClickPropagation(popupElement);
                    L.DomEvent.disableScrollPropagation(popupElement);
                    this._wirePopupControls(popupElement);
                });
            };
            this.activeContactPopup.on('add', wirePopupControls);
            this.activeContactPopup.on('contentupdate', wirePopupControls);
            this.activeContactPopup.on('remove', () => {
                this._destroyActiveContactTrailMap();
                if (this.activeContactPopup && !this.map.hasLayer(this.activeContactPopup)) {
                    this.activeContactPopup = null;
                }
            });
        }

        const popup = this.activeContactPopup
            .setLatLng(latLng)
            .setContent(contact.getMarkerTooltip(this.popupUiState));

        if (!this.map.hasLayer(popup)) {
            popup.addTo(this.map);
        }

        requestAnimationFrame(() => this._renderPopupContactTrailMap(contact));
    }

    _wirePopupControls(popupElement) {
        // Popup button controls are handled globally in _handlePopupControlPointer.
        void popupElement;
    }

    updateSelectedContactPopup() {
        const contactId = this.selectedMarkerId ?? this.activePopupContactId;
        if (!contactId) return;
        const contact = this.contacts[contactId] ?? null;
        if (!contact?.adv) return;
        this.openContactPopup(contact);
    }

    _destroyActiveContactTrailMap() {
        if (!this.activeContactTrailMap) return;
        try {
            this.activeContactTrailMap.remove();
        } catch (_error) {
            void _error;
        }
        this.activeContactTrailMap = null;
        this.activeContactTrailContactId = null;
    }

    _renderPopupContactTrailMap(contact) {
        if (!contact?.adv || !contact.isClient || !contact.isClient() || this.popupUiState?.tab !== 'general') {
            this._destroyActiveContactTrailMap();
            return;
        }

        const trail = this.getContactAdvertisementTrail(contact.data.id);
        if (!Array.isArray(trail.points) || trail.points.length < 1) {
            this._destroyActiveContactTrailMap();
            return;
        }

        const popupElement = this.activeContactPopup?.getElement?.();
        if (!popupElement) {
            this._destroyActiveContactTrailMap();
            return;
        }

        const mapElement = popupElement.querySelector(`#device-popup-adv-map-${contact.data.id}`);
        if (!mapElement) {
            this._destroyActiveContactTrailMap();
            return;
        }

        this._destroyActiveContactTrailMap();

        const miniMap = L.map(mapElement, {
            zoomControl: false,
            attributionControl: false,
            dragging: true,
            scrollWheelZoom: false,
            doubleClickZoom: true,
            boxZoom: false,
            keyboard: false,
            tap: false,
        });

        const requestedLayer = Settings.get('map.layer', 'dark');
        const layerName = (requestedLayer === 'light' || requestedLayer === 'topo') ? requestedLayer : 'dark';
        const tileLayers = (typeof window !== 'undefined' && window._TILE_LAYERS) ? window._TILE_LAYERS : null;
        const layerConfig = (tileLayers && tileLayers[layerName])
            ? tileLayers[layerName]
            : {
                url: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
                opts: {
                    maxZoom: 19,
                    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                }
            };

        L.tileLayer(layerConfig.url, {
            ...(layerConfig.opts ?? {}),
            attributionControl: false,
        }).addTo(miniMap);

        // Build valid point list (skip non-finite coords)
        const validPoints = [];
        for (let i = 0; i < trail.points.length; i++) {
            const point = trail.points[i];
            const lat = Number(point.lat);
            const lon = Number(point.lon);
            if (!Number.isFinite(lat) || !Number.isFinite(lon)) continue;
            validPoints.push({ ...point, lat, lon });
        }

        const TWO_HOURS_MS = 2 * 60 * 60 * 1000;
        const n = validPoints.length;

        // Draw segmented polylines — connect consecutive points within 2-hour gap
        let segStart = 0;
        for (let i = 1; i <= n; i++) {
            const gap = i < n
                ? new Date(validPoints[i].created_at).getTime() - new Date(validPoints[i - 1].created_at).getTime()
                : Infinity;
            if (gap > TWO_HOURS_MS || i === n) {
                const segment = validPoints.slice(segStart, i).map((p) => [p.lat, p.lon]);
                if (segment.length >= 2) {
                    L.polyline(segment, {
                        color: '#67b7ff',
                        weight: 2,
                        opacity: 0.55,
                        interactive: false,
                    }).addTo(miniMap);
                }
                segStart = i;
            }
        }

        // Draw dots — oldest dim, newest bright/larger
        const latLngs = [];
        for (let i = 0; i < n; i++) {
            const point = validPoints[i];
            const { lat, lon } = point;
            const isLatest = (i === n - 1);
            const ageFraction = n > 1 ? i / (n - 1) : 1; // 0=oldest, 1=newest
            const fillOpacity = 0.35 + ageFraction * 0.6;
            const fillColor = isLatest ? '#00d9e9' : '#67b7ff';
            const radius = isLatest ? 6 : 3;

            const marker = L.circleMarker([lat, lon], {
                radius,
                color: isLatest ? '#e6eef7' : '#8ab8d8',
                weight: 1,
                fillColor,
                fillOpacity,
            }).addTo(miniMap);

            const sentAt = contact.formatPopupTimestamp(point.sent_at);
            const receivedAt = contact.formatPopupTimestamp(point.created_at);
            const popupHtml = `
                <div class="device-popup-mini-dot-popup">
                    <div><strong>Received:</strong> ${escapeXml(receivedAt)}</div>
                    <div><strong>Sender:</strong> ${escapeXml(sentAt)}</div>
                </div>
            `;

            marker.bindTooltip(escapeXml(receivedAt), {
                direction: 'top',
                offset: [0, -6],
                opacity: 0.95,
                className: 'device-popup-mini-dot-tooltip',
            });
            marker.bindPopup(popupHtml, {
                closeButton: true,
                autoClose: true,
                className: 'device-popup-mini-dot-popup-wrap',
            });

            marker.on('mouseover', () => marker.openTooltip());
            marker.on('mouseout', () => marker.closeTooltip());
            latLngs.push([lat, lon]);
        }

        if (latLngs.length > 0) {
            const bounds = L.latLngBounds(latLngs);
            if (bounds.isValid()) {
                miniMap.fitBounds(bounds, { padding: [20, 20], maxZoom: 16 });
            }
        }

        this.activeContactTrailMap = miniMap;
        this.activeContactTrailContactId = contact.data.id;

        setTimeout(() => {
            try {
                miniMap.invalidateSize();
            } catch (_error) {
                void _error;
            }
        }, 0);
    }

    getPacketTypeLabelForStats(msg) {
        if (msg instanceof MeshLogAdvertisement) return 'ADV';
        if (msg instanceof MeshLogChannelMessage) return 'PUB';
        if (msg instanceof MeshLogDirectMessage) return 'DIR';
        if (msg instanceof MeshLogTelemetryMessage) return 'TEL';
        if (msg instanceof MeshLogSystemReportMessage) return 'SYS';
        if (msg instanceof MeshLogRawPacket) return 'RAW';
        return 'OTHER';
    }

    _getStatsBucketCount(windowHours = 24) {
        if (windowHours === 24) return 24;
        if (windowHours === 36) return 18;
        return 12;
    }

    _normalizeStatsWindowHours(windowHours = 24) {
        return [1, 24, 36].includes(Number(windowHours)) ? Number(windowHours) : 24;
    }

    _buildStatsChartSvg(buckets, windowHours = 24, unitLabel = 'pkts') {
        const safeBuckets = Array.isArray(buckets) && buckets.length > 0
            ? buckets
            : new Array(this._getStatsBucketCount(windowHours)).fill(0);
        const bucketCount = safeBuckets.length;
        const maxBucket = Math.max(1, ...safeBuckets);
        const chartWidth = 284;
        const chartHeight = 110;
        const yAxisWidth = 30;   // left margin for Y-axis labels
        const barsWidth = chartWidth - yAxisWidth;
        const barsTop = 6;
        const barsBottom = 82;
        const barsHeight = barsBottom - barsTop;
        const barGap = 3;
        const barWidth = Math.max(4, Math.floor((barsWidth - ((bucketCount - 1) * barGap)) / bucketCount));
        const bucketHours = windowHours / bucketCount;
        const barsSvg = safeBuckets.map((count, index) => {
            const height = Math.max(count > 0 ? 4 : 0, Math.round((count / maxBucket) * barsHeight));
            const x = yAxisWidth + index * (barWidth + barGap);
            const y = barsBottom - height;
            // Time range for this bar: index 0 is oldest, last index is newest (now)
            const hoursAgoEnd = Math.round((bucketCount - 1 - index) * bucketHours * 10) / 10;
            const hoursAgoStart = Math.round((hoursAgoEnd + bucketHours) * 10) / 10;
            const timeLabel = hoursAgoEnd === 0
                ? `last ${bucketHours < 1 ? Math.round(bucketHours * 60) + ' min' : bucketHours.toFixed(1) + 'h'}`
                : `-${hoursAgoStart.toFixed(hoursAgoStart % 1 === 0 ? 0 : 1)}h → -${hoursAgoEnd.toFixed(hoursAgoEnd % 1 === 0 ? 0 : 1)}h`;
            const tooltipText = `${timeLabel}: ${count} ${unitLabel}`;
            // Always render a full-height transparent hit area so empty bars are also hoverable.
            const escapedTooltip = tooltipText.replace(/"/g, '&quot;');
            const hitRect = `<rect x="${x}" y="${barsTop}" width="${barWidth}" height="${barsHeight}" fill="transparent" data-chart-tooltip="${escapedTooltip}"></rect>`;
            const barRect = height > 0 ? `<rect x="${x}" y="${y}" width="${barWidth}" height="${height}" rx="2" ry="2"></rect>` : '';
            return hitRect + barRect;
        }).join('');

        return `
            <div class="device-popup-chart-wrap">
                <svg class="device-popup-chart" viewBox="0 0 ${chartWidth} ${chartHeight}" preserveAspectRatio="xMidYMid meet" aria-label="Packet activity chart">
                    <line x1="${yAxisWidth}" y1="${barsTop}" x2="${yAxisWidth}" y2="${barsBottom + 0.5}" class="device-popup-chart-axis"></line>
                    <line x1="${yAxisWidth}" y1="${barsBottom + 0.5}" x2="${chartWidth}" y2="${barsBottom + 0.5}" class="device-popup-chart-axis"></line>
                    <g class="device-popup-chart-bars">${barsSvg}</g>
                    <text x="${yAxisWidth - 4}" y="${barsTop + 5}" text-anchor="end" class="device-popup-chart-label">${maxBucket}</text>
                    <text x="${yAxisWidth - 4}" y="${barsBottom}" text-anchor="end" class="device-popup-chart-label">0</text>
                    <text x="${yAxisWidth - 4}" y="102" text-anchor="end" class="device-popup-chart-label">${unitLabel}</text>
                    <text x="${yAxisWidth}" y="102" class="device-popup-chart-label">-${windowHours}h</text>
                    <text x="${chartWidth}" y="102" text-anchor="end" class="device-popup-chart-label">now</text>
                </svg>
            </div>
        `;
    }

    _getEmptyContactAdvertisementTrail(overrides = {}) {
        const points = Array.isArray(overrides.points) ? overrides.points : [];
        return {
            points,
            count: Number(overrides.count ?? points.length) || 0,
            note: overrides.note ?? 'All advertisements with stored GPS coordinates for this chat client.',
            isLoading: overrides.isLoading ?? false,
            hasError: overrides.hasError ?? false,
        };
    }

    _normalizeContactAdvertisementTrailResponse(response) {
        const objects = Array.isArray(response?.objects) ? response.objects : [];
        const points = objects
            .map((row) => ({
                id: Number(row?.id) || 0,
                contact_id: Number(row?.contact_id) || 0,
                lat: Number(row?.lat),
                lon: Number(row?.lon),
                sent_at: row?.sent_at ?? null,
                created_at: row?.created_at ?? null,
                reports: Array.isArray(row?.reports)
                    ? row.reports.map((report) => ({
                        id: Number(report?.id) || 0,
                        reporter_id: Number(report?.reporter_id) || 0,
                        path: report?.path ?? null,
                        snr: Number(report?.snr),
                        scope: report?.scope ?? null,
                        route_type: report?.route_type ?? null,
                        sender_at: report?.sender_at ?? null,
                        received_at: report?.received_at ?? null,
                        created_at: report?.created_at ?? null,
                    })).filter((report) => report.reporter_id > 0)
                    : [],
            }))
            .filter((row) => Number.isFinite(row.lat) && Number.isFinite(row.lon) && !(row.lat === 0 && row.lon === 0));

        return this._getEmptyContactAdvertisementTrail({
            points,
            count: Number(response?.count) || points.length,
            note: response?.note ?? 'All advertisements with stored GPS coordinates for this chat client.',
        });
    }

    _loadContactAdvertisementTrail(contactId, forceRefresh = false) {
        const numericContactId = Number(contactId);
        if (!Number.isFinite(numericContactId) || numericContactId <= 0) return;

        const key = `${numericContactId}`;
        const now = Date.now();
        const cacheTtlMs = 10 * 60 * 1000;
        const cached = this.contactAdvertisementTrailCache.get(key) ?? null;

        if (!forceRefresh && cached?.status === 'ready' && (now - cached.fetchedAt) < cacheTtlMs) {
            return;
        }

        if (cached?.status === 'loading') {
            return;
        }

        const loadingData = cached?.data
            ? { ...cached.data, isLoading: true, hasError: false }
            : this._getEmptyContactAdvertisementTrail({ isLoading: true });

        this.contactAdvertisementTrailCache.set(key, {
            status: 'loading',
            fetchedAt: cached?.fetchedAt ?? 0,
            data: loadingData,
        });

        this.__fetchJson(
            'api/v1/contact_advertisements',
            { contact_id: numericContactId },
            (data) => {
                const normalized = data?.error
                    ? this._getEmptyContactAdvertisementTrail({
                        hasError: true,
                        note: data.error,
                    })
                    : this._normalizeContactAdvertisementTrailResponse(data);

                this.contactAdvertisementTrailCache.set(key, {
                    status: data?.error ? 'error' : 'ready',
                    fetchedAt: Date.now(),
                    data: {
                        ...normalized,
                        isLoading: false,
                        hasError: Boolean(data?.error),
                    },
                });

                if (this.activeRouteTrailContactId === numericContactId) {
                    this._renderActiveRouteTrail();
                }

                if (this.selectedMarkerId === numericContactId && this.popupUiState?.tab === 'general') {
                    this.updateSelectedContactPopup();
                }
            },
            () => {
                this.contactAdvertisementTrailCache.set(key, {
                    status: 'error',
                    fetchedAt: Date.now(),
                    data: this._getEmptyContactAdvertisementTrail({
                        hasError: true,
                        note: 'Failed to fetch advertisement coordinates.',
                    }),
                });

                if (this.activeRouteTrailContactId === numericContactId) {
                    this._renderActiveRouteTrail();
                }

                if (this.selectedMarkerId === numericContactId && this.popupUiState?.tab === 'general') {
                    this.updateSelectedContactPopup();
                }
            }
        );
    }

    getContactAdvertisementTrail(contactId) {
        const numericContactId = Number(contactId);
        const key = `${numericContactId}`;
        const cacheTtlMs = 10 * 60 * 1000;
        const cached = this.contactAdvertisementTrailCache.get(key) ?? null;

        if (!cached) {
            this._loadContactAdvertisementTrail(numericContactId);
            return this._getEmptyContactAdvertisementTrail({ isLoading: true });
        }

        if (cached.status === 'ready' && (Date.now() - cached.fetchedAt) >= cacheTtlMs) {
            this._loadContactAdvertisementTrail(numericContactId, true);
            return { ...cached.data, isLoading: true };
        }

        if (cached.status === 'error' && (Date.now() - cached.fetchedAt) >= cacheTtlMs) {
            this._loadContactAdvertisementTrail(numericContactId, true);
        }

        return cached.data;
    }

    isRouteTrailVisible(contactId) {
        return Number(contactId) === Number(this.activeRouteTrailContactId);
    }

    _getRouteTrailPointLayerId(contactId, pointId) {
        return `route_trail_${contactId}_${pointId}`;
    }

    _getRouteTrailSyntheticPointId(pointId) {
        return -1 * (1000000 + Number(pointId || 0));
    }

    _getRouteTrailPointRepeaters(contact, point) {
        if (!contact?.adv || !point) return [];

        const pointLat = Number(point.lat);
        const pointLon = Number(point.lon);
        if (!Number.isFinite(pointLat) || !Number.isFinite(pointLon)) return [];

        const reports = Array.isArray(point.reports) ? point.reports : [];
        const repeaters = [];
        const seenContactIds = new Set();

        for (let i = 0; i < reports.length; i++) {
            const report = reports[i];
            const firstHopHash = parsePath(report?.path ?? '')[0] ?? '';
            if (!firstHopHash) continue;

            const nearest = this.findNearestContact(pointLat, pointLon, firstHopHash, true);
            const repeaterContact = nearest?.result ?? null;
            if (!repeaterContact?.adv) continue;
            if (!repeaterContact.isRepeater || !repeaterContact.isRepeater()) continue;
            if (seenContactIds.has(repeaterContact.data.id)) continue;

            seenContactIds.add(repeaterContact.data.id);
            repeaters.push({
                report,
                contact: repeaterContact,
            });
        }

        return repeaters;
    }

    _hideActiveRouteTrailPoint() {
        if (!this.activeRouteTrailPointLayerId) return;
        if (this.layer_descs.hasOwnProperty(this.activeRouteTrailPointLayerId)) {
            delete this.layer_descs[this.activeRouteTrailPointLayerId];
            this.updatePaths();
        }
        this.activeRouteTrailPointLayerId = null;
    }

    _showRouteTrailPointReports(contact, point) {
        this._hideActiveRouteTrailPoint();

        if (!contact?.adv || !point) return;

        const repeaters = this._getRouteTrailPointRepeaters(contact, point);
        if (repeaters.length < 1) return;

        const pointAnchor = {
            lat: Number(point.lat),
            lon: Number(point.lon),
            contact_id: this._getRouteTrailSyntheticPointId(point.id),
        };

        const layerId = this._getRouteTrailPointLayerId(contact.data.id, point.id);
        const desc = {
            paths: [],
            markers: new Set([contact.data.id]),
            warnings: [],
            preview: {
                title: `${contact.adv?.data?.name ?? contact.data.public_key} route point`,
                subtitle: `${repeaters.length} repeater${repeaters.length === 1 ? '' : 's'} heard this advertisement`,
                accent: '#ff4d4d',
                chips: [contact.hash ? `[${contact.hash}]` : null, contact.getContactTypeLabel()].filter(Boolean),
                footer: point.created_at ? `Seen ${point.created_at}` : '',
            },
            animated: false,
        };

        for (let i = 0; i < repeaters.length; i++) {
            const item = repeaters[i];
            const repeaterAnchor = {
                lat: Number(item.contact.adv.data.lat),
                lon: Number(item.contact.adv.data.lon),
                contact_id: item.contact.data.id,
            };
            desc.markers.add(item.contact.data.id);
            desc.paths.push(new MeshLogLinkLayer(
                repeaterAnchor,
                pointAnchor,
                {
                    data: { id: `route_${point.id}_${item.contact.data.id}` },
                    getStyle: () => ({ color: '#ff4d4d', stroke: '#ffffff' })
                },
                false
            ));
        }

        this.layer_descs[layerId] = desc;
        this.activeRouteTrailPointLayerId = layerId;
        this.updatePaths();
    }

    _renderActiveRouteTrail() {
        this.route_trail_layers.clearLayers();

        const contactId = Number(this.activeRouteTrailContactId);
        if (!Number.isFinite(contactId) || contactId <= 0) return;

        const contact = this.contacts[contactId] ?? null;
        if (!contact?.adv) return;

        const trail = this.getContactAdvertisementTrail(contactId);
        if (!Array.isArray(trail.points) || trail.points.length < 1) return;

        for (let i = 0; i < trail.points.length; i++) {
            const point = trail.points[i];
            const lat = Number(point.lat);
            const lon = Number(point.lon);
            if (!Number.isFinite(lat) || !Number.isFinite(lon)) continue;

            const repeaters = this._getRouteTrailPointRepeaters(contact, point);
            const heardBy = repeaters.map((item) => item.contact?.adv?.data?.name ?? item.contact?.data?.name ?? null).filter(Boolean);
            const popupHtml = `
                <div class="device-popup-mini-dot-popup">
                    <div><strong>Received:</strong> ${escapeXml(contact.formatPopupTimestamp(point.created_at))}</div>
                    <div><strong>Sender:</strong> ${escapeXml(contact.formatPopupTimestamp(point.sent_at))}</div>
                    <div><strong>Heard by:</strong> ${escapeXml(heardBy.length > 0 ? heardBy.join(', ') : 'No repeater path stored')}</div>
                </div>
            `;

            const marker = L.circleMarker([lat, lon], {
                pane: MeshLog.ROUTE_POINT_PANE,
                radius: 6,
                color: '#ffd4d4',
                weight: 1.5,
                fillColor: '#ff3030',
                fillOpacity: 0.92,
            });
            marker.bindTooltip(escapeXml(contact.formatPopupTimestamp(point.created_at)), {
                direction: 'top',
                offset: [0, -8],
                opacity: 0.95,
                className: 'device-popup-mini-dot-tooltip',
            });
            marker.bindPopup(popupHtml, {
                closeButton: true,
                autoClose: true,
                className: 'device-popup-mini-dot-popup-wrap',
            });
            marker.on('click', (event) => {
                if (event?.originalEvent) {
                    L.DomEvent.stopPropagation(event.originalEvent);
                }
                this._showRouteTrailPointReports(contact, point);
            });
            marker.addTo(this.route_trail_layers);
        }
    }

    hideRouteTrail(contactId = null) {
        const targetId = contactId === null ? this.activeRouteTrailContactId : Number(contactId);
        if (!Number.isFinite(Number(targetId)) || Number(targetId) <= 0) return false;
        if (this.activeRouteTrailContactId !== Number(targetId)) return false;

        this.activeRouteTrailContactId = null;
        this.route_trail_layers.clearLayers();
        this._hideActiveRouteTrailPoint();

        const contact = this.contacts[Number(targetId)] ?? null;
        contact?.update?.();
        if (this.activePopupContactId === Number(targetId) && this.popupUiState?.tab === 'general') {
            this.openContactPopup(contact);
        }
        return false;
    }

    showRouteTrail(contact) {
        if (!contact?.adv || !contact.isClient || !contact.isClient()) return false;

        const previousId = Number(this.activeRouteTrailContactId) || null;
        if (previousId && previousId !== Number(contact.data.id)) {
            this.hideRouteTrail(previousId);
        }

        this.activeRouteTrailContactId = Number(contact.data.id);
        this._renderActiveRouteTrail();
        contact.update?.();
        if (this.activePopupContactId === Number(contact.data.id) && this.popupUiState?.tab === 'general') {
            this.openContactPopup(contact);
        }
        return true;
    }

    toggleRouteTrail(contact) {
        if (!contact?.adv || !contact.isClient || !contact.isClient()) return false;
        if (this.isRouteTrailVisible(contact.data.id)) {
            return this.hideRouteTrail(contact.data.id);
        }
        return this.showRouteTrail(contact);
    }

    // ── Node Health Timeline ──────────────────────────────────────────────────

    _getEmptyContactHealth(overrides = {}) {
        return {
            systemReports: overrides.systemReports ?? [],
            telemetry: overrides.telemetry ?? null,
            isLoading: overrides.isLoading ?? false,
            hasError: overrides.hasError ?? false,
            hasData: overrides.hasData ?? false,
            note: overrides.note ?? '',
        };
    }

    _loadContactHealth(contactId, forceRefresh = false) {
        const numericContactId = Number(contactId);
        if (!Number.isFinite(numericContactId)) return;

        const key = `health:${numericContactId}`;
        const now = Date.now();
        const cacheTtlMs = 30 * 60 * 1000;
        const cached = this.contactHealthCache.get(key) ?? null;

        if (!forceRefresh && cached?.status === 'ready' && (now - cached.fetchedAt) < cacheTtlMs) return;
        if (cached?.status === 'loading') return;

        const loadingData = cached?.data
            ? { ...cached.data, isLoading: true, hasError: false }
            : this._getEmptyContactHealth({ isLoading: true });

        this.contactHealthCache.set(key, { status: 'loading', fetchedAt: cached?.fetchedAt ?? 0, data: loadingData });

        this.__fetchJson(
            'api/v1/contact_health',
            { contact_id: numericContactId, limit: 48 },
            (data) => {
                const normalized = data?.error
                    ? this._getEmptyContactHealth({ hasError: true, note: data.error })
                    : {
                        systemReports: Array.isArray(data?.system_reports) ? data.system_reports : [],
                        telemetry: data?.telemetry ?? null,
                        isLoading: false,
                        hasError: false,
                        hasData: (data?.system_reports?.length ?? 0) > 0 || data?.telemetry != null,
                        note: '',
                    };
                this.contactHealthCache.set(key, {
                    status: data?.error ? 'error' : 'ready',
                    fetchedAt: Date.now(),
                    data: { ...normalized, isLoading: false },
                });
                if (this.selectedMarkerId === numericContactId && this.popupUiState?.tab === 'health') {
                    this.updateSelectedContactPopup();
                }
            },
            () => {
                this.contactHealthCache.set(key, {
                    status: 'error',
                    fetchedAt: Date.now(),
                    data: this._getEmptyContactHealth({ hasError: true, note: 'Failed to load health data.' }),
                });
                if (this.selectedMarkerId === numericContactId && this.popupUiState?.tab === 'health') {
                    this.updateSelectedContactPopup();
                }
            }
        );
    }

    getContactHealth(contactId) {
        const key = `health:${Number(contactId)}`;
        const cached = this.contactHealthCache.get(key) ?? null;
        const cacheTtlMs = 30 * 60 * 1000;
        if (cached?.status === 'ready' && (Date.now() - cached.fetchedAt) < cacheTtlMs) return cached.data;
        if (!cached || cached?.status !== 'loading') this._loadContactHealth(contactId);
        return cached?.data ?? this._getEmptyContactHealth({ isLoading: true });
    }

    _buildHealthSparkline(values, color = '#0099ff', unitLabel = '', yMin = null) {
        if (!values || values.length < 2) return '';
        const valid = values.filter(v => v != null && Number.isFinite(Number(v))).map(Number);
        if (valid.length < 2) return '';
        const W = 240, H = 48;
        const pad = 4;
        const minV = yMin != null ? Math.min(yMin, ...valid) : Math.min(...valid);
        const maxV = Math.max(...valid);
        const range = maxV - minV || 1;
        const pts = valid.map((v, i) => {
            const x = pad + (i / (valid.length - 1)) * (W - pad * 2);
            const y = H - pad - ((v - minV) / range) * (H - pad * 2);
            return `${x.toFixed(1)},${y.toFixed(1)}`;
        }).join(' ');
        const firstVal = valid[valid.length - 1]; // newest first in array
        const lastVal  = valid[0];
        const labelVal = lastVal != null ? `${lastVal}${unitLabel}` : '';
        return `
            <svg class="health-sparkline" viewBox="0 0 ${W} ${H}" preserveAspectRatio="none" aria-hidden="true">
                <polyline points="${pts}" fill="none" stroke="${color}" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" opacity="0.9"/>
                <circle cx="${valid.length > 1 ? (pad + ((valid.length - 1) / (valid.length - 1)) * (W - pad * 2)).toFixed(1) : W - pad}" cy="${(H - pad - ((valid[valid.length - 1] - minV) / range) * (H - pad * 2)).toFixed(1)}" r="3" fill="${color}"/>
            </svg>
            <span class="health-sparkline-label">${escapeXml(labelVal)}</span>`;
    }

    _formatUptime(seconds) {
        if (seconds == null || !Number.isFinite(Number(seconds))) return null;
        const s = Number(seconds);
        if (s < 60) return `${s}s`;
        if (s < 3600) return `${Math.floor(s / 60)}m`;
        if (s < 86400) return `${Math.floor(s / 3600)}h ${Math.floor((s % 3600) / 60)}m`;
        return `${Math.floor(s / 86400)}d ${Math.floor((s % 86400) / 3600)}h`;
    }

    _formatHeapPct(free, total) {
        if (free == null || total == null || !total) return null;
        return `${Math.round((free / total) * 100)}%`;
    }

    getDevicePopupHealthHtml(contactId) {
        const health = this.getContactHealth(contactId);

        if (health.isLoading && !health.hasData) {
            return `<div class="device-popup-section"><div class="device-popup-note">Loading health data…</div></div>`;
        }
        if (health.hasError) {
            return `<div class="device-popup-section"><div class="device-popup-note device-popup-note-error">${escapeXml(health.note || 'Error loading health data.')}</div></div>`;
        }
        if (!health.hasData) {
            return `<div class="device-popup-section"><div class="device-popup-note">No system reports recorded for this device.</div></div>`;
        }

        const reports = health.systemReports; // newest first
        const latest  = reports[0] ?? null;

        // Extract time series (oldest→newest for sparklines — reverse)
        const ordered = [...reports].reverse();
        const rssiSeries    = ordered.map(r => r.rssi);
        const heapFreeSeries = ordered.map(r => r.heap_free != null && r.heap_total ? Math.round((r.heap_free / r.heap_total) * 100) : null);
        const uptimeSeries  = ordered.map(r => r.uptime);

        // Reboot detection: uptime went down
        const reboots = reports.slice(0, -1).filter((r, i) => {
            const next = reports[i + 1]; // older
            return r.uptime != null && next?.uptime != null && r.uptime < next.uptime;
        }).length;

        // Version changes
        const versions = [...new Set(reports.map(r => r.version).filter(Boolean))];

        // RSSI color
        const rssiColor = (v) => {
            if (v == null) return 'rgba(205,220,238,0.4)';
            if (v >= -60) return '#6dd98c';
            if (v >= -75) return '#a3d96d';
            if (v >= -85) return '#f0d060';
            if (v >= -95) return '#f09040';
            return '#e05050';
        };

        // Heap color (% free)
        const heapColor = (pct) => {
            if (pct == null) return 'rgba(205,220,238,0.4)';
            if (pct >= 50) return '#6dd98c';
            if (pct >= 30) return '#f0d060';
            return '#e05050';
        };

        const latestRssi   = latest?.rssi;
        const latestHeapPct = latest?.heap_free != null && latest?.heap_total
            ? Math.round((latest.heap_free / latest.heap_total) * 100)
            : null;
        const latestUptime = this._formatUptime(latest?.uptime);
        const latestVersion = latest?.version ?? null;
        const receivedStr = latest?.received_at ? this.formatPopupTimestamp?.(latest.received_at) ?? latest.received_at : '-';

        const rssiSparkline   = this._buildHealthSparkline(rssiSeries, rssiColor(latestRssi), ' dBm');
        const heapSparkline   = this._buildHealthSparkline(heapFreeSeries, heapColor(latestHeapPct), '%', 0);
        const uptimeSparkline = this._buildHealthSparkline(uptimeSeries, '#cc33ff', 's');

        const metricRow = (label, value, color, sparkline) => value != null
            ? `<div class="health-metric-row">
                <div class="health-metric-head">
                    <span class="health-metric-label">${escapeXml(label)}</span>
                    <span class="health-metric-value" style="color:${color}">${escapeXml(String(value))}</span>
                </div>
                <div class="health-metric-spark">${sparkline}</div>
               </div>`
            : '';

        const rssiLabel  = latestRssi  != null ? `${latestRssi} dBm` : null;
        const heapLabel  = latestHeapPct != null ? `${latestHeapPct}% free (${latest.heap_free != null ? (latest.heap_free / 1024).toFixed(0) + ' KB' : '-'})` : null;

        const metricsHtml = [
            metricRow('RSSI',      rssiLabel,  rssiColor(latestRssi),    rssiSparkline),
            metricRow('Heap free', heapLabel,  heapColor(latestHeapPct), heapSparkline),
            latestUptime ? metricRow('Uptime', latestUptime, '#cc33ff', uptimeSparkline) : '',
        ].join('');

        const versionBadge = latestVersion
            ? `<span class="health-version-badge">${escapeXml(latestVersion)}</span>`
            : '';

        const rebootBadge = reboots > 0
            ? `<span class="health-reboot-badge" title="Detected ${reboots} uptime reset(s) in recorded history">${reboots} reboot${reboots !== 1 ? 's' : ''}</span>`
            : '';

        const versionList = versions.length > 1
            ? `<div class="health-version-history">Versions seen: ${versions.map(v => `<span class="health-version-badge">${escapeXml(v)}</span>`).join(' ')}</div>`
            : '';

        // Telemetry section
        let telemetryHtml = '';
        if (health.telemetry?.data && Object.keys(health.telemetry.data).length > 0) {
            const fields = Object.entries(health.telemetry.data)
                .filter(([, v]) => v !== null && v !== undefined)
                .map(([k, v]) => {
                    const displayVal = typeof v === 'object' ? JSON.stringify(v) : String(v);
                    return `<div class="device-popup-row"><span class="device-popup-key">${escapeXml(k)}</span><span class="device-popup-value">${escapeXml(displayVal)}</span></div>`;
                }).join('');
            const telTime = health.telemetry.received_at
                ? `<span class="health-tel-time">${escapeXml(health.telemetry.received_at)}</span>`
                : '';
            telemetryHtml = `
                <div class="device-popup-section">
                    <div class="health-section-head">
                        <div class="device-popup-section-title">Latest Telemetry</div>
                        ${telTime}
                    </div>
                    ${fields}
                </div>`;
        }

        return `
            <div class="device-popup-section">
                <div class="health-section-head">
                    <div class="device-popup-section-title">System Health</div>
                    <div class="health-badges">${versionBadge}${rebootBadge}</div>
                </div>
                <div class="health-meta-row">
                    <span class="health-meta-item">Last report: <b>${escapeXml(receivedStr)}</b></span>
                    <span class="health-meta-item">Records: <b>${escapeXml(String(reports.length))}</b></span>
                </div>
                ${versionList}
                ${metricsHtml}
            </div>
            ${telemetryHtml}
        `;
    }

    // ── end Node Health Timeline ──────────────────────────────────────────────

    _getEmptyContactPacketStats(windowHours = 24, overrides = {}) {
        const bucketCount = this._getStatsBucketCount(windowHours);
        const buckets = Array.isArray(overrides.buckets) && overrides.buckets.length === bucketCount
            ? overrides.buckets
            : new Array(bucketCount).fill(0);

        return {
            totalLoaded: overrides.totalLoaded ?? 0,
            last1h: overrides.last1h ?? 0,
            last24h: overrides.last24h ?? 0,
            last36h: overrides.last36h ?? 0,
            loadedSpanLabel: overrides.loadedSpanLabel ?? 'No database history',
            newestLabel: overrides.newestLabel ?? '-',
            oldestLabel: overrides.oldestLabel ?? '-',
            packetMixLabel: overrides.packetMixLabel ?? 'No packets recorded',
            chartSvg: this._buildStatsChartSvg(buckets, windowHours),
            sourceLabel: overrides.sourceLabel ?? 'Database',
            note: overrides.note ?? '',
            reporterId: overrides.reporterId ?? null,
            isLoading: overrides.isLoading ?? false,
            hasError: overrides.hasError ?? false,
            hasData: overrides.hasData ?? false,
        };
    }

    _normalizeContactPacketStatsResponse(response, windowHours = 24) {
        const oldestTime = parseMeshlogTimestamp(response?.oldest_created_at);
        const newestTime = parseMeshlogTimestamp(response?.newest_created_at);
        const loadedSpanHours = Number.isFinite(oldestTime) && Number.isFinite(newestTime)
            ? Math.max(0, (newestTime - oldestTime) / (60 * 60 * 1000))
            : 0;
        const packetMixLabel = Object.entries(response?.packet_mix ?? {})
            .sort((a, b) => b[1] - a[1] || a[0].localeCompare(b[0]))
            .map(([key, count]) => `${key} ${count}`)
            .join(', ') || 'No packets recorded';
        const buckets = Array.isArray(response?.chart_buckets)
            ? response.chart_buckets.map(value => Number(value) || 0)
            : [];
        const totalLoaded = Number(response?.total_packets) || 0;

        const reporterId = (response?.reporter_id != null && response.reporter_id !== '' && Number(response.reporter_id) > 0)
            ? Number(response.reporter_id)
            : null;

        return this._getEmptyContactPacketStats(windowHours, {
            totalLoaded,
            last1h: Number(response?.last_1h) || 0,
            last24h: Number(response?.last_24h) || 0,
            last36h: Number(response?.last_36h) || 0,
            loadedSpanLabel: totalLoaded > 0 ? `${loadedSpanHours.toFixed(1)} h` : 'No database history',
            newestLabel: Number.isFinite(newestTime) ? new Date(newestTime).toLocaleString() : '-',
            oldestLabel: Number.isFinite(oldestTime) ? new Date(oldestTime).toLocaleString() : '-',
            packetMixLabel,
            buckets,
            sourceLabel: reporterId != null ? 'Database (sent + relayed)' : 'Database',
            note: response?.note ?? '',
            reporterId,
            hasData: totalLoaded > 0,
        });
    }

    _loadContactPacketStats(contactId, windowHours = 24, forceRefresh = false) {
        const numericContactId = Number(contactId);
        const numericWindowHours = Number(windowHours);
        if (!Number.isFinite(numericContactId) || !Number.isFinite(numericWindowHours)) return;

        const key = `${numericContactId}:${numericWindowHours}`;
        const now = Date.now();
        const cacheTtlMs = 60 * 60 * 1000;
        const cached = this.contactPacketStatsCache.get(key) ?? null;

        if (!forceRefresh && cached?.status === 'ready' && (now - cached.fetchedAt) < cacheTtlMs) {
            return;
        }

        if (cached?.status === 'loading') {
            return;
        }

        const loadingData = cached?.data
            ? { ...cached.data, isLoading: true, hasError: false }
            : this._getEmptyContactPacketStats(numericWindowHours, { isLoading: true });

        this.contactPacketStatsCache.set(key, {
            status: 'loading',
            fetchedAt: cached?.fetchedAt ?? 0,
            data: loadingData,
        });

        this.__fetchJson(
            'api/v1/contact_stats',
            { contact_id: numericContactId, window_hours: numericWindowHours },
            (data) => {
                const normalized = data?.error
                    ? this._getEmptyContactPacketStats(numericWindowHours, {
                        hasError: true,
                        note: data.error,
                    })
                    : this._normalizeContactPacketStatsResponse(data, numericWindowHours);

                this.contactPacketStatsCache.set(key, {
                    status: data?.error ? 'error' : 'ready',
                    fetchedAt: Date.now(),
                    data: {
                        ...normalized,
                        isLoading: false,
                        hasError: Boolean(data?.error),
                    },
                });

                if (this.selectedMarkerId === numericContactId && this.popupUiState?.tab === 'stats' && Number(this.popupUiState?.statsWindowHours) === numericWindowHours) {
                    this.updateSelectedContactPopup();
                }
            },
            () => {
                this.contactPacketStatsCache.set(key, {
                    status: 'error',
                    fetchedAt: Date.now(),
                    data: this._getEmptyContactPacketStats(numericWindowHours, {
                        hasError: true,
                        note: 'Failed to fetch database-backed stats.',
                    }),
                });

                if (this.selectedMarkerId === numericContactId && this.popupUiState?.tab === 'stats' && Number(this.popupUiState?.statsWindowHours) === numericWindowHours) {
                    this.updateSelectedContactPopup();
                }
            }
        );
    }

    getContactPacketStats(contactId, windowHours = 24) {
        const key = `${Number(contactId)}:${Number(windowHours)}`;
        const cached = this.contactPacketStatsCache.get(key) ?? null;
        const cacheTtlMs = 60 * 60 * 1000;

        if (!cached) {
            this._loadContactPacketStats(contactId, windowHours);
            return this._getEmptyContactPacketStats(windowHours, { isLoading: true });
        }

        if (cached.status === 'ready' && (Date.now() - cached.fetchedAt) >= cacheTtlMs) {
            this._loadContactPacketStats(contactId, windowHours, true);
            return { ...cached.data, isLoading: true };
        }

        if (cached.status === 'error' && (Date.now() - cached.fetchedAt) >= cacheTtlMs) {
            this._loadContactPacketStats(contactId, windowHours, true);
        }

        return cached.data;
    }

    _getEmptyGeneralStats(windowHours = 24, overrides = {}) {
        const normalizedWindowHours = this._normalizeStatsWindowHours(windowHours);
        const bucketCount = this._getStatsBucketCount(normalizedWindowHours);
        const buckets = Array.isArray(overrides.buckets) && overrides.buckets.length === bucketCount
            ? overrides.buckets
            : new Array(bucketCount).fill(0);
        const uniqueDeviceBuckets = Array.isArray(overrides.uniqueDeviceBuckets) && overrides.uniqueDeviceBuckets.length === bucketCount
            ? overrides.uniqueDeviceBuckets
            : new Array(bucketCount).fill(0);

        return {
            totalReports: overrides.totalReports ?? 0,
            totalAdvertisements: overrides.totalAdvertisements ?? 0,
            uniqueDevices: overrides.uniqueDevices ?? 0,
            uniqueCollectors: overrides.uniqueCollectors ?? 0,
            directReports: overrides.directReports ?? 0,
            floodReports: overrides.floodReports ?? 0,
            unknownRouteReports: overrides.unknownRouteReports ?? 0,
            classifiedReports: overrides.classifiedReports ?? ((overrides.directReports ?? 0) + (overrides.floodReports ?? 0)),
            relayedReports: overrides.relayedReports ?? 0,
            noHopReports: overrides.noHopReports ?? 0,
            loadedSpanLabel: overrides.loadedSpanLabel ?? 'No advertisement reports recorded',
            newestLabel: overrides.newestLabel ?? '-',
            oldestLabel: overrides.oldestLabel ?? '-',
            chartSvg: this._buildStatsChartSvg(buckets, normalizedWindowHours, 'adv'),
            uniqueDeviceChartSvg: this._buildStatsChartSvg(uniqueDeviceBuckets, normalizedWindowHours, 'dev'),
            collectorTotals: Array.isArray(overrides.collectorTotals) ? overrides.collectorTotals : [],
            channelTotals: Array.isArray(overrides.channelTotals) ? overrides.channelTotals : [],
            note: overrides.note ?? 'Counts advertisement report receptions in the selected time window.',
            hasData: overrides.hasData ?? false,
            isLoading: overrides.isLoading ?? false,
            hasError: overrides.hasError ?? false,
        };
    }

    _normalizeGeneralStatsResponse(response, windowHours = 24) {
        const normalizedWindowHours = this._normalizeStatsWindowHours(windowHours);
        const oldestTime = parseMeshlogTimestamp(response?.oldest_created_at);
        const newestTime = parseMeshlogTimestamp(response?.newest_created_at);
        const loadedSpanHours = Number.isFinite(oldestTime) && Number.isFinite(newestTime)
            ? Math.max(0, (newestTime - oldestTime) / (60 * 60 * 1000))
            : 0;
        const totalReports = Number(response?.total_reports) || 0;
        const buckets = Array.isArray(response?.chart_buckets)
            ? response.chart_buckets.map(value => Number(value) || 0)
            : [];
        const uniqueDeviceBuckets = Array.isArray(response?.unique_device_buckets)
            ? response.unique_device_buckets.map(value => Number(value) || 0)
            : [];
        const collectorTotals = Array.isArray(response?.collector_totals)
            ? response.collector_totals.map((row) => ({
                reporterId: Number(row?.reporter_id) || 0,
                reporterName: row?.reporter_name || `Collector ${row?.reporter_id ?? ''}`,
                publicKey: row?.public_key || '',
                style: row?.style || '{}',
                totalPackets: Number(row?.total_packets) || 0,
                advPackets: Number(row?.adv_packets) || 0,
                dirPackets: Number(row?.dir_packets) || 0,
                pubPackets: Number(row?.pub_packets) || 0,
                telPackets: Number(row?.tel_packets) || 0,
                sysPackets: Number(row?.sys_packets) || 0,
                ctrlPackets: Number(row?.ctrl_packets) || 0,
                rawPackets: Number(row?.raw_packets) || 0,
                avgSnr: row?.avg_snr != null ? Number(row.avg_snr) : null,
                maxSnr: row?.max_snr != null ? Number(row.max_snr) : null,
            }))
            : [];
        const channelTotals = Array.isArray(response?.channel_totals)
            ? response.channel_totals.map((row) => ({
                channelId: Number(row?.channel_id) || 0,
                channelHash: row?.channel_hash || '',
                channelName: row?.channel_name || `#${row?.channel_hash ?? row?.channel_id ?? '?'}`,
                totalMessages: Number(row?.total_messages) || 0,
                uniqueSenders: Number(row?.unique_senders) || 0,
            }))
            : [];

        return this._getEmptyGeneralStats(normalizedWindowHours, {
            totalReports,
            totalAdvertisements: Number(response?.total_advertisements) || 0,
            uniqueDevices: Number(response?.unique_devices) || 0,
            uniqueCollectors: Number(response?.unique_collectors) || 0,
            directReports: Number(response?.direct_reports) || 0,
            floodReports: Number(response?.flood_reports) || 0,
            unknownRouteReports: Number(response?.unknown_route_reports) || 0,
            classifiedReports: (Number(response?.direct_reports) || 0) + (Number(response?.flood_reports) || 0),
            relayedReports: Number(response?.relayed_reports) || 0,
            noHopReports: Number(response?.no_hop_reports) || 0,
            loadedSpanLabel: totalReports > 0 ? `${loadedSpanHours.toFixed(1)} h` : 'No advertisement reports recorded',
            newestLabel: Number.isFinite(newestTime) ? new Date(newestTime).toLocaleString() : '-',
            oldestLabel: Number.isFinite(oldestTime) ? new Date(oldestTime).toLocaleString() : '-',
            buckets,
            uniqueDeviceBuckets,
            collectorTotals,
            channelTotals,
            note: response?.note ?? 'Counts advertisement report receptions in the selected time window.',
            hasData: totalReports > 0,
        });
    }

    _loadGeneralStats(windowHours = 24, forceRefresh = false) {
        const normalizedWindowHours = this._normalizeStatsWindowHours(windowHours);
        const key = `${normalizedWindowHours}`;
        const now = Date.now();
        const cacheTtlMs = 60 * 60 * 1000;
        const cached = this.generalStatsCache.get(key) ?? null;

        if (!forceRefresh && cached?.status === 'ready' && (now - cached.fetchedAt) < cacheTtlMs) {
            return;
        }

        if (cached?.status === 'loading') {
            return;
        }

        const loadingData = cached?.data
            ? { ...cached.data, isLoading: true, hasError: false }
            : this._getEmptyGeneralStats(normalizedWindowHours, { isLoading: true });

        this.generalStatsCache.set(key, {
            status: 'loading',
            fetchedAt: cached?.fetchedAt ?? 0,
            data: loadingData,
        });

        this.__fetchJson(
            'api/v1/stats',
            { window_hours: normalizedWindowHours },
            (data) => {
                const normalized = data?.error
                    ? this._getEmptyGeneralStats(normalizedWindowHours, {
                        hasError: true,
                        note: data.error,
                    })
                    : this._normalizeGeneralStatsResponse(data, normalizedWindowHours);

                this.generalStatsCache.set(key, {
                    status: data?.error ? 'error' : 'ready',
                    fetchedAt: Date.now(),
                    data: {
                        ...normalized,
                        isLoading: false,
                        hasError: Boolean(data?.error),
                    },
                });

                if (document.querySelector('.sidebar-tab.active')?.dataset.tab === 'stats') {
                    this.refreshGeneralStatsPanel();
                }
            },
            () => {
                this.generalStatsCache.set(key, {
                    status: 'error',
                    fetchedAt: Date.now(),
                    data: this._getEmptyGeneralStats(normalizedWindowHours, {
                        hasError: true,
                        note: 'Failed to fetch database-backed advertisement stats.',
                    }),
                });

                if (document.querySelector('.sidebar-tab.active')?.dataset.tab === 'stats') {
                    this.refreshGeneralStatsPanel();
                }
            }
        );
    }

    getGeneralStats(windowHours = 24) {
        const normalizedWindowHours = this._normalizeStatsWindowHours(windowHours);
        const key = `${normalizedWindowHours}`;
        const cached = this.generalStatsCache.get(key) ?? null;
        const cacheTtlMs = 60 * 60 * 1000;

        if (!cached) {
            this._loadGeneralStats(normalizedWindowHours);
            return this._getEmptyGeneralStats(normalizedWindowHours, { isLoading: true });
        }

        if (cached.status === 'ready' && (Date.now() - cached.fetchedAt) >= cacheTtlMs) {
            this._loadGeneralStats(normalizedWindowHours, true);
            return { ...cached.data, isLoading: true };
        }

        if (cached.status === 'error' && (Date.now() - cached.fetchedAt) >= cacheTtlMs) {
            this._loadGeneralStats(normalizedWindowHours, true);
        }

        return cached.data;
    }

    setGeneralStatsWindowHours(windowHours = 24) {
        this.generalStatsWindowHours = this._normalizeStatsWindowHours(windowHours);
        Settings.set('stats.window.hours', this.generalStatsWindowHours);
        this.refreshGeneralStatsPanel(true);
    }

    _renderGeneralStatsHtml(stats, windowHours = 24) {
        const normalizedWindowHours = this._normalizeStatsWindowHours(windowHours);
        const renderValue = (value) => (value === null || value === undefined || value === '') ? '-' : value;
        const renderCount = (value) => Number.isFinite(Number(value)) ? Number(value).toLocaleString() : '-';
        const renderSnr = (v) => (v != null && Number.isFinite(Number(v))) ? `${Number(v).toFixed(1)} dB` : null;
        const normalizeChannelName = (rawName) => {
            const value = String(rawName ?? '').trim();
            if (!value) return 'Unknown';
            if (value.toLowerCase() === 'public') return 'Public';
            return value.startsWith('#') ? value : `#${value}`;
        };

        // Snr quality color
        const snrColor = (snr) => {
            if (snr == null) return 'rgba(205,220,238,0.35)';
            if (snr >= 8)  return '#6dd98c';
            if (snr >= 3)  return '#a3d96d';
            if (snr >= -2) return '#f0d060';
            if (snr >= -8) return '#f09040';
            return '#e05050';
        };

        // Window selector
        const windowButtons = [1, 24, 36].map((hours) => {
            const active = hours === normalizedWindowHours ? ' general-stats-range-active' : '';
            return `<button type="button" class="general-stats-range${active}" data-hours="${hours}">${hours}h</button>`;
        }).join('');

        // KPI metric cards
        const total    = Number(stats.totalReports)      || 0;
        const devices  = Number(stats.uniqueDevices)     || 0;
        const cols     = Number(stats.uniqueCollectors)  || 0;
        const chans    = Number(stats.channelTotals?.length) || 0;
        const advs     = Number(stats.totalAdvertisements) || 0;
        const direct   = Number(stats.directReports)     || 0;
        const flood    = Number(stats.floodReports)      || 0;
        const relay    = Number(stats.relayedReports)    || 0;
        const legacy   = Number(stats.unknownRouteReports) || 0;
        const noHop    = Number(stats.noHopReports)      || 0;

        const pct = (n) => total > 0 ? `${Math.round((n / total) * 100)}%` : '—';

        // Rate: packets per hour
        const rate = normalizedWindowHours > 0 ? (total / normalizedWindowHours).toFixed(1) : '—';

        const kpiCards = [
            { value: renderCount(devices),     label: 'Active devices',   sub: `${renderCount(advs)} advertisements`,  color: '#0099ff' },
            { value: renderCount(total),        label: 'Total reports',    sub: `~${rate}/h avg rate`,                  color: '#00ff33' },
            { value: renderCount(cols),         label: 'Collectors',       sub: `${renderCount(chans)} channels`,       color: '#cc33ff' },
            { value: renderCount(direct),       label: 'Direct links',     sub: `${pct(direct)} of reports`,            color: '#00ccff' },
        ].map((card) => `
            <div class="stats-kpi-card">
                <div class="stats-kpi-card-value" style="color:${card.color}">${card.value}</div>
                <div class="stats-kpi-card-label">${card.label}</div>
                <div class="stats-kpi-card-sub">${card.sub}</div>
            </div>`).join('');

        // Route breakdown — stacked proportional bar
        const routeBar = total > 0 ? (() => {
            const segments = [
                { count: direct, color: '#00ccff', label: 'Direct' },
                { count: flood,  color: '#00ff33', label: 'Flood' },
                { count: relay,  color: '#ff7700', label: 'Relay-hop' },
                { count: legacy, color: 'rgba(200,220,240,0.22)', label: 'Legacy' },
            ].filter(s => s.count > 0);
            const bars = segments.map(s =>
                `<span class="stats-route-bar-seg" style="width:${(s.count/total*100).toFixed(2)}%;background:${s.color}" data-chart-tooltip="${s.label}: ${s.count.toLocaleString()} (${pct(s.count)})"></span>`
            ).join('');
            const pills = segments.map(s =>
                `<span class="stats-route-pill"><span class="stats-route-dot" style="background:${s.color}"></span>${s.label} <b>${s.count.toLocaleString()}</b> <span class="stats-route-pct">${pct(s.count)}</span></span>`
            ).join('');
            const noHopNote = noHop > 0
                ? `<span class="stats-route-pill stats-route-pill-ghost" title="Reports received direct from sender with no relay hops in path">No-hop ${noHop.toLocaleString()}</span>`
                : '';
            return `
                <div class="stats-route-stacked-bar">${bars}</div>
                <div class="stats-route-legend">${pills}${noHopNote}</div>`;
        })() : '';

        const noteClassName = stats.hasError ? 'general-stats-note general-stats-note-error' : 'general-stats-note';
        const noteText = stats.isLoading && !stats.hasData ? 'Loading statistics…' : renderValue(stats.note);

        // Collector rows — segmented type bar + SNR badge
        const pkTypeColors = { adv:'#0099ff', dir:'#00ff33', pub:'#cc33ff', tel:'#ffdd00', sys:'#ff7700', ctrl:'#ff3333', raw:'rgba(200,220,238,0.35)' };
        const collectorMax = Math.max(1, ...stats.collectorTotals.map((r) => Number(r.totalPackets) || 0));
        const collectorRows = stats.collectorTotals.length > 0
            ? stats.collectorTotals.map((row) => {
                const reporter = this.reporters[row.reporterId] ?? null;
                const style = reporter ? reporter.getStyle() : (() => { try { return JSON.parse(row.style || '{}'); } catch { return {}; } })();
                const color = style?.color ?? '#4ea4c4';
                const shortKey = row.publicKey ? row.publicKey.slice(0, 10) : '';
                const pTotal = Number(row.totalPackets) || 0;
                const widthPct = Math.max(4, Math.round((pTotal / collectorMax) * 100));

                // Segmented type bar
                const typeSegs = [
                    { k: 'adv',  n: row.advPackets,  label: 'ADV'  },
                    { k: 'dir',  n: row.dirPackets,  label: 'DIR'  },
                    { k: 'pub',  n: row.pubPackets,  label: 'PUB'  },
                    { k: 'tel',  n: row.telPackets,  label: 'TEL'  },
                    { k: 'sys',  n: row.sysPackets,  label: 'SYS'  },
                    { k: 'ctrl', n: row.ctrlPackets, label: 'CTRL' },
                    { k: 'raw',  n: row.rawPackets,  label: 'RAW'  },
                ].filter(s => s.n > 0);

                const typeBarSegs = pTotal > 0
                    ? typeSegs.map(s =>
                        `<span style="width:${(s.n/pTotal*100).toFixed(2)}%;background:${pkTypeColors[s.k]}" data-chart-tooltip="${s.label}: ${s.n.toLocaleString()}"></span>`
                    ).join('')
                    : '';

                const breakdownPills = typeSegs.map(s =>
                    `<span class="coll-type-pill" style="color:${pkTypeColors[s.k]}">${s.label} ${s.n.toLocaleString()}</span>`
                ).join('');

                const snrLabel = renderSnr(row.avgSnr);
                const snrBadge = snrLabel
                    ? `<span class="collector-snr-badge" style="color:${snrColor(row.avgSnr)}" title="Avg ADV SNR: ${snrLabel}">${snrLabel}</span>`
                    : '';

                return `
                    <div class="collector-stats-compact-row">
                        <div class="collector-stats-head">
                            <div class="collector-stats-name-wrap">
                                <span class="collector-stats-swatch" style="background:${escapeXml(color)}"></span>
                                <span class="collector-stats-name">${escapeXml(row.reporterName)}</span>
                                <span class="collector-stats-key">${escapeXml(shortKey)}</span>
                            </div>
                            <div class="collector-stats-head-right">
                                ${snrBadge}
                                <span class="collector-stats-total">${renderCount(pTotal)}</span>
                            </div>
                        </div>
                        <div class="stats-segmented-bar-wrap">
                            <div class="stats-segmented-bar" style="--bar-w:${widthPct}%">${typeBarSegs}</div>
                        </div>
                        <div class="collector-stats-breakdown">${breakdownPills}</div>
                    </div>`;
            }).join('')
            : '<div class="general-stats-note">No collector packet activity recorded in this window.</div>';

        // Channel rows — message rate + unique sender badge
        const channelMax = Math.max(1, ...stats.channelTotals.map((r) => Number(r.totalMessages) || 0));
        const channelRows = stats.channelTotals.length > 0
            ? stats.channelTotals.map((row) => {
                const width = Math.max(4, Math.round(((Number(row.totalMessages) || 0) / channelMax) * 100));
                const theme = getChannelTheme(`${row.channelHash ?? ''}:${row.channelName ?? ''}`);
                const color = theme.badgeBg ?? theme.ring ?? '#8fb3c9';
                const keyLabel = row.channelHash ? row.channelHash.slice(0, 10) : `ID ${row.channelId}`;
                const msgs = Number(row.totalMessages) || 0;
                const senders = Number(row.uniqueSenders) || 0;
                const msgRate = normalizedWindowHours > 0 ? (msgs / normalizedWindowHours).toFixed(1) : '—';
                return `
                    <div class="collector-stats-compact-row">
                        <div class="collector-stats-head">
                            <div class="collector-stats-name-wrap">
                                <span class="collector-stats-swatch" style="background:${escapeXml(color)}"></span>
                                <span class="collector-stats-name">${escapeXml(normalizeChannelName(row.channelName))}</span>
                                <span class="collector-stats-key">${escapeXml(keyLabel)}</span>
                            </div>
                            <div class="collector-stats-head-right">
                                <span class="channel-sender-badge">${senders} sender${senders !== 1 ? 's' : ''}</span>
                                <span class="collector-stats-total">${renderCount(msgs)}</span>
                            </div>
                        </div>
                        <div class="stats-segmented-bar-wrap">
                            <div class="stats-segmented-bar" style="--bar-w:${width}%"><span style="width:100%;background:${escapeXml(color)}"></span></div>
                        </div>
                        <div class="collector-stats-breakdown"><span class="coll-type-pill" style="color:rgba(205,220,238,0.7)">~${msgRate} msg/h</span></div>
                    </div>`;
            }).join('')
            : '';

        const channelSection = channelRows
            ? `<section class="settings-card general-stats-shell">
                <div class="stats-section-head">
                    <span class="stats-section-title">Channels</span>
                    <div style="display:flex;align-items:center;gap:6px">
                        <span class="general-stats-meta">${stats.channelTotals.length} active · last ${normalizedWindowHours}h</span>
                        <button type="button" class="settings-help-btn" data-help="stats" title="About Channels">?</button>
                    </div>
                </div>
                <div class="collector-stats-list">${channelRows}</div>
               </section>`
            : '';

        return `
            <section class="settings-card general-stats-shell">
                <div class="general-stats-toolbar">
                    <div class="general-stats-range-group">${windowButtons}</div>
                    <div class="general-stats-toolbar-right">
                        <span class="general-stats-meta">History: ${renderValue(stats.loadedSpanLabel)}</span>
                        <button type="button" class="settings-help-btn" data-help="stats" title="About Stats">?</button>
                    </div>
                </div>
                <div class="stats-kpi-grid">${kpiCards}</div>
                ${routeBar ? `<div class="stats-route-breakdown">${routeBar}</div>` : ''}
                <div class="${noteClassName}">${noteText}</div>
            </section>
            <section class="settings-card general-stats-shell">
                <div class="stats-charts-row">
                    <div class="stats-chart-half">
                        <div class="stats-chart-label">ADV reports · ${normalizedWindowHours}h</div>
                        ${stats.chartSvg}
                    </div>
                    <div class="stats-chart-half">
                        <div class="stats-chart-label">Unique devices · ${normalizedWindowHours}h</div>
                        ${stats.uniqueDeviceChartSvg}
                    </div>
                </div>
            </section>
            <section class="settings-card general-stats-shell">
                <div class="stats-section-head">
                    <span class="stats-section-title">Collectors</span>
                    <div style="display:flex;align-items:center;gap:6px">
                        <span class="general-stats-meta">${stats.collectorTotals.length} active · last ${normalizedWindowHours}h</span>
                        <button type="button" class="settings-help-btn" data-help="stats" title="About Collectors">?</button>
                    </div>
                </div>
                <div class="collector-stats-list">${collectorRows}</div>
            </section>
            ${channelSection}
        `;
    }

    refreshGeneralStatsPanel(forceRefresh = false) {
        if (!this.dom_general_stats) return;

        const normalizedWindowHours = this._normalizeStatsWindowHours(this.generalStatsWindowHours);
        if (forceRefresh) {
            this._loadGeneralStats(normalizedWindowHours, true);
        }

        const stats = this.getGeneralStats(normalizedWindowHours);
        this.dom_general_stats.innerHTML = this._renderGeneralStatsHtml(stats, normalizedWindowHours);
    }

    toggleHeatmap() {
        this._heatmapEnabled = !this._heatmapEnabled;
        Settings.set('heatmap.enabled', this._heatmapEnabled ? '1' : '0');
        if (this._heatmapEnabled) {
            this._loadHeatmapData(this.heatmapWindowHours);
        } else {
            this._removeHeatmapLayer();
        }
        this.updateHeatmapMenuState();
    }

    setHeatmapWindow(hours) {
        this.heatmapWindowHours = this._normalizeStatsWindowHours(hours);
        Settings.set('heatmap.window.hours', this.heatmapWindowHours);
        if (this._heatmapEnabled) {
            this._loadHeatmapData(this.heatmapWindowHours);
        }
        this.updateHeatmapMenuState();
    }

    updateHeatmapMenuState() {
        const toggleBtn = document.getElementById('map-heatmap-toggle-btn');
        if (toggleBtn) {
            toggleBtn.textContent = this._heatmapEnabled ? 'On' : 'Off';
            toggleBtn.classList.toggle('map-menu-heatmap-toggle-active', this._heatmapEnabled);
        }
        document.querySelectorAll('.map-menu-heatmap-window-btn').forEach((btn) => {
            btn.classList.toggle('active', parseInt(btn.dataset.hours) === this.heatmapWindowHours);
        });
    }

    _loadHeatmapData(windowHours) {
        const wh = this._normalizeStatsWindowHours(windowHours);
        const statusEl = document.getElementById('map-heatmap-status');
        if (statusEl) statusEl.textContent = 'loading…';
        fetch(`api/v1/stats/heatmap/?window_hours=${wh}`)
            .then((r) => r.json())
            .then((data) => {
                if (Array.isArray(data.points) && data.points.length > 0) {
                    this._applyHeatmapLayer(data.points);
                    if (statusEl) statusEl.textContent = `${data.points.length} pts`;
                } else {
                    this._removeHeatmapLayer();
                    if (statusEl) statusEl.textContent = 'no data';
                }
            })
            .catch((e) => {
                console.warn('[Heatmap] fetch failed:', e);
                if (statusEl) statusEl.textContent = 'error';
            });
    }

    _applyHeatmapLayer(points) {
        if (!this.map || typeof L === 'undefined' || typeof L.heatLayer !== 'function') return;
        if (this._heatmapLayer) {
            this._heatmapLayer.setLatLngs(points);
        } else {
            this._heatmapLayer = L.heatLayer(points, {
                radius: 28,
                blur: 18,
                maxZoom: 17,
                max: 10,
                gradient: { 0.2: '#2166ac', 0.45: '#74add1', 0.65: '#fee090', 0.85: '#f46d43', 1.0: '#d73027' },
            }).addTo(this.map);
        }
    }

    _removeHeatmapLayer() {
        if (this._heatmapLayer && this.map) {
            this.map.removeLayer(this._heatmapLayer);
            this._heatmapLayer = null;
        }
        const statusEl = document.getElementById('map-heatmap-status');
        if (statusEl) statusEl.textContent = '';
    }

    // ── Coverage spot overlay ───────────────────────────────────────────────

    _normalizeCoverageWindowHours(hours) {
        return [24, 168, 720].includes(Number(hours)) ? Number(hours) : 168;
    }

    toggleCoverage() {
        this._coverageEnabled = !this._coverageEnabled;
        Settings.set('coverage.enabled', this._coverageEnabled ? '1' : '0');
        if (this._coverageEnabled) {
            this._loadCoverageSpots(this._coverageWindowHours);
        } else {
            this._removeCoverageLayer();
        }
        this._updateCoverageMenuState();
    }

    setCoverageWindow(hours) {
        this._coverageWindowHours = this._normalizeCoverageWindowHours(hours);
        Settings.set('coverage.window.hours', this._coverageWindowHours);
        if (this._coverageEnabled) {
            this._loadCoverageSpots(this._coverageWindowHours);
        }
        this._updateCoverageMenuState();
    }

    _updateCoverageMenuState() {
        const toggleBtn = document.getElementById('map-coverage-toggle-btn');
        if (toggleBtn) {
            toggleBtn.textContent = this._coverageEnabled ? 'On' : 'Off';
            toggleBtn.classList.toggle('map-menu-coverage-toggle-active', this._coverageEnabled);
        }
        document.querySelectorAll('.map-menu-coverage-window-btn').forEach((btn) => {
            btn.classList.toggle('active', parseInt(btn.dataset.hours) === this._coverageWindowHours);
        });
    }

    _loadCoverageSpots(windowHours) {
        const wh = this._normalizeCoverageWindowHours(windowHours);
        const statusEl = document.getElementById('map-coverage-status');
        if (statusEl) statusEl.textContent = 'loading…';
        fetch(`api/v1/coverage/?window_hours=${wh}`)
            .then((r) => r.json())
            .then((data) => {
                if (Array.isArray(data.spots) && data.spots.length > 0) {
                    this._renderCoverageSpots(data.spots);
                    if (statusEl) statusEl.textContent = `${data.spots.length} cells`;
                } else {
                    this._removeCoverageLayer();
                    if (statusEl) statusEl.textContent = 'no data';
                }
            })
            .catch((e) => {
                console.warn('[Coverage] fetch failed:', e);
                if (statusEl) statusEl.textContent = 'error';
            });
    }

    _snrToColor(snr) {
        if (snr >= 5)   return '#4caf50'; // strong — green
        if (snr >= 0)   return '#8bc34a'; // good — light green
        if (snr >= -5)  return '#ffeb3b'; // fair — yellow
        if (snr >= -10) return '#ff9800'; // weak — orange
        return '#f44336';                  // poor — red
    }

    _renderCoverageSpots(spots) {
        if (!this.map || typeof L === 'undefined') return;
        this._removeCoverageLayer();
        const markers = spots.map((spot) => {
            const color = this._snrToColor(spot.avg_snr);
            const radius = 3 + Math.min(4, Math.log10(spot.report_count + 1) * 3);
            const marker = L.circleMarker([spot.lat, spot.lon], {
                radius,
                color,
                weight: 0,
                fillColor: color,
                fillOpacity: 0.75,
                interactive: true,
            });
            marker.bindTooltip(
                `SNR: ${spot.avg_snr} dB · ${spot.report_count} report${spot.report_count !== 1 ? 's' : ''}`,
                { direction: 'top', offset: [0, -4], opacity: 0.92, className: 'map-coverage-tooltip' }
            );
            return marker;
        });
        this._coverageLayer = L.layerGroup(markers).addTo(this.map);
    }

    _removeCoverageLayer() {
        if (this._coverageLayer && this.map) {
            this.map.removeLayer(this._coverageLayer);
            this._coverageLayer = null;
        }
        const statusEl = document.getElementById('map-coverage-status');
        if (statusEl) statusEl.textContent = '';
    }

    _initMapPanes() {
        // Route lines sit between tiles and markers so they're always visible beneath nodes.
        const routePane = this.map.createPane(MeshLog.ROUTE_PANE);
        routePane.style.zIndex = '450';
        routePane.style.pointerEvents = 'auto';

        const routePointPane = this.map.createPane(MeshLog.ROUTE_POINT_PANE);
        routePointPane.style.zIndex = '610';
        routePointPane.style.pointerEvents = 'auto';

        // Background (non-highlighted) markers sit ABOVE route lines so they stay clickable
        // even when routes are displayed.  Previously this was 350 (below routes at 500),
        // which let glow-lines and animation trails intercept click events on markers.
        const backgroundPane = this.map.createPane(MeshLog.MARKER_PANE_BACKGROUND);
        backgroundPane.style.zIndex = '520';
        backgroundPane.style.pointerEvents = 'auto';

        // Highlighted / active markers sit on top of everything.
        const routeMarkerPane = this.map.createPane(MeshLog.MARKER_PANE_ROUTE);
        routeMarkerPane.style.zIndex = '650';
        routeMarkerPane.style.pointerEvents = 'auto';

        // Live animated route lines float above all markers.
        // pointer-events: none so they never block clicks on device bubbles.
        const liveRoutePane = this.map.createPane(MeshLog.LIVE_ROUTE_PANE);
        liveRoutePane.style.zIndex = '660';
        liveRoutePane.style.pointerEvents = 'none';
    }

    getMarkerPaneName(active = false) {
        return active ? MeshLog.MARKER_PANE_ROUTE : MeshLog.MARKER_PANE_BACKGROUND;
    }

    _stopRouteLineAnimations() {
        for (let i = 0; i < this.routeAnimationFrames.length; i++) {
            cancelAnimationFrame(this.routeAnimationFrames[i].id);
        }
        this.routeAnimationFrames = [];
    }

    _cleanupTransientRouteAnimations() {
        this.transientRouteAnimations = this.transientRouteAnimations.filter(animation => {
            if (animation.done) {
                if (animation.layer && this.map.hasLayer(animation.layer)) {
                    this.map.removeLayer(animation.layer);
                }
                return false;
            }
            return true;
        });
    }

    _trimTransientRouteAnimations() {
        this._cleanupTransientRouteAnimations();
        while (this.transientRouteAnimations.length >= MeshLog.MAX_TRANSIENT_ROUTE_ANIMATIONS) {
            const animation = this.transientRouteAnimations.shift();
            if (!animation) break;
            animation.done = true;
            if (animation.frameId) {
                cancelAnimationFrame(animation.frameId);
            }
            if (animation.layer && this.map.hasLayer(animation.layer)) {
                this.map.removeLayer(animation.layer);
            }
        }
    }

    // Close any open tooltips bound to markers to avoid lingering boxes after
    // programmatic map interactions (zoom/pan) where Leaflet may not emit
    // mouseout events for every marker.
    closeAllMarkerTooltips(debounce = false) {
        if (debounce) {
            if (this._tooltipPruneTimer) clearTimeout(this._tooltipPruneTimer);
            this._tooltipPruneTimer = setTimeout(() => this.closeAllMarkerTooltips(false), 100);
            return;
        }

        const preservedId = this.selectedMarkerId ?? this.previewFocusedContactId ?? null;

        // Close marker hover tooltips on all known marker instances.
        // Keep them bound so ordinary hover remains stable and usable.
        try {
            Object.values(this.contacts).forEach(c => {
                if (c && c.marker) {
                    if (preservedId !== null && c.data?.id === preservedId) return;
                    try { c.marker.closeTooltip(); } catch (_) { void _; }
                }
            });
        } catch (_) { void _; }
        try {
            Object.values(this.reporters).forEach(r => {
                if (r && r.marker) {
                    try { r.marker.closeTooltip(); } catch (_) { void _; }
                }
            });
        } catch (_) { void _; }

        // Remove any leftover marker mini-tooltip DOM nodes inside the map container.
        // Do not touch other tooltip types such as route hop distance tooltips.
        try {
            if (preservedId !== null) return;
            const container = this.map && this.map.getContainer ? this.map.getContainer() : document;
            const tooltips = container.querySelectorAll ? container.querySelectorAll('.leaflet-tooltip.mini-tooltip') : [];
            for (const t of tooltips) {
                if (t && t.parentNode) {
                    t.parentNode.removeChild(t);
                }
            }
        } catch (_) { void _; }
    }

    clearSelection() {
        try {
            this._destroyActiveContactTrailMap();
            this._hideActiveRouteTrailPoint();
            if (this.previewFocusedContactId) {
                const previewContact = this.contacts[this.previewFocusedContactId] ?? null;
                try { previewContact?.showLabel(false); } catch (_) { void _; }
            }
            this.previewFocusedContactId = null;
            this.selectedMarkerId = null;
            this.activePopupContactId = null;
            this._hideMapSearchResults?.();
            // close any open popups
            try { this.map.closePopup(); } catch (_) { void _; }
            this.activeContactPopup = null;
            // remove leftover popup DOM nodes
            try {
                const container = this.map && this.map.getContainer ? this.map.getContainer() : document;
                const popups = container.querySelectorAll ? container.querySelectorAll('.leaflet-popup') : [];
                for (const p of popups) {
                    if (p && p.parentNode) p.parentNode.removeChild(p);
                }
            } catch (_) { void _; }
            this.visible_markers.clear();
            this.fadeMarkers(1);
        } catch (_) { void _; }
    }

    _startRouteLineAnimation(lineGlow, line2, baseWeight) {
        const start = performance.now();
        const handle = { id: 0 };
        this.routeAnimationFrames.push(handle);

        const animate = (now) => {
            const elapsed = now - start;
            const pulse = 0.45 + (Math.sin(elapsed / 240) * 0.15);
            const width = baseWeight + (Math.sin(elapsed / 190) * 0.9);

            lineGlow.setStyle({ opacity: Math.max(0.28, pulse) });
            line2.setStyle({ weight: Math.max(2.5, width) });

            handle.id = requestAnimationFrame(animate);
        };

        handle.id = requestAnimationFrame(animate);
    }

    handleMouseEvent(e) {
        const neighborButton = e.target.closest('.contact-neighbors-btn');
        if (neighborButton) {
            e.preventDefault();
            e.stopPropagation();

            const contactId = Number(neighborButton.dataset.contactId);
            if (!Number.isFinite(contactId)) return;
            const contact = this.contacts[contactId] ?? null;
            if (!contact) return;

            contact.toggleNeighbors();
            contact.updateDom();
            return;
        }

        const el = e.target.closest('.log-entry');
        if (!el) return;

        const from = e.relatedTarget;
        const related = (from && el.contains(from));

        const instance = el.instance;
        if (!instance) return;

        const cls = instance.constructor;

        switch (e.type) {
            case 'click':
                if (cls.onclick) cls.onclick.call(instance, e);
                break;
            case 'mouseover':
                if (!related && cls.onmouseover) cls.onmouseover.call(instance, e);
                break;
            case 'mouseout':
                if (!related && cls.onmouseout) cls.onmouseout.call(instance, e);
                break;
            case 'contextmenu':
                if (cls.oncontextmenu) cls.oncontextmenu.call(instance, e);
                break;
        }
    };

    __createCb(label, img, key, def, onchange) {
        let div = document.createElement("div");
        let cb = document.createElement("input");
        let lbl = document.createElement("label");
        let ico = document.createElement("img");

        cb.type = "checkbox";
        cb.checked = Settings.getBool(key, def);
        cb.onchange = (e) => {
            Settings.set(key, e.target.checked);
            onchange(e);
        };

        lbl.innerText = label;

        if (img) {
            ico.src = img;
            ico.classList.add('icon-16');
            lbl.prepend(ico);
        }

        lbl.insertBefore(cb, lbl.firstChild);

        div.classList.add("settings-cb");
        div.appendChild(lbl);

        return div;
    }

    __createBadgeToggle(label, key, def, onchange, options = {}) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'selector-badge';
        button.innerText = label;

        const applyTheme = (theme) => {
            if (!theme) return;
            button.style.setProperty('--badge-bg', theme.background);
            button.style.setProperty('--badge-border', theme.border);
            button.style.setProperty('--badge-text', theme.text);
            button.style.setProperty('--badge-solid', theme.solid);
        };

        const setActive = (active) => {
            button.classList.toggle('active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        };

        const setDisabled = (disabled) => {
            button.disabled = disabled;
            button.classList.toggle('is-disabled', disabled);
        };

        applyTheme(options.theme);
        applyPresentation(button, { title: options.title ?? label }, options.title ?? label);
        setActive(Settings.getBool(key, def));
        setDisabled(Boolean(options.disabled));

        button.addEventListener('click', () => {
            if (button.disabled) return;
            const nextValue = !Settings.getBool(key, def);
            Settings.set(key, nextValue);
            setActive(nextValue);
            onchange({ target: { checked: nextValue, value: nextValue } });
        });

        return { button, setActive, setDisabled };
    }

    __init_filter_layout() {
        this.dom_settings_types.replaceChildren();

        const createGroup = (title, helpText) => {
            const section = document.createElement('section');
            section.className = 'settings-card';

            const heading = document.createElement('div');
            heading.className = 'settings-card-heading';

            const headingTitle = document.createElement('div');
            headingTitle.className = 'settings-card-title';
            headingTitle.innerText = title;

            const helpBtn = document.createElement('button');
            helpBtn.type = 'button';
            helpBtn.className = 'settings-help-btn';
            helpBtn.innerText = '?';
            helpBtn.title = helpText;
            helpBtn.addEventListener('click', () => {
                if (typeof window.openAppHelp === 'function') window.openAppHelp(title, helpText);
            });

            const badgeRow = document.createElement('div');
            badgeRow.className = 'settings-badge-row';

            const rightBtns = document.createElement('span');
            rightBtns.style.cssText = 'display:flex;gap:4px;align-items:center;flex-shrink:0';
            rightBtns.append(helpBtn);

            heading.append(headingTitle, rightBtns);
            section.append(heading, badgeRow);
            this.dom_settings_types.append(section);

            return { badgeRow, section };
        };

        const liveFeedGroup = createGroup(
            'Live Feed Filters',
            'Toggle which packet types appear in the live feed below. Choices are saved per browser.\n\nAdvertisements — periodic node beacons.\nChannel Messages — decoded group/channel messages.\nDirect Messages — unicast messages.\nRaw Packets — undecoded or encrypted packets.\nTelemetry — sensor and measurement updates.\nSystem Reports — self-reported device health and firmware data.\nNotifications — browser push alerts for new entries.'
        );
        this.dom_type_badges = liveFeedGroup.badgeRow;
        this.dom_type_badges_section = liveFeedGroup.section;
        const collectorsGroup = createGroup(
            'Collectors',
            'Filter the live feed to only show packets received by the selected collector nodes. All collectors are active by default when no preference has been saved.'
        );
        this.dom_collectors_section = collectorsGroup.section;
        const channelGroup = createGroup(
            'Channel Filters',
            'Show or hide individual channels in the live feed. Each channel has a unique color that matches its badge in the feed entries.\n\nChannels shown as grey/dim have been disabled in the Admin panel and cannot be toggled here.'
        );
        this.dom_channel_badges = channelGroup.badgeRow;
    }

    __createInput(name, key, def, onchange) {
        let div = document.createElement("div");
        let inp = document.createElement("input");

        inp.type = "text";
        inp.value = Settings.get(key, def) ?? def;
        inp.oninput = (e) => {
            Settings.set(key, e.target.value);
            onchange(e);
        };
        inp.placeholder = name;

        div.classList.add("settings-input");
        div.appendChild(inp);

        return div;
    }

    __onTypesChanged() {
        this.updateMessagesDom();
        this.updateMarkersForFilter();
    }

    // Show/hide every contact map marker based on the active collector filter.
    updateMarkersForFilter() {
        Object.values(this.contacts).forEach(c => c.syncMarkerVisibility());
    }

    sortContacts(fn=undefined, reverse=false) {
        if (!fn) {
            fn = this.order.fn;
            reverse = this.order.reverse;
        }

        const items = Array.from(this.dom_contacts.children);
        items.sort(fn);
        if (reverse) items.reverse();
        items.forEach(item => {
            let type = parseInt(item.dataset.type);
            let hidden = false;;
            if (type == 1 && !Settings.getBool('contactTypes.clients', true)) { hidden = true; }
            else if (type == 2 && !Settings.getBool('contactTypes.repeaters', true)) { hidden = true; }
            else if (type == 3 && !Settings.getBool('contactTypes.rooms', true)) { hidden = true; }
            else if (type == 4 && !Settings.getBool('contactTypes.sensors', true)) { hidden = true; }

            if (!hidden) {
                let filter = Settings.get('contactFilter.value', '').trim().toLowerCase();
                if (filter) {
                    let cmp1 = (item.dataset.name ?? '').toLowerCase().includes(filter);
                    let cmp2 = (item.dataset.hash ?? '').toLowerCase().includes(filter);
                    hidden = !cmp1 && !cmp2;
                }
            }

            item.hidden = hidden;
            this.dom_contacts.appendChild(item)
        });
    }

    __init_contact_order() {
        let orders = [
            {
                name: 'Last Heard',
                fn: (a, b) => { 
                    return (Number(b.dataset.time) - Number(a.dataset.time));
                }
            },
            {
                name: 'Hash',
                fn: (a, b) => { 
                    return parseInt(`0x${a.dataset.hash}`) - parseInt(`0x${b.dataset.hash}`);
                }
            },
            {
                name: 'Name',
                fn: (a, b) => { 
                    return (a.dataset.name ?? '').localeCompare(b.dataset.name ?? '');
                },
            },
            {
                name: 'First Seen',
                fn: (a, b) => {
                    return (b.dataset.first_seen ?? '').localeCompare(a.dataset.first_seen ?? '');
                },
            },
        ];

        this.order = {
            fn: orders[0].fn,
            reverse: false,
            buttons: []
        };

        let container = document.createElement('div');
        let text = document.createElement('span');
        container.append(text);

        const self = this;
        for (let i=0;i<orders.length;i++) {
            let btn = document.createElement('button');
            btn.classList.add('btn');
            btn.innerText = orders[i].name;

            if (i == 0) {
                btn.classList.add('active');
            }

            btn.onclick = (e) => {
                for (const b of self.order.buttons) {
                    b.classList.remove('active');
                    b.classList.remove('reverse');
                }

                btn.classList.add('active');

                if (self.order.fn == orders[i].fn) {
                    self.order.reverse = !self.order.reverse;
                    if (self.order.reverse) {
                        btn.classList.add('reverse');
                    }
                } else {
                    self.order.fn = orders[i].fn;
                    self.order.reverse = false;
                }
                self.sortContacts();
            }
            this.order.buttons.push(btn);
            container.appendChild(btn);
        }

        this.dom_settings_contacts.appendChild(container);
    }

    __init_contact_types() {
        const self = this;
        this.dom_settings_contacts.appendChild(
            this.__createCb(
                "",
                "assets/img/tower.svg",
                'contactTypes.repeaters',
                true,
                (e) => {
                    self.sortContacts();
                }
            )
        );
        this.dom_settings_contacts.appendChild(
            this.__createCb(
                "",
                "assets/img/person.svg",
                'contactTypes.clients',
                true,
                (e) => {
                    self.sortContacts();
                }
            )
        );
        this.dom_settings_contacts.appendChild(
            this.__createCb(
                "",
                "assets/img/group.svg",
                'contactTypes.rooms',
                true,
                (e) => {
                    self.sortContacts();
                }
            )
        );
        this.dom_settings_contacts.appendChild(
            this.__createCb(
                "",
                "assets/img/sensor.svg",
                'contactTypes.sensors',
                true,
                (e) => {
                    self.sortContacts();
                }
            )
        );
        this.dom_settings_contacts.appendChild(
            this.__createInput(
                "Filter by Name or Hash/Key",
                'contactFilter.value',
                '',
                (e) => {
                    self.sortContacts();
                }
            )
        );
    }

    __init_message_types() {
        const self = this;
        this.dom_type_badges.appendChild(
            this.__createBadgeToggle(
                "Advertisements",
                'messageTypes.advertisements',
                true,
                (e) => {
                    self.__onTypesChanged();
                },
                {
                    title: 'Show or hide advertisement entries in the live feed'
                }
            )
            .button
        );

        this.dom_type_badges.appendChild(
            this.__createBadgeToggle(
                "Channel Messages",
                'messageTypes.channel',
                true,
                (e) => {
                    self.__onTypesChanged();
                },
                {
                    title: 'Show or hide decoded channel messages in the live feed'
                }
            )
            .button
        );

        this.dom_type_badges.append(
            this.__createBadgeToggle(
                "Direct Messages",
                'messageTypes.direct',
                false,
                (e) => {
                    self.__onTypesChanged();
                },
                {
                    title: 'Show or hide decoded direct messages in the live feed'
                }
            )
            .button
        );

        this.dom_type_badges.append(
            this.__createBadgeToggle(
                "Raw Packets",
                'messageTypes.raw',
                false,
                (e) => {
                    self.__onTypesChanged();
                },
                {
                    title: 'Show or hide stored raw packets in the live feed. Enable to use the per-type filters below.'
                }
            )
            .button
        );

        // Per-subtype raw packet filters (active when the Raw Packets master is on)
        const rawSubTypes = [
            { label: 'REQ',      type: 0,  title: 'PAYLOAD_TYPE_REQ — encrypted unicast request' },
            { label: 'RESP',     type: 1,  title: 'PAYLOAD_TYPE_RESPONSE — encrypted unicast response' },
            { label: 'MSG',      type: 2,  title: 'PAYLOAD_TYPE_TXT_MSG — encrypted direct text message' },
            { label: 'ACK',      type: 3,  title: 'PAYLOAD_TYPE_ACK — 4-byte CRC acknowledgment' },
            { label: 'ADV',      type: 4,  title: 'PAYLOAD_TYPE_ADVERT — advertisement (undecoded fallback)' },
            { label: 'PUB',      type: 5,  title: 'PAYLOAD_TYPE_GRP_TXT — group message (undecryptable channel)' },
            { label: 'GRP DATA', type: 6,  title: 'PAYLOAD_TYPE_GRP_DATA — group datagram (undecryptable channel)' },
            { label: 'ANON',     type: 7,  title: 'PAYLOAD_TYPE_ANON_REQ — anonymous encrypted request' },
            { label: 'PATH',     type: 8,  title: 'PAYLOAD_TYPE_PATH — returned path packet' },
            { label: 'TRACE',    type: 9,  title: 'PAYLOAD_TYPE_TRACE — path trace with per-hop SNR' },
            { label: 'MULTI',    type: 10, title: 'PAYLOAD_TYPE_MULTIPART — multi-part packet fragment' },
            { label: 'CTRL',     type: 11, title: 'PAYLOAD_TYPE_CONTROL — control / discovery packet' },
            { label: 'CUST',     type: 15, title: 'PAYLOAD_TYPE_RAW_CUSTOM — application-defined raw packet' },
        ];
        const rawSubRow = document.createElement('div');
        rawSubRow.className = 'raw-subtype-row';
        rawSubTypes.forEach(({ label, type, title }) => {
            const key = `messageTypes.rawtype.${type}`;
            const { button } = this.__createBadgeToggle(
                label, key, true,
                () => self.__onTypesChanged(),
                { title }
            );
            button.classList.add('raw-subtype-btn');
            rawSubRow.append(button);
        });
        this.dom_type_badges.append(rawSubRow);

        this.dom_type_badges.append(
            this.__createBadgeToggle(
                "Telemetry",
                'messageTypes.telemetry',
                false,
                (e) => {
                    self.__onTypesChanged();
                },
                {
                    title: 'Show or hide telemetry packets in the live feed'
                }
            )
            .button
        );

        this.dom_type_badges.append(
            this.__createBadgeToggle(
                "System Reports",
                'messageTypes.system',
                false,
                (e) => {
                    self.__onTypesChanged();
                },
                {
                    title: 'Show or hide system/self reports in the live feed'
                }
            )
            .button
        );

        this.dom_type_badges.append(
            this.__createBadgeToggle(
                'Notifications',
                'notifications.enabled',
                false,
                (e) => { },
                {
                    title: 'Enable or disable browser notifications for newly received feed entries'
                }
            )
            .button
        );
    }

    __init_reporters() {
        Object.entries(this.reporters).forEach(([id,_]) => {
            let reporter = this.reporters[id];
            if (reporter.hasOwnProperty('dom')) {
                return;
            }
            const self = this;
            this.reporters[id].enabled = true;
            this.dom_settings_reporters.hidden = true;
        });
        this.__init_collector_filter();
    }

    isReporterAllowed(reporter_id) {
        // Use readCookie directly to avoid auto-writing a default:
        // undefined = no preference saved → allow all
        // ''        = user explicitly deselected all → allow none
        // 'id1,id2' = allow only those IDs
        const raw = Settings.readCookie('reporterFilter.selected');
        if (raw === undefined) return true;
        if (!raw) return false;
        return raw.split(',').some(s => s.trim() === String(reporter_id));
    }

    __init_collector_filter() {
        const self = this;
        if (this._collectorFilterRow) {
            this._collectorFilterRow.remove();
            this._collectorFilterRow = null;
        }
        const reporters = Object.values(this.reporters);
        const container = this.dom_collectors_section ?? this.dom_type_badges_section ?? this.dom_type_badges;
        if (!container) return;
        if (reporters.length === 0) return;

        const row = document.createElement('div');
        row.className = 'collector-filter-row';
        this._collectorFilterRow = row;

        // Read without writing a default — undefined means "all selected" (no preference stored).
        const isAllDefault = () => Settings.readCookie('reporterFilter.selected') === undefined;
        const getSelected = () => {
            const raw = Settings.readCookie('reporterFilter.selected');
            if (raw === undefined) return new Set();
            return new Set(raw ? raw.split(',').map(s => s.trim()).filter(Boolean) : []);
        };
        const refreshBtns = () => {
            const defaultAll = isAllDefault();
            const sel = getSelected();
            row.querySelectorAll('.collector-btn').forEach(b => {
                b.classList.toggle('active', defaultAll || sel.has(b.dataset.reporterId));
            });
        };

        reporters.forEach(reporter => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'collector-btn';
            const btnLabel = reporter.data.name || reporter.data.public_key.slice(0, 8);
            btn.textContent = btnLabel;
            btn.dataset.reporterId = String(reporter.data.id);
            btn.title = `Filter live feed to collector: ${btnLabel}`;
            // Active by default when no preference is saved (isAllDefault)
            if (isAllDefault() || getSelected().has(String(reporter.data.id))) {
                btn.classList.add('active');
            }
            btn.addEventListener('click', () => {
                const rid = String(reporter.data.id);
                let sel;
                if (isAllDefault()) {
                    // First interaction: expand implicit "all" into an explicit full set,
                    // then toggle the clicked reporter out of it.
                    sel = new Set(reporters.map(r => String(r.data.id)));
                } else {
                    sel = getSelected();
                }
                if (sel.has(rid)) { sel.delete(rid); } else { sel.add(rid); }
                Settings.set('reporterFilter.selected', [...sel].join(','));
                refreshBtns();
                self.__onTypesChanged();
            });
            row.append(btn);
        });

        container.append(row);
    }

    __init_warnings() {
        this.dom_warning_messages = document.createElement("div");
        this.dom_warning_messages.classList.add("warnings");
        this.dom_warning_messages_btn = document.createElement("button");
        this.dom_warning_messages_btn.innerText = "Show less";
        this.dom_warning_messages_btn.classList.add("btn");
        this.dom_warning_messages_btn.onclick = (e) => {
            let str = "Show more";
            let com = "1";
            if (this.dom_warning.dataset.compact === com) {
                com = "0";
                str = "Show less";
            }
            this.dom_warning.dataset.compact = com;
            this.dom_warning_messages_btn.innerText = str;
            this.updatePaths();
        }

        this.dom_warning.append(this.dom_warning_messages);
        this.dom_warning.append(this.dom_warning_messages_btn);
    }

    __addObject(dataset, id, obj) {
        if (dataset.hasOwnProperty(id)) {
            dataset[id].merge(obj.data);
        } else {
            dataset[id] = obj;
        }
    }

    __prepareQuery(params={}) {
        let query = {};
        // bax date
        if (params.hasOwnProperty('after_ms')) {
            query.after_ms = params['after_ms'];
        }
        // min date
        if (params.hasOwnProperty('before_ms')) {
            query.before_ms = params['before_ms'];
        }

        // max count
        if (params.hasOwnProperty('count')) {
            query.before = params['count'];
        }

        // reporter ids
        if (params.hasOwnProperty('reporters')) {
            query.reporters = params['reporters'];
        }

        return query;
    }

    __fetchJson(url, query, onResponse, onError = null) {
        const urlparams = new URLSearchParams();

        for (const key in query) {
            if (query.hasOwnProperty(key)) {
                const value = query[key];
                if (Array.isArray(value)) {
                    // For arrays, append each item with same key
                    value.forEach(item => urlparams.append(key, item));
                } else if (value !== undefined && value !== null) {
                    urlparams.append(key, value);
                }
            }
        }

        fetch(`${url}?${urlparams.toString()}`)
            .then(response => response.json())
            .then(data => onResponse(data))
            .catch(error => {
                if (typeof onError === 'function') {
                    onError(error);
                }
            });
    }

    __fetchQuery(params, url, onResponse, onError = null) {
        const query = this.__prepareQuery(params);
        this.__fetchJson(url, query, onResponse, onError);
    }

    showWarning(msg) {
        this.dom_warning_messages.innerText = msg;
        if (msg.length > 0) {
            this.dom_warning.hidden = false;
        } else {
            this.dom_warning.hidden = true;
        }
    }

    getContactLabel(contactId) {
        const contact = this.contacts[contactId] ?? false;
        if (!contact) return null;
        return contact.adv?.data?.name ?? contact.data?.name ?? `Node ${contactId}`;
    }

    showError(message, timeout=0) {
        this.setAutorefresh(0);
        this.dom_error.innerHTML = message;
        this.dom_error.classList.add('show');

        // Auto-hide after duration
        const self = this;
        if (timeout > 0) {
           setTimeout(() => {
                self.dom_error.classList.remove('show');
            }, timeout);
        }
    }

    __loadObjects(dataset, data, klass) {
        if (data.error) {
            this.showError(data.error);
            return 0;
        }

        for (let i=0;i<data.objects.length;i++) {
            const o = data.objects[i];
            const obj = new klass(this, o);
            const id = klass.idPrefix + o.id;
            this.__addObject(dataset, id, obj);

            if (o.created_at) {
                const created_at = parseMeshlogTimestamp(o.created_at);
                if (created_at > 0 && !isNaN(created_at) && created_at > this.latest) {
                    this.latest = created_at;
                }
            }
        }

        return data.objects;
    }

    _removeContactByKey(contactKey) {
        const contact = this.contacts[contactKey] ?? null;
        if (!contact) return false;

        const contactId = Number(contact.data?.id);
        if (this.selectedMarkerId === contactId || this.previewFocusedContactId === contactId || this.activePopupContactId === contactId) {
            this.clearSelection();
        }

        if (this.isRouteTrailVisible(contactId)) {
            this.hideRouteTrail(contactId);
        }

        if (contact.marker) {
            try {
                contact.marker.closeTooltip();
                contact.marker.unbindTooltip();
                contact.marker.remove();
            } catch (_error) {
                void _error;
            }
            contact.marker = null;
            contact.markerPane = null;
            contact.markerTooltip = undefined;
            contact.markerIconRoot = null;
        }

        if (contact.dom?.container?.parentNode) {
            contact.dom.container.parentNode.removeChild(contact.dom.container);
        }

        delete this.contacts[contactKey];
        return true;
    }

    _removeReporterByKey(reporterKey) {
        const reporter = this.reporters[reporterKey] ?? null;
        if (!reporter) return false;
        delete this.reporters[reporterKey];
        return true;
    }

    _pruneRouteDescsByContactIds(contactIds) {
        if (!(contactIds instanceof Set) || contactIds.size < 1) return false;
        let changed = false;
        Object.keys(this.layer_descs).forEach((layerId) => {
            const desc = this.layer_descs[layerId];
            if (!desc) return;
            const markerHit = desc.markers instanceof Set
                ? Array.from(desc.markers).some((id) => contactIds.has(Number(id)))
                : false;
            const pathHit = Array.isArray(desc.paths)
                ? desc.paths.some((path) => contactIds.has(Number(path?.from?.contact_id)) || contactIds.has(Number(path?.to?.contact_id)))
                : false;
            if (markerHit || pathHit) {
                delete this.layer_descs[layerId];
                changed = true;
            }
        });
        return changed;
    }

    _pruneRouteDescsByReporterIds(reporterIds) {
        if (!(reporterIds instanceof Set) || reporterIds.size < 1) return false;
        let changed = false;
        Object.keys(this.layer_descs).forEach((layerId) => {
            const desc = this.layer_descs[layerId];
            if (!desc || !Array.isArray(desc.paths)) return;
            const reporterHit = desc.paths.some((path) => reporterIds.has(Number(path?.reporter?.data?.id)));
            if (reporterHit) {
                delete this.layer_descs[layerId];
                changed = true;
            }
        });
        return changed;
    }

    _mergeLiveContactSnapshot(existing, incoming) {
        if (!existing || !incoming) return;

        existing.merge(incoming.data);
        existing.telemetry = incoming.telemetry;

        if (incoming.adv) {
            const incomingTime = Number(incoming.last?.time ?? 0);
            const existingTime = Number(existing.last?.time ?? 0);
            if (!existing.last || incomingTime >= existingTime) {
                existing.adv = incoming.adv;
                existing.last = incoming.last;
            }
        }
    }

    _shouldReplaceContactAdvertisement(existingAdvertisement, incomingAdvertisement) {
        if (!(incomingAdvertisement instanceof MeshLogAdvertisement)) return false;
        if (!(existingAdvertisement instanceof MeshLogAdvertisement)) return true;

        const incomingLat = Number(incomingAdvertisement.data?.lat);
        const incomingLon = Number(incomingAdvertisement.data?.lon);
        const existingLat = Number(existingAdvertisement.data?.lat);
        const existingLon = Number(existingAdvertisement.data?.lon);

        const incomingHasCoords = Number.isFinite(incomingLat) && Number.isFinite(incomingLon) && !(incomingLat === 0 && incomingLon === 0);
        const existingHasCoords = Number.isFinite(existingLat) && Number.isFinite(existingLon) && !(existingLat === 0 && existingLon === 0);

        if (incomingHasCoords && !existingHasCoords) return true;
        if (!incomingHasCoords && existingHasCoords) return false;

        const incomingTime = Number(incomingAdvertisement.time ?? 0);
        const existingTime = Number(existingAdvertisement.time ?? 0);
        if (incomingTime > existingTime) return true;
        if (incomingTime < existingTime) return false;

        const incomingId = Number(incomingAdvertisement.data?.id ?? 0);
        const existingId = Number(existingAdvertisement.data?.id ?? 0);
        return incomingId >= existingId;
    }

    _syncLiveContacts(data) {
        if (data?.error) {
            this.showError(data.error, 4000);
            return false;
        }
        if (!Array.isArray(data?.objects)) return false;

        const seenKeys = new Set();
        for (let i = 0; i < data.objects.length; i++) {
            const row = data.objects[i];
            const key = `${row.id}`;
            seenKeys.add(key);
            const incoming = new MeshLogContact(this, row);
            if (this.contacts.hasOwnProperty(key)) {
                this._mergeLiveContactSnapshot(this.contacts[key], incoming);
            } else {
                this.contacts[key] = incoming;
            }
        }

        const removedContactKeys = [];
        Object.keys(this.contacts).forEach((key) => {
            if (!seenKeys.has(key)) {
                removedContactKeys.push(key);
            }
        });

        let routesChanged = false;
        const removedContactIds = new Set();
        for (let i = 0; i < removedContactKeys.length; i++) {
            const key = removedContactKeys[i];
            const contactId = Number(this.contacts[key]?.data?.id);
            if (Number.isFinite(contactId)) {
                removedContactIds.add(contactId);
            }
            this._removeContactByKey(key);
            routesChanged = true;
        }

        if (this._pruneRouteDescsByContactIds(removedContactIds)) {
            routesChanged = true;
        }

        if (routesChanged) {
            this.updatePaths();
        }

        this.onLoadContacts();
        return true;
    }

    _syncLiveReporters(data) {
        if (data?.error) {
            this.showError(data.error, 4000);
            return false;
        }
        if (!Array.isArray(data?.objects)) return false;

        const seenKeys = new Set();
        for (let i = 0; i < data.objects.length; i++) {
            const row = data.objects[i];
            const key = `${row.id}`;
            seenKeys.add(key);
            const incoming = new MeshLogReporter(this, row);
            if (this.reporters.hasOwnProperty(key)) {
                this.reporters[key].merge(incoming.data);
            } else {
                this.reporters[key] = incoming;
            }
        }

        const removedReporterKeys = [];
        const removedReporterIds = new Set();
        Object.keys(this.reporters).forEach((key) => {
            if (seenKeys.has(key)) return;
            removedReporterKeys.push(key);
            const reporterId = Number(this.reporters[key]?.data?.id);
            if (Number.isFinite(reporterId)) {
                removedReporterIds.add(reporterId);
            }
        });

        let routesChanged = false;
        for (let i = 0; i < removedReporterKeys.length; i++) {
            if (this._removeReporterByKey(removedReporterKeys[i])) {
                routesChanged = true;
            }
        }

        if (this._pruneRouteDescsByReporterIds(removedReporterIds)) {
            routesChanged = true;
        }

        if (routesChanged) {
            this.updatePaths();
        }

        this.__init_reporters();
        return true;
    }

    syncLiveMapEntities(onDone = null) {
        const query = { count: 5000 };
        let pending = 2;

        const finish = () => {
            pending -= 1;
            if (pending > 0) return;

            this._renderActiveRouteTrail();

            if (typeof onDone === 'function') {
                onDone();
            }
        };

        this.__fetchJson('api/v1/contacts', query, (data) => {
            this._syncLiveContacts(data);
            finish();
        }, () => finish());

        this.__fetchJson('api/v1/reporters', query, (data) => {
            this._syncLiveReporters(data);
            finish();
        }, () => finish());
    }

    loadNew(onload=null) {
        let params = { 
            "after_ms": this.latest
        };
        this.loadAll(params, onload);
    }

    loadOld(onload=null) {
        const self = this;
        let oldest_adv = this.latest;
        let oldest_grp = this.latest;
        let oldest_dm  = this.latest;
        let oldest_tel = this.latest;
        let oldest_sys = this.latest;

        Object.entries(this.messages).forEach(([k,v]) => {
            if (v instanceof MeshLogAdvertisement) {
                if (v.time < oldest_adv) oldest_adv = v.time;
            } else if (v instanceof MeshLogChannelMessage) {
                if (v.time < oldest_grp) oldest_grp = v.time;
            } else if (v instanceof MeshLogDirectMessage) {
                if (v.time < oldest_dm) oldest_dm = v.time;
            } else if (v instanceof MeshLogTelemetryMessage) {
                if (v.time < oldest_tel) oldest_tel = v.time;
            } else if (v instanceof MeshLogSystemReportMessage) {
                if (v.time < oldest_sys) oldest_sys = v.time;
            }
        });

        this.__fetchQuery({ "before_ms": oldest_adv }, 'api/v1/advertisements', data => {
            const rep = self.__loadObjects(self.advertisements, data, MeshLogAdvertisement);
            if (rep.length) console.log(`${rep.length} advertisements loaded`);
            self.onLoadAll();
            if (onload) onload();
        });

        this.__fetchQuery({ "before_ms": oldest_grp }, 'api/v1/channel_messages', data => {
            const rep = self.__loadObjects(self.channel_messages, data, MeshLogChannelMessage);
            if (rep.length) console.log(`${rep.length} group messages loaded`);
            self.onLoadAll();
            if (onload) onload();
        });

        this.__fetchQuery({ "before_ms": oldest_dm }, 'api/v1/direct_messages', data => {
            const rep = self.__loadObjects(self.direct_messages, data, MeshLogDirectMessage);
            if (rep.length) console.log(`${rep.length} direct messages loaded`);
            self.onLoadAll();
            if (onload) onload();
        });

        this.__fetchQuery({ "before_ms": oldest_tel }, 'api/v1/telemetry', data => {
            const rep = self.__loadObjects(self.messages, data, MeshLogTelemetryMessage);
            if (rep.length) console.log(`${rep.length} telemetry packets loaded`);
            self.onLoadAll();
            if (onload) onload();
        });

        this.__fetchQuery({ "before_ms": oldest_sys }, 'api/v1/system_reports', data => {
            const rep = self.__loadObjects(self.messages, data, MeshLogSystemReportMessage);
            if (rep.length) console.log(`${rep.length} system reports loaded`);
            self.onLoadAll();
            if (onload) onload();
        });
    }

    loadAll(params={}, onload=null) {
        this.__fetchQuery(params, 'api/v1/all', data => {
            if (data.error) {
                this.showError(data.error);
                return;
            }

            const rep1 = this.__loadObjects(this.reporters, data.reporters, MeshLogReporter);
            const rep2 = this.__loadObjects(this.contacts, data.contacts, MeshLogContact);
            const rep4 = this.__loadObjects(this.channels, data.channels, MeshLogChannel);

            const rep3 = this.__loadObjects(this.messages, data.advertisements, MeshLogAdvertisement);
            const rep5 = this.__loadObjects(this.messages, data.channel_messages, MeshLogChannelMessage);
            const rep6 = this.__loadObjects(this.messages, data.direct_messages, MeshLogDirectMessage);
            const rep7 = this.__loadObjects(this.messages, data.raw_packets ?? {objects: []}, MeshLogRawPacket);
            const rep8 = this.__loadObjects(this.messages, data.telemetry ?? {objects: []}, MeshLogTelemetryMessage);
            const rep9 = this.__loadObjects(this.messages, data.system_reports ?? {objects: []}, MeshLogSystemReportMessage);

            if (rep1.length) console.log(`${rep1.length} reporters loaded`);
            if (rep2.length) console.log(`${rep2.length} contacts loaded`);
            if (rep3.length) console.log(`${rep3.length} advertisements loaded`);
            if (rep4.length) console.log(`${rep4.length} groups loaded`);
            if (rep5.length) console.log(`${rep5.length} group messages loaded`);
            if (rep6.length) console.log(`${rep6.length} direct messages loaded`);
            if (rep7.length) console.log(`${rep7.length} raw packets loaded`);
            if (rep8.length) console.log(`${rep8.length} telemetry packets loaded`);
            if (rep9.length) console.log(`${rep9.length} system reports loaded`);

            this.__init_reporters();
            this.onLoadAll();
            this._initialLoad = false;

            if (onload) {
                onload({
                    reporters: rep1,
                    contacts: rep2,
                    groups: rep4,
                    advertisements: rep3,
                    channel_messages: rep5,
                    direct_messages: rep6,
                    raw_packets: rep7,
                    telemetry: rep8,
                    system_reports: rep9,
                });
            }
        });
    }

    onLoadContacts() {
        let repHashes = {};
        Object.entries(this.contacts).forEach(([id,contact]) => {
            if (contact.isRepeater()) {
                let hashstr = contact.hash;
                if (!repHashes.hasOwnProperty(hashstr)) {
                    repHashes[hashstr] = 1;
                } else {
                    repHashes[hashstr] += 1;
                }
            }
        });

        Object.entries(this.contacts).forEach(([id,contact]) => {
            let latest = contact.last;
            if (!latest) return;

            // Mark dupes
            if (contact.isRepeater()) {
                let hashstr = contact.hash;
                let count = repHashes[hashstr];
                contact.flags.dupe = count > 1;
            }

            this.addContact(contact);
            contact.addToMap(this.map);
            contact.syncMarkerVisibility();
        });
        this.updateContactsDom();
    }

    onLoadChannels() {
        // Sort: non-hashtag channel names first, then hashtag channels; each group alphabetical
        const sorted = Object.values(this.channels).sort((a, b) => {
            const aHash = (a.data.name ?? '').startsWith('#');
            const bHash = (b.data.name ?? '').startsWith('#');
            if (aHash !== bHash) return aHash ? 1 : -1;
            return (a.data.name ?? '').localeCompare(b.data.name ?? '');
        });
        sorted.forEach(channel => this.addChannel(channel));
    }

    addChannel(ch) {
        let isnew = ch.dom ? false : true;
        let dom = ch.createDom();
        ch.updateDom();
        if (isnew) {
            this.dom_channel_badges.appendChild(dom.cb);
        }
    }

    addMessage(msg) {
        let isnew = msg.dom ? false : true;
        let dom = msg.createDom();
        msg.updateDom();

        if (isnew) {
            // find pos by date
            let inserted = false;
            let newTime = dom.container.dataset.time;
            for (let child of this.dom_logs.children) {
                const childTime = child.dataset.time;
                if (newTime > childTime) {
                    this.dom_logs.insertBefore(dom.container, child);
                    inserted = true;
                    break;
                }
            }

            // If not inserted, append at the end
            if (!inserted) this.dom_logs.appendChild(dom.container);

            if (this.contacts.hasOwnProperty(msg.data.contact_id)) {
                let contact = this.contacts[msg.data.contact_id];
                if (!contact.last || msg.time > contact.last.time) {
                    contact.last = msg;
                }
                if (msg instanceof MeshLogAdvertisement && this._shouldReplaceContactAdvertisement(contact.adv, msg)) {
                    contact.adv = msg;
                    contact.update(); // refresh sidebar badge + marker when a new ADV arrives
                }
            }
            this.onNewMessage(msg);
        }
    }

    addContact(msg) {
        let dom = msg.createDom();
        msg.updateDom();
        this.dom_contacts.appendChild(dom.container);
    }

    updateContactsDom() {
        this.sortContacts();
        this.updateMarkersForFilter();
    }

    updateMessagesDom() {
        for (const [key, msg] of Object.entries(this.messages)) {
            msg.createDom(false);
            msg.updateDom();
        }
    }

    onLoadMessages() {
        Object.entries(this.messages).forEach(([id, msg]) => {
            try { this.addMessage(msg); }
            catch (e) { console.error('addMessage failed for', id, e); }
        });
        this.updateMessagesDom();
    }

    onLoadAll() {
        this.onLoadMessages();
        this.onLoadContacts();
        this.onLoadChannels();
    }

    loadReporters(params={}, onload=null) {
        this.__fetchQuery(params, 'api/v1/reporters', data => {
            const sz = this.__loadObjects(this.reporters, data, MeshLogObject);
            console.log(`${sz} reporters loaded`);
            if (onload) onload();
        });
    }

    loadContacts(params={}, onload=null) {
        this.__fetchQuery(params, 'api/v1/contacts', data => {
            const sz = this.__loadObjects(this.contacts, data, MeshLogContact);
            console.log(`${sz} contacts loaded`);
            if (onload) onload();
        });
    }

    loadAdvertisements(params={}, onload=null) {
        this.__fetchQuery(params, 'api/v1/advertisements', data => {
            const sz = this.__loadObjects(this.advertisements, data, MeshLogAdvertisement);
            console.log(`${sz} advertisements loaded`);
            if (onload) onload();
        });
    }

    loadChannels(params={}, onload=null) {
        this.__fetchQuery(params, 'api/v1/channels', data => {
            const sz = this.__loadObjects(this.channels, data, MeshLogObject);
            console.log(`${sz} channels loaded`);
            if (onload) onload();
        });
    }

    loadChannelMessages(params={}, onload=null) {
        this.__fetchQuery(params, 'api/v1/channel_messages', data => {
            const sz = this.__loadObjects(this.channel_messages, data, MeshLogChannelMessage);
            console.log(`${sz} channels messages loaded`);
            if (onload) onload();
        });
    }

    loadDirectMessages(params={}, onload=null) {
        this.__fetchQuery(params, 'api/v1/direct_messages', data => {
            const sz = this.__loadObjects(this.direct_messages, data, MeshLogDirectMessage);
            console.log(`${sz} direct messages loaded`);
            if (onload) onload();
        });
    }

    loadTelemetry(params={}, onload=null) {
        this.__fetchQuery(params, 'api/v1/telemetry', data => {
            const sz = this.__loadObjects(this.messages, data, MeshLogTelemetryMessage);
            console.log(`${sz} telemetry packets loaded`);
            if (onload) onload();
        });
    }

    loadSystemReports(params={}, onload=null) {
        this.__fetchQuery(params, 'api/v1/system_reports', data => {
            const sz = this.__loadObjects(this.messages, data, MeshLogSystemReportMessage);
            console.log(`${sz} system reports loaded`);
            if (onload) onload();
        });
    }

    fadeMarkers(opacity=0.2) {
        const empty = this.visible_markers.size < 1;
        Object.entries(this.contacts).forEach(([k,v]) => {
            if (!v.marker) return;
            const highlighted = !empty && this.visible_markers.has(v.data.id);
            v.setMarkerPane(highlighted);
            if (empty || highlighted) {
                v.marker.setOpacity(1);
                v.marker.setZIndexOffset(1000);
                v.updateTooltip();
            } else {
                v.marker.setOpacity(opacity);
                v.marker.setZIndexOffset(2);
                v.updateTooltip('');
            }
            // Selection should not auto-open marker tooltips. Hover handles the compact tooltip.
            v.showLabel(false);
        });
    }

    findNearestContact(lat, lon, hash, repeater) {
        let matches = 0;
        let match = false;
        let matchDist = 99999;

        Object.entries(this.contacts).forEach(([k,c]) => {
            if (c.checkHash(hash) && c.adv && !c.isVeryExpired() && (!repeater || c.isRepeater())) {
                let current = [c.adv.data.lat, c.adv.data.lon];
                if (current[0] == 0 && current[1] == 0) return;

                matches++;
                const dist = haversineDistance(lat, lon, current[0], current[1]);

                if (!match || dist < matchDist) {
                    match = c;
                    matchDist = dist;
                }
            }
        });

        if (!match) return false;

        return {
            result: match,
            distance: matchDist,
            matches: matches
        };
    }

    updatePaths() {
        this._stopRouteLineAnimations();

        // remove all layers
        const self = this;
        this.link_layers.eachLayer(function (layer) {
            self.link_layers.removeLayer(layer);
        });

        this.visible_markers.clear();

        // temp, dupe prevention
        let links = [];
        let circles = [];
        let decors = {};
        let warnings = [];

        const ln_weight        = 2;
        const ln_glow          = 8;
        const ln_outline       = 3;
        const ln_decor_weight  = 3;
        const ln_decor_outline = 5;
        const ln_offset        = 10;
        const ln_repeat        = 120;

        const linkOutlineColor = '#0d0d0d';
        const linkStrokeColor  = '#fff';

        Object.entries(this.layer_descs).forEach(([k,desc]) => {
            const animatedRoute = !!desc.animated;

            for (const cid of desc.markers) {
                this.visible_markers.add(cid);
            }

            for (let i=0;i<desc.paths.length;i++) {
                let path = desc.paths[i];
                let line_uid  = [path.from.contact_id, path.to.contact_id].join('_');
                let line_id   = [path.from.contact_id, path.to.contact_id].sort((a, b) => a - b).join('_');
                let decor_id  = `${path.reporter.data.id}`;
                let circle_id = `${path.to.contact_id}`;

                let linePath = [
                    [path.to.lat, path.to.lon],
                    [path.from.lat, path.from.lon]
                ];

                const reporterColor = path.reporter.getStyle().color ?? '#4ea4c4';
                const glowOpacity = animatedRoute ? 0.5 : 0.22;
                const innerWeight = animatedRoute ? ln_weight + 1 : ln_weight;
                const dashArray = animatedRoute ? '10 10' : null;

                // Three-layer rendering: wide semi-transparent glow → dark outline → colored inner.
                // Glow and outline are decorative only — mark non-interactive so they never
                // intercept pointer events and block marker clicks underneath.
                const linePane = animatedRoute ? MeshLog.LIVE_ROUTE_PANE : MeshLog.ROUTE_PANE;
                let lineGlow = L.polyline(linePath, {pane: linePane, interactive: false, color: reporterColor, weight: ln_glow, opacity: glowOpacity});
                let line1    = L.polyline(linePath, {pane: linePane, interactive: false, color: linkOutlineColor, weight: ln_outline});
                let line2    = L.polyline(linePath, {pane: linePane, interactive: !animatedRoute, color: reporterColor, weight: innerWeight, dashArray: dashArray});

                if (!links.includes(line_id)) {
                    links.push(line_id);
                    lineGlow.addTo(this.link_layers);
                    line1.addTo(this.link_layers);
                    line2.addTo(this.link_layers);
                }

                let dist = haversineDistance(path.to.lat, path.to.lon, path.from.lat, path.from.lon);
                if (dist < 1) {
                    dist = `${Math.round(dist*1000)} m`;
                } else {
                    dist = `${Math.round(dist * 100) / 100} km`;
                }

                const tooltipOpts = { sticky: true, direction: 'top', className: 'hop-tooltip' };
                lineGlow.bindTooltip(dist, tooltipOpts);
                line1.bindTooltip(dist, tooltipOpts);
                line2.bindTooltip(dist, tooltipOpts);

                const mouseover = function(e) {
                    lineGlow.setStyle({ opacity: 0.6, weight: ln_glow + 4 });
                    line1.setStyle({ color: '#00acc1' });
                    line2.setStyle({ color: '#00d9e9', weight: ln_weight + 1 });
                    this.openTooltip();
                };

                const mouseout = function(e) {
                    lineGlow.setStyle({ opacity: 0.22, weight: ln_glow });
                    line1.setStyle({ color: linkOutlineColor });
                    line2.setStyle({ color: reporterColor, weight: ln_weight });
                    this.closeTooltip();
                };

                lineGlow.on('mouseover', mouseover.bind(lineGlow));
                lineGlow.on('mouseout',  mouseout.bind(lineGlow));
                line1.on('mouseover',    mouseover.bind(line1));
                line1.on('mouseout',     mouseout.bind(line1));
                line2.on('mouseover',    mouseover.bind(line2));
                line2.on('mouseout',     mouseout.bind(line2));

                if (animatedRoute) {
                    this._startRouteLineAnimation(lineGlow, line2, innerWeight);
                    // Apply glow effect to devices on the active path
                    for (const contactId of desc.markers) {
                        const contact = this.contacts[contactId];
                        if (contact?.marker?.getElement?.()) {
                            const markerEl = contact.marker.getElement();
                            markerEl.classList.add('device-packet-active');
                            // Remove the animation class after it completes so it can be retriggered
                            setTimeout(() => markerEl.classList.remove('device-packet-active'), 600);
                        }
                    }
                }

                // Circle for client-origin unknowns
                if (path.circle && !circles.includes(circle_id)) {
                    circles.push(circle_id);
                    let circle = L.circle(linePath[0], {
                        pane: MeshLog.ROUTE_PANE,
                        color: reporterColor,
                        fillColor: reporterColor,
                        fillOpacity: 0.2,
                        radius: 1000
                    });
                    circle.addTo(this.link_layers);
                }

                // Arrow decorators — show hop direction
                if (!decors.hasOwnProperty(line_uid)) {
                    decors[line_uid] = [];
                }

                if (!decors[line_uid].includes(decor_id)) {
                    const offset = ln_offset * decors[line_uid].length;
                    decors[line_uid].push(decor_id);

                    const strokeColor = path.reporter.getStyle().stroke ?? linkStrokeColor;

                    const decorator1 = L.polylineDecorator(line1, {
                        patterns: [
                        {
                            offset: offset,
                            repeat: ln_repeat,
                            symbol: L.Symbol.arrowHead({
                                pixelSize: 14,
                                polygon: false,
                                pathOptions: { pane: MeshLog.ROUTE_PANE, renderer: this.canvas_renderer, stroke: true, color: strokeColor, weight: ln_decor_outline }
                            })
                        },
                        {
                            offset: offset,
                            repeat: ln_repeat,
                            symbol: L.Symbol.arrowHead({
                                pixelSize: 14,
                                polygon: false,
                                pathOptions: { pane: MeshLog.ROUTE_PANE, renderer: this.canvas_renderer, stroke: true, color: path.reporter.getStyle().color, weight: ln_decor_weight }
                            })
                        }
                        ]
                    });

                    if (this.decor) {
                        decorator1.addTo(this.link_layers);
                    }
                }

                // Markers
                this.visible_markers;
            }
            warnings = [...warnings, ...desc.warnings];
        });
        warnings = [...new Set(warnings)];
        let warningsStr = `${warnings.length} Path warnings`;
        if (this.dom_warning.dataset.compact != "1") {
            warningsStr = warnings.join("\n");
        }
        this.showWarning(warningsStr);
        this.fadeMarkers();
    }

    // Build ordered [lat,lon] waypoints for a path: sender → hops → reporter
    _buildPathWaypoints(hashes, src, reporter) {
        const waypoints = [];
        const reporterAnchor = this._getReporterAnchor(reporter);
        if (!reporterAnchor) return waypoints;

        let prev = { lat: reporterAnchor.lat, lon: reporterAnchor.lon };
        waypoints.push([prev.lat, prev.lon]);
        for (let i = hashes.length - 1; i >= 0; i--) {
            const nearest = this.findNearestContact(prev.lat, prev.lon, hashes[i], true);
            if (nearest && nearest.result.adv) {
                prev = { lat: nearest.result.adv.data.lat, lon: nearest.result.adv.data.lon };
                waypoints.push([prev.lat, prev.lon]);
            }
        }
        if (src && src.adv && (src.adv.data.lat !== 0 || src.adv.data.lon !== 0)) {
            waypoints.push([src.adv.data.lat, src.adv.data.lon]);
        }
        return waypoints.reverse(); // sender → reporter
    }

    _getReporterAnchor(reporter) {
        if (!reporter) return null;

        const lat = Number(reporter?.data?.lat);
        const lon = Number(reporter?.data?.lon);
        if (Number.isFinite(lat) && Number.isFinite(lon) && (lat !== 0 || lon !== 0)) {
            return { lat, lon, contact_id: reporter.getContactId() };
        }

        const reporterContactId = reporter.getContactId();
        const reporterContact = this.contacts[reporterContactId] ?? null;
        if (reporterContact?.adv && (reporterContact.adv.data.lat !== 0 || reporterContact.adv.data.lon !== 0)) {
            return {
                lat: Number(reporterContact.adv.data.lat),
                lon: Number(reporterContact.adv.data.lon),
                contact_id: reporterContactId
            };
        }

        return null;
    }

    _calcPathLength(waypoints) {
        let total = 0;
        for (let i = 1; i < waypoints.length; i++) {
            total += haversineDistance(waypoints[i-1][0], waypoints[i-1][1], waypoints[i][0], waypoints[i][1]);
        }
        return total || 0.001;
    }

    // Animate a traveling dot + glow line from waypoints[0] to waypoints[last]
    _runPathAnimation(waypoints, color) {
        const validWpts = waypoints.filter(w => w[0] !== 0 || w[1] !== 0);
        if (validWpts.length < 2) return;

        this._trimTransientRouteAnimations();

        const animLayer = L.layerGroup().addTo(this.map);
        const cr = this.canvas_renderer;
        const animationState = {
            layer: animLayer,
            frameId: 0,
            done: false
        };
        this.transientRouteAnimations.push(animationState);

        // All animation shapes are purely visual — mark as non-interactive so they never
        // intercept pointer events or block marker clicks during/after an animation.
        const glowLine  = L.polyline(waypoints, { pane: MeshLog.ROUTE_PANE, interactive: false, renderer: cr, color: color,     weight: 14, opacity: 0 }).addTo(animLayer);
        const innerLine = L.polyline(waypoints, { pane: MeshLog.ROUTE_PANE, interactive: false, renderer: cr, color: '#ffffff', weight: 2,  opacity: 0 }).addTo(animLayer);
        const dot = L.circleMarker(waypoints[0], {
            pane: MeshLog.MARKER_PANE_ROUTE, interactive: false, renderer: cr, radius: 7, color: '#ffffff', fillColor: color, fillOpacity: 1, weight: 2, opacity: 1
        }).addTo(animLayer);
        const pulse = L.circleMarker(waypoints[waypoints.length - 1], {
            pane: MeshLog.MARKER_PANE_ROUTE, interactive: false, renderer: cr, radius: 0, color: color, fillColor: color, fillOpacity: 0.35, weight: 2, opacity: 0
        }).addTo(animLayer);

        const totalLen     = this._calcPathLength(waypoints);
        const travelMs     = Math.min(3200, Math.max(1000, totalLen * 350));
        const glowInMs     = travelMs * 0.12;
        const glowOutMs    = travelMs * 0.65;
        const totalMs      = travelMs + 900;
        const startTime    = performance.now();
        const self         = this;

        const ptAlongPath = (t) => {
            const target = t * totalLen;
            let dist = 0;
            for (let i = 1; i < waypoints.length; i++) {
                const seg = haversineDistance(waypoints[i-1][0], waypoints[i-1][1], waypoints[i][0], waypoints[i][1]);
                if (dist + seg >= target || i === waypoints.length - 1) {
                    const st = seg > 0 ? Math.min(1, (target - dist) / seg) : 0;
                    return [
                        waypoints[i-1][0] + (waypoints[i][0] - waypoints[i-1][0]) * st,
                        waypoints[i-1][1] + (waypoints[i][1] - waypoints[i-1][1]) * st
                    ];
                }
                dist += seg;
            }
            return waypoints[waypoints.length - 1];
        };

        const animate = (now) => {
            if (animationState.done) {
                if (self.map.hasLayer(animLayer)) {
                    self.map.removeLayer(animLayer);
                }
                return;
            }

            const t = now - startTime;
            if (t > totalMs) {
                animationState.done = true;
                self._cleanupTransientRouteAnimations();
                return;
            }

            // Glow envelope
            let gOp;
            if      (t < glowInMs)  { gOp = (t / glowInMs) * 0.55; }
            else if (t < glowOutMs) { gOp = 0.55; }
            else                    { gOp = 0.55 * (1 - (t - glowOutMs) / (totalMs - glowOutMs)); }
            glowLine.setStyle({ opacity: gOp });
            innerLine.setStyle({ opacity: Math.min(gOp * 1.5, 0.85) });

            // Traveling dot with ease-in-out
            const rawT  = Math.min(t / travelMs, 1);
            const eased = rawT < 0.5 ? 2 * rawT * rawT : -1 + (4 - 2 * rawT) * rawT;
            dot.setLatLng(ptAlongPath(eased));

            if (t > glowOutMs) {
                const ft = (t - glowOutMs) / (totalMs - glowOutMs);
                dot.setStyle({ opacity: 1 - ft, fillOpacity: 1 - ft });
            }

            // Expanding ring at destination once dot arrives
            if (rawT >= 1) {
                const pt = Math.min((t - travelMs) / 700, 1);
                pulse.setStyle({ opacity: 1 - pt, fillOpacity: 0.35 * (1 - pt) });
                pulse.setRadius(pt * 22);
            }

            animationState.frameId = requestAnimationFrame(animate);
        };
        animationState.frameId = requestAnimationFrame(animate);
    }

    _animateNewPacketPath(msg) {
        if (this._initialLoad) return;
        if (!msg.reports || msg.reports.length === 0) return;
        const srcContact = this.contacts[msg.data.contact_id] ?? null;
        const labelContacts = new Set();
        const animatedPathKeys = new Set();

        msg.reports.forEach(report => {
            const reporter = this.reporters[report.data.reporter_id];
            if (!reporter) return;
            const hashes    = parsePath(report.data.path);
            const waypoints = this._buildPathWaypoints(hashes, srcContact, reporter);
            if (waypoints.length < 2) return;
            const color     = reporter.getStyle().color ?? '#4eb8d0';
            const pathKey = `${report.data.reporter_id}:${color}:${waypoints.map(point => point.join(',')).join('|')}`;
            if (!animatedPathKeys.has(pathKey)) {
                animatedPathKeys.add(pathKey);
                this._runPathAnimation(waypoints, color);
            }

            // Collect contacts on this path for label display
            const anchor = this._getReporterAnchor(reporter);
            const reporterContact = anchor?.contact_id ? this.contacts[anchor.contact_id] : null;
            if (reporterContact) labelContacts.add(reporterContact);
            let prev = anchor;
            for (let i = hashes.length - 1; i >= 0; i--) {
                const nearest = this.findNearestContact(prev?.lat ?? 0, prev?.lon ?? 0, hashes[i], true);
                if (nearest?.result) {
                    labelContacts.add(nearest.result);
                    prev = { lat: nearest.result.adv.data.lat, lon: nearest.result.adv.data.lon };
                }
            }
            if (srcContact?.adv) labelContacts.add(srcContact);
        });

        labelContacts.forEach(c => c.showLabel(true));
        const self = this;
        // Close each label individually once the contact is no longer part of an
        // active highlighted route.  The old check (visible_markers.size < 1)
        // was too strict: as long as ANY route was shown, ALL labels stayed open
        // forever.
        setTimeout(() => {
            labelContacts.forEach(c => {
                if (!self.visible_markers.has(c.data.id)) c.showLabel(false);
            });
        }, 5000);
    }

    // Only adds descriptors, not layers
    showPath(id, path, src, reporter, preview = null, animated = false) {
        if (this.layer_descs.hasOwnProperty(id)) {
            this.layer_descs[id].preview = preview;
            this.layer_descs[id].animated = animated;
            return;
        }

        const reporterAnchor = this._getReporterAnchor(reporter);
        if (!reporterAnchor) return;

        let hashes = parsePath(path);
        let prev = { ...reporterAnchor };

        let addCircle = false;

        if (src && !src.isClient()) {
            hashes.unshift(src.data.public_key);
        } else {
            addCircle = true;
        }

        let desc = {
            paths: [],
            markers: new Set(),
            warnings: [],
            preview: preview,
            animated: animated
        }

        for (let i=hashes.length-1;i>=0;i--) {
            let hash = hashes[i];
            let nearest = this.findNearestContact(prev.lat, prev.lon, hash, true);

            // Valid repeater found?
            if (nearest) {
                if (nearest.matches > 1) {
                    desc.warnings.push(`Multiple paths (${nearest.matches}) detected to ${hash}. Showing shortest.`);
                }

                let current = {
                    lat: nearest.result.adv.data.lat,
                    lon: nearest.result.adv.data.lon,
                    contact_id: nearest.result.data.id
                };
                desc.markers.add(nearest.result.data.id);
                desc.paths.push(new MeshLogLinkLayer(prev, current, reporter, addCircle && i == 0));
                prev = current;
            } else {
                console.log('no nearest: ');
            }
        }

        // Show at least one direct line when no hop could be resolved.
        if (desc.paths.length < 1 && src && src.adv && (src.adv.data.lat !== 0 || src.adv.data.lon !== 0)) {
            const senderPoint = {
                lat: Number(src.adv.data.lat),
                lon: Number(src.adv.data.lon),
                contact_id: src.data.id
            };
            desc.markers.add(src.data.id);
            if (reporterAnchor.contact_id) {
                desc.markers.add(reporterAnchor.contact_id);
            }
            desc.paths.push(new MeshLogLinkLayer(prev, senderPoint, reporter, addCircle));
        }

        this.layer_descs[id] = desc;
    }

    hidePath(id) {
        if (!this.layer_descs.hasOwnProperty(id)) return;
        delete this.layer_descs[id];
    }

    fitToLayerDescs(ids) {
        const latLngs = [];
        for (const id of ids) {
            const desc = this.layer_descs[id];
            if (!desc) continue;
            for (const segment of desc.paths) {
                if (segment.from?.lat && segment.from?.lon) latLngs.push([segment.from.lat, segment.from.lon]);
                if (segment.to?.lat && segment.to?.lon)   latLngs.push([segment.to.lat,   segment.to.lon]);
            }
        }
        if (latLngs.length > 0) {
            this.map.fitBounds(L.latLngBounds(latLngs), { padding: [60, 60], maxZoom: 12 });
        }
    }

    refresh() {
        clearTimeout(this.timer);
        const self = this;
        this.loadNew((data) => {
            const count = Object.keys(this.new_messages).length;
            if (count) {
                if (Settings.getBool('notifications.enabled', false)) {
                    new Audio('assets/audio/notif.mp3').play();
                }

                document.getElementById('favicon').setAttribute('href','assets/favicon/faviconr.ico');
                document.title = `(${count}) MeshCore Log (forked)`; 
            }

            self.syncLiveMapEntities();
        });
        this.setAutorefresh(this.interval);
    }

    setAutorefresh(interval) {
        this.new_messages = {};
        if (this.timer) {
            clearTimeout(this.timer);
            this.timer = null;
        }

        if (interval >= 5000) {
            this.interval = interval;
            const self = this;
            this.timer = setTimeout(() => { self.refresh(); }, interval);
        } else {
            this.interval = 0;
        }
    }

    onNewMessage(msg) {
        if (msg instanceof MeshLogChannelMessage || msg instanceof MeshLogDirectMessage) {
            const hash = msg.data.hash;
            if (!this.new_messages.hasOwnProperty(hash)) {
                this.new_messages[hash] = [];
            };
            this.new_messages[hash].push(msg);
        }
        // Animate the path of any newly received packet on the map
        this._animateNewPacketPath(msg);
    }

    clearNotifications() {
        this.new_messages = [];
        document.getElementById('favicon').setAttribute('href','assets/favicon/faviconw.ico');
        document.title = `MeshCore Log (forked)`; 
    }

    isReporter(public_key) {
        for (const key in this.reporters) {
            if (this.reporters[key].data.public_key == public_key) {
                return this.reporters[key];
            }
        }
        return false;
    }
}

function haversineDistance(lat1, lon1, lat2, lon2) {
    const toRad = (angle) => (angle * Math.PI) / 180;

    const R = 6371; // Radius of the Earth in km
    const dLat = toRad(lat2 - lat1);
    const dLon = toRad(lon2 - lon1);

    const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
              Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) *
              Math.sin(dLon / 2) * Math.sin(dLon / 2);

    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    return R * c; // Distance in km
}

    function escapeXml(str) {
    return str
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&apos;");
}

function str2color(str, saturation = 65, lightness = 45) {
    return `hsl(${str2hue(str)}deg, ${saturation}%, ${lightness}%)`;
}

function createTooltip(element, contents) {
    if (!element) return;

    let tooltip = element._warnTooltip;
    if (!tooltip) {
        tooltip = document.createElement("div");
        tooltip.classList.add('warn-tooltip');
        element.append(tooltip);
        element._warnTooltip = tooltip;

        element.addEventListener("mouseenter", () => {
            if (!tooltip.textContent) return;
            const rect = element.getBoundingClientRect();
            tooltip.style.display = "block";
            tooltip.style.left = (rect.right + window.scrollX + 8) + "px";
            tooltip.style.top = (rect.top + window.scrollY + (rect.height / 2) - (tooltip.offsetHeight / 2)) + "px";
        });

        element.addEventListener("mouseleave", () => {
            tooltip.style.display = "none";
        });
    }

    tooltip.textContent = contents || '';
    if (!tooltip.textContent) {
        tooltip.style.display = "none";
    }
}
