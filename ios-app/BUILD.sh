#!/bin/bash

# MeshLog iOS App - Build Instructions

echo "Building MeshLog iOS App..."

# 1. Open project
echo "Opening project in Xcode..."
open -a Xcode "ios-app/MeshLog.xcodeproj"

echo ""
echo "=== Build Instructions ==="
echo "1. Select an iOS 17+ simulator or device"
echo "2. Press Cmd+B to build"
echo "3. Press Cmd+R to run"
echo ""
echo "Or use xcodebuild from command line:"
echo "  xcodebuild build -project ios-app/MeshLog.xcodeproj -scheme MeshLog"
echo "  xcodebuild test  -project ios-app/MeshLog.xcodeproj -scheme MeshLog"
