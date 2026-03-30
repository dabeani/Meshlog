/// Map View - Visualize device locations with live update animations
import SwiftUI
import MapKit
import Combine

// MARK: - Helpers

private let lastHeardFormatter: DateFormatter = {
    let f = DateFormatter()
    f.dateFormat = "yyyy-MM-dd HH:mm:ss"
    return f
}()

private func secondsSince(_ dateString: String) -> TimeInterval {
    guard let date = lastHeardFormatter.date(from: dateString) else { return .infinity }
    return Date().timeIntervalSince(date)
}

private func markerStatusLabel(for contact: Contact) -> String {
    let age = secondsSince(contact.lastHeardAt)
    if age > (7 * 24 * 60 * 60) { return "Inactive" }
    if age > (3 * 24 * 60 * 60) { return "Stale" }
    return "Live"
}

private func formatDrift(_ milliseconds: TimeInterval) -> String {
    let totalMs = abs(milliseconds)
    if totalMs < 1000 { return "\(Int(totalMs)) ms" }

    let totalSeconds = totalMs / 1000
    if totalSeconds < 60 {
        return String(format: totalSeconds >= 10 ? "%.0f s" : "%.1f s", totalSeconds)
    }

    let totalMinutes = totalSeconds / 60
    if totalMinutes < 60 {
        return String(format: totalMinutes >= 10 ? "%.0f min" : "%.1f min", totalMinutes)
    }

    let totalHours = totalMinutes / 60
    return String(format: totalHours >= 10 ? "%.0f h" : "%.1f h", totalHours)
}

private func repeaterClockWarning(for contact: Contact) -> String? {
    guard contact.meshNodeType == .repeater,
          let sentAt = lastHeardFormatter.date(from: contact.advertisementSentAt ?? ""),
          let receivedAt = lastHeardFormatter.date(from: contact.advertisementCreatedAt ?? "") else {
        return nil
    }

    let driftMs = sentAt.timeIntervalSince(receivedAt) * 1000
    guard abs(driftMs) >= 300_000 else { return nil }

    let direction = driftMs >= 0 ? "ahead of" : "behind"
    return "Repeater clock is ~\(formatDrift(driftMs)) \(direction) server reception time. Time sync likely needed."
}

// MARK: - Pulse Ring

private struct PulseRing: View {
    let color: Color
    @State private var animating = false

    var body: some View {
        Circle()
            .stroke(color.opacity(0.75), lineWidth: 1.5)
            .scaleEffect(animating ? 2.7 : 1.0)
            .opacity(animating ? 0.0 : 0.9)
            .animation(
                .easeOut(duration: 1.9).repeatForever(autoreverses: false),
                value: animating
            )
            .onAppear { animating = true }
    }
}

// MARK: - Node Annotation View

private struct NodeAnnotationView: View {
    let contact: Contact
    let isReporter: Bool
    let isNew: Bool
    let onTap: () -> Void

    @AppStorage("map_show_repeater_names") private var showLabels = true
    @State private var scale: CGFloat

    init(
        contact: Contact,
        isReporter: Bool,
        isNew: Bool,
        onTap: @escaping () -> Void
    ) {
        self.contact = contact
        self.isReporter = isReporter
        self.isNew = isNew
        self.onTap = onTap
        _scale = State(initialValue: isNew ? 0.01 : 1.0)
    }

    private var nodeColor: Color {
        let age = secondsSince(contact.lastHeardAt)
        if age < 300  { return Color(red: 0.42, green: 0.78, blue: 0.72) }
        if age < 3600 { return Color(red: 0.90, green: 0.76, blue: 0.40) }
        return Color(red: 0.42, green: 0.42, blue: 0.52)
    }

    private var isPulsing: Bool { secondsSince(contact.lastHeardAt) < 300 }
    private var shouldShowLabel: Bool { showLabels }

    var body: some View {
        VStack(spacing: 3) {
            ZStack {
                if isPulsing {
                    PulseRing(color: nodeColor)
                        .frame(width: 38, height: 38)
                }

                ZStack(alignment: .topTrailing) {
                    Image(systemName: iconName)
                        .font(.system(size: 13, weight: .bold))
                        .foregroundColor(.white)
                        .frame(width: 28, height: 28)
                        .background(Circle().fill(nodeColor))

                    if isReporter {
                        Image(systemName: "receipt.fill")
                            .font(.system(size: 7, weight: .bold))
                            .foregroundColor(.white)
                            .padding(4)
                            .background(Circle().fill(Color.black.opacity(0.65)))
                            .offset(x: 5, y: -4)
                    }
                }
            }
            .onTapGesture { onTap() }

            if shouldShowLabel {
                Text(contact.name)
                    .font(.system(size: 10, weight: .semibold, design: .monospaced))
                    .foregroundColor(.white)
                    .padding(.horizontal, 4)
                    .padding(.vertical, 2)
                    .background(Color.black.opacity(0.70))
                    .cornerRadius(3)
                    .transition(.opacity.combined(with: .scale(scale: 0.85)))
            }
        }
        .scaleEffect(scale)
        .onAppear {
            if isNew {
                withAnimation(.spring(response: 0.45, dampingFraction: 0.55)) {
                    scale = 1.0
                }
            }
        }
    }

    private var iconName: String {
        contact.meshNodeType.mapSymbolName
    }
}

// MARK: - Map View

struct MapView: View {
    @EnvironmentObject var apiClient: APIClient
    @EnvironmentObject var navigationState: AppNavigationState

    @State private var contacts: [Contact] = []
    @State private var recentPackets: [Packet] = []
    @State private var cameraPosition: MapCameraPosition = .automatic
    @State private var isLoading = false
    @State private var selectedContact: Contact?
    @State private var selectedContactStats: StatisticsResponse?
    @State private var selectedContactTrail: [Packet] = []
    @State private var selectedContactTab: String = "general"
    @State private var selectedTrailGpxUrl: URL?
    @State private var routeFocusContactId: Int?
    @State private var neighborFocusContactId: Int?
    @State private var config: AppConfiguration?

    @AppStorage("map_show_repeater_names") private var showRepeaterNames = true
    @AppStorage("map_dark_theme") private var isDarkMapTheme = true
    @AppStorage("map_show_routes") private var showRoutes = true
    @AppStorage("map_show_neighbors") private var showNeighbors = false
    @AppStorage("map_visible_trail_contact_ids") private var trailVisibleContactIdsRaw = ""
    @AppStorage("collector_filter_selected_ids") private var collectorFilterRaw = ""
    @State private var knownContactIds: Set<Int> = []
    @State private var newContactIds: Set<Int> = []
    @State private var hasFitCamera = false
    @State private var trailPolylinesByContactId: [Int: [CLLocationCoordinate2D]] = [:]
    @State private var trailPacketsByContactId: [Int: [Packet]] = [:]
    @State private var trailLoadingContactIds: Set<Int> = []
    @State private var selectedTrailPoint: TrailPointData?
    @State private var selectedTrailReporterLinks: [TrailReporterLink] = []
    @State private var showSearch = false
    @State private var searchText = ""
    @State private var mapLatitudeDelta: Double = 5.0
    @State private var reporters: [Reporter] = []
    @State private var routeOverlayTask: Task<Void, Never>?
    @State private var routeOverlaySinceMs: Int = 0
    @State private var animatedDashPhase: CGFloat = 18

    private var mapBackgroundColor: Color {
        isDarkMapTheme
            ? Color(red: 0.08, green: 0.08, blue: 0.12)
            : Color(red: 0.95, green: 0.96, blue: 0.98)
    }

    private var detailCardBackground: Color {
        isDarkMapTheme
            ? Color(red: 0.13, green: 0.15, blue: 0.19)
            : Color(red: 0.92, green: 0.94, blue: 0.98)
    }

    private var detailCardTextColor: Color {
        isDarkMapTheme ? .white : .black
    }

    private var detailCardSecondaryColor: Color {
        isDarkMapTheme ? .gray : Color(red: 0.4, green: 0.4, blue: 0.4)
    }

    private var detailCardBorderColor: Color {
        isDarkMapTheme
            ? Color.white.opacity(0.14)
            : Color.black.opacity(0.12)
    }

    private var selectedCollectorIds: Set<Int> {
        Set(
            collectorFilterRaw
                .split(separator: ",")
                .compactMap { Int($0.trimmingCharacters(in: .whitespaces)) }
        )
    }

    private var visibleTrailContactIds: Set<Int> {
        Set(
            trailVisibleContactIdsRaw
                .split(separator: ",")
                .compactMap { Int($0.trimmingCharacters(in: .whitespaces)) }
        )
    }

    private var visibleContacts: [Contact] {
        let selected = selectedCollectorIds
        guard !selected.isEmpty else { return contacts }
        return contacts.filter { !Set($0.reporterIds).isDisjoint(with: selected) }
    }

    private var routeFocusContact: Contact? {
        guard let id = routeFocusContactId else { return nil }
        return contacts.first(where: { $0.id == id })
    }

    private var neighborFocusContact: Contact? {
        guard let id = neighborFocusContactId else { return nil }
        return contacts.first(where: { $0.id == id })
    }

    private var reporterKeySet: Set<String> {
        Set(reporters.map { $0.publicKey.uppercased() })
    }

    private var reporterIdToContact: [Int: Contact] {
        var map: [Int: Contact] = [:]
        let lowerMap: [String: Contact] = Dictionary(
            uniqueKeysWithValues: contacts
                .filter { $0.latitude != nil && $0.longitude != nil }
                .map { ($0.publicKey.lowercased(), $0) }
        )
        for reporter in reporters {
            if let contact = lowerMap[reporter.publicKey.lowercased()] {
                map[reporter.id] = contact
            }
        }
        return map
    }

    private var trailCoordinates: [CLLocationCoordinate2D] {
        selectedContactTrail.compactMap { packet in
            guard let lat = packet.latitude, let lon = packet.longitude else { return nil }
            return CLLocationCoordinate2D(latitude: lat, longitude: lon)
        }
    }

    private var mainTrailPolylines: [PolylineData] {
        let visibleIds = Set(visibleContacts.map { $0.id })

        return trailPolylinesByContactId
            .filter { visibleTrailContactIds.contains($0.key) && visibleIds.contains($0.key) && $0.value.count >= 2 }
            .map { id, coords in
                PolylineData(id: "t-\(id)", coords: coords)
            }
            .sorted { $0.id < $1.id }
    }

    private struct TrailPointData: Identifiable {
        let id: String
        let contactId: Int
        let contactName: String
        let packet: Packet
        let coord: CLLocationCoordinate2D
    }

    private struct TrailReporterLink: Identifiable {
        let id: String
        let reporterId: Int
        let reporterName: String
        let reporterCoord: CLLocationCoordinate2D
        let distanceKm: Double
        let snr: Double?
    }

    private var mainTrailPoints: [TrailPointData] {
        let visibleIds = Set(visibleContacts.map { $0.id })
        var points: [TrailPointData] = []

        for (contactId, packets) in trailPacketsByContactId {
            guard visibleTrailContactIds.contains(contactId), visibleIds.contains(contactId) else { continue }
            let contactName = contacts.first(where: { $0.id == contactId })?.name ?? "Device \(contactId)"

            for packet in packets {
                guard let lat = packet.latitude, let lon = packet.longitude else { continue }
                points.append(
                    TrailPointData(
                        id: "tp-\(contactId)-\(packet.id)",
                        contactId: contactId,
                        contactName: contactName,
                        packet: packet,
                        coord: CLLocationCoordinate2D(latitude: lat, longitude: lon)
                    )
                )
            }
        }

        return points.sorted { parseDate($0.packet.sentAt) < parseDate($1.packet.sentAt) }
    }


    private var selectedTrailReporterPolylines: [PolylineData] {
        guard showRoutes,
              let point = selectedTrailPoint,
              !selectedTrailReporterLinks.isEmpty else {
            return []
        }

        return selectedTrailReporterLinks.map { link in
            PolylineData(
                id: "trl-\(point.id)-\(link.id)",
                coords: [point.coord, link.reporterCoord]
            )
        }
    }

    private var trailDotDiameter: CGFloat {
        let safeDelta = max(0.0002, mapLatitudeDelta)
        let zoomLevel = log2(360.0 / safeDelta)
        let scaled = 12.0 + (zoomLevel - 8.0) * 1.2
        return CGFloat(min(28.0, max(12.0, scaled)))
    }

    private var trailDotTapTargetDiameter: CGFloat {
        max(30, trailDotDiameter + 16)
    }

    private var sortedTrailPoints: [Packet] {
        selectedContactTrail.sorted {
            parseDate($0.sentAt) < parseDate($1.sentAt)
        }
    }

    var body: some View {
        NavigationStack {
            ZStack {
                GeometryReader { proxy in
                    if proxy.size.width > 1 && proxy.size.height > 1 {
                        Map(position: $cameraPosition) {
                            if showRoutes {
                                ForEach(pathPolylines) { poly in
                                    MapPolyline(coordinates: poly.coords)
                                        .stroke(
                                            Color.white.opacity(0.10),
                                            style: StrokeStyle(lineWidth: 5.0, lineCap: .round, lineJoin: .round)
                                        )

                                    MapPolyline(coordinates: poly.coords)
                                        .stroke(
                                            Color(red: 0.42, green: 0.78, blue: 0.72).opacity(0.88),
                                            style: StrokeStyle(
                                                lineWidth: 2.6,
                                                lineCap: .round,
                                                lineJoin: .round,
                                                dash: [10, 7],
                                                dashPhase: animatedDashPhase
                                            )
                                        )
                                }

                                ForEach(mainTrailPolylines) { poly in
                                    MapPolyline(coordinates: poly.coords)
                                        .stroke(
                                            Color(red: 0.93, green: 0.28, blue: 0.34).opacity(0.52),
                                            style: StrokeStyle(lineWidth: 2.0, dash: [7, 5])
                                        )
                                }

                                ForEach(mainTrailPoints) { point in
                                    Annotation("", coordinate: point.coord) {
                                        ZStack {
                                            Circle()
                                                .fill(Color.red)
                                                .frame(width: trailDotDiameter, height: trailDotDiameter)
                                                .overlay(
                                                    Circle().stroke(
                                                        Color.white.opacity(0.9),
                                                        lineWidth: max(1.0, trailDotDiameter * 0.12)
                                                    )
                                                )
                                                .shadow(color: Color.black.opacity(0.25), radius: 2)

                                            Circle()
                                                .fill(Color.white.opacity(0.001))
                                                .frame(width: trailDotTapTargetDiameter, height: trailDotTapTargetDiameter)
                                        }
                                        .contentShape(Circle())
                                        .onTapGesture {
                                            withAnimation(.easeInOut(duration: 0.2)) {
                                                selectTrailPoint(point)
                                            }
                                        }
                                    }
                                }

                                ForEach(selectedTrailReporterPolylines) { poly in
                                    MapPolyline(coordinates: poly.coords)
                                        .stroke(
                                            Color.white.opacity(0.10),
                                            style: StrokeStyle(lineWidth: 3.8, lineCap: .round, lineJoin: .round)
                                        )

                                    MapPolyline(coordinates: poly.coords)
                                        .stroke(
                                            Color(red: 0.43, green: 0.68, blue: 0.94).opacity(0.86),
                                            style: StrokeStyle(
                                                lineWidth: 2.1,
                                                lineCap: .round,
                                                lineJoin: .round,
                                                dash: [5, 4],
                                                dashPhase: animatedDashPhase * 0.7
                                            )
                                        )
                                }
                            }

                            if showNeighbors {
                                ForEach(neighborPolylines) { poly in
                                    MapPolyline(coordinates: poly.coords)
                                        .stroke(
                                            Color.white.opacity(0.11),
                                            style: StrokeStyle(lineWidth: 4.4, lineCap: .round, lineJoin: .round)
                                        )

                                    MapPolyline(coordinates: poly.coords)
                                        .stroke(
                                            Color(red: 0.55, green: 0.72, blue: 0.94).opacity(0.92),
                                            style: StrokeStyle(
                                                lineWidth: 2.5,
                                                lineCap: .round,
                                                lineJoin: .round,
                                                dash: [7, 5],
                                                dashPhase: animatedDashPhase * 0.5
                                            )
                                        )
                                }
                            }

                            ForEach(visibleContacts) { contact in
                                if let lat = contact.latitude, let lon = contact.longitude {
                                    Annotation(
                                        "",
                                        coordinate: CLLocationCoordinate2D(latitude: lat, longitude: lon)
                                    ) {
                                        NodeAnnotationView(
                                            contact: contact,
                                            isReporter: reporterKeySet.contains(contact.publicKey.uppercased()),
                                            isNew: newContactIds.contains(contact.id),
                                            onTap: { withAnimation { selectedContact = contact } }
                                        )
                                    }
                                }
                            }
                        }
                        .mapStyle(.standard(elevation: .realistic))
                        .environment(\.colorScheme, isDarkMapTheme ? .dark : .light)
                        .onMapCameraChange(frequency: .continuous) { context in
                            mapLatitudeDelta = context.region.span.latitudeDelta
                        }
                        .frame(maxWidth: .infinity, maxHeight: .infinity)
                    } else {
                        Color.clear
                    }
                }

                if isLoading {
                    VStack(spacing: 6) {
                        ProgressView().tint(.cyan)
                        Text("Loading map…")
                            .font(.system(size: 12))
                            .foregroundColor(.white)
                    }
                    .frame(maxWidth: .infinity, maxHeight: .infinity)
                    .background(Color.black.opacity(0.3))
                }
            }
            .overlay(alignment: .bottom) {
                if let contact = selectedContact {
                    contactDetailCard(contact)
                        .padding(14)
                        .transition(.asymmetric(
                            insertion: .move(edge: .bottom).combined(with: .opacity),
                            removal: .opacity
                        ))
                }
            }
            .overlay(alignment: .top) {
                VStack(spacing: 0) {
                    if showSearch {
                        searchOverlay()
                    }
                    if showRoutes, let point = selectedTrailPoint {
                        trailPointInspector(point)
                    }
                }
            }
            .background(mapBackgroundColor.ignoresSafeArea())
            .navigationTitle("Map")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItemGroup(placement: .navigationBarTrailing) {
                    Button {
                        withAnimation(.easeInOut(duration: 0.2)) { showSearch.toggle() }
                        if !showSearch { searchText = "" }
                    } label: {
                        Image(systemName: showSearch ? "magnifyingglass.circle.fill" : "magnifyingglass")
                            .foregroundColor(showSearch ? .cyan : .secondary)
                    }

                    Button {
                        withAnimation(.easeInOut(duration: 0.2)) { showRepeaterNames.toggle() }
                    } label: {
                        Image(systemName: showRepeaterNames ? "tag.fill" : "tag.slash.fill")
                            .foregroundColor(showRepeaterNames ? .cyan : .secondary)
                    }

                    Button {
                        withAnimation(.easeInOut(duration: 0.3)) { isDarkMapTheme.toggle() }
                    } label: {
                        Image(systemName: isDarkMapTheme ? "moon.fill" : "sun.max.fill")
                            .foregroundColor(isDarkMapTheme ? .cyan : .yellow)
                    }

                    Button {
                        withAnimation(.easeInOut(duration: 0.2)) { showRoutes.toggle() }
                    } label: {
                        Image(systemName: "point.topleft.down.curvedto.point.bottomright.up")
                            .foregroundColor(showRoutes ? .cyan : .secondary)
                    }

                    Button {
                        withAnimation(.easeInOut(duration: 0.2)) { showNeighbors.toggle() }
                    } label: {
                        Image(systemName: "network")
                            .foregroundColor(showNeighbors ? .cyan : .secondary)
                    }

                    Button {
                        Task { await loadAll(fitCamera: false) }
                    } label: {
                        Image(systemName: "arrow.clockwise")
                    }
                }
            }
            .onAppear {
                Task { await loadAll(fitCamera: true) }
                Task { await loadConfig() }
                Task { await ensureVisibleTrailsLoaded() }
                Task { await processNavigationFocusRequest() }
                startRouteOverlayAnimation()
                startRouteOverlayStream()
            }
            .onDisappear {
                routeOverlayTask?.cancel()
            }
            .onChange(of: selectedContact?.id) { _, _ in
                guard selectedContact != nil else {
                    selectedContactStats = nil
                    selectedContactTrail = []
                    selectedTrailGpxUrl = nil
                    routeFocusContactId = nil
                    neighborFocusContactId = nil
                    return
                }
                selectedContactTab = "general"
                selectedTrailGpxUrl = nil
                neighborFocusContactId = nil
                Task { await loadSelectedContactDetails() }
            }
            .onChange(of: selectedContactTab) { _, tab in
                guard tab == "trail", trailCoordinates.count >= 2 else { return }
                focusCameraOnTrail()
            }
            .onChange(of: contacts.map { $0.id }) { _, _ in
                Task {
                    syncVisibleTrailIdsWithCurrentContacts()
                    await ensureVisibleTrailsLoaded()
                }
            }
            .onChange(of: trailVisibleContactIdsRaw) { _, _ in
                Task { await ensureVisibleTrailsLoaded() }
            }
            .onChange(of: showRoutes) { _, enabled in
                if !enabled {
                    selectedTrailPoint = nil
                    selectedTrailReporterLinks = []
                }
            }
            .onChange(of: navigationState.mapFocusNonce) { _, _ in
                Task { await processNavigationFocusRequest() }
            }
            // Periodic contact-only refresh (5 min): refreshes the contact list and reporter map
            // without re-fetching packet data — the live stream handles incremental updates.
            .onReceive(
                Timer.publish(every: 300, on: .main, in: .common).autoconnect()
            ) { _ in
                Task { await loadAll(fitCamera: false) }
            }
        }
    }

    // MARK: - Path Polylines

    private struct PolylineData: Identifiable {
        let id: String
        let coords: [CLLocationCoordinate2D]
    }

    /// Build relay-path polylines from live-feed packet paths.
    /// Path strings look like "ab12->cd34->ef56" where each segment is a
    /// short (1–3 byte) hex prefix of a relay node's public key.
    private var pathPolylines: [PolylineData] {
        var lines: [PolylineData] = []
        var dedupe = Set<String>()
        let selected = selectedCollectorIds
        let focusContact = routeFocusContact

        for packet in recentPackets {
            if let focusContact {
                let sameContactId = packet.contactId == focusContact.id
                let samePublicKey = packet.contactPublicKey?.uppercased() == focusContact.publicKey.uppercased()
                if !sameContactId && !samePublicKey {
                    continue
                }
            }

            let reports: [(reporterId: Int?, path: String?)] = packet.reports.isEmpty
                ? [(packet.reporterId, packet.path)]
                : packet.reports.map { ($0.reporterId ?? packet.reporterId, $0.path ?? packet.path) }

            for report in reports {
                if !selected.isEmpty,
                   let rid = report.reporterId,
                   !selected.contains(rid) {
                    continue
                }

                let chain = buildRouteChain(for: packet, reporterId: report.reporterId, path: report.path)
                let coords = coordinates(for: chain)
                guard coords.count >= 2 else { continue }

                let key = coords
                    .map { String(format: "%.5f,%.5f", $0.latitude, $0.longitude) }
                    .joined(separator: "|")
                guard dedupe.insert(key).inserted else { continue }
                lines.append(PolylineData(id: key, coords: coords))
            }
        }
        return lines
    }

    private var neighborPolylines: [PolylineData] {
        guard let selectedContact = neighborFocusContact,
              let srcLat = selectedContact.latitude,
              let srcLon = selectedContact.longitude else {
            return []
        }

        let selected = selectedCollectorIds
        var neighborIds = Set<Int>()

        for packet in recentPackets {
            let reports: [(reporterId: Int?, path: String?)] = packet.reports.isEmpty
                ? [(packet.reporterId, packet.path)]
                : packet.reports.map { ($0.reporterId, $0.path) }

            for report in reports {
                if !selected.isEmpty,
                   let rid = report.reporterId,
                   !selected.contains(rid) {
                    continue
                }

                let chain = buildRouteChain(for: packet, reporterId: report.reporterId, path: report.path)
                guard chain.count >= 2 else { continue }

                for idx in 0..<(chain.count - 1) {
                    let a = chain[idx]
                    let b = chain[idx + 1]
                    if a.id == selectedContact.id { neighborIds.insert(b.id) }
                    else if b.id == selectedContact.id { neighborIds.insert(a.id) }
                }
            }
        }

        guard !neighborIds.isEmpty else { return [] }

        return visibleContacts.compactMap { contact in
            guard neighborIds.contains(contact.id),
                  let lat = contact.latitude,
                  let lon = contact.longitude else { return nil }
            return PolylineData(
                id: "n-\(selectedContact.id)-\(contact.id)",
                coords: [
                    CLLocationCoordinate2D(latitude: srcLat, longitude: srcLon),
                    CLLocationCoordinate2D(latitude: lat, longitude: lon)
                ]
            )
        }
    }

    // MARK: - Detail Card

    @ViewBuilder
    private func contactDetailCard(_ contact: Contact) -> some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack {
                VStack(alignment: .leading, spacing: 3) {
                    Text(contact.name)
                        .font(.system(size: 15, weight: .bold))
                        .foregroundColor(detailCardTextColor)
                    Text(contact.publicKey.prefix(16) + "…")
                        .font(.system(size: 10, design: .monospaced))
                        .foregroundColor(detailCardSecondaryColor)

                    HStack(spacing: 6) {
                        popupBadge(contact.typeLabel.uppercased(), color: .cyan)
                        popupBadge(markerStatusLabel(for: contact).uppercased(), color: statusBadgeColor(for: contact))
                        popupBadge("[\(contact.shortHash)]", color: .gray)
                        if reporterKeySet.contains(contact.publicKey.uppercased()) {
                            popupBadge("REPORTER", color: .mint)
                        }
                        popupBadge("\(contact.reporterIds.count) COL", color: .mint)
                    }
                }
                Spacer()
                Button {
                    withAnimation { selectedContact = nil }
                } label: {
                    Image(systemName: "xmark.circle.fill")
                        .font(.system(size: 20))
                        .foregroundColor(detailCardSecondaryColor)
                }
            }

            Divider()

            Picker("Device popup tab", selection: $selectedContactTab) {
                Text("General").tag("general")
                Text("Stats").tag("stats")
                Text("Trail").tag("trail")
            }
            .pickerStyle(.segmented)

            if selectedContactTab == "general" {
                VStack(alignment: .leading, spacing: 6) {
                    sectionTitle("General")
                    cardRow("Type", contact.typeLabel)
                    cardRow("Status", markerStatusLabel(for: contact))
                    cardRow("Collectors", "\(contact.reporterIds.count)")
                    if let lat = contact.latitude, let lon = contact.longitude {
                        cardRow("Location", String(format: "%.5f,  %.5f", lat, lon))
                    }
                    cardRow("Last Heard", formatTime(contact.lastHeardAt))
                    cardRow("First Seen", formattedAbsoluteTime(contact.createdAt))
                    cardRow("Reporter", reporterKeySet.contains(contact.publicKey.uppercased()) ? "Yes" : "No")

                    Divider()
                    sectionTitle("Identity")
                    cardRow("Public Key", contact.publicKey)
                    if let hash = contact.advertisementHash, !hash.isEmpty {
                        cardRow("Message Hash", hash)
                    }
                    cardRow("Hash Size", "\(contact.hashSize)")

                    if let warning = repeaterClockWarning(for: contact), !warning.isEmpty {
                        Divider()
                        sectionTitle("Clock Warning")
                        Text(warning)
                            .font(.system(size: 11))
                            .foregroundColor(.orange)
                            .fixedSize(horizontal: false, vertical: true)
                    }

                    if let routeSummary = latestRouteSummary(for: contact) {
                        Divider()
                        sectionTitle("Latest Route")
                        cardRow("Collector", routeSummary.reporterName)
                        cardRow("Hops", routeSummary.hopsLabel)
                        cardRow("Path", routeSummary.pathLabel)
                        if let snrLabel = routeSummary.snrLabel {
                            cardRow("SNR", snrLabel)
                        }
                        cardRow("Received", routeSummary.receivedLabel)
                    }

                    if !contact.telemetry.isEmpty {
                        Divider()
                        sectionTitle("Telemetry")
                        LazyVGrid(columns: [GridItem(.adaptive(minimum: 130), spacing: 6)], spacing: 6) {
                            ForEach(contact.telemetry.sorted(by: { $0.key < $1.key }), id: \.key) { key, value in
                                telemetryChip(
                                    key: key.replacingOccurrences(of: "_", with: " ").capitalized,
                                    value: value
                                )
                            }
                        }
                    }

                    Divider()

                    HStack(spacing: 8) {
                        Button {
                            if routeFocusContactId == contact.id {
                                routeFocusContactId = nil
                            } else {
                                routeFocusContactId = contact.id
                                showRoutes = true
                            }
                        } label: {
                            Text(routeFocusContactId == contact.id ? "Hide Routes" : "Show Routes")
                                .font(.system(size: 11, weight: .semibold))
                                .padding(.horizontal, 10)
                                .padding(.vertical, 6)
                                .background(Color.cyan.opacity(routeFocusContactId == contact.id ? 0.3 : 0.18))
                                .clipShape(Capsule())
                        }

                        Button {
                            if neighborFocusContactId == contact.id {
                                neighborFocusContactId = nil
                            } else {
                                neighborFocusContactId = contact.id
                                showNeighbors = true
                            }
                        } label: {
                            Text(neighborFocusContactId == contact.id ? "Hide Neighbors" : "Show Neighbors")
                                .font(.system(size: 11, weight: .semibold))
                                .padding(.horizontal, 10)
                                .padding(.vertical, 6)
                                .background(Color.cyan.opacity(neighborFocusContactId == contact.id ? 0.3 : 0.18))
                                .clipShape(Capsule())
                        }

                        Button {
                            Task { await toggleDeviceTrail(contact) }
                        } label: {
                            Text(visibleTrailContactIds.contains(contact.id) ? "Hide Trail" : "Show Trail")
                                .font(.system(size: 11, weight: .semibold))
                                .padding(.horizontal, 10)
                                .padding(.vertical, 6)
                                .background(Color.orange.opacity(visibleTrailContactIds.contains(contact.id) ? 0.30 : 0.18))
                                .clipShape(Capsule())
                        }
                        .disabled(trailLoadingContactIds.contains(contact.id))
                    }
                }
                .font(.system(size: 12))
            } else if selectedContactTab == "stats" {
                if let stats = selectedContactStats {
                    VStack(alignment: .leading, spacing: 4) {
                        sectionTitle("Stats")
                        ForEach(stats.stats.sorted(by: { $0.key < $1.key }), id: \.key) { key, value in
                            cardRow(key.replacingOccurrences(of: "_", with: " ").capitalized, "\(value)")
                        }
                    }
                    .font(.system(size: 12))
                } else {
                    Text("Loading stats...")
                        .font(.system(size: 11))
                        .foregroundColor(detailCardSecondaryColor)
                }
            } else {
                VStack(alignment: .leading, spacing: 8) {
                    sectionTitle("Advertisement Trail")
                    if trailCoordinates.count >= 2 {
                        GeometryReader { proxy in
                            if proxy.size.width > 1 && proxy.size.height > 1 {
                                Map {
                                    MapPolyline(coordinates: trailCoordinates)
                                        .stroke(.cyan.opacity(0.75), lineWidth: 2)

                                    ForEach(Array(sortedTrailPoints.enumerated()), id: \.offset) { idx, packet in
                                        if let lat = packet.latitude, let lon = packet.longitude {
                                            Annotation("", coordinate: CLLocationCoordinate2D(latitude: lat, longitude: lon)) {
                                                Circle()
                                                    .fill(idx == sortedTrailPoints.count - 1 ? Color.green : Color.cyan)
                                                    .frame(width: 8, height: 8)
                                            }
                                        }
                                    }
                                }
                                .mapStyle(.standard(elevation: .flat))
                                .clipShape(RoundedRectangle(cornerRadius: 8, style: .continuous))
                            } else {
                                Color.clear
                            }
                        }
                        .frame(height: 130)
                    }

                    cardRow("Points", "\(trailCoordinates.count)")

                    if let latest = sortedTrailPoints.last {
                        cardRow("Latest", formatTime(latest.sentAt))
                    }

                    if let url = selectedTrailGpxUrl {
                        ShareLink(item: url) {
                            Label("Export GPX", systemImage: "square.and.arrow.up")
                                .font(.system(size: 11, weight: .semibold))
                                .foregroundColor(.cyan)
                        }
                    } else {
                        Button {
                            selectedTrailGpxUrl = exportTrailGpx(contact: contact, points: sortedTrailPoints)
                        } label: {
                            Label("Prepare GPX", systemImage: "square.and.arrow.up")
                                .font(.system(size: 11, weight: .semibold))
                                .foregroundColor(.cyan)
                        }
                    }
                }
            }
        }
        .padding(.horizontal, 16)
        .padding(.vertical, 12)
        .background(detailCardBackground)
        .overlay(
            RoundedRectangle(cornerRadius: 12, style: .continuous)
                .stroke(detailCardBorderColor, lineWidth: 1)
        )
        .cornerRadius(12)
    }

    @ViewBuilder
    private func sectionTitle(_ title: String) -> some View {
        Text(title)
            .font(.system(size: 11, weight: .bold, design: .monospaced))
            .foregroundColor(isDarkMapTheme ? Color(red: 0.63, green: 0.68, blue: 0.73) : detailCardSecondaryColor)
            .textCase(.uppercase)
    }

    @ViewBuilder
    private func popupBadge(_ text: String, color: Color) -> some View {
        Text(text)
            .font(.system(size: 9, weight: .bold, design: .monospaced))
            .foregroundColor(color)
            .padding(.horizontal, 7)
            .padding(.vertical, 3)
            .background(color.opacity(0.16))
            .overlay(
                Capsule().stroke(color.opacity(0.35), lineWidth: 0.8)
            )
            .clipShape(Capsule())
    }

    @ViewBuilder
    private func telemetryChip(key: String, value: String) -> some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(key)
                .font(.system(size: 9, weight: .semibold))
                .foregroundColor(detailCardSecondaryColor)
                .lineLimit(1)
            Text(value)
                .font(.system(size: 10, weight: .semibold, design: .monospaced))
                .foregroundColor(.cyan)
                .lineLimit(1)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(.horizontal, 8)
        .padding(.vertical, 6)
        .background(Color.white.opacity(isDarkMapTheme ? 0.06 : 0.35))
        .overlay(
            RoundedRectangle(cornerRadius: 7, style: .continuous)
                .stroke(Color.white.opacity(isDarkMapTheme ? 0.12 : 0.18), lineWidth: 0.7)
        )
        .clipShape(RoundedRectangle(cornerRadius: 7, style: .continuous))
    }

    @ViewBuilder
    private func cardRow(_ label: String, _ value: String) -> some View {
        HStack {
            Text(label + ":")
                .foregroundColor(detailCardSecondaryColor)
                .font(.system(size: 11, design: .monospaced))
            Text(value)
                .foregroundColor(.cyan)
                .font(.monospaced(.system(size: 11, weight: .semibold))())
            Spacer(minLength: 0)
        }
    }

    // MARK: - Logic

    private func loadAll(fitCamera: Bool) async {
        DispatchQueue.main.async {
            if self.contacts.isEmpty { self.isLoading = true }
        }

        do {
            async let contactsFetch = apiClient.fetchContacts()
            // ADV only — path polylines need advertisement positions only.
            // All 6 types caused excessive memory use and OOM crashes on device.
            async let feedFetch = apiClient.fetchLiveFeed(sinceMs: 0, types: ["ADV"], limit: 50)
            async let reportersFetch = apiClient.fetchReporters()
            let (cr, fr, rr) = try await (contactsFetch, feedFetch, reportersFetch)

            DispatchQueue.main.async {
                self.isLoading = false
                self.reporters = rr
                self.routeOverlaySinceMs = max(self.routeOverlaySinceMs, fr.timestampMs)

                // Detect newly arrived contacts (not seen in previous refresh)
                let incoming = Set(cr.contacts.map { $0.id })
                let nowNew: Set<Int> = self.knownContactIds.isEmpty
                    ? []
                    : incoming.subtracting(self.knownContactIds)
                self.knownContactIds = incoming

                withAnimation(.easeInOut(duration: 0.35)) {
                    self.contacts = cr.contacts
                    self.recentPackets = self.mergePackets(fr.packets, into: self.recentPackets)
                }

                if !nowNew.isEmpty {
                    self.newContactIds = nowNew
                    DispatchQueue.main.asyncAfter(deadline: .now() + 6) {
                        withAnimation { self.newContactIds.removeAll() }
                    }
                }

                // Auto-fit camera on first load only
                if fitCamera && !self.hasFitCamera {
                    self.hasFitCamera = true
                    let located = self.visibleContacts.filter { $0.latitude != nil && $0.longitude != nil }
                    if !located.isEmpty {
                        let lats = located.compactMap { $0.latitude }
                        let lons = located.compactMap { $0.longitude }
                        let avgLat = lats.reduce(0, +) / Double(lats.count)
                        let avgLon = lons.reduce(0, +) / Double(lons.count)
                        self.cameraPosition = .region(MKCoordinateRegion(
                            center: .init(latitude: avgLat, longitude: avgLon),
                            span: .init(latitudeDelta: 5, longitudeDelta: 5)
                        ))
                    }
                }
            }
        } catch {
            print("Map load error: \(error)")
            DispatchQueue.main.async { self.isLoading = false }
        }
    }

    private func startRouteOverlayAnimation() {
        animatedDashPhase = 18
        withAnimation(.linear(duration: 1.15).repeatForever(autoreverses: false)) {
            animatedDashPhase = 0
        }
    }

    private func startRouteOverlayStream() {
        routeOverlayTask?.cancel()
        routeOverlayTask = Task {
            while !Task.isCancelled {
                do {
                    // Only stream ADV packets — path polylines on the map only need advertisement positions.
                    // Streaming all 6 types caused excessive memory use and OOM crashes.
                    let stream = apiClient.streamLiveFeed(
                        sinceMs: routeOverlaySinceMs,
                        types: ["ADV"],
                        limit: 50
                    )

                    for try await response in stream {
                        if Task.isCancelled { return }
                        await MainActor.run {
                            recentPackets = mergePackets(response.packets, into: recentPackets)
                            routeOverlaySinceMs = max(routeOverlaySinceMs, response.timestampMs)
                        }
                    }
                } catch {
                    if Task.isCancelled { return }

                    do {
                        let response = try await apiClient.fetchLiveFeed(
                            sinceMs: routeOverlaySinceMs,
                            types: ["ADV"],
                            limit: 50
                        )
                        await MainActor.run {
                            recentPackets = mergePackets(response.packets, into: recentPackets)
                            routeOverlaySinceMs = max(routeOverlaySinceMs, response.timestampMs)
                        }
                    } catch {
                        if Task.isCancelled { return }
                    }

                    try? await Task.sleep(nanoseconds: 3_000_000_000)
                }
            }
        }
    }

    private func loadConfig() async {
        do {
            let appConfig = try await apiClient.fetchConfiguration()
            DispatchQueue.main.async { self.config = appConfig }
        } catch {
            print("Map config error: \(error)")
        }
    }

    private func loadSelectedContactDetails() async {
        guard let contact = selectedContact else { return }

        do {
            async let statsData = apiClient.fetchContactStats(contactId: contact.id)
            async let advsData = apiClient.fetchContactAdvertisements(contactId: contact.id)

            let (stats, advertisements) = try await (statsData, advsData)
            let trailPoints = advertisements.filter { $0.latitude != nil && $0.longitude != nil }

            await MainActor.run {
                self.selectedContactStats = stats
                self.selectedContactTrail = trailPoints
            }
        } catch {
            print("Map detail load error: \(error)")
        }
    }

    private func processNavigationFocusRequest() async {
        let requestId = navigationState.mapFocusContactId
        let requestKey = navigationState.mapFocusContactPublicKey?.uppercased()

        guard requestId != nil || requestKey != nil else { return }

        await MainActor.run {
            focusRequestedContact(contactId: requestId, publicKey: requestKey)
        }

        if selectedContact == nil {
            await loadAll(fitCamera: false)
            await MainActor.run {
                focusRequestedContact(contactId: requestId, publicKey: requestKey)
            }
        }
    }

    @MainActor
    private func focusRequestedContact(contactId: Int?, publicKey: String?) {
        let normalizedKey = publicKey?.uppercased()

        guard let contact = contacts.first(where: { item in
            if let contactId, item.id == contactId {
                return true
            }
            if let normalizedKey, item.publicKey.uppercased() == normalizedKey {
                return true
            }
            return false
        }) else {
            return
        }

        guard let lat = contact.latitude, let lon = contact.longitude else {
            return
        }

        withAnimation(.easeInOut(duration: 0.45)) {
            selectedContact = contact
            cameraPosition = .region(MKCoordinateRegion(
                center: CLLocationCoordinate2D(latitude: lat, longitude: lon),
                span: MKCoordinateSpan(latitudeDelta: 0.12, longitudeDelta: 0.12)
            ))
        }
    }

    @MainActor
    private func toggleDeviceTrail(_ contact: Contact) async {
        var ids = visibleTrailContactIds

        if ids.contains(contact.id) {
            ids.remove(contact.id)
            trailPolylinesByContactId.removeValue(forKey: contact.id)
            setVisibleTrailContactIds(ids)
            return
        }

        ids.insert(contact.id)
        setVisibleTrailContactIds(ids)
        await loadTrailForContact(contact.id)
    }

    @MainActor
    private func setVisibleTrailContactIds(_ ids: Set<Int>) {
        trailVisibleContactIdsRaw = ids.sorted().map(String.init).joined(separator: ",")
    }

    @MainActor
    private func syncVisibleTrailIdsWithCurrentContacts() {
        let currentIds = Set(contacts.map { $0.id })
        let valid = visibleTrailContactIds.intersection(currentIds)
        if valid != visibleTrailContactIds {
            setVisibleTrailContactIds(valid)
        }

        for key in trailPolylinesByContactId.keys where !currentIds.contains(key) || !valid.contains(key) {
            trailPolylinesByContactId.removeValue(forKey: key)
        }

        for key in trailPacketsByContactId.keys where !currentIds.contains(key) || !valid.contains(key) {
            trailPacketsByContactId.removeValue(forKey: key)
        }
    }

    private func ensureVisibleTrailsLoaded() async {
        let ids = visibleTrailContactIds
        for id in ids {
            await loadTrailForContact(id)
        }
    }

    private func loadTrailForContact(_ contactId: Int) async {
        let alreadyLoading = await MainActor.run {
            if trailLoadingContactIds.contains(contactId) {
                return true
            }
            trailLoadingContactIds.insert(contactId)
            return false
        }

        if alreadyLoading {
            return
        }

        do {
            let advertisements = try await apiClient.fetchContactAdvertisements(contactId: contactId)
            let sorted = advertisements.sorted { parseDate($0.sentAt) < parseDate($1.sentAt) }
            // Cap trail to 200 most-recent points to prevent unbounded memory growth.
            let capped = Array(sorted.suffix(200))
            let coords = capped.compactMap { packet -> CLLocationCoordinate2D? in
                guard let lat = packet.latitude, let lon = packet.longitude else { return nil }
                return CLLocationCoordinate2D(latitude: lat, longitude: lon)
            }

            await MainActor.run {
                trailPolylinesByContactId[contactId] = coords
                trailPacketsByContactId[contactId] = capped
                _ = trailLoadingContactIds.remove(contactId)
            }
        } catch {
            await MainActor.run {
                _ = trailLoadingContactIds.remove(contactId)
            }
            print("Trail load error for contact \(contactId): \(error)")
        }
    }

    private func mergePackets(_ incoming: [Packet], into existing: [Packet]) -> [Packet] {
        var merged: [Packet] = []
        var seen = Set<String>()

        for packet in incoming + existing {
            let key = "\(packet.type)-\(packet.id)"
            if seen.insert(key).inserted {
                merged.append(packet)
            }
        }

        // Cap at 60 — map polylines only need recent ADV packets; larger sizes cause OOM.
        return Array(merged.prefix(60))
    }

    private func splitHops(_ path: String) -> [String] {
        let separator = path.contains("->") ? "->" : ","
        return path
            .components(separatedBy: separator)
            .map { $0.lowercased().trimmingCharacters(in: .whitespacesAndNewlines) }
            .filter { $0.count >= 2 }
    }

    private func sourceContact(for packet: Packet) -> Contact? {
        if let key = packet.contactPublicKey {
            if let contact = contacts.first(where: {
                $0.publicKey.uppercased() == key.uppercased() &&
                $0.latitude != nil &&
                $0.longitude != nil
            }) {
                return contact
            }
        }

        if let cid = packet.contactId {
            return contacts.first(where: { $0.id == cid && $0.latitude != nil && $0.longitude != nil })
        }

        return nil
    }

    private func locatedContacts(matching prefix: String, repeatersOnly: Bool = false) -> [Contact] {
        let normalizedPrefix = prefix.lowercased()
        let candidates = contacts.filter {
            $0.publicKey.lowercased().hasPrefix(normalizedPrefix) &&
            $0.latitude != nil &&
            $0.longitude != nil
        }

        guard repeatersOnly else { return candidates }

        let repeaters = candidates.filter { $0.meshNodeType == .repeater }
        return repeaters.isEmpty ? candidates : repeaters
    }

    private func resolveHop(_ prefix: String, near previous: CLLocationCoordinate2D?) -> Contact? {
        let candidates = locatedContacts(matching: prefix, repeatersOnly: true)
        guard !candidates.isEmpty else { return nil }
        guard let previous else { return candidates.first }

        let previousLocation = CLLocation(latitude: previous.latitude, longitude: previous.longitude)
        return candidates.min { lhs, rhs in
            let lhsDistance = previousLocation.distance(from: CLLocation(latitude: lhs.latitude ?? 0, longitude: lhs.longitude ?? 0))
            let rhsDistance = previousLocation.distance(from: CLLocation(latitude: rhs.latitude ?? 0, longitude: rhs.longitude ?? 0))
            return lhsDistance < rhsDistance
        }
    }

    private func buildRouteChain(for packet: Packet, reporterId: Int?, path: String?) -> [Contact] {
        guard let source = sourceContact(for: packet) else { return [] }

        var chain: [Contact] = [source]
        var previousCoordinate = CLLocationCoordinate2D(latitude: source.latitude ?? 0, longitude: source.longitude ?? 0)

        if let path, !path.isEmpty {
            for hop in splitHops(path) {
                guard let relay = resolveHop(hop, near: previousCoordinate) else { continue }
                if chain.last?.id != relay.id {
                    chain.append(relay)
                    previousCoordinate = CLLocationCoordinate2D(
                        latitude: relay.latitude ?? previousCoordinate.latitude,
                        longitude: relay.longitude ?? previousCoordinate.longitude
                    )
                }
            }
        }

        if let reporterId,
           let reporterContact = reporterIdToContact[reporterId],
           chain.last?.id != reporterContact.id {
            chain.append(reporterContact)
        }

        return chain
    }

    private func coordinates(for contacts: [Contact]) -> [CLLocationCoordinate2D] {
        contacts.compactMap { contact in
            guard let lat = contact.latitude, let lon = contact.longitude else { return nil }
            return CLLocationCoordinate2D(latitude: lat, longitude: lon)
        }
    }

    private struct RouteSummary {
        let reporterName: String
        let hopsLabel: String
        let pathLabel: String
        let snrLabel: String?
        let receivedLabel: String
    }

    private func latestRouteSummary(for contact: Contact) -> RouteSummary? {
        let matches = recentPackets.filter {
            $0.contactId == contact.id || $0.contactPublicKey?.uppercased() == contact.publicKey.uppercased()
        }
        .sorted { parseDate($0.receivedAt) > parseDate($1.receivedAt) }

        for packet in matches {
            let reports: [(reporterId: Int?, path: String?, snr: Double?, receivedAt: String?)] = packet.reports.isEmpty
                ? [(packet.reporterId, packet.path, packet.snr, packet.receivedAt)]
                : packet.reports.map { ($0.reporterId ?? packet.reporterId, $0.path ?? packet.path, $0.snr ?? packet.snr, $0.receivedAt ?? packet.receivedAt) }

            guard let best = reports.sorted(by: {
                parseDate($0.receivedAt ?? "") > parseDate($1.receivedAt ?? "")
            }).first else {
                continue
            }

            let reporterName: String = {
                if let reporterId = best.reporterId,
                   let mapped = reporterIdToContact[reporterId] {
                    return mapped.name
                }
                if let name = packet.reporterName, !name.isEmpty { return name }
                return "Unknown reporter"
            }()

            let hops = splitHops(best.path ?? "").count
            return RouteSummary(
                reporterName: reporterName,
                hopsLabel: hops == 0 ? "Direct" : "\(hops) hop\(hops == 1 ? "" : "s")",
                pathLabel: (best.path?.isEmpty == false ? best.path! : "Direct"),
                snrLabel: best.snr.map { String(format: "%.1f", $0) },
                receivedLabel: formattedAbsoluteTime(best.receivedAt ?? packet.receivedAt)
            )
        }

        return nil
    }

    private func statusBadgeColor(for contact: Contact) -> Color {
        switch markerStatusLabel(for: contact) {
        case "Live":
            return .green
        case "Stale":
            return .orange
        default:
            return .gray
        }
    }

    @MainActor
    private func selectTrailPoint(_ point: TrailPointData) {
        selectedTrailPoint = point

        let reportsByReporter = Dictionary(
            grouping: point.packet.reports.compactMap { report -> PacketReport? in
                guard report.reporterId != nil else { return nil }
                return report
            },
            by: { $0.reporterId! }
        )

        let links: [TrailReporterLink] = reportsByReporter.compactMap { reporterId, reports in
            let candidates = contacts.filter {
                $0.reporterIds.contains(reporterId) &&
                $0.latitude != nil &&
                $0.longitude != nil
            }

            guard !candidates.isEmpty else { return nil }

            let pointLoc = CLLocation(latitude: point.coord.latitude, longitude: point.coord.longitude)
            let bestContact = candidates.min(by: { a, b in
                let aLoc = CLLocation(latitude: a.latitude ?? 0, longitude: a.longitude ?? 0)
                let bLoc = CLLocation(latitude: b.latitude ?? 0, longitude: b.longitude ?? 0)
                return pointLoc.distance(from: aLoc) < pointLoc.distance(from: bLoc)
            })

            guard let best = bestContact,
                  let lat = best.latitude,
                  let lon = best.longitude else {
                return nil
            }

            let reporterLoc = CLLocation(latitude: lat, longitude: lon)
            let km = pointLoc.distance(from: reporterLoc) / 1000.0
            let snr = reports.compactMap { $0.snr }.max()

            return TrailReporterLink(
                id: "r-\(point.id)-\(reporterId)-\(best.id)",
                reporterId: reporterId,
                reporterName: best.name,
                reporterCoord: CLLocationCoordinate2D(latitude: lat, longitude: lon),
                distanceKm: km,
                snr: snr
            )
        }

        selectedTrailReporterLinks = links.sorted { $0.distanceKm < $1.distanceKm }
    }

    // MARK: - Device Search Overlay (mirrors WebUI top-right search control)

    @ViewBuilder
    private func searchOverlay() -> some View {
        VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 8) {
                Image(systemName: "magnifyingglass")
                    .foregroundColor(.secondary)
                    .font(.system(size: 13))
                TextField("Search devices…", text: $searchText)
                    .font(.system(size: 13))
                    .foregroundColor(.white)
                    .autocorrectionDisabled()
                    .textInputAutocapitalization(.never)
                    .submitLabel(.search)
                if !searchText.isEmpty {
                    Button {
                        searchText = ""
                    } label: {
                        Image(systemName: "xmark.circle.fill")
                            .foregroundColor(.secondary)
                            .font(.system(size: 13))
                    }
                }
            }
            .padding(.horizontal, 12)
            .padding(.vertical, 8)
            .background(Color(white: 0.12, opacity: 0.92))

            if !searchText.isEmpty {
                let results = Array(contacts.filter {
                    let q = searchText.lowercased()
                    return $0.name.lowercased().contains(q) ||
                           $0.publicKey.lowercased().hasPrefix(q)
                }.prefix(6))

                if results.isEmpty {
                    Text("No matching devices")
                        .font(.system(size: 12))
                        .foregroundColor(.secondary)
                        .padding(.horizontal, 12)
                        .padding(.vertical, 8)
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .background(Color(white: 0.10, opacity: 0.92))
                } else {
                    ForEach(results) { contact in
                        Button {
                            jumpToContact(contact)
                            withAnimation { showSearch = false }
                            searchText = ""
                        } label: {
                            HStack(spacing: 8) {
                                Text(contact.name)
                                    .font(.system(size: 12, weight: .semibold))
                                    .foregroundColor(.white)
                                Spacer(minLength: 0)
                                Text(contact.typeLabel.uppercased())
                                    .font(.system(size: 9, weight: .bold))
                                    .foregroundColor(.cyan)
                                Text(contact.publicKey.prefix(8).lowercased())
                                    .font(.system(size: 9, design: .monospaced))
                                    .foregroundColor(.gray)
                            }
                            .padding(.horizontal, 12)
                            .padding(.vertical, 8)
                            .background(Color(white: 0.10, opacity: 0.92))
                        }
                        Divider()
                            .background(Color.white.opacity(0.07))
                    }
                }
            }
        }
        .cornerRadius(10)
        .padding(.horizontal, 14)
        .padding(.top, 8)
        .shadow(color: Color.black.opacity(0.35), radius: 6)
    }

    private func jumpToContact(_ contact: Contact) {
        guard let lat = contact.latitude, let lon = contact.longitude else { return }
        withAnimation(.easeInOut(duration: 0.45)) {
            selectedContact = contact
            cameraPosition = .region(MKCoordinateRegion(
                center: CLLocationCoordinate2D(latitude: lat, longitude: lon),
                span: MKCoordinateSpan(latitudeDelta: 0.05, longitudeDelta: 0.05)
            ))
        }
    }

    // MARK: - Trail Point Inspector

    @ViewBuilder
    private func trailPointInspector(_ point: TrailPointData) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack {
                Text("Trail Point")
                    .font(.system(size: 11, weight: .bold, design: .monospaced))
                    .foregroundColor(.white)
                Spacer()
                Button {
                    withAnimation(.easeInOut(duration: 0.2)) {
                        selectedTrailPoint = nil
                        selectedTrailReporterLinks = []
                    }
                } label: {
                    Image(systemName: "xmark.circle.fill")
                        .foregroundColor(.gray)
                }
            }

            Text(point.contactName)
                .font(.system(size: 12, weight: .semibold))
                .foregroundColor(.cyan)

            Text(String(format: "%.5f, %.5f", point.coord.latitude, point.coord.longitude))
                .font(.system(size: 10, design: .monospaced))
                .foregroundColor(.gray)

            if selectedTrailReporterLinks.isEmpty {
                Text("No located repeater listeners for this marker")
                    .font(.system(size: 10))
                    .foregroundColor(.gray)
            } else {
                ForEach(selectedTrailReporterLinks) { link in
                    trailReporterRow(link)
                }
            }
        }
        .padding(10)
        .background(Color.black.opacity(0.72))
        .overlay(
            RoundedRectangle(cornerRadius: 10, style: .continuous)
                .stroke(Color.white.opacity(0.14), lineWidth: 1)
        )
        .clipShape(RoundedRectangle(cornerRadius: 10, style: .continuous))
        .padding(.horizontal, 14)
        .padding(.top, 10)
    }

    @ViewBuilder
    private func trailReporterRow(_ link: TrailReporterLink) -> some View {
        HStack(spacing: 6) {
            Text(link.reporterName)
                .font(.system(size: 10, weight: .semibold))
                .foregroundColor(.white)
            Text(String(format: "%.2f km", link.distanceKm))
                .font(.system(size: 10, design: .monospaced))
                .foregroundColor(.cyan)
            if let snr = link.snr {
                Text(String(format: "SNR %.1f", snr))
                    .font(.system(size: 10, design: .monospaced))
                    .foregroundColor(.mint)
            }
            Spacer(minLength: 0)
        }
    }

    private func parseDate(_ value: String) -> Date {
        lastHeardFormatter.date(from: value) ?? .distantPast
    }

    private func formattedAbsoluteTime(_ value: String) -> String {
        guard !value.isEmpty else { return "-" }
        guard let date = lastHeardFormatter.date(from: value) else { return value }
        return lastHeardFormatter.string(from: date)
    }

    private func focusCameraOnTrail() {
        let points = trailCoordinates
        guard points.count >= 2 else { return }

        let lats = points.map { $0.latitude }
        let lons = points.map { $0.longitude }

        guard let minLat = lats.min(), let maxLat = lats.max(),
              let minLon = lons.min(), let maxLon = lons.max() else {
            return
        }

        let center = CLLocationCoordinate2D(
            latitude: (minLat + maxLat) / 2,
            longitude: (minLon + maxLon) / 2
        )

        let latDelta = max((maxLat - minLat) * 1.5, 0.01)
        let lonDelta = max((maxLon - minLon) * 1.5, 0.01)

        withAnimation(.easeInOut(duration: 0.5)) {
            cameraPosition = .region(MKCoordinateRegion(
                center: center,
                span: MKCoordinateSpan(latitudeDelta: latDelta, longitudeDelta: lonDelta)
            ))
        }
    }

    private func exportTrailGpx(contact: Contact, points: [Packet]) -> URL? {
        let entries = points.compactMap { packet -> String? in
            guard let lat = packet.latitude, let lon = packet.longitude else { return nil }
            return "<trkpt lat=\"\(lat)\" lon=\"\(lon)\"></trkpt>"
        }

        guard !entries.isEmpty else { return nil }

        let gpx = """
        <?xml version="1.0" encoding="UTF-8"?>
        <gpx version="1.1" creator="MeshLog iOS" xmlns="http://www.topografix.com/GPX/1/1">
          <trk>
            <name>\(contact.name)</name>
            <trkseg>
              \(entries.joined(separator: "\n"))
            </trkseg>
          </trk>
        </gpx>
        """

        let filename = "meshlog_trail_\(contact.id).gpx"
        let fileURL = FileManager.default.temporaryDirectory.appendingPathComponent(filename)
        do {
            try gpx.data(using: .utf8)?.write(to: fileURL)
            return fileURL
        } catch {
            print("GPX export error: \(error)")
            return nil
        }
    }

    private func formatTime(_ dateString: String) -> String {
        guard let date = lastHeardFormatter.date(from: dateString) else { return dateString }
        let interval = Date().timeIntervalSince(date)
        if interval < 60    { return "Just now" }
        if interval < 3600  { return "\(Int(interval / 60))m ago" }
        if interval < 86400 { return "\(Int(interval / 3600))h ago" }
        return "\(Int(interval / 86400))d ago"
    }
}

#Preview {
    MapView()
        .environmentObject(APIClient())
        .environmentObject(AuthenticationManager())
    .environmentObject(AppNavigationState())
}
