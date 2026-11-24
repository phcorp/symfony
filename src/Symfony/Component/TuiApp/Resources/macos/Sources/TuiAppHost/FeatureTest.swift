import AppKit
import TuiAppHostCore

/// `--feature-test`: the features around the node tree, against an application that plays the
/// feature scenario (`FAKE_APP_FEATURES=1 php tools/fake-app.php`, or `tools/feature-app.php`).
/// See HOSTS.md. Each stage acts through AppKit as a user or the system would, and waits for
/// label node 2 to show that the application got it. Any failure, or 60 s, exits 1.
///
/// `TUI_APP_FEATURE_CAPTURE=<dir>` writes what the host drew: window.png, dock.png and the
/// force-quit alert, alert-1.png and alert-2.png.
@MainActor
final class FeatureTest {
    private weak var controller: HostWindowController?
    private weak var mainMenu: MainMenu?
    private var stage = "waiting for the first patch"
    private var done = false
    private let begin = Date()
    private var deadline: Timer?
    private let capture = ProcessInfo.processInfo.environment["TUI_APP_FEATURE_CAPTURE"].flatMap { $0.isEmpty ? nil : $0 }

    // What the application sent, and what came of it.
    private var notifications: [String] = []
    private var notifyOutcomes: [String] = []
    private var progress: WindowProgress?
    private var beeps = 0
    private var lastOpen: [String]?
    private var alerts = 0
    private var applicationPid: Int32?
    private var exitStatus: Int32?

    init(controller: HostWindowController, mainMenu: MainMenu) {
        self.controller = controller
        self.mainMenu = mainMenu
        controller.notifier.provisional = true
        controller.notifier.onOutcome = { [weak self] in self?.notifyOutcomes.append($0) }
        say("starting")
        deadline = Timer.scheduledTimer(withTimeInterval: 60, repeats: false) { [weak self] _ in
            MainActor.assumeIsolated {
                guard let self else { return }
                self.fail("no result after 60 s, while \(self.stage)")
            }
        }
    }

    private func say(_ text: String) {
        let elapsed = String(format: "%.2f", Date().timeIntervalSince(begin))
        FileHandle.standardOutput.write(Data("feature-test [\(elapsed)s]: \(text)\n".utf8))
    }

    // MARK: Hooks

    func received(_ message: Message) {
        switch message {
        case let .notify(title, body):
            notifications.append(title + (body.map { " — \($0)" } ?? ""))
        case let .window(_, progress):
            if case let .value(p) = progress { self.progress = p }
            if case .null = progress { self.progress = nil }
        case .beep:
            beeps += 1
        default:
            break
        }
    }

    func opened(_ paths: [String]) {
        lastOpen = paths
    }

    func firstPatchDrawn() {
        guard stage == "waiting for the first patch", let controller else { return }
        applicationPid = controller.applicationPid
        let expected = lastOpen.map { "open: " + $0.joined(separator: " | ") } ?? "ready"
        if let lastOpen {
            say("open at launch: \(lastOpen.joined(separator: " | "))")
        }
        stage = "waiting for node 2 to read \"\(expected)\""
        waitForLabel(expected) { [weak self] in self?.checkSystemMessages() }
    }

    // MARK: Stages

    /// notify, the Dock progress and beep, sent before "ready".
    private func checkSystemMessages() {
        stage = "checking notify, progress and beep"
        guard notifications.count == 1 else {
            return fail("expected 1 notify, got \(notifications.count)")
        }
        say("notify received: \(notifications[0])")
        guard let progress, progress.state == "normal", progress.value.value == 0.5 else {
            return fail("expected window progress normal 0.5, got \(String(describing: self.progress))")
        }
        guard let tile = NSApp.dockTile.contentView as? DockProgressView, tile.state == "normal", tile.value == 0.5 else {
            return fail("the Dock tile does not show the progress (content view: \(NSApp.dockTile.contentView.map { String(describing: type(of: $0)) } ?? "none"))")
        }
        say("Dock tile shows progress normal 0.5 (DockProgressView)")
        guard beeps == 1 else {
            return fail("expected 1 beep, got \(beeps)")
        }
        say("beep received and played with NSSound.beep()")
        if capture != nil, let controller {
            save(controller.window.contentView?.superview ?? controller.window.contentView, to: "window.png")
            save(tile, to: "dock.png")
        }
        waitFor("the notification outcome", seconds: 10, { [weak self] in self?.notifyOutcomes.isEmpty == false }) { [weak self] in
            guard let self else { return }
            let outcome = self.notifyOutcomes[0]
            guard !outcome.hasPrefix("not shown") else {
                return self.fail("the notification was \(outcome)")
            }
            self.say("notification \(outcome)")
            self.menuShortcut()
        }
    }

    /// File > Save through its key equivalent, then File > Wrap lines as a click does.
    private func menuShortcut() {
        stage = "menu"
        guard let menu = NSApp.mainMenu,
              let event = NSEvent.keyEvent(with: .keyDown, location: .zero, modifierFlags: [.control], timestamp: ProcessInfo.processInfo.systemUptime,
                                           windowNumber: controller?.window.windowNumber ?? 0, context: nil, characters: "\u{13}", charactersIgnoringModifiers: "s", isARepeat: false, keyCode: 1)
        else { return fail("no main menu") }
        guard menu.performKeyEquivalent(with: event) else {
            return fail("ctrl+s is not the key equivalent of a menu item")
        }
        say("pressed ctrl+s: the main menu took it")
        waitForLabel("menu: file.save") { [weak self] in
            guard let self else { return }
            guard let file = menu.items.first(where: { $0.title == "File" })?.submenu,
                  let index = file.items.firstIndex(where: { $0.title == "Wrap lines" })
            else { return self.fail("no File > Wrap lines item") }
            guard file.items[index].state == .on else {
                return self.fail("File > Wrap lines is not checked")
            }
            file.performActionForItem(at: index)
            self.say("chose File > Wrap lines (checked)")
            self.waitForLabel("menu: file.wrap") { [weak self] in self?.appearance() }
        }
    }

    /// The system appearance flips, then comes back.
    private func appearance() {
        stage = "appearance"
        guard (controller?.manifest.window.theme ?? "system") == "system" else {
            say("window.theme is \(controller?.manifest.window.theme ?? "?"): appearance changes are not sent; skipped")
            return openWhileRunning()
        }
        let original = HostWindowController.appearance()
        let other = original == "dark" ? "light" : "dark"
        NSApp.appearance = NSAppearance(named: other == "dark" ? .darkAqua : .aqua)
        say("switched the appearance from \(original) to \(other)")
        waitForLabel("appearance: \(other)") { [weak self] in
            NSApp.appearance = nil
            self?.say("back to the system appearance (\(original))")
            self?.waitForLabel("appearance: \(original)") { [weak self] in self?.openWhileRunning() }
        }
    }

    /// Launch Services sends the running bundle an open documents Apple event for a file,
    /// then a get URL one for a URL, as `open -a` and the Finder do. An unbundled host cannot be
    /// addressed by Launch Services: the stage is skipped (the .app run covers it).
    private func openWhileRunning() {
        stage = "open while running"
        guard Notifier.bundled else {
            say("open while running: skipped, Launch Services only addresses a bundle")
            return closeKeptOpen()
        }
        let file = NSTemporaryDirectory() + "feature-test-\(getpid()).fake"
        FileManager.default.createFile(atPath: file, contents: Data("x".utf8))
        // As Launch Services hands it over: /var/folders is really /private/var/folders.
        guard let resolved = realpath(file, nil) else { return fail("cannot resolve \(file)") }
        let path = String(cString: resolved)
        free(resolved)
        open(URL(fileURLWithPath: path))
        waitForLabel("open: \(path)") { [weak self] in
            guard let self else { return }
            try? FileManager.default.removeItem(atPath: path)
            let url = "fake-tuiapp://feature?id=42"
            self.open(URL(string: url)!)
            self.waitForLabel("open: \(url)") { [weak self] in self?.closeKeptOpen() }
        }
    }

    private func open(_ url: URL) {
        let configuration = NSWorkspace.OpenConfiguration()
        configuration.activates = false
        say("asking Launch Services to open \(url.isFileURL ? url.path : url.absoluteString) with \(Bundle.main.bundleURL.lastPathComponent)")
        NSWorkspace.shared.open([url], withApplicationAt: Bundle.main.bundleURL, configuration: configuration) { application, error in
            if let error {
                let message = "Launch Services did not open \(url): \(error.localizedDescription)"
                DispatchQueue.main.async { MainActor.assumeIsolated { self.fail(message) } }
            } else if application?.processIdentifier != getpid() {
                let message = "Launch Services opened \(url) in process \(application?.processIdentifier ?? 0), not this one (\(getpid()))"
                DispatchQueue.main.async { MainActor.assumeIsolated { self.fail(message) } }
            }
        }
    }

    /// The close button: the application keeps the window open.
    private func closeKeptOpen() {
        stage = "close, kept open"
        guard let window = controller?.window else { return fail("no window") }
        window.performClose(nil)
        say("clicked the close button")
        waitForLabel("close: kept open") { [weak self] in
            DispatchQueue.main.asyncAfter(deadline: .now() + 0.5) {
                MainActor.assumeIsolated {
                    guard let self, let controller = self.controller else { return }
                    guard controller.window.isVisible, !controller.finished else {
                        return self.fail("the window closed although the application kept it open")
                    }
                    self.say("the application kept the window open (close-reply false); the window stayed")
                    self.closeUnanswered()
                }
            }
        }
    }

    /// The close button again: no answer, so the force-quit question; Wait keeps the window.
    private func closeUnanswered() {
        stage = "close, no answer"
        controller?.window.performClose(nil)
        say("clicked the close button; the application does not answer")
    }

    /// Called just before the force-quit alert runs modally: a timer in the modal run loop
    /// answers it as a user would, by clicking a button.
    func forceQuitOffered(_ alert: NSAlert) {
        alerts += 1
        let number = alerts
        say("the force-quit alert is up: \"\(alert.messageText)\"")
        let timer = Timer(timeInterval: 0.5, repeats: false) { [weak self] _ in
            MainActor.assumeIsolated {
                guard let self else { return }
                if self.capture != nil {
                    self.save(alert.window.contentView?.superview ?? alert.window.contentView, to: "alert-\(number).png")
                }
                let title = number == 1 ? "Wait" : "Force Quit"
                guard let button = alert.buttons.first(where: { $0.title == title }) else {
                    return self.fail("the alert has no \(title) button")
                }
                self.say("clicking \(title)")
                if number == 1 {
                    self.afterWait()
                } else {
                    self.stage = "force quit"
                }
                button.performClick(nil)
            }
        }
        RunLoop.main.add(timer, forMode: .modalPanel)
    }

    private func afterWait() {
        DispatchQueue.main.asyncAfter(deadline: .now() + 0.5) {
            MainActor.assumeIsolated {
                guard let controller = self.controller else { return }
                guard controller.window.isVisible, !controller.finished else {
                    return self.fail("the window closed after Wait")
                }
                self.say("Wait kept the window open")
                self.stage = "close, hung"
                controller.window.performClose(nil)
                self.say("clicked the close button; the application hangs and ignores SIGTERM")
            }
        }
    }

    func processExited(status: Int32, saidBye: Bool, exit: CloseFlow.Exit) {
        exitStatus = status
        guard stage == "force quit" else {
            return fail("the application exited with status \(status) while \(stage)")
        }
        guard exit == .finished else {
            return fail("the force-quit exit (status \(status)) is reported as \(exit)")
        }
        say("the application was killed (status \(status)\(status == SIGKILL ? ", SIGKILL after SIGTERM was ignored" : "")); no error dialog")
    }

    func windowClosed() {
        guard !done else { return }
        guard stage == "force quit", exitStatus != nil else {
            return fail("the window closed while \(stage)")
        }
        if let pid = applicationPid, kill(pid, 0) == 0 || errno != ESRCH {
            return fail("process \(pid) is still there")
        }
        say("the window closed; process \(applicationPid.map(String.init) ?? "?") is gone")
        pass()
    }

    // MARK: Helpers

    private func labelText() -> String? {
        (controller?.reconciler.views[2] as? LabelNodeView)?.field.stringValue
    }

    private func waitForLabel(_ text: String, then: @escaping @MainActor () -> Void) {
        waitFor("node 2 to read \"\(text)\"", seconds: 5, { [weak self] in self?.labelText() == text }) { [weak self] in
            self?.say("node 2 reads \"\(text)\"")
            then()
        }
    }

    private func waitFor(_ what: String, seconds: Double, _ condition: @escaping @MainActor () -> Bool, then: @escaping @MainActor () -> Void) {
        let until = Date().addingTimeInterval(seconds)
        func poll() {
            guard !done else { return }
            if condition() { return then() }
            guard Date() < until else {
                return fail("waited \(Int(seconds)) s for \(what); node 2 reads \"\(labelText() ?? "(no label)")\"")
            }
            DispatchQueue.main.asyncAfter(deadline: .now() + 0.05) {
                MainActor.assumeIsolated { poll() }
            }
        }
        poll()
    }

    /// Draws a view, with its subviews, to a PNG in the capture directory.
    private func save(_ view: NSView?, to name: String) {
        guard let capture, let view else { return }
        if let how = WindowCapture.write(view, to: (capture as NSString).appendingPathComponent(name)) {
            say("drew \(name) (\(Int(view.bounds.width))×\(Int(view.bounds.height)) pt, \(how))")
        }
    }

    private func pass() {
        done = true
        say("ok")
        exit(0)
    }

    func fail(_ why: String) {
        guard !done else { return }
        done = true
        FileHandle.standardError.write(Data("feature-test: FAILED: \(why)\n".utf8))
        // SIGKILL now: exit(1) does not wait for killApplication()'s SIGKILL a second later.
        if let pid = applicationPid ?? controller?.applicationPid { kill(pid, SIGKILL) }
        exit(1)
    }
}
