import Foundation
import XCTest
@testable import TuiAppHostCore

final class LaunchTests: XCTestCase {
    private func manifest(mode: String = "embedded", php: Manifest.PHP? = nil) throws -> Manifest {
        var manifest = try Manifest.parse(Fixtures.data("contract/fixtures/manifest.json"))
        manifest.launch.mode = mode
        manifest.launch.php = php
        if mode == "system" {
            manifest.launch.command = nil
            manifest.launch.args = ["./app/app.php", "--flag"]
        }
        return manifest
    }

    func testTheEnvironmentKeepsOnlyTheAllowlistAndPass() {
        let inherited = ["PATH": "/bin", "HOME": "/h", "LC_ALL": "fr", "XDG_X": "1", "SECRET": "s", "LANG": "en", "DYLD_INSERT_LIBRARIES": "x", "KEEP": "k", "APP_ENV": "dev"]
        let env = EnvironmentFilter.filter(inherited, pass: ["KEEP"], set: ["APP_ENV": "prod"])
        XCTAssertEqual(env, ["PATH": "/bin", "HOME": "/h", "LC_ALL": "fr", "XDG_X": "1", "LANG": "en", "KEEP": "k", "APP_ENV": "prod"])
    }

    func testAnEmbeddedLaunchResolvesAgainstTheManifestDirectory() throws {
        let plan = try LaunchPlan.make(manifest: manifest(), manifestDirectory: "/A.app/Contents/Resources", inherited: ["PATH": "/bin", "SECRET": "x"], address: "tcp://127.0.0.1:5", token: "t")
        XCTAssertEqual(plan.executable, "/A.app/Contents/Resources/runtime/php")
        XCTAssertEqual(plan.arguments, ["-n", "-c", "/A.app/Contents/Resources/runtime/php.ini", "/A.app/Contents/Resources/app/app.phar"])
        XCTAssertEqual(plan.environment, ["PATH": "/bin", "APP_ENV": "prod", "TUI_APP_ADDR": "tcp://127.0.0.1:5", "TUI_APP_TOKEN": "t"])
        XCTAssertEqual(plan.workingDirectory, "/A.app/Contents/Resources")
    }

    func testTheManifestCannotOverrideTheConnection() throws {
        var m = try manifest()
        m.launch.env?.set = ["TUI_APP_TOKEN": "forged"]
        let plan = try LaunchPlan.make(manifest: m, manifestDirectory: "/r", inherited: [:], address: "a", token: "real")
        XCTAssertEqual(plan.environment["TUI_APP_TOKEN"], "real")
    }

    func testASystemLaunchRunsTheChosenPHP() throws {
        let plan = try LaunchPlan.make(manifest: manifest(mode: "system"), manifestDirectory: "/r", inherited: [:], address: "a", token: "t", php: "/opt/homebrew/bin/php")
        XCTAssertEqual(plan.executable, "/opt/homebrew/bin/php")
        XCTAssertEqual(plan.arguments, ["/r/app/app.php", "--flag"])
        XCTAssertThrowsError(try LaunchPlan.make(manifest: manifest(mode: "system", php: .init(path: nil, minVersion: "8.4")), manifestDirectory: "/r", inherited: [:], address: "a", token: "t")) { error in
            XCTAssertTrue(String(describing: error).contains("PHP 8.4 or later"), "\(error)")
        }
    }

    func testPHPIsLookedForInTheConfiguredPathThenOnPATH() throws {
        let present: Set<String> = ["/usr/bin/php", "/opt/homebrew/bin/php"]
        let candidates = LaunchPlan.phpCandidates(manifest: try manifest(mode: "system"), manifestDirectory: "/r", path: "/usr/bin:/bin:/opt/homebrew/bin", home: "/h", exists: present.contains)
        XCTAssertEqual(candidates, ["/usr/bin/php", "/opt/homebrew/bin/php"])
        let configured = LaunchPlan.phpCandidates(manifest: try manifest(mode: "system", php: .init(path: "runtime/php", minVersion: nil)), manifestDirectory: "/r", path: "/usr/bin", home: nil, exists: present.contains)
        XCTAssertEqual(configured, ["/r/runtime/php"])
    }

    func testVersions() {
        XCTAssertTrue(Version.satisfies("8.4.1", minimum: "8.4"))
        XCTAssertTrue(Version.satisfies("8.10.0", minimum: "8.9"))
        XCTAssertTrue(Version.satisfies("8.4.0-dev", minimum: "8.4.0"))
        XCTAssertFalse(Version.satisfies("8.3.30", minimum: "8.4"))
        XCTAssertTrue(Version.satisfies("7.0", minimum: nil))
        XCTAssertFalse(Version.satisfies("", minimum: "8.1"))
    }

    func testADevelopmentLaunchFindsTheCommandOnPATH() throws {
        let plan = try LaunchPlan.development(command: ["php", "../tools/fake-app.php"], inherited: ["PATH": "/usr/bin:/opt/homebrew/bin", "FAKE_APP_LOG": "/l"], currentDirectory: "/w", address: "a", token: "t", exists: { $0 == "/opt/homebrew/bin/php" })
        XCTAssertEqual(plan.executable, "/opt/homebrew/bin/php")
        XCTAssertEqual(plan.arguments, ["../tools/fake-app.php"])
        XCTAssertEqual(plan.environment["FAKE_APP_LOG"], "/l")
        XCTAssertThrowsError(try LaunchPlan.development(command: ["nope"], inherited: [:], currentDirectory: "/w", address: "a", token: "t", exists: { _ in false }))
    }

    func testTokens() {
        let token = Token.make()
        XCTAssertNotNil(token.range(of: "^[0-9a-f]{32}$", options: .regularExpression))
        XCTAssertNotEqual(token, Token.make())
        XCTAssertTrue(Token.matches(token, token))
        XCTAssertFalse(Token.matches(String(token.dropLast()), token))
        XCTAssertFalse(Token.matches(token + "0", token))
        XCTAssertFalse(Token.matches("", token))
    }

    func testCells() {
        let cell = CellSize(width: 8.4, height: 17)
        XCTAssertEqual(cell.cells(x: 0, y: 0, width: 840, height: 510), [0, 0, 100, 30])
        XCTAssertEqual(cell.cells(x: 9, y: 35, width: 10, height: 1), [2, 1, 2, 1])
        XCTAssertEqual(cell.cells(x: 0, y: 0, width: 0, height: 0), [0, 0, 0, 0])
        XCTAssertEqual(cell.grid(width: 845, height: 520).columns, 100)
        XCTAssertEqual(cell.grid(width: 845, height: 520).rows, 30)
        XCTAssertEqual(cell.grid(width: 1, height: 1).columns, 1)
        XCTAssertEqual(RGB(hex: "#ff8000"), RGB(hex: "#FF8000"))
        XCTAssertEqual(RGB(hex: "#ff8000")?.green ?? 0, 128.0 / 255, accuracy: 1e-9)
        XCTAssertNil(RGB(hex: "red"))
    }

    func testThrottleSendsAtMostOncePerInterval() {
        var throttle = Throttle(interval: 0.05)
        XCTAssertEqual(throttle.delay(at: 1.0), 0)
        XCTAssertEqual(throttle.delay(at: 1.01), 0.04, accuracy: 1e-9)
        throttle.sent(at: 1.05)
        XCTAssertEqual(throttle.delay(at: 1.2), 0)
    }

    func testInfoPlistFromTheManifest() throws {
        let m = try manifest()
        let plist = InfoPlist.dictionary(manifest: m, executable: "Acme Notes", iconFile: "AppIcon.icns")
        XCTAssertEqual(plist["CFBundleName"] as? String, "Acme Notes")
        XCTAssertEqual(plist["CFBundleIdentifier"] as? String, "com.acme.notes")
        XCTAssertEqual(plist["CFBundleShortVersionString"] as? String, "1.2.0")
        XCTAssertEqual(plist["NSHumanReadableCopyright"] as? String, "© 2026 Acme")
        XCTAssertEqual(plist["LSApplicationCategoryType"] as? String, "public.app-category.productivity")
        XCTAssertEqual(plist["CFBundleIconFile"] as? String, "AppIcon.icns")
        let types = try XCTUnwrap(plist["CFBundleDocumentTypes"] as? [[String: Any]])
        XCTAssertEqual(types.first?["CFBundleTypeExtensions"] as? [String], ["acme"])
        XCTAssertEqual(types.first?["CFBundleTypeName"] as? String, "Acme note")
        XCTAssertEqual(types.first?["CFBundleTypeRole"] as? String, "Editor")
        XCTAssertNil(types.first?["CFBundleTypeMIMETypes"], "mimeType null")
        let urls = try XCTUnwrap(plist["CFBundleURLTypes"] as? [[String: Any]])
        XCTAssertEqual(urls.first?["CFBundleURLSchemes"] as? [String], ["acme"])
        XCTAssertNoThrow(try InfoPlist.xml(manifest: m, executable: "x", iconFile: nil))

        var bare = try manifest()
        bare.app.category = nil
        bare.app.fileAssociations = nil
        bare.app.urlSchemes = nil
        let plain = InfoPlist.dictionary(manifest: bare, executable: "x", iconFile: nil)
        XCTAssertNil(plain["LSApplicationCategoryType"])
        XCTAssertNil(plain["CFBundleDocumentTypes"])
    }
}
