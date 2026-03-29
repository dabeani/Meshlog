# iOS App - Quick Start Cheat Sheet

## 🚀 In 3 Steps

### 1. Open Xcode Project
```bash
cd /Users/bernhard/BMKArduino/MC/Meshlog
open -a Xcode ios-app/MeshLog.xcodeproj
```

### 2. Select iOS 17+ Simulator/Device
- Xcode → Product → Scheme → Select Device
- Or use top-left device picker

### 3. Build & Run
```bash
Cmd+B  # Build
Cmd+R  # Run
```

---

## 📋 What's Included

| Component | Location | Status |
|-----------|----------|--------|
| App Entry Point | `ios-app/MeshLogApp.swift` | ✅ Ready |
| Models | `ios-app/Models/PacketModels.swift` | ✅ 15+ models |
| Auth Manager | `ios-app/Managers/AuthenticationManager.swift` | ✅ Complete |
| API Client | `ios-app/Managers/APIClient.swift` | ✅ All endpoints |
| Live Feed | `ios-app/Views/LiveFeedView.swift` | ✅ Auto-refresh |
| Map View | `ios-app/Views/MapView.swift` | ✅ MapKit ready |
| Devices | `ios-app/Views/DevicesView.swift` | ✅ Searchable |
| Statistics | `ios-app/Views/StatsView.swift` | ✅ Dashboard |
| Settings | `ios-app/Views/SettingsView.swift` | ✅ Admin panel |
| Backend API Extensions | `api/v1/auth/`, `api/v1/live/`, `api/v1/config/` | ✅ 3 endpoints |
| Documentation | `iOS-APP-GUIDE.md` | ✅ Complete |

---

## 🎯 Features

### Live Feed ✅
- Real-time packet stream
- 3-second auto-refresh
- Packet type filtering (ADV, MSG, PUB, RAW)
- Shows sender, receiver, SNR, path, location
- Pull-to-refresh support

### Map 🗺️
- Interactive MapKit integration
- Device location markers
- Tap to view device details
- Auto-center to device cluster
- Icon shows node type

### Devices 📱
- Searchable device list
- Sorted by last heard time
- Per-device statistics
- Recent advertisement history
- Location & node type data

### Statistics 📊
- Time window selector (1h, 6h, 24h, 36h)
- Stat cards with key metrics
- Detailed breakdown
- Per-device statistics

### Admin ⚙️
- Channel (PSK) management
- Reporter authorization status
- Channel enable/disable toggle
- Server configuration viewing

### Authentication 🔐
- Secure login with username/password
- Token-based session management
- Auto-persist credentials
- One-tap logout

---

## 📱 Requirements

### Minimum
- **iOS**: 17.0+
- **Device**: iPhone or iPad
- **Storage**: ~50 MB

### Network
- Connection to MeshLog server
- HTTP or HTTPS supported

---

## ⚙️ Configuration

### On First Launch
1. Enter Server URL: `http://192.168.1.100:8080`
2. Enter admin username
3. Enter admin password
4. Tap "Login"

### Change Server
- Settings → General → Edit URL

---

## 🔗 API Endpoints (New)

**Authentication**
```
POST /api/v1/auth/
Body: { "username": "...", "password": "..." }
Response: { "token": "...", "user": {...}, "expires_in": 604800 }
```

**Live Feed**
```
GET /api/v1/live?since_ms=0&types=ADV,MSG,PUB&limit=50
Response: { "packets": [...], "timestamp_ms": 1234567890, "count": 45 }
```

**App Config**
```
GET /api/v1/config
Response: { "features": {...}, "map": {...}, "polling_interval_seconds": 5 }
```

---

## 🛠️ Customization

### Change Polling Interval
Edit `ios-app/Views/LiveFeedView.swift`:
```swift
let pollingInterval: TimeInterval = 3.0  // seconds
```
- `1.0` = Fast (more updates, high CPU)
- `3.0` = Medium (balanced)
- `10.0` = Slow (battery-friendly)

### Change Default Map Location
Edit your `config.php`:
```php
'map' => array(
    'lat' => 40.7128,   // Your latitude
    'lon' => -74.0060,  // Your longitude
    'zoom' => 12,       // Zoom level
),
```

### Add New Tab
Edit `ios-app/MeshLogApp.swift`:
```swift
TabView {
    // ... existing tabs ...
    MyNewView()
        .tabItem {
            Label("Custom", systemImage: "star.fill")
        }
}
```

---

## 🧪 Testing

### Test Live Feed
1. Launch app
2. Login with admin credentials
3. Go to Live tab
4. Should update every 3 seconds
5. Toggle filters (ADV, MSG, etc.)

### Test Map
1. Open Map tab
2. Should show devices with location data
3. Tap a marker to see details

### Test Devices
1. Open Devices tab
2. Search by name or public key
3. Tap device to see statistics
4. Should show recent advertisements

### Test Admin
1. Open Settings tab
2. Switch to Admin tab
3. Should see Channels section
4. Should see Reporters section

---

## 🐛 Common Issues

### App Won't Open in Xcode
```bash
xcode-select --install  # Install Command Line Tools
```

### "No such file or directory" Error
```bash
cd /path/to/Meshlog  # Correct your path
open -a Xcode ios-app
```

### Build Fails with Swift Errors
- ✅ Xcode → Product → Clean Build Folder (Cmd+Shift+K)
- ✅ Cmd+B to rebuild
- ✅ Check Internet connection
- ✅ Update Xcode if needed

### Login Fails
- ✅ Check server URL format (include http://)
- ✅ Verify MeshLog server is running
- ✅ Check admin username/password
- ✅ Check network connectivity

### No Devices Showing
- ✅ Check if contacts table has data
- ✅ Verify API responses have contacts
- ✅ Check device list in web UI first

### Map Shows No Markers
- ✅ Devices need latitude/longitude set
- ✅ Check device data in web UI
- ✅ Zoom in/out to see markers

---

## 📚 Documentation

**Full Guide**: `iOS-APP-GUIDE.md` in project root  
**API Reference**: See guide section "API Endpoints Reference"  
**Code Structure**: See guide section "Project Structure"  
**Troubleshooting**: See guide section "Troubleshooting"

---

## 🎉 You're Ready!

```bash
# Navigate to project
cd /Users/bernhard/BMKArduino/MC/Meshlog

# Open in Xcode
open -a Xcode ios-app

# Select iOS 17+ simulator
# Press Cmd+R to run

# Enter your MeshLog server URL and credentials
# Enjoy your native iOS app! 🚀
```

---

**Questions?** Check `iOS-APP-GUIDE.md` or reach out!
