#!/bin/bash
# iOS App Development Setup Script

echo "🚀 MeshLog iOS App - Development Setup"
echo "======================================"

# Check for Xcode
if ! command -v xcode-select &> /dev/null; then
    echo "❌ Xcode Command Line Tools not found"
    echo "   Running: xcode-select --install"
    xcode-select --install
else
    echo "✅ Xcode Command Line Tools installed"
fi

# Navigate to project
echo ""
echo "📂 Setting up project directory..."
cd "$(dirname "$0")" || exit

# Check Swift
if command -v swift &> /dev/null; then
    echo "✅ Swift installed: $(swift --version)"
else
    echo "❌ Swift not found"
fi

# List all Swift files
echo ""
echo "📝 Swift Files:"
find . -name "*.swift" -type f | sort

# Build instructions
echo ""
echo "🔨 Build Instructions:"
echo "====================="
echo ""
echo "To build the iOS app:"
echo "1. Open Xcode:"
echo "   open -a Xcode ."
echo ""
echo "2. Or from terminal:"
echo "   xcodebuild build -project MeshLog.xcodeproj -scheme MeshLog"
echo ""
echo "3. To run on simulator:"
echo "   xcodebuild test -project MeshLog.xcodeproj -scheme MeshLog"
echo ""
echo "4. Keyboard shortcuts in Xcode:"
echo "   Cmd+B  = Build"
echo "   Cmd+R  = Run"
echo "   Cmd+U  = Test"
echo "   Cmd+K  = Clean Build Folder"
echo ""

# File structure
echo "📁 Project Structure:"
echo "==================="
tree -L 2 -I '__pycache__|*.pyc' . 2>/dev/null || find . -type f -name "*.swift" | head -20

echo ""
echo "✅ Setup complete!"
