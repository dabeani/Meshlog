/// Map View - Visualize device locations with live update animations
import SwiftUI
import MapKit
import Combine
#if canImport(CoreLocation)
import CoreLocation
#endif

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

private func fallbackRepeaterClockWarning(for contact: Contact) -> String? {
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

enum MeshMapSource: String, CaseIterable {
    case dark
    case light
    case topo

    var isDarkAppearance: Bool {
#if os(tvOS)
        // tvOS runs topo as the default source; treat it as dark for overlay/background consistency.
        self == .dark || self == .topo
#else
        self == .dark
#endif
    }

    var systemImage: String {
        switch self {
        case .dark:
            return "moon.fill"
        case .light:
            return "sun.max.fill"
        case .topo:
            return "mountain.2.fill"
        }
    }

    var feedbackLabel: String {
        switch self {
        case .dark:
            return "Dark Map"
        case .light:
            return "Light Map"
        case .topo:
            return "Topo Map"
        }
    }

    var next: MeshMapSource {
        switch self {
        case .dark:
            return .light
        case .light:
            return .topo
        case .topo:
            return .dark
        }
    }
}

enum TopoMapPolylineStyle {
    case route
    case trail
    case neighbor
    case trailReporter
}

struct TopoMapPolyline: Identifiable {
    let id: String
    let coordinates: [CLLocationCoordinate2D]
    let style: TopoMapPolylineStyle
}

struct TopoMapTrailPoint: Identifiable {
    let id: String
    let contactId: Int
    let contactName: String
    let timestamp: String
    let coordinate: CLLocationCoordinate2D
}

struct TopoMapContactMarker: Identifiable {
    let contact: Contact
    let isReporter: Bool
    let isHighlighted: Bool
    let hasClockWarning: Bool

    var id: Int { contact.id }
}

private final class OpenTopoMapTileOverlay: MKTileOverlay {
    override func url(forTilePath path: MKTileOverlayPath) -> URL {
        let subdomains = ["a", "b", "c"]
        let index = abs(path.x + path.y) % subdomains.count
        let subdomain = subdomains[index]
        return URL(string: "https://\(subdomain).tile.opentopomap.org/\(path.z)/\(path.x)/\(path.y).png")!
    }
}

private final class StyledPolylineOverlay: MKPolyline {
    var style: TopoMapPolylineStyle = .route
}

private final class ContactAnnotation: NSObject, MKAnnotation {
    let marker: TopoMapContactMarker
    let coordinate: CLLocationCoordinate2D
    let title: String?
    let subtitle: String?

    init(marker: TopoMapContactMarker) {
        self.marker = marker
        self.coordinate = CLLocationCoordinate2D(
            latitude: marker.contact.latitude ?? 0,
            longitude: marker.contact.longitude ?? 0
        )
        self.title = marker.contact.name

        var parts: [String] = []
        if marker.isReporter {
            parts.append("Collector")
        }
        if marker.hasClockWarning {
            parts.append("Clock warning")
        }
        self.subtitle = parts.isEmpty ? nil : parts.joined(separator: " · ")
    }
}

private final class TrailPointAnnotation: NSObject, MKAnnotation {
    let point: TopoMapTrailPoint
    let coordinate: CLLocationCoordinate2D
    let title: String?
    let subtitle: String?

    init(point: TopoMapTrailPoint) {
        self.point = point
        self.coordinate = point.coordinate
        self.title = point.contactName
        self.subtitle = point.timestamp
    }
}

struct TopoTileMapView: UIViewRepresentable {
    @Binding var region: MKCoordinateRegion

    let contacts: [TopoMapContactMarker]
    let polylines: [TopoMapPolyline]
    let trailPoints: [TopoMapTrailPoint]
    let onSelectContact: (Contact) -> Void
    let onSelectTrailPoint: (String) -> Void

    func makeCoordinator() -> Coordinator {
        Coordinator(parent: self)
    }

    func makeUIView(context: Context) -> MKMapView {
        let mapView = MKMapView(frame: .zero)
        mapView.delegate = context.coordinator
        mapView.pointOfInterestFilter = .excludingAll
    #if os(iOS)
        mapView.showsCompass = true
    #endif
        mapView.showsScale = false
#if os(iOS)
        mapView.showsUserLocation = true
#else
        mapView.showsUserLocation = false
#endif
    #if os(iOS)
        mapView.isPitchEnabled = false
        mapView.isRotateEnabled = false
    #endif
        mapView.setRegion(region, animated: false)

        let overlay = OpenTopoMapTileOverlay()
        overlay.canReplaceMapContent = true
        mapView.addOverlay(overlay, level: .aboveLabels)

        return mapView
    }

    func updateUIView(_ mapView: MKMapView, context: Context) {
        context.coordinator.parent = self

        if context.coordinator.shouldUpdateRegion(mapView.region, to: region) {
            context.coordinator.isApplyingProgrammaticRegionChange = true
            mapView.setRegion(region, animated: true)
        }

        let polylineSignature = polylines.map { $0.id }.joined(separator: "|")
        if context.coordinator.lastPolylineSignature != polylineSignature {
            let existingOverlays = mapView.overlays.filter { !($0 is OpenTopoMapTileOverlay) }
            if !existingOverlays.isEmpty {
                mapView.removeOverlays(existingOverlays)
            }

            for polyline in polylines where polyline.coordinates.count >= 2 {
                let overlay = StyledPolylineOverlay(coordinates: polyline.coordinates, count: polyline.coordinates.count)
                overlay.style = polyline.style
                mapView.addOverlay(overlay)
            }
            context.coordinator.lastPolylineSignature = polylineSignature
        }

        let contactSignature = contacts
            .map { marker in
                "\(marker.id):\(marker.isReporter ? 1 : 0):\(marker.isHighlighted ? 1 : 0):\(marker.hasClockWarning ? 1 : 0)"
            }
            .joined(separator: "|")
        let trailSignature = trailPoints.map { $0.id }.joined(separator: "|")

        if context.coordinator.lastContactSignature != contactSignature ||
            context.coordinator.lastTrailSignature != trailSignature {
            let removableAnnotations = mapView.annotations.filter {
                ($0 is ContactAnnotation) || ($0 is TrailPointAnnotation)
            }
            if !removableAnnotations.isEmpty {
                mapView.removeAnnotations(removableAnnotations)
            }

            let contactAnnotations = contacts.compactMap { marker -> ContactAnnotation? in
                guard marker.contact.latitude != nil, marker.contact.longitude != nil else { return nil }
                return ContactAnnotation(marker: marker)
            }
            let trailAnnotations = trailPoints.map(TrailPointAnnotation.init)
            mapView.addAnnotations(contactAnnotations + trailAnnotations)

            context.coordinator.lastContactSignature = contactSignature
            context.coordinator.lastTrailSignature = trailSignature
        }
    }

    final class Coordinator: NSObject, MKMapViewDelegate {
        var parent: TopoTileMapView
        var isApplyingProgrammaticRegionChange = false
        var lastPolylineSignature = ""
        var lastContactSignature = ""
        var lastTrailSignature = ""

        init(parent: TopoTileMapView) {
            self.parent = parent
        }

        func shouldUpdateRegion(_ current: MKCoordinateRegion, to next: MKCoordinateRegion) -> Bool {
            let centerDelta = abs(current.center.latitude - next.center.latitude) + abs(current.center.longitude - next.center.longitude)
            let spanDelta = abs(current.span.latitudeDelta - next.span.latitudeDelta) + abs(current.span.longitudeDelta - next.span.longitudeDelta)
            return centerDelta > 0.0001 || spanDelta > 0.0001
        }

        func mapView(_ mapView: MKMapView, regionDidChangeAnimated animated: Bool) {
            if isApplyingProgrammaticRegionChange {
                isApplyingProgrammaticRegionChange = false
            }

            let updatedRegion = mapView.region
            DispatchQueue.main.async {
                self.parent.region = updatedRegion
            }
        }

        func mapView(_ mapView: MKMapView, rendererFor overlay: MKOverlay) -> MKOverlayRenderer {
            if overlay is OpenTopoMapTileOverlay {
                return MKTileOverlayRenderer(overlay: overlay)
            }

            guard let styled = overlay as? StyledPolylineOverlay else {
                return MKOverlayRenderer(overlay: overlay)
            }

            let renderer = MKPolylineRenderer(polyline: styled)
            renderer.lineCap = .round
            renderer.lineJoin = .round

            switch styled.style {
            case .route:
                renderer.strokeColor = UIColor(red: 0.42, green: 0.78, blue: 0.72, alpha: 0.9)
                renderer.lineWidth = 3.0
                renderer.lineDashPattern = [10, 7]
            case .trail:
                renderer.strokeColor = UIColor(red: 0.93, green: 0.28, blue: 0.34, alpha: 0.58)
                renderer.lineWidth = 2.2
                renderer.lineDashPattern = [7, 5]
            case .neighbor:
                renderer.strokeColor = UIColor(red: 0.55, green: 0.72, blue: 0.94, alpha: 0.92)
                renderer.lineWidth = 2.6
                renderer.lineDashPattern = [7, 5]
            case .trailReporter:
                renderer.strokeColor = UIColor(red: 0.43, green: 0.68, blue: 0.94, alpha: 0.88)
                renderer.lineWidth = 2.1
                renderer.lineDashPattern = [5, 4]
            }

            return renderer
        }

        func mapView(_ mapView: MKMapView, viewFor annotation: MKAnnotation) -> MKAnnotationView? {
            if let annotation = annotation as? ContactAnnotation {
                let identifier = "TopoContactAnnotation"
                let view = (mapView.dequeueReusableAnnotationView(withIdentifier: identifier) as? MKMarkerAnnotationView)
                    ?? MKMarkerAnnotationView(annotation: annotation, reuseIdentifier: identifier)
                view.annotation = annotation
                view.canShowCallout = false
                view.clusteringIdentifier = nil
                view.displayPriority = .required
                view.glyphImage = UIImage(systemName: annotation.marker.contact.meshNodeType.mapSymbolName)
                view.glyphTintColor = .white
                view.markerTintColor = markerColor(for: annotation.marker)
                view.layer.borderWidth = annotation.marker.isHighlighted ? 2.0 : 0.0
                view.layer.borderColor = UIColor.white.cgColor
                return view
            }

            if annotation is MKUserLocation {
                return nil
            }

            if annotation is TrailPointAnnotation {
                let identifier = "TopoTrailPointAnnotation"
                let view = mapView.dequeueReusableAnnotationView(withIdentifier: identifier) ?? MKAnnotationView(annotation: annotation, reuseIdentifier: identifier)
                view.annotation = annotation
                view.canShowCallout = false
                view.clusteringIdentifier = nil
                view.displayPriority = .required
                view.frame = CGRect(x: 0, y: 0, width: 14, height: 14)
                view.backgroundColor = UIColor.systemRed
                view.layer.cornerRadius = 7
                view.layer.borderWidth = 2
                view.layer.borderColor = UIColor.white.withAlphaComponent(0.92).cgColor
                return view
            }

            return nil
        }

        func mapView(_ mapView: MKMapView, didSelect view: MKAnnotationView) {
            defer {
                if let annotation = view.annotation {
                    mapView.deselectAnnotation(annotation, animated: false)
                }
            }

            if let annotation = view.annotation as? ContactAnnotation {
                parent.onSelectContact(annotation.marker.contact)
            } else if let annotation = view.annotation as? TrailPointAnnotation {
                parent.onSelectTrailPoint(annotation.point.id)
            }
        }

        private func markerColor(for marker: TopoMapContactMarker) -> UIColor {
            if marker.isHighlighted {
                return UIColor(red: 0.06, green: 0.67, blue: 0.78, alpha: 1.0)
            }

            let age = secondsSince(marker.contact.lastHeardAt)

            if age < 300 {
                return UIColor(red: 0.42, green: 0.78, blue: 0.72, alpha: 1.0)
            }
            if age < 3600 {
                return UIColor(red: 0.90, green: 0.76, blue: 0.40, alpha: 1.0)
            }
            return UIColor(red: 0.42, green: 0.42, blue: 0.52, alpha: 1.0)
        }
    }
}

#if os(iOS)

private final class DeviceLocationManager: NSObject, ObservableObject, CLLocationManagerDelegate {
    @Published var currentCoordinate: CLLocationCoordinate2D?

    private let manager = CLLocationManager()
    private var didRequest = false

    override init() {
        super.init()
        manager.delegate = self
        manager.desiredAccuracy = kCLLocationAccuracyHundredMeters
    }

    func requestCurrentLocation() {
        guard !didRequest else { return }
        didRequest = true

        switch manager.authorizationStatus {
        case .notDetermined:
            manager.requestWhenInUseAuthorization()
        case .authorizedWhenInUse, .authorizedAlways:
            manager.requestLocation()
        default:
            break
        }
    }

    func locationManagerDidChangeAuthorization(_ manager: CLLocationManager) {
        switch manager.authorizationStatus {
        case .authorizedWhenInUse, .authorizedAlways:
            manager.requestLocation()
        default:
            break
        }
    }

    func locationManager(_ manager: CLLocationManager, didUpdateLocations locations: [CLLocation]) {
        currentCoordinate = locations.last?.coordinate
    }

    func locationManager(_ manager: CLLocationManager, didFailWithError error: Error) {
        // Ignore transient location errors; map falls back to contact-fit logic.
    }
}

#else

private final class DeviceLocationManager: ObservableObject {
    @Published var currentCoordinate: CLLocationCoordinate2D?

    func requestCurrentLocation() {
        // tvOS: no runtime location request flow in this app.
    }
}

#endif

// MARK: - Node Annotation View

private struct NodeAnnotationView: View {
    let contact: Contact
    let isReporter: Bool
    let isNew: Bool
    let isPathHighlighted: Bool
    let hasClockWarning: Bool
    let forceShowLabel: Bool
    let onTap: () -> Void

    @AppStorage("map_show_repeater_names") private var showLabels = true
    @State private var scale: CGFloat

    init(
        contact: Contact,
        isReporter: Bool,
        isNew: Bool,
        isPathHighlighted: Bool,
        hasClockWarning: Bool,
        forceShowLabel: Bool = false,
        onTap: @escaping () -> Void
    ) {
        self.contact = contact
        self.isReporter = isReporter
        self.isNew = isNew
        self.isPathHighlighted = isPathHighlighted
        self.hasClockWarning = hasClockWarning
        self.forceShowLabel = forceShowLabel
        self.onTap = onTap
        _scale = State(initialValue: isNew ? 0.01 : 1.0)
    }

    private var nodeColor: Color {
        let age = secondsSince(contact.lastHeardAt)
        if age < 300  { return Color(red: 0.42, green: 0.78, blue: 0.72) }
        if age < 3600 { return Color(red: 0.90, green: 0.76, blue: 0.40) }
        return Color(red: 0.42, green: 0.42, blue: 0.52)
    }

    private var showsLiveHalo: Bool { secondsSince(contact.lastHeardAt) < 300 }
    // Highlighted names are rendered in a dedicated top overlay layer.
    private var shouldShowLabel: Bool { forceShowLabel || showLabels }

    var body: some View {
        VStack(spacing: 3) {
            ZStack {
                if showsLiveHalo || isPathHighlighted {
                    Circle()
                        .fill((isPathHighlighted ? Color(red: 0.20, green: 0.90, blue: 1.0) : nodeColor).opacity(isPathHighlighted ? 0.35 : 0.14))
                        .frame(width: isPathHighlighted ? 50 : 38, height: isPathHighlighted ? 50 : 38)
                }

                if isPathHighlighted {
                    Circle()
                        .stroke(Color.white.opacity(0.95), lineWidth: 2)
                        .frame(width: 42, height: 42)
                }

                ZStack(alignment: .topTrailing) {
                    Image(systemName: iconName)
                        .font(.system(size: 13, weight: .bold))
                        .foregroundColor(.white)
                        .frame(width: 28, height: 28)
                        .background(Circle().fill(isPathHighlighted ? Color(red: 0.06, green: 0.67, blue: 0.78) : nodeColor))

                    if isReporter {
                        Image(systemName: "receipt.fill")
                            .font(.system(size: 7, weight: .bold))
                            .foregroundColor(.white)
                            .padding(4)
                            .background(Circle().fill(Color.black.opacity(0.65)))
                            .offset(x: 5, y: -4)
                    }

                    if hasClockWarning {
                        Text("!")
                            .font(.system(size: 10, weight: .black, design: .rounded))
                            .foregroundColor(.white)
                            .frame(width: 16, height: 16)
                            .background(Circle().fill(Color(red: 0.90, green: 0.34, blue: 0.14)))
                            .overlay(
                                Circle().stroke(Color.white.opacity(0.95), lineWidth: 1.2)
                            )
                            .offset(x: 8, y: 8)
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

private struct HighlightedContactOverlay: View {
    let contact: Contact
    let isReporter: Bool
    let hasClockWarning: Bool

    var body: some View {
        NodeAnnotationView(
            contact: contact,
            isReporter: isReporter,
            isNew: false,
            isPathHighlighted: true,
            hasClockWarning: hasClockWarning,
            forceShowLabel: true,
            onTap: {}
        )
        .shadow(color: Color(red: 0.15, green: 0.95, blue: 1.0).opacity(0.55), radius: 10)
    }
}

private struct HighlightedOverlayMarker: Identifiable {
    let id: Int
    let contact: Contact
    let isReporter: Bool
    let hasClockWarning: Bool
}

// MARK: - Map View

struct MapView: View {
    @EnvironmentObject var apiClient: APIClient
    @EnvironmentObject var navigationState: AppNavigationState
    @Environment(\.scenePhase) private var scenePhase

    @State private var contacts: [Contact] = []
    @State private var recentPackets: [Packet] = []
    @State private var cameraPosition: MapCameraPosition = .automatic
    @State private var topoRegion = MKCoordinateRegion(
        center: CLLocationCoordinate2D(latitude: 47.6, longitude: 14.3),
        span: MKCoordinateSpan(latitudeDelta: 5.0, longitudeDelta: 5.0)
    )
    @StateObject private var deviceLocationManager = DeviceLocationManager()
    @State private var isLoading = false
    @State private var selectedContact: Contact?
    @State private var selectedContactStats: StatisticsResponse?
    @State private var selectedContactTrail: [Packet] = []
    @State private var selectedContactTab: String = "general"
    @State private var selectedTrailGpxUrl: URL?
    @State private var neighborFocusContactId: Int?
    @State private var config: AppConfiguration?

    @AppStorage("map_show_repeater_names") private var showRepeaterNames = true
    @AppStorage("map_source") private var mapSourceRaw = ""
    @AppStorage("map_dark_theme") private var legacyDarkMapTheme = true
    @AppStorage("map_show_routes") private var showRoutes = true
    @AppStorage("map_show_neighbors") private var showNeighbors = false
    @AppStorage("map_visible_route_contact_ids") private var routeVisibleContactIdsRaw = ""
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
    @FocusState private var isSearchFieldFocused: Bool
    @State private var searchOverlayTask: Task<Void, Never>?
    @State private var mapLatitudeDelta: Double = 5.0
    @State private var reporters: [Reporter] = []
    @State private var routeOverlayTask: Task<Void, Never>?
    @State private var mapRenderTask: Task<Void, Never>?
    @State private var trailRenderRefreshTask: Task<Void, Never>?
    @State private var routeRenderRefreshTask: Task<Void, Never>?
    @State private var topToggleFeedback: TopToggleFeedback?
    @State private var topToggleFeedbackTask: Task<Void, Never>?
    @State private var showLegend = false
    @State private var routeOverlaySinceMs: Int = 0
    @State private var animatedDashPhase: CGFloat = 18
    @State private var renderedMainTrailPoints: [TrailPointData] = []
    @State private var renderedPathPolylines: [PolylineData] = []
    @State private var renderedNeighborPolylines: [PolylineData] = []
    @State private var shouldRenderMainMap = false
    @State private var livePacketFlashes: [LivePacketFlash] = []
    @State private var highlightedPathContactIds: Set<Int> = []
    @State private var clockWarningContactIds: Set<Int> = []
    @State private var clockWarningTextByContactId: [Int: String] = [:]
    @State private var highlightedOverlayMarkers: [HighlightedOverlayMarker] = []

    private let maxStoredRecentPackets = 60
    private let maxRenderedFocusedRoutePolylines = 24
    private let maxAnimatedRoutePolylines = 8
    private let maxRenderedTrailDots = 700

    private var selectedMapSource: MeshMapSource {
        get {
            if let value = MeshMapSource(rawValue: mapSourceRaw) {
                return value
            }
            return legacyDarkMapTheme ? .dark : .light
        }
        nonmutating set {
            mapSourceRaw = newValue.rawValue
            legacyDarkMapTheme = (newValue == .dark)
        }
    }

    private var isDarkMapTheme: Bool {
        selectedMapSource.isDarkAppearance
    }

    private var mapColorScheme: ColorScheme {
        isDarkMapTheme ? .dark : .light
    }

    private var mapSourceTintColor: Color {
        selectedMapSource == .light ? .yellow : .cyan
    }

    private var mapSourceSymbol: String {
        selectedMapSource.systemImage
    }

    private var periodicRefreshTimer: Publishers.Autoconnect<Timer.TimerPublisher> {
        Timer.publish(every: 300, on: .main, in: .common).autoconnect()
    }

    private var mapBackgroundColor: Color {
        isDarkMapTheme
            ? Color(red: 0.08, green: 0.08, blue: 0.12)
            : Color(red: 0.95, green: 0.96, blue: 0.98)
    }

    private var mainMapStyle: MapStyle {
    #if os(tvOS)
        // tvOS map startup is more stable with flat elevation.
        return .standard(elevation: .flat)
    #elseif targetEnvironment(simulator)
        // Simulator often emits sandbox warnings for Maps telemetry with realistic elevation.
        return .standard(elevation: .flat)
#else
        return .standard(elevation: .realistic)
#endif
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

    private var visibleRouteContactIds: Set<Int> {
        Set(
            routeVisibleContactIdsRaw
                .split(separator: ",")
                .compactMap { Int($0.trimmingCharacters(in: .whitespaces)) }
        )
    }

    private var visibleContacts: [Contact] {
        let selected = selectedCollectorIds
        guard !selected.isEmpty else { return contacts }
        return contacts.filter { !Set($0.reporterIds).isDisjoint(with: selected) }
    }

    private var neighborFocusContact: Contact? {
        guard let id = neighborFocusContactId else { return nil }
        return contacts.first(where: { $0.id == id })
    }

    private var reporterKeySet: Set<String> {
        Set(reporters.map { $0.publicKey.uppercased() })
    }

    private var reporterByPublicKey: [String: Reporter] {
        Dictionary(uniqueKeysWithValues: reporters.map { ($0.publicKey.uppercased(), $0) })
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

    private func repeaterClockWarningText(for contact: Contact, reporter: Reporter?) -> String? {
        guard contact.meshNodeType == .repeater else { return nil }

        if let timeSync = reporter?.timeSync, timeSync.available {
            guard timeSync.warning else { return nil }
            let driftMs = timeSync.driftMs ?? 0
            let direction = driftMs >= 0 ? "ahead of" : "behind"
            return "Repeater clock is \(formatDrift(driftMs)) \(direction) UTC (NTP reference). Time sync needed."
        }

        return fallbackRepeaterClockWarning(for: contact)
    }

    @MainActor
    private func rebuildClockWarningCaches(for contacts: [Contact], reporters: [Reporter]) {
        let reporterByKey = Dictionary(uniqueKeysWithValues: reporters.map { ($0.publicKey.uppercased(), $0) })
        var warningIds = Set<Int>()
        var warningTexts: [Int: String] = [:]

        for contact in contacts {
            if let warning = repeaterClockWarningText(for: contact, reporter: reporterByKey[contact.publicKey.uppercased()]) {
                warningIds.insert(contact.id)
                warningTexts[contact.id] = warning
            }
        }

        clockWarningContactIds = warningIds
        clockWarningTextByContactId = warningTexts
    }

    @MainActor
    private func rebuildHighlightedOverlayMarkers() {
        guard !highlightedPathContactIds.isEmpty else {
            highlightedOverlayMarkers = []
            return
        }

        highlightedOverlayMarkers = displayedContacts
            .filter { highlightedPathContactIds.contains($0.id) }
            .map { contact in
                HighlightedOverlayMarker(
                    id: contact.id,
                    contact: contact,
                    isReporter: reporterKeySet.contains(contact.publicKey.uppercased()),
                    hasClockWarning: clockWarningContactIds.contains(contact.id)
                )
            }
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

    private var shouldAnimateRoutePolylines: Bool {
        renderedPathPolylines.count <= maxAnimatedRoutePolylines
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

    private struct TopToggleFeedback: Identifiable {
        let id = UUID()
        let symbol: String
        let text: String
    }

    private var displayedContacts: [Contact] {
        let source = visibleContacts
        let maxCount: Int
        switch mapLatitudeDelta {
        case ..<1.5:
            maxCount = 800
        case ..<5.0:
            maxCount = 500
        case ..<15.0:
            maxCount = 300
        default:
            maxCount = 180
        }

        guard source.count > maxCount else { return source }
        let stride = max(1, Int(ceil(Double(source.count) / Double(maxCount))))
        let sampled = source.enumerated().compactMap { index, item in
            (index % stride == 0) ? item : nil
        }

        // Keep currently highlighted path nodes visible even when the map is downsampling.
        guard !highlightedPathContactIds.isEmpty else { return sampled }
        let sampledIds = Set(sampled.map { $0.id })
        let highlightedExtras = source.filter {
            highlightedPathContactIds.contains($0.id) && !sampledIds.contains($0.id)
        }
        return sampled + highlightedExtras
    }

    private var topoContactMarkers: [TopoMapContactMarker] {
        displayedContacts.map { contact in
            TopoMapContactMarker(
                contact: contact,
                isReporter: reporterKeySet.contains(contact.publicKey.uppercased()),
                isHighlighted: highlightedPathContactIds.contains(contact.id),
                hasClockWarning: clockWarningContactIds.contains(contact.id)
            )
        }
    }

    private var topoPolylines: [TopoMapPolyline] {
        var items: [TopoMapPolyline] = []

        if showRoutes {
            items.append(contentsOf: renderedPathPolylines.map {
                TopoMapPolyline(id: $0.id, coordinates: $0.coords, style: .route)
            })
            items.append(contentsOf: mainTrailPolylines.map {
                TopoMapPolyline(id: $0.id, coordinates: $0.coords, style: .trail)
            })
            items.append(contentsOf: selectedTrailReporterPolylines.map {
                TopoMapPolyline(id: $0.id, coordinates: $0.coords, style: .trailReporter)
            })
        }

        if showNeighbors {
            items.append(contentsOf: renderedNeighborPolylines.map {
                TopoMapPolyline(id: $0.id, coordinates: $0.coords, style: .neighbor)
            })
        }

        return items
    }

    private var topoTrailPoints: [TopoMapTrailPoint] {
        guard showRoutes else { return [] }
        return renderedMainTrailPoints.map { point in
            TopoMapTrailPoint(
                id: point.id,
                contactId: point.contactId,
                contactName: point.contactName,
                timestamp: point.packet.sentAt,
                coordinate: point.coord
            )
        }
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

    private var isMapTabActive: Bool {
        navigationState.selectedTab == 1 && scenePhase == .active
    }

    private var detailCardTransition: AnyTransition {
        .asymmetric(
            insertion: .move(edge: .bottom).combined(with: .opacity),
            removal: .opacity
        )
    }

    @ViewBuilder
    private func selectedContactOverlay() -> some View {
        if let contact = selectedContact {
            contactDetailCard(contact)
                .padding(14)
                .transition(detailCardTransition)
        }
    }

    @ViewBuilder
    private func loadingOverlay() -> some View {
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

    @ViewBuilder
    private func topOverlayContent() -> some View {
        VStack(spacing: 0) {
            if let feedback = topToggleFeedback {
                topToggleFeedbackBanner(feedback)
                    .padding(.horizontal, 14)
                    .padding(.top, 8)
                    .transition(.move(edge: .top).combined(with: .opacity))
            }

#if os(iOS)
            if showSearch {
                searchOverlay()
            }
#endif
            if showLegend {
                mapLegendCard()
                    .padding(.horizontal, 14)
                    .padding(.top, 8)
                    .transition(.move(edge: .top).combined(with: .opacity))
            }
            if showRoutes, let point = selectedTrailPoint {
                trailPointInspector(point)
            }
        }
    }

    private func cycleMapSource() {
        setMapSource(selectedMapSource.next)
    }

    private func setMapSource(_ source: MeshMapSource) {
        guard selectedMapSource != source else { return }
        withAnimation(.easeInOut(duration: 0.3)) {
            selectedMapSource = source
        }
        presentTopToggleFeedback(
            symbol: selectedMapSource.systemImage,
            text: selectedMapSource.feedbackLabel
        )
    }

    private func handleTopoRegionChange(_ region: MKCoordinateRegion) {
        let latitudeDelta = region.span.latitudeDelta
        mapLatitudeDelta = latitudeDelta
        if selectedMapSource == .topo {
            cameraPosition = .region(region)
        }
    }

    private func refreshMapActiveState() {
        let active = isMapTabActive
        setMapActiveState(active)
    }

    private struct TopoRegionChangeToken: Equatable {
        let centerLatitude: Double
        let centerLongitude: Double
        let latitudeDelta: Double
        let longitudeDelta: Double
    }

    private var topoRegionChangeToken: TopoRegionChangeToken {
        TopoRegionChangeToken(
            centerLatitude: topoRegion.center.latitude,
            centerLongitude: topoRegion.center.longitude,
            latitudeDelta: topoRegion.span.latitudeDelta,
            longitudeDelta: topoRegion.span.longitudeDelta
        )
    }

    private func handleMapAppear() {
    #if os(tvOS)
        selectedMapSource = .topo
    #else
        if mapSourceRaw.isEmpty {
            selectedMapSource = legacyDarkMapTheme ? .dark : .light
        }
    #endif
#if os(tvOS)
        showSearch = false
        isSearchFieldFocused = false
#endif
        refreshMapActiveState()
        deviceLocationManager.requestCurrentLocation()
        scheduleInitialLocationFit()
        scheduleInitialMapLoadIfNeeded()
        Task { await loadConfig() }
        Task { await ensureVisibleTrailsLoaded() }
        Task { await processNavigationFocusRequest() }
        scheduleTrailPointRefresh()
    }

    private func scheduleInitialLocationFit() {
        Task { @MainActor in
            for _ in 0..<20 {
                if hasFitCamera { return }
                if let coordinate = deviceLocationManager.currentCoordinate {
                    hasFitCamera = true
                    setCameraRegion(MKCoordinateRegion(
                        center: coordinate,
                        span: .init(latitudeDelta: 0.08, longitudeDelta: 0.08)
                    ))
                    return
                }
                try? await Task.sleep(nanoseconds: 300_000_000)
            }
        }
    }

    private func scheduleInitialMapLoadIfNeeded() {
        Task {
            if isMapTabActive {
                await loadAll(fitCamera: true)
                await MainActor.run {
                    scheduleRouteOverlayRefresh()
                }
            }
        }
    }

    private func handleMapDisappear() {
        routeOverlayTask?.cancel()
        mapRenderTask?.cancel()
        trailRenderRefreshTask?.cancel()
        routeRenderRefreshTask?.cancel()
        topToggleFeedbackTask?.cancel()
        searchOverlayTask?.cancel()
        shouldRenderMainMap = false
        livePacketFlashes.removeAll()
    }

    private func handleSelectedContactChange() {
        guard selectedContact != nil else {
            selectedContactStats = nil
            selectedContactTrail = []
            selectedTrailGpxUrl = nil
            neighborFocusContactId = nil
            return
        }
        selectedContactTab = "general"
        selectedTrailGpxUrl = nil
        neighborFocusContactId = nil
        Task { await loadSelectedContactDetails() }
    }

    private func handleSelectedContactTabChange(_ tab: String) {
        guard tab == "trail", trailCoordinates.count >= 2 else { return }
        focusCameraOnTrail()
    }

    private func handleContactIdsChange() {
        Task {
            syncVisibleRouteIdsWithCurrentContacts()
            syncVisibleTrailIdsWithCurrentContacts()
            await ensureVisibleTrailsLoaded()
            await MainActor.run {
                rebuildHighlightedOverlayMarkers()
                scheduleTrailPointRefresh()
                scheduleRouteOverlayRefresh()
            }
        }
    }

    private func handleTrailVisibleContactIdsChange() {
        Task {
            await ensureVisibleTrailsLoaded()
            await MainActor.run {
                scheduleTrailPointRefresh()
            }
        }
    }

    private func handleCollectorFilterChange() {
        rebuildHighlightedOverlayMarkers()
        scheduleTrailPointRefresh()
        scheduleRouteOverlayRefresh()
    }

    private func handleMapLatitudeDeltaChange() {
        rebuildHighlightedOverlayMarkers()
        scheduleTrailPointRefresh()
    }

    private func handleShowRoutesChange(_ enabled: Bool) {
        if !enabled {
            selectedTrailPoint = nil
            selectedTrailReporterLinks = []
        }
        scheduleRouteOverlayRefresh()
    }

    private func handleShowNeighborsChange(_ enabled: Bool) {
        if !enabled {
            neighborFocusContactId = nil
        }
        scheduleRouteOverlayRefresh()
    }

    private func handleNavigationFocusChange() {
        Task { await processNavigationFocusRequest() }
    }

    private func handlePeriodicRefresh() {
        Task { await loadAll(fitCamera: false) }
    }

    @ViewBuilder
    private func mapCanvasArea() -> some View {
        ZStack {
            GeometryReader { proxy in
                if shouldRenderMainMap && proxy.size.width > 1 && proxy.size.height > 1 {
#if os(tvOS)
                    TopoTileMapView(
                        region: $topoRegion,
                        contacts: topoContactMarkers,
                        polylines: topoPolylines,
                        trailPoints: topoTrailPoints,
                        onSelectContact: { contact in
                            withAnimation(.easeInOut(duration: 0.2)) {
                                selectedContact = contact
                            }
                        },
                        onSelectTrailPoint: { trailPointId in
                            withAnimation(.easeInOut(duration: 0.2)) {
                                selectTrailPoint(withId: trailPointId)
                            }
                        }
                    )
                    .frame(maxWidth: .infinity, maxHeight: .infinity)
#else
                    if selectedMapSource == .topo {
                        TopoTileMapView(
                            region: $topoRegion,
                            contacts: topoContactMarkers,
                            polylines: topoPolylines,
                            trailPoints: topoTrailPoints,
                            onSelectContact: { contact in
                                withAnimation(.easeInOut(duration: 0.2)) {
                                    selectedContact = contact
                                }
                            },
                            onSelectTrailPoint: { trailPointId in
                                withAnimation(.easeInOut(duration: 0.2)) {
                                    selectTrailPoint(withId: trailPointId)
                                }
                            }
                        )
                        .frame(maxWidth: .infinity, maxHeight: .infinity)
                    } else {
                        MapReader { mapProxy in
                            Map(position: $cameraPosition) {
                                routeMapContent()
                                neighborMapContent()
                                contactMapContent()
                            }
                            .mapStyle(mainMapStyle)
                            .environment(\.colorScheme, mapColorScheme)
                            .onMapCameraChange(frequency: .onEnd) { context in
                                mapLatitudeDelta = context.region.span.latitudeDelta
                                topoRegion = context.region
                            }
#if os(iOS)
                            .overlay(alignment: .topLeading) {
                                flashCanvasOverlay(mapProxy: mapProxy)
                            }
#endif
                            .frame(maxWidth: .infinity, maxHeight: .infinity)
                        } // MapReader
                    }
#endif
                } else {
                    Color.clear
                        .frame(maxWidth: .infinity, maxHeight: .infinity)
                }
            }

            loadingOverlay()
        }
    }

    @ToolbarContentBuilder
    private var mapToolbarContent: some ToolbarContent {
        ToolbarItemGroup(placement: .navigationBarTrailing) {
            Button {
                withAnimation(.easeInOut(duration: 0.2)) { showLegend.toggle() }
                presentTopToggleFeedback(
                    symbol: showLegend ? "list.bullet.rectangle.portrait.fill" : "list.bullet.rectangle.portrait",
                    text: showLegend ? "Legend Open" : "Legend Hidden"
                )
            } label: {
                Image(systemName: showLegend ? "list.bullet.rectangle.portrait.fill" : "list.bullet.rectangle.portrait")
                    .foregroundColor(showLegend ? .cyan : .secondary)
            }

#if os(iOS)
            Button {
                let willEnableSearch = !showSearch
                toggleSearchOverlay()
                presentTopToggleFeedback(
                    symbol: willEnableSearch ? "magnifyingglass.circle.fill" : "magnifyingglass",
                    text: willEnableSearch ? "Search Enabled" : "Search Hidden"
                )
            } label: {
                Image(systemName: showSearch ? "magnifyingglass.circle.fill" : "magnifyingglass")
                    .foregroundColor(showSearch ? .cyan : .secondary)
            }
#endif

            Button {
                withAnimation(.easeInOut(duration: 0.2)) { showRepeaterNames.toggle() }
                presentTopToggleFeedback(
                    symbol: showRepeaterNames ? "tag.fill" : "tag.slash.fill",
                    text: showRepeaterNames ? "Labels On" : "Labels Off"
                )
            } label: {
                Image(systemName: showRepeaterNames ? "tag.fill" : "tag.slash.fill")
                    .foregroundColor(showRepeaterNames ? .cyan : .secondary)
            }

#if os(tvOS)
            Button {
                cycleMapSource()
            } label: {
                Image(systemName: mapSourceSymbol)
                    .foregroundColor(mapSourceTintColor)
            }
#else
            Menu {
                ForEach(MeshMapSource.allCases, id: \.self) { source in
                    Button {
                        setMapSource(source)
                    } label: {
                        Label(source.feedbackLabel, systemImage: source.systemImage)
                    }
                }
            } label: {
                Image(systemName: mapSourceSymbol)
                    .foregroundColor(mapSourceTintColor)
            }
#endif

            Button {
                withAnimation(.easeInOut(duration: 0.2)) { showRoutes.toggle() }
                presentTopToggleFeedback(
                    symbol: "point.topleft.down.curvedto.point.bottomright.up",
                    text: showRoutes ? "Routes On" : "Routes Off"
                )
            } label: {
                Image(systemName: "point.topleft.down.curvedto.point.bottomright.up")
                    .foregroundColor(showRoutes ? .cyan : .secondary)
            }

            Button {
                withAnimation(.easeInOut(duration: 0.2)) { showNeighbors.toggle() }
                presentTopToggleFeedback(
                    symbol: "network",
                    text: showNeighbors ? "Neighbors On" : "Neighbors Off"
                )
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

    @ViewBuilder
    private func trailPointAnnotationView(_ point: TrailPointData) -> some View {
        let markerDiameter = trailDotDiameter
        let tapDiameter = trailDotTapTargetDiameter
        let strokeWidth = max(1.0, markerDiameter * 0.12)

        ZStack {
            Circle()
                .fill(Color.red)
                .frame(width: markerDiameter, height: markerDiameter)
                .overlay(
                    Circle().stroke(
                        Color.white.opacity(0.9),
                        lineWidth: strokeWidth
                    )
                )

            Circle()
                .fill(Color.white.opacity(0.001))
                .frame(width: tapDiameter, height: tapDiameter)
        }
        .contentShape(Circle())
        .onTapGesture {
            withAnimation(.easeInOut(duration: 0.2)) {
                selectTrailPoint(point)
            }
        }
    }

    @MapContentBuilder
    private func dualStrokePolyline(
        coordinates: [CLLocationCoordinate2D],
        outerColor: Color,
        outerStyle: StrokeStyle,
        innerColor: Color,
        innerStyle: StrokeStyle
    ) -> some MapContent {
        MapPolyline(coordinates: coordinates)
            .stroke(outerColor, style: outerStyle)

        MapPolyline(coordinates: coordinates)
            .stroke(innerColor, style: innerStyle)
    }

    @MapContentBuilder
    private func trailHistoryPolyline(_ poly: PolylineData) -> some MapContent {
        MapPolyline(coordinates: poly.coords)
            .stroke(
                Color(red: 0.93, green: 0.28, blue: 0.34).opacity(0.52),
                style: StrokeStyle(lineWidth: 2.0, dash: [7, 5])
            )
    }

    @MapContentBuilder
    private func routePolyline(_ poly: PolylineData) -> some MapContent {
        let outer = StrokeStyle(lineWidth: 5.0, lineCap: .round, lineJoin: .round)
        let inner = StrokeStyle(
            lineWidth: 2.6,
            lineCap: .round,
            lineJoin: .round,
            dash: [10, 7],
            dashPhase: shouldAnimateRoutePolylines ? animatedDashPhase : 0
        )

        dualStrokePolyline(
            coordinates: poly.coords,
            outerColor: Color.white.opacity(0.10),
            outerStyle: outer,
            innerColor: Color(red: 0.42, green: 0.78, blue: 0.72).opacity(0.88),
            innerStyle: inner
        )
    }

    @MapContentBuilder
    private func neighborPolyline(_ poly: PolylineData) -> some MapContent {
        let outer = StrokeStyle(lineWidth: 4.4, lineCap: .round, lineJoin: .round)
        let inner = StrokeStyle(lineWidth: 2.5, lineCap: .round, lineJoin: .round, dash: [7, 5], dashPhase: 0)

        dualStrokePolyline(
            coordinates: poly.coords,
            outerColor: Color.white.opacity(0.11),
            outerStyle: outer,
            innerColor: Color(red: 0.55, green: 0.72, blue: 0.94).opacity(0.92),
            innerStyle: inner
        )
    }

    @MapContentBuilder
    private func trailReporterPolyline(_ poly: PolylineData) -> some MapContent {
        let outer = StrokeStyle(lineWidth: 3.8, lineCap: .round, lineJoin: .round)
        let inner = StrokeStyle(lineWidth: 2.1, lineCap: .round, lineJoin: .round, dash: [5, 4], dashPhase: 0)

        dualStrokePolyline(
            coordinates: poly.coords,
            outerColor: Color.white.opacity(0.10),
            outerStyle: outer,
            innerColor: Color(red: 0.43, green: 0.68, blue: 0.94).opacity(0.86),
            innerStyle: inner
        )
    }

    @ViewBuilder
    private func contactAnnotationView(_ contact: Contact) -> some View {
        let isReporter = reporterKeySet.contains(contact.publicKey.uppercased())
        let isNew = newContactIds.contains(contact.id)
        let isHighlighted = highlightedPathContactIds.contains(contact.id)
        let hasClockWarning = clockWarningContactIds.contains(contact.id)

        NodeAnnotationView(
            contact: contact,
            isReporter: isReporter,
            isNew: isNew,
            isPathHighlighted: isHighlighted,
            hasClockWarning: hasClockWarning,
            onTap: { withAnimation { selectedContact = contact } }
        )
    }

    @MapContentBuilder
    private func contactAnnotation(_ contact: Contact) -> some MapContent {
        if let lat = contact.latitude, let lon = contact.longitude {
            let coordinate = CLLocationCoordinate2D(latitude: lat, longitude: lon)
            Annotation("", coordinate: coordinate) {
                contactAnnotationView(contact)
            }
        }
    }

    @MapContentBuilder
    private func trailPointAnnotation(_ point: TrailPointData) -> some MapContent {
        Annotation("", coordinate: point.coord) {
            trailPointAnnotationView(point)
        }
    }

    @MapContentBuilder
    private func routeMapContent() -> some MapContent {
        if showRoutes {
            ForEach(renderedPathPolylines) { poly in
                routePolyline(poly)
            }

            ForEach(mainTrailPolylines) { poly in
                trailHistoryPolyline(poly)
            }

            ForEach(renderedMainTrailPoints) { point in
                trailPointAnnotation(point)
            }

            ForEach(selectedTrailReporterPolylines) { poly in
                trailReporterPolyline(poly)
            }
        }
    }

    @MapContentBuilder
    private func neighborMapContent() -> some MapContent {
        if showNeighbors {
            ForEach(renderedNeighborPolylines) { poly in
                neighborPolyline(poly)
            }
        }
    }

    @MapContentBuilder
    private func contactMapContent() -> some MapContent {
        ForEach(displayedContacts) { contact in
            contactAnnotation(contact)
        }
    }

    @ViewBuilder
    private func highlightedMarkerOverlay(mapProxy: MapProxy) -> some View {
        ZStack(alignment: .topLeading) {
            ForEach(highlightedOverlayMarkers) { marker in
                if let lat = marker.contact.latitude,
                   let lon = marker.contact.longitude,
                   let pt = mapProxy.convert(
                        CLLocationCoordinate2D(latitude: lat, longitude: lon),
                        to: .local
                   ) {
                    HighlightedContactOverlay(
                        contact: marker.contact,
                        isReporter: marker.isReporter,
                        hasClockWarning: marker.hasClockWarning
                    )
                    .position(x: pt.x, y: max(20, pt.y))
                }
            }
        }
        .allowsHitTesting(false)
        .zIndex(1001)
    }

    @ViewBuilder
    private func flashCanvasOverlay(mapProxy: MapProxy) -> some View {
        TimelineView(.animation(minimumInterval: 1.0 / 30.0)) { timeCtx in
            Canvas { canvasCtx, _ in
                let now = timeCtx.date
                for flash in livePacketFlashes where !flash.isExpired(at: now) {
                    let opacity = flash.opacity(at: now)
                    let pts = flash.waypoints.compactMap {
                        mapProxy.convert($0, to: .local)
                    }
                    guard pts.count >= 2 else { continue }
                    var linePath = Path()
                    linePath.move(to: pts[0])
                    pts.dropFirst().forEach { linePath.addLine(to: $0) }
                    canvasCtx.stroke(
                        linePath,
                        with: .color(.white.opacity(0.45 * opacity)),
                        style: StrokeStyle(lineWidth: 13, lineCap: .round, lineJoin: .round)
                    )
                    canvasCtx.stroke(
                        linePath,
                        with: .color(Color(red: 0.15, green: 0.95, blue: 1.0).opacity(1.0 * opacity)),
                        style: StrokeStyle(lineWidth: 6.0, lineCap: .round, lineJoin: .round)
                    )
                    let dotCoord = flash.dotPosition(at: now)
                    if let dotPt = mapProxy.convert(dotCoord, to: .local) {
                        let r: CGFloat = 11
                        let rect = CGRect(x: dotPt.x - r, y: dotPt.y - r, width: r * 2, height: r * 2)
                        canvasCtx.fill(
                            Path(ellipseIn: rect),
                            with: .color(Color(red: 0.35, green: 0.92, blue: 1.0).opacity(opacity))
                        )
                        canvasCtx.stroke(
                            Path(ellipseIn: CGRect(
                                x: dotPt.x - r - 2,
                                y: dotPt.y - r - 2,
                                width: (r + 2) * 2,
                                height: (r + 2) * 2
                            )),
                            with: .color(.white.opacity(0.92 * opacity)),
                            lineWidth: 2.6
                        )
                    }
                }
            }
            .overlay(alignment: .topLeading) {
                highlightedMarkerOverlay(mapProxy: mapProxy)
            }
        }
        .allowsHitTesting(false)
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .zIndex(999)
    }

    @ViewBuilder
    private func mapScreenBaseContent() -> some View {
        mapCanvasArea()
            .overlay(alignment: .bottom) {
                selectedContactOverlay()
            }
            .overlay(alignment: .top) {
                topOverlayContent()
            }
            .background(mapBackgroundColor.ignoresSafeArea())
    }

    @ViewBuilder
    private func mapScreenNavigationContent() -> some View {
#if os(tvOS)
        mapScreenBaseContent()
#else
        mapScreenBaseContent()
            .navigationTitle("Map")
            .meshNavigationBarInline()
            .toolbar {
                mapToolbarContent
            }
#endif
    }

    @ViewBuilder
    private func mapScreenVisibilityContent() -> some View {
        mapScreenNavigationContent()
            .onAppear(perform: handleMapAppear)
            .onDisappear(perform: handleMapDisappear)
            .onChange(of: scenePhase) { _, _ in
                refreshMapActiveState()
            }
            .onChange(of: navigationState.selectedTab) { _, _ in
                refreshMapActiveState()
            }
    }

    @ViewBuilder
    private func mapScreenSelectionContent() -> some View {
        mapScreenVisibilityContent()
            .onChange(of: selectedContact?.id) { _, _ in
                handleSelectedContactChange()
            }
            .onChange(of: selectedContactTab) { _, tab in
                handleSelectedContactTabChange(tab)
            }
            .onChange(of: contacts.map { $0.id }) { _, _ in
                handleContactIdsChange()
            }
            .onChange(of: trailVisibleContactIdsRaw) { _, _ in
                handleTrailVisibleContactIdsChange()
            }
            .onChange(of: collectorFilterRaw) { _, _ in
                handleCollectorFilterChange()
            }
            .onChange(of: mapLatitudeDelta) { _, _ in
                handleMapLatitudeDeltaChange()
            }
    }

    @ViewBuilder
    private func mapScreenContent() -> some View {
        mapScreenSelectionContent()
            .onChange(of: showRoutes) { _, enabled in
                handleShowRoutesChange(enabled)
            }
            .onChange(of: showNeighbors) { _, enabled in
                handleShowNeighborsChange(enabled)
            }
            .onChange(of: neighborFocusContactId) { _, _ in
                scheduleRouteOverlayRefresh()
            }
            .onChange(of: routeVisibleContactIdsRaw) { _, _ in
                scheduleRouteOverlayRefresh()
            }
            .onChange(of: navigationState.mapFocusNonce) { _, _ in
                handleNavigationFocusChange()
            }
            .onChange(of: topoRegionChangeToken) { _, _ in
                handleTopoRegionChange(topoRegion)
            }
            // Periodic contact-only refresh (5 min): refreshes the contact list and reporter map
            // without re-fetching packet data — the live stream handles incremental updates.
            .onReceive(periodicRefreshTimer) { _ in
                handlePeriodicRefresh()
            }
    }

    var body: some View {
#if os(tvOS)
        mapScreenContent()
            .frame(maxWidth: .infinity, maxHeight: .infinity)
#else
        NavigationStack {
            mapScreenContent()
        }
#endif
    }

    // MARK: - Path Polylines

    @MainActor
    private func setMapActiveState(_ isActive: Bool) {
        mapRenderTask?.cancel()

        if isActive {
            mapRenderTask = Task {
                // Delay map mount slightly to avoid zero-sized CAMetal surface during tab transition.
                try? await Task.sleep(nanoseconds: 160_000_000)
                guard !Task.isCancelled else { return }
                await MainActor.run {
                    shouldRenderMainMap = true
                    startRouteOverlayAnimation()
                    startRouteOverlayStream()
                }
            }
        } else {
            shouldRenderMainMap = false
            routeOverlayTask?.cancel()
            livePacketFlashes.removeAll()
            highlightedPathContactIds.removeAll()
        }
    }

    private struct PolylineData: Identifiable {
        let id: String
        let coords: [CLLocationCoordinate2D]
    }

    // MARK: - Live Packet Flash Animation

    private struct LivePacketFlash: Identifiable {
        let id = UUID()
        let waypoints: [CLLocationCoordinate2D]
        let highlightedContactIds: Set<Int>
        let startedAt: Date = Date()
        let duration: TimeInterval = 3.0

        func opacity(at tick: Date) -> Double {
            let elapsed = tick.timeIntervalSince(startedAt)
            guard elapsed >= 0 else { return 0 }
            if elapsed < 0.25 { return 1.0 }
            return max(0, 1.0 - (elapsed - 0.25) / max(0.001, duration - 0.25))
        }

        func dotPosition(at tick: Date) -> CLLocationCoordinate2D {
            let elapsed = tick.timeIntervalSince(startedAt)
            let travelDuration = duration * 0.75
            let rawT = elapsed < 0 ? 0.0 : min(1.0, elapsed / travelDuration)
            // Ease in-out cubic
            let eased = rawT < 0.5 ? 2 * rawT * rawT : -1 + (4 - 2 * rawT) * rawT
            return interpolatedPosition(eased)
        }

        func isExpired(at tick: Date) -> Bool {
            tick.timeIntervalSince(startedAt) >= duration
        }

        private func interpolatedPosition(_ t: Double) -> CLLocationCoordinate2D {
            guard waypoints.count >= 2 else { return waypoints.first ?? CLLocationCoordinate2D() }
            var segLengths: [Double] = []
            for i in 1..<waypoints.count {
                let dlat = waypoints[i].latitude - waypoints[i-1].latitude
                let dlon = waypoints[i].longitude - waypoints[i-1].longitude
                segLengths.append(sqrt(dlat * dlat + dlon * dlon))
            }
            let total = segLengths.reduce(0, +)
            guard total > 0 else { return waypoints.last! }
            let target = t * total
            var dist = 0.0
            for i in 0..<segLengths.count {
                if dist + segLengths[i] >= target || i == segLengths.count - 1 {
                    let segT = segLengths[i] > 0 ? min(1, (target - dist) / segLengths[i]) : 0
                    let a = waypoints[i]
                    let b = waypoints[min(i + 1, waypoints.count - 1)]
                    return CLLocationCoordinate2D(
                        latitude: a.latitude + (b.latitude - a.latitude) * segT,
                        longitude: a.longitude + (b.longitude - a.longitude) * segT
                    )
                }
                dist += segLengths[i]
            }
            return waypoints.last!
        }
    }

    /// Build relay-path polylines from live-feed packet paths.
    /// Path strings look like "ab12->cd34->ef56" where each segment is a
    /// short (1–3 byte) hex prefix of a relay node's public key.
    private func buildPathPolylines() -> [PolylineData] {
        guard !visibleRouteContactIds.isEmpty else { return [] }

        var lines: [PolylineData] = []
        var dedupe = Set<String>()
        let selected = selectedCollectorIds
        let routeLimit = maxRenderedFocusedRoutePolylines

        let selectedRoutePublicKeys: Set<String> = Set(
            contacts
                .filter { visibleRouteContactIds.contains($0.id) }
                .map { $0.publicKey.uppercased() }
        )

        for packet in recentPackets {
            if lines.count >= routeLimit { break }

            let packetContactKey = packet.contactPublicKey?.uppercased()
            let packetMatchesSelection = {
                if let contactId = packet.contactId, visibleRouteContactIds.contains(contactId) {
                    return true
                }
                if let packetContactKey, selectedRoutePublicKeys.contains(packetContactKey) {
                    return true
                }
                return false
            }()

            if !packetMatchesSelection {
                continue
            }

            let reports: [(reporterId: Int?, path: String?)] = {
                if packet.reports.isEmpty {
                    return [(packet.reporterId, packet.path)]
                }

                let best = packet.reports.max {
                    parseDate($0.receivedAt ?? "") < parseDate($1.receivedAt ?? "")
                }
                return [(best?.reporterId ?? packet.reporterId, best?.path ?? packet.path)]
            }()

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

                if lines.count >= routeLimit {
                    break
                }
            }
        }
        return lines
    }

    private func buildNeighborPolylines() -> [PolylineData] {
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

                    if let reporter = reporterByPublicKey[contact.publicKey.uppercased()],
                       let status = reporter.reporterStatus {
                        Divider()
                        sectionTitle("Collector Status")

                        if let statusText = status.status, !statusText.isEmpty {
                            cardRow("Status", statusText.capitalized)
                        }
                        if let updatedAt = status.updatedAt, !updatedAt.isEmpty {
                            cardRow("Updated", formattedAbsoluteTime(updatedAt))
                        }
                        if let origin = status.origin, !origin.isEmpty {
                            cardRow("Origin", origin)
                        }
                        if let originId = status.originId, !originId.isEmpty {
                            cardRow("Origin ID", originId)
                        }
                        if let iata = status.iata, !iata.isEmpty {
                            cardRow("IATA", iata)
                        }
                        if let model = status.model, !model.isEmpty {
                            cardRow("Model", model)
                        }
                        if let fw = status.firmwareVersion, !fw.isEmpty {
                            cardRow("Firmware", fw)
                        }
                        if let client = status.clientVersion, !client.isEmpty {
                            cardRow("Client", client)
                        }
                        if let radio = status.radio, !radio.isEmpty {
                            cardRow("Radio", radio)
                        }
                        if let rssi = status.rssi {
                            cardRow("RSSI", "\(rssi) dBm")
                        }
                        if let snr = status.snr {
                            cardRow("SNR", String(format: "%.1f dB", snr))
                        }
                        if let battery = status.batteryMv {
                            cardRow("Battery", "\(battery) mV")
                        }
                        if let uptime = status.uptimeSecs {
                            cardRow("Uptime", formatUptime(seconds: uptime))
                        }
                        if let queue = status.queueLen {
                            cardRow("Queue", "\(queue)")
                        }
                        if let errors = status.errors {
                            cardRow("Errors", "\(errors)")
                        }
                    }

                    Divider()
                    sectionTitle("Identity")
                    cardRow("Public Key", contact.publicKey)
                    if let hash = contact.advertisementHash, !hash.isEmpty {
                        cardRow("Message Hash", hash)
                    }
                    cardRow("Hash Size", "\(contact.hashSize)")

                    if let warning = clockWarningTextByContactId[contact.id], !warning.isEmpty {
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
                            toggleDeviceRoute(contact)
                        } label: {
                            Text(visibleRouteContactIds.contains(contact.id) ? "Hide Routes" : "Show Routes")
                                .font(.system(size: 11, weight: .semibold))
                                .padding(.horizontal, 10)
                                .padding(.vertical, 6)
                                .background(Color.cyan.opacity(visibleRouteContactIds.contains(contact.id) ? 0.3 : 0.18))
                                .clipShape(Capsule())
                        }

                        Button {
                            if neighborFocusContactId == contact.id {
                                neighborFocusContactId = nil
                                showNeighbors = false
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

                    #if os(iOS)
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
                    #else
                    Button {
                        selectedTrailGpxUrl = exportTrailGpx(contact: contact, points: sortedTrailPoints)
                    } label: {
                        Label("Prepare GPX", systemImage: "square.and.arrow.up")
                            .font(.system(size: 11, weight: .semibold))
                            .foregroundColor(.cyan)
                    }
                    #endif
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
    private func mapLegendCard() -> some View {
        VStack(alignment: .leading, spacing: 8) {
            HStack {
                Text("Map Legend")
                    .font(.system(size: 12, weight: .bold, design: .monospaced))
                    .foregroundColor(detailCardTextColor)
                Spacer(minLength: 8)
                Button {
                    withAnimation(.easeInOut(duration: 0.2)) { showLegend = false }
                } label: {
                    Image(systemName: "xmark.circle.fill")
                        .foregroundColor(detailCardSecondaryColor)
                }
            }

            legendColorRow(
                color: Color(red: 0.42, green: 0.78, blue: 0.72),
                label: "Bubble color: Live (<5 min)"
            )
            legendColorRow(
                color: Color(red: 0.90, green: 0.76, blue: 0.40),
                label: "Bubble color: Recent (<1 h)"
            )
            legendColorRow(
                color: Color(red: 0.42, green: 0.42, blue: 0.52),
                label: "Bubble color: Older"
            )
            legendSignRow(
                symbol: "point.topleft.down.curvedto.point.bottomright.up",
                color: Color(red: 0.20, green: 0.90, blue: 1.0),
                label: "Cyan ring/route glow: Active packet path"
            )
            legendSignRow(
                symbol: "receipt.fill",
                color: .mint,
                label: "Small receipt badge: Collector/Reporter"
            )
            legendSignRow(
                symbol: "exclamationmark",
                color: Color(red: 0.90, green: 0.34, blue: 0.14),
                label: "Orange !: Clock sync warning"
            )

            legendLineRow(
                color: Color(red: 0.55, green: 0.72, blue: 0.94),
                label: "Blue dashed line: Radio neighbor link (focused node <-> direct relay neighbors)"
            )
            legendLineRow(
                color: Color(red: 0.93, green: 0.28, blue: 0.34),
                label: "Red dashed line: Same device GPS trail over time (movement history, not neighbor links)"
            )

            Divider()

            legendTypeRow(symbol: MeshNodeType.chat.mapSymbolName, label: MeshNodeType.chat.label)
            legendTypeRow(symbol: MeshNodeType.repeater.mapSymbolName, label: MeshNodeType.repeater.label)
            legendTypeRow(symbol: MeshNodeType.roomServer.mapSymbolName, label: MeshNodeType.roomServer.label)
            legendTypeRow(symbol: MeshNodeType.sensor.mapSymbolName, label: MeshNodeType.sensor.label)
        }
        .padding(10)
        .frame(maxWidth: 340, alignment: .leading)
        .background(detailCardBackground.opacity(isDarkMapTheme ? 0.96 : 0.98))
        .overlay(
            RoundedRectangle(cornerRadius: 12, style: .continuous)
                .stroke(detailCardBorderColor, lineWidth: 1)
        )
        .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
        .shadow(color: Color.black.opacity(isDarkMapTheme ? 0.45 : 0.15), radius: 10, y: 2)
    }

    @ViewBuilder
    private func legendColorRow(color: Color, label: String) -> some View {
        HStack(spacing: 8) {
            Circle()
                .fill(color)
                .frame(width: 12, height: 12)
                .overlay(Circle().stroke(Color.white.opacity(0.6), lineWidth: 0.8))
            Text(label)
                .font(.system(size: 11, design: .monospaced))
                .foregroundColor(detailCardTextColor)
            Spacer(minLength: 0)
        }
    }

    @ViewBuilder
    private func legendSignRow(symbol: String, color: Color, label: String) -> some View {
        HStack(spacing: 8) {
            Image(systemName: symbol)
                .font(.system(size: 10, weight: .bold))
                .foregroundColor(.white)
                .frame(width: 16, height: 16)
                .background(Circle().fill(color))
            Text(label)
                .font(.system(size: 11, design: .monospaced))
                .foregroundColor(detailCardTextColor)
            Spacer(minLength: 0)
        }
    }

    @ViewBuilder
    private func legendLineRow(color: Color, label: String) -> some View {
        HStack(spacing: 8) {
            RoundedRectangle(cornerRadius: 2, style: .continuous)
                .fill(color)
                .frame(width: 16, height: 3)
            Text(label)
                .font(.system(size: 11, design: .monospaced))
                .foregroundColor(detailCardTextColor)
            Spacer(minLength: 0)
        }
    }

    @ViewBuilder
    private func legendTypeRow(symbol: String, label: String) -> some View {
        HStack(spacing: 8) {
            Image(systemName: symbol)
                .font(.system(size: 11, weight: .semibold))
                .foregroundColor(.white)
                .frame(width: 18, height: 18)
                .background(Circle().fill(Color(red: 0.42, green: 0.78, blue: 0.72)))
            Text("Type icon: \(label)")
                .font(.system(size: 11, design: .monospaced))
                .foregroundColor(detailCardTextColor)
            Spacer(minLength: 0)
        }
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

    private func formatUptime(seconds: Int) -> String {
        let total = max(0, seconds)
        let days = total / 86_400
        let hours = (total % 86_400) / 3_600
        let minutes = (total % 3_600) / 60

        if days > 0 {
            return "\(days)d \(hours)h"
        }
        if hours > 0 {
            return "\(hours)h \(minutes)m"
        }
        return "\(minutes)m"
    }

    // MARK: - Logic

    private func loadAll(fitCamera: Bool) async {
        await MainActor.run {
            if contacts.isEmpty { isLoading = true }
        }

        do {
            async let contactsFetch = apiClient.fetchContacts()
            // ADV only for initial static route overlay; stream handles incremental updates.
            async let feedFetch = apiClient.fetchLiveFeed(sinceMs: 0, types: ["ADV"], limit: 50)
            async let reportersFetch = apiClient.fetchReporters()
            let (cr, fr, rr) = try await (contactsFetch, feedFetch, reportersFetch)

            await MainActor.run {
                isLoading = false
                reporters = rr
                routeOverlaySinceMs = max(routeOverlaySinceMs, fr.timestampMs)

                // Detect newly arrived contacts (not seen in previous refresh)
                let incoming = Set(cr.contacts.map { $0.id })
                let nowNew: Set<Int> = knownContactIds.isEmpty
                    ? []
                    : incoming.subtracting(knownContactIds)
                knownContactIds = incoming

                withAnimation(.easeInOut(duration: 0.35)) {
                    contacts = cr.contacts
                    recentPackets = mergePackets(fr.packets, into: recentPackets)
                }
                rebuildClockWarningCaches(for: cr.contacts, reporters: rr)
                scheduleTrailPointRefresh()
                scheduleRouteOverlayRefresh()
                rebuildHighlightedOverlayMarkers()

                if !nowNew.isEmpty {
                    newContactIds = nowNew
                    Task { @MainActor in
                        try? await Task.sleep(nanoseconds: 6_000_000_000)
                        withAnimation { newContactIds.removeAll() }
                    }
                }

                // Auto-fit camera on first load only
                if fitCamera && !hasFitCamera {
                    hasFitCamera = true
                    let located = visibleContacts.filter { $0.latitude != nil && $0.longitude != nil }
                    if !located.isEmpty {
                        let lats = located.compactMap { $0.latitude }
                        let lons = located.compactMap { $0.longitude }
                        let avgLat = lats.reduce(0, +) / Double(lats.count)
                        let avgLon = lons.reduce(0, +) / Double(lons.count)
                        setCameraRegion(MKCoordinateRegion(
                            center: .init(latitude: avgLat, longitude: avgLon),
                            span: .init(latitudeDelta: 5, longitudeDelta: 5)
                        ))
                    }
                }
            }
        } catch {
            print("Map load error: \(error)")
            await MainActor.run { isLoading = false }
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
                    // Stream ADV, MSG, PUB: ADV packets feed static route overlays;
                    // all types trigger live path flash animations (matching WebUI behavior).
                    let stream = apiClient.streamLiveFeed(
                        sinceMs: routeOverlaySinceMs,
                        types: ["ADV", "MSG", "PUB"],
                        limit: 30
                    )

                    for try await response in stream {
                        if Task.isCancelled { return }
                        await MainActor.run {
                            // Only ADV packets update static route polylines (need GPS source coord).
                            let advPackets = response.packets.filter { $0.type == "ADV" }
                            if !advPackets.isEmpty {
                                recentPackets = mergePackets(advPackets, into: recentPackets)
                            }
                            routeOverlaySinceMs = max(routeOverlaySinceMs, response.timestampMs)
                            scheduleRouteOverlayRefresh()
                            // Flash-animate every incoming packet that has a resolvable route chain.
                            for packet in response.packets {
                                triggerPacketFlash(for: packet)
                            }
                        }
                    }
                } catch {
                    if Task.isCancelled { return }

                    do {
                        let response = try await apiClient.fetchLiveFeed(
                            sinceMs: routeOverlaySinceMs,
                            types: ["ADV", "MSG", "PUB"],
                            limit: 30
                        )
                        await MainActor.run {
                            let advPackets = response.packets.filter { $0.type == "ADV" }
                            if !advPackets.isEmpty {
                                recentPackets = mergePackets(advPackets, into: recentPackets)
                            }
                            routeOverlaySinceMs = max(routeOverlaySinceMs, response.timestampMs)
                            scheduleRouteOverlayRefresh()
                        }
                    } catch {
                        if Task.isCancelled { return }
                    }

                    try? await Task.sleep(nanoseconds: 3_000_000_000)
                }
            }
        }
    }

    @MainActor
    private func triggerPacketFlash(for packet: Packet) {
        // Use the most-recent report's path for the most accurate relay chain.
        let report = packet.reports.max { parseDate($0.receivedAt ?? "") < parseDate($1.receivedAt ?? "") }
        let reporterId = report?.reporterId ?? packet.reporterId
        let path = report?.path ?? packet.path
        let chain = buildRouteChain(for: packet, reporterId: reporterId, path: path)
        var coords = coordinates(for: chain)
        var highlightedIds = Set(chain.map { $0.id })

        // Fallback: if contact lookup produced < 2 points, use the packet's own
        // GPS coordinates (ADV packets embed the sender's lat/lon).
        if coords.count < 2, let srcLat = packet.latitude, let srcLon = packet.longitude {
            let srcCoord = CLLocationCoordinate2D(latitude: srcLat, longitude: srcLon)
            if let destContact = reporterId.flatMap({ reporterIdToContact[$0] }),
               let dLat = destContact.latitude, let dLon = destContact.longitude {
                coords = [srcCoord, CLLocationCoordinate2D(latitude: dLat, longitude: dLon)]
                highlightedIds.insert(destContact.id)
            } else if coords.count == 1 {
                coords = [srcCoord, coords[0]]
            }
        }
        guard coords.count >= 2 else { return }
        // Prune expired flashes and cap concurrent count.
        let now = Date()
        livePacketFlashes.removeAll { $0.isExpired(at: now) }
        if livePacketFlashes.count >= 5 { livePacketFlashes.removeFirst() }
        livePacketFlashes.append(LivePacketFlash(waypoints: coords, highlightedContactIds: highlightedIds))
        refreshHighlightedPathContacts(at: now)
        // Schedule deferred cleanup so stale entries don't linger if no new packets arrive.
        Task { @MainActor in
            try? await Task.sleep(nanoseconds: 4_000_000_000) // > flash duration (3 s)
            let pruneTime = Date()
            livePacketFlashes.removeAll { $0.isExpired(at: pruneTime) }
            refreshHighlightedPathContacts(at: pruneTime)
        }
    }

    @MainActor
    private func refreshHighlightedPathContacts(at timestamp: Date = Date()) {
        highlightedPathContactIds = Set(
            livePacketFlashes
                .filter { !$0.isExpired(at: timestamp) }
                .flatMap { $0.highlightedContactIds }
        )
        rebuildHighlightedOverlayMarkers()
    }

    private func loadConfig() async {
        do {
            let appConfig = try await apiClient.fetchConfiguration()
            await MainActor.run { config = appConfig }
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
            setCameraRegion(MKCoordinateRegion(
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
            trailPacketsByContactId.removeValue(forKey: contact.id)
            setVisibleTrailContactIds(ids)
            scheduleTrailPointRefresh()
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
    private func setVisibleRouteContactIds(_ ids: Set<Int>) {
        routeVisibleContactIdsRaw = ids.sorted().map(String.init).joined(separator: ",")
    }

    @MainActor
    private func toggleDeviceRoute(_ contact: Contact) {
        var ids = visibleRouteContactIds
        if ids.contains(contact.id) {
            ids.remove(contact.id)
        } else {
            ids.insert(contact.id)
            showRoutes = true
        }
        setVisibleRouteContactIds(ids)
        scheduleRouteOverlayRefresh()
    }

    @MainActor
    private func syncVisibleRouteIdsWithCurrentContacts() {
        let currentIds = Set(contacts.map { $0.id })
        let valid = visibleRouteContactIds.intersection(currentIds)
        if valid != visibleRouteContactIds {
            setVisibleRouteContactIds(valid)
        }
        scheduleRouteOverlayRefresh()
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

        scheduleTrailPointRefresh()
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
                scheduleTrailPointRefresh()
            }
        } catch {
            await MainActor.run {
                _ = trailLoadingContactIds.remove(contactId)
            }
            print("Trail load error for contact \(contactId): \(error)")
        }
    }

    @MainActor
    private func scheduleTrailPointRefresh() {
        trailRenderRefreshTask?.cancel()
        trailRenderRefreshTask = Task {
            try? await Task.sleep(nanoseconds: 120_000_000)
            guard !Task.isCancelled else { return }
            refreshRenderedTrailPoints()
        }
    }

    @MainActor
    private func scheduleRouteOverlayRefresh() {
        routeRenderRefreshTask?.cancel()
        routeRenderRefreshTask = Task {
            try? await Task.sleep(nanoseconds: 90_000_000)
            guard !Task.isCancelled else { return }
            refreshRenderedRouteOverlays()
        }
    }

    @MainActor
    private func refreshRenderedRouteOverlays() {
        renderedPathPolylines = buildPathPolylines()
        renderedNeighborPolylines = buildNeighborPolylines()
    }

    @MainActor
    private func refreshRenderedTrailPoints() {
        let visibleIds = Set(visibleContacts.map { $0.id })
        let contactNames = Dictionary(uniqueKeysWithValues: contacts.map { ($0.id, $0.name) })

        let trailSources: [(Int, [Packet])] = trailPacketsByContactId
            .filter { visibleTrailContactIds.contains($0.key) && visibleIds.contains($0.key) }
            .sorted { $0.key < $1.key }

        var allPoints: [TrailPointData] = []
        var latestPerContact: [TrailPointData] = []

        for (contactId, packets) in trailSources {
            let contactName = contactNames[contactId] ?? "Device \(contactId)"

            let pointsForContact: [TrailPointData] = packets.compactMap { packet in
                guard let lat = packet.latitude, let lon = packet.longitude else { return nil }
                return TrailPointData(
                    id: "tp-\(contactId)-\(packet.id)",
                    contactId: contactId,
                    contactName: contactName,
                    packet: packet,
                    coord: CLLocationCoordinate2D(latitude: lat, longitude: lon)
                )
            }

            allPoints.append(contentsOf: pointsForContact)
            if let latest = pointsForContact.last {
                latestPerContact.append(latest)
            }
        }

        guard !allPoints.isEmpty else {
            renderedMainTrailPoints = []
            return
        }

        let stride = trailPointSamplingStride(totalCount: allPoints.count)
        var sampled: [TrailPointData] = []
        sampled.reserveCapacity(min(maxRenderedTrailDots, allPoints.count))

        if stride <= 1 {
            sampled = Array(allPoints.prefix(maxRenderedTrailDots))
        } else {
            for (index, point) in allPoints.enumerated() {
                if index % stride == 0 {
                    sampled.append(point)
                }
                if sampled.count >= maxRenderedTrailDots {
                    break
                }
            }
        }

        var seenIds = Set(sampled.map { $0.id })
        for latest in latestPerContact where sampled.count < maxRenderedTrailDots {
            if seenIds.insert(latest.id).inserted {
                sampled.append(latest)
            }
        }

        renderedMainTrailPoints = sampled
    }

    private func trailPointSamplingStride(totalCount: Int) -> Int {
        let zoomStride: Int
        switch mapLatitudeDelta {
        case ..<1.5:
            zoomStride = 1
        case ..<4.0:
            zoomStride = 2
        case ..<10.0:
            zoomStride = 4
        case ..<25.0:
            zoomStride = 8
        default:
            zoomStride = 12
        }

        let pressureStride = max(1, Int(ceil(Double(totalCount) / Double(maxRenderedTrailDots))))
        return max(zoomStride, pressureStride)
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

        // Cap at maxStoredRecentPackets — map polylines only need recent ADV packets; larger buffers increase memory.
        return Array(merged.prefix(maxStoredRecentPackets))
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

    @MainActor
    private func selectTrailPoint(withId id: String) {
        guard let point = renderedMainTrailPoints.first(where: { $0.id == id }) else { return }
        selectTrailPoint(point)
    }

    @MainActor
    private func setCameraRegion(_ region: MKCoordinateRegion) {
        topoRegion = region
        cameraPosition = .region(region)
    }

    // MARK: - Device Search Overlay (mirrors WebUI top-right search control)

    @MainActor
    private func toggleSearchOverlay() {
        showSearch ? closeSearchOverlay(clearText: true) : openSearchOverlay()
    }

    @MainActor
    private func openSearchOverlay() {
        searchOverlayTask?.cancel()
        withAnimation(.easeInOut(duration: 0.2)) {
            showSearch = true
        }

        searchOverlayTask = Task {
            try? await Task.sleep(nanoseconds: 120_000_000)
            guard !Task.isCancelled else { return }
            await MainActor.run {
                if showSearch {
                    isSearchFieldFocused = true
                }
            }
        }
    }

    @MainActor
    private func closeSearchOverlay(clearText: Bool) {
        searchOverlayTask?.cancel()
        isSearchFieldFocused = false

        searchOverlayTask = Task {
            try? await Task.sleep(nanoseconds: 120_000_000)
            guard !Task.isCancelled else { return }
            await MainActor.run {
                withAnimation(.easeInOut(duration: 0.2)) {
                    showSearch = false
                }
                if clearText {
                    searchText = ""
                }
            }
        }
    }

    @ViewBuilder
    private func searchOverlay() -> some View {
        VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 8) {
                Image(systemName: "magnifyingglass")
                    .foregroundColor(.secondary)
                    .font(.system(size: 13))
                TextField(
                    "",
                    text: $searchText,
                    prompt: Text("Search devices...").foregroundColor(Color.white.opacity(0.65))
                )
                    .font(.system(size: 13))
                    .autocorrectionDisabled()
                    .textInputAutocapitalization(.never)
                    .focused($isSearchFieldFocused)
                    .submitLabel(.search)
                    .meshTextInputStyle()
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
                            closeSearchOverlay(clearText: true)
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
            setCameraRegion(MKCoordinateRegion(
                center: CLLocationCoordinate2D(latitude: lat, longitude: lon),
                span: MKCoordinateSpan(latitudeDelta: 0.05, longitudeDelta: 0.05)
            ))
        }
    }

    // MARK: - Trail Point Inspector

    @ViewBuilder
    private func topToggleFeedbackBanner(_ feedback: TopToggleFeedback) -> some View {
        HStack(spacing: 7) {
            Image(systemName: feedback.symbol)
                .font(.system(size: 11, weight: .semibold))
                .foregroundColor(.cyan)
            Text(feedback.text)
                .font(.system(size: 11, weight: .semibold, design: .monospaced))
                .foregroundColor(.white)
        }
        .padding(.horizontal, 10)
        .padding(.vertical, 7)
        .background(Color.black.opacity(0.72))
        .overlay(
            Capsule().stroke(Color.white.opacity(0.16), lineWidth: 1)
        )
        .clipShape(Capsule())
        .shadow(color: Color.black.opacity(0.30), radius: 3)
    }

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

    @MainActor
    private func presentTopToggleFeedback(symbol: String, text: String) {
        topToggleFeedbackTask?.cancel()

        withAnimation(.easeInOut(duration: 0.18)) {
            topToggleFeedback = TopToggleFeedback(symbol: symbol, text: text)
        }

        topToggleFeedbackTask = Task {
            try? await Task.sleep(nanoseconds: 1_250_000_000)
            guard !Task.isCancelled else { return }
            await MainActor.run {
                withAnimation(.easeInOut(duration: 0.20)) {
                    topToggleFeedback = nil
                }
            }
        }
    }

    private func parseDate(_ value: String) -> Date {
        lastHeardFormatter.date(from: value) ?? .distantPast
    }

    private func formattedAbsoluteTime(_ value: String) -> String {
        value.isEmpty ? "-" : value
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
            setCameraRegion(MKCoordinateRegion(
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
