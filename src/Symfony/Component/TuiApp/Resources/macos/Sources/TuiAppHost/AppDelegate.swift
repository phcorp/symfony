import AppKit
import TuiAppHostCore

@MainActor
final class AppDelegate: NSObject, NSApplicationDelegate {
    private let options: LaunchOptions
    private var manifest: Manifest!
    private var manifestDirectory: String?
    private var controller: HostWindowController?
    private let mainMenu = MainMenu()
    private var appearanceObservation: NSKeyValueObservation?
    private var pendingOpen: [String] = []

    init(options: LaunchOptions) {
        self.options = options
        super.init()
    }

    func applicationWillFinishLaunching(_ notification: Notification) {
        do {
            (manifest, manifestDirectory) = try Self.loadManifest(options)
        } catch {
            fatal("The application cannot start.", detail: String(describing: error))
        }
        mainMenu.install(name: manifest.app.name, about: manifest.menu.about != false, quit: manifest.menu.quit != false, target: self)
        switch manifest.window.theme {
        case "light": NSApp.appearance = NSAppearance(named: .aqua)
        case "dark": NSApp.appearance = NSAppearance(named: .darkAqua)
        default: NSApp.appearance = nil
        }
    }

    func applicationDidFinishLaunching(_ notification: Notification) {
        if handOverToRunningInstance() {
            return
        }
        let controller = HostWindowController(manifest: manifest, manifestDirectory: manifestDirectory, options: options)
        controller.mainMenu = mainMenu
        controller.onClosed = { [weak self] in
            self?.controller = nil
            NSApp.terminate(nil)
        }
        if options.selfTest {
            controller.selfTest = SelfTest(controller: controller)
        } else if options.featureTest {
            controller.featureTest = FeatureTest(controller: controller, mainMenu: mainMenu)
        } else if options.canvasTest {
            controller.canvasTest = CanvasTest(controller: controller)
        }
        self.controller = controller
        controller.open(pendingOpen)
        pendingOpen = []
        appearanceObservation = NSApp.observe(\.effectiveAppearance, options: [.new]) { [weak self] _, _ in
            MainActor.assumeIsolated { self?.controller?.appearanceChanged() }
        }
        controller.start()
    }

    // MARK: Manifest

    static func loadManifest(_ options: LaunchOptions) throws -> (Manifest, String?) {
        if let command = options.development {
            return (Manifest.development(command: command), nil)
        }
        var candidates = [String]()
        if let path = options.manifestPath {
            candidates = [path]
        } else {
            if let resources = Bundle.main.resourceURL {
                candidates.append(resources.appendingPathComponent("app.manifest.json").path)
            }
            if let executable = Bundle.main.executableURL {
                candidates.append(executable.deletingLastPathComponent().appendingPathComponent("app.manifest.json").path)
            }
        }
        for path in candidates {
            let url = URL(fileURLWithPath: path).standardizedFileURL
            guard let data = FileManager.default.contents(atPath: url.path) else { continue }
            return (try Manifest.parse(data), url.deletingLastPathComponent().path)
        }
        throw Manifest.Error.unreadable("no app.manifest.json at \(candidates.joined(separator: " or ")). Use --manifest <file>, or --dev -- <command>.")
    }

    // MARK: Single instance

    private var openNotification: Notification.Name { .init("\(manifest.app.id).tuiapp.open") }

    /// With `singleInstance`, a second launch hands its paths to the first and exits.
    private func handOverToRunningInstance() -> Bool {
        guard manifest.app.singleInstance == true, options.development == nil, !options.selfTest, !options.featureTest, !options.canvasTest else { return false }
        let others = NSRunningApplication.runningApplications(withBundleIdentifier: manifest.app.id)
            .filter { $0.processIdentifier != ProcessInfo.processInfo.processIdentifier }
        if let first = others.first {
            DistributedNotificationCenter.default().postNotificationName(openNotification, object: nil, userInfo: ["paths": options.paths + pendingOpen], deliverImmediately: true)
            first.activate()
            exit(0)
        }
        DistributedNotificationCenter.default().addObserver(forName: openNotification, object: nil, queue: .main) { [weak self] notification in
            let paths = notification.userInfo?["paths"] as? [String] ?? []
            MainActor.assumeIsolated {
                self?.controller?.open(paths)
                NSApp.activate(ignoringOtherApps: true)
            }
        }
        return false
    }

    // MARK: Open

    func application(_ application: NSApplication, open urls: [URL]) {
        let paths = urls.map { $0.isFileURL ? $0.path : $0.absoluteString }
        if let controller {
            controller.open(paths)
        } else {
            pendingOpen += paths
        }
    }

    // MARK: Menu actions

    @objc func showAbout(_ sender: Any?) {
        var options: [NSApplication.AboutPanelOptionKey: Any] = [
            .applicationName: manifest.app.name,
            .applicationVersion: manifest.app.version,
            .version: "",
        ]
        let credits = [manifest.app.description, manifest.app.homepage].compactMap { $0 }.filter { !$0.isEmpty }.joined(separator: "\n")
        if !credits.isEmpty {
            options[.credits] = NSAttributedString(string: credits, attributes: [.font: NSFont.systemFont(ofSize: NSFont.smallSystemFontSize), .foregroundColor: NSColor.secondaryLabelColor])
        }
        if let copyright = manifest.app.copyright {
            options[NSApplication.AboutPanelOptionKey(rawValue: "Copyright")] = copyright
        }
        NSApp.orderFrontStandardAboutPanel(options: options)
        NSApp.activate(ignoringOtherApps: true)
    }

    @objc func quit(_ sender: Any?) {
        NSApp.terminate(sender)
    }

    func applicationShouldTerminate(_ sender: NSApplication) -> NSApplication.TerminateReply {
        // Quit is a close: the application decides, and the window takes the host with it.
        if let controller, !controller.finished {
            controller.requestClose()
            return .terminateCancel
        }
        return .terminateNow
    }

    func applicationShouldTerminateAfterLastWindowClosed(_ sender: NSApplication) -> Bool {
        true
    }

    private func fatal(_ message: String, detail: String) -> Never {
        if options.selfTest || options.featureTest || options.canvasTest {
            FileHandle.standardError.write(Data("\(options.selfTest ? "self" : options.featureTest ? "feature" : "canvas")-test: FAILED: \(message) \(detail)\n".utf8))
            exit(1)
        }
        FileHandle.standardError.write(Data("tuiapp-host: \(message) \(detail)\n".utf8))
        let alert = NSAlert()
        alert.alertStyle = .critical
        alert.messageText = message
        alert.informativeText = detail
        alert.runModal()
        exit(1)
    }
}
