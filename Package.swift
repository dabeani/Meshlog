// Package.swift for Swift Package Manager support (optional)
// This allows using the iOS app code as a package in other projects

import PackageDescription

let package = Package(
    name: "MeshLogiOSApp",
    platforms: [
        .iOS(.v17)
    ],
    products: [
        .library(
            name: "MeshLogApp",
            targets: ["MeshLogApp"]
        ),
    ],
    dependencies: [],
    targets: [
        .target(
            name: "MeshLogApp",
            path: "ios-app"
        ),
    ]
)
