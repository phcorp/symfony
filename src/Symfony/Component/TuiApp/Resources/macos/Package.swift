// swift-tools-version:6.0
import PackageDescription

let package = Package(
    name: "TuiAppHost",
    platforms: [.macOS(.v13)],
    products: [
        .executable(name: "TuiAppHost", targets: ["TuiAppHost"]),
    ],
    targets: [
        // No AppKit: everything here is tested by `swift test` against contract/fixtures.
        .target(name: "TuiAppHostCore"),
        .executableTarget(name: "TuiAppHost", dependencies: ["TuiAppHostCore"]),
        .testTarget(name: "TuiAppHostCoreTests", dependencies: ["TuiAppHostCore"]),
    ],
    swiftLanguageModes: [.v6]
)
