# MeshLog iOS App

A native SwiftUI application for viewing and managing MeshCore mesh radio networks.

## Features

✨ **Live Feed** - Real-time packet stream with auto-refresh
🗺️ **Interactive Map** - Visualize device locations
📱 **Device List** - Browse all network devices with detailed information
📊 **Statistics** - View network-wide packet statistics
⚙️ **Admin Panel** - Manage channels, reporters, and settings
🔐 **Secure Authentication** - Login with admin credentials

## Requirements

- iOS 17.0+
- Swift 5.9+
- Xcode 15.0+

## Installation

### 1. Clone the Repository

```bash
cd ios-app
```

### 2. Open in Xcode

```bash
open MeshLog.xcodeproj
```

### 3. Build & Run

- Select a simulator or device
- Press `Cmd + R` to build and run

## Configuration

### Server Connection

When launching the app:
1. Enter the MeshLog server URL (e.g., `http://192.168.1.100:8080`)
2. Provide admin username and password
3. Tap "Login"

The app will store your authentication token and connection details.

## Architecture

### Project Structure

```
ios-app/
├── MeshLogApp.swift              # App entry point
├── Models/
│   └── PacketModels.swift        # Data models (Packet, Contact, etc.)
├── Managers/
│   ├── AuthenticationManager.swift
│   └── APIClient.swift
└── Views/
    ├── LoginView.swift
    ├── LiveFeedView.swift
    ├── MapView.swift
    ├── DevicesView.swift
    ├── StatsView.swift
    └── SettingsView.swift
```

### ViewModels & Managers

- **AuthenticationManager** - Handles login/logout and token management
- **APIClient** - Centralized API communication with async/await
- **Models** - Codable structs matching PHP API responses

## API Endpoints Used

### Authentication
- `POST /api/v1/auth/` - Login

### Data Fetching
- `GET /api/v1/live` - Live feed (polling)
- `GET /api/v1/contacts` - Device list
- `GET /api/v1/advertisements` - Advertisements
- `GET /api/v1/contact_advertisements` - Device-specific advertisements
- `GET /api/v1/stats` - General statistics
- `GET /api/v1/contact_stats` - Device statistics
- `GET /api/v1/reporters` - Reporter list
- `GET /api/v1/channels` - Channel list
- `GET /api/v1/config` - App configuration

## Features in Detail

### Live Feed
- Auto-refreshes every 3 seconds
- Filter by packet type (ADV, MSG, PUB, RAW)
- Shows sender, receiver, SNR, and location
- Pull-to-refresh support

### Map View
- Interactive map with device markers
- Zoom to devices with location data
- Tap markers to view device info
- Shows device type icon

### Devices
- Searchable device list
- Sort by last heard time
- Detailed view with statistics
- Shows recent advertisements
- Location data when available

### Statistics
- Configurable time window (1h, 6h, 24h, 36h)
- Network-wide statistics dashboard
- Per-device statistics
- Multiple stat types (advertisements, messages, packets, etc.)

### Admin Panel
- Manage channels and PSKs
- View reporter status
- Toggle channel enabled state
- Monitor reporter authorization

## Development

### Adding New Features

1. **New API Endpoint**
   - Add method to `APIClient.swift`
   - Create Codable model in `Models/PacketModels.swift`

2. **New View**
   - Create new SwiftUI view file
   - Add to tab bar in `MeshLogApp.swift`

3. **Data Refresh**
   - Use `@State` for local state
   - Use `.onAppear` for initial load
   - Use `.refreshable` for pull-to-refresh

### Async/Await Pattern

```swift
@State private var data: [Item] = []

private func loadData() async {
    do {
        let response = try await apiClient.fetchData()
        DispatchQueue.main.async {
            self.data = response
        }
    } catch {
        print("Error: \(error)")
    }
}

.onAppear {
    Task {
        await loadData()
    }
}
```

## Troubleshooting

### "Invalid URL" Error
- Ensure server URL is correct and includes protocol (http:// or https://)
- Check that MeshLog server is running and accessible
- Verify network connectivity

### "Invalid credentials" Error
- Double-check username and password
- Ensure user account exists on MeshLog server
- Check user has admin permissions

### Data Not Loading
- Verify server is running
- Check internet connectivity
- Ensure all API endpoints are available
- Check server logs for errors

### Map Not Showing
- Ensure devices have location data
- Grant location permission if requested
- Verify map service is available in your region

## Performance Tips

- The live feed updates every 3 seconds by default (configurable)
- Large device lists are paginated to 100 items
- Statistics can be heavy; adjust time window if needed
- Map rendering is optimized for up to 500 devices

## Security

- Authentication tokens are stored securely in Keychain (UserDefaults with secure flag)
- Avoid using the app on untrusted networks
- Consider using HTTPS for production deployments
- Admin passwords should be strong

## Building for Release

```bash
# Archive for App Store
Product → Archive

# Export for distribution
App Store Connect / Ad Hoc / Enterprise
```

## License

Same as MeshLog server

## Support

For issues, questions, or feature requests, refer to the main MeshLog repository.
