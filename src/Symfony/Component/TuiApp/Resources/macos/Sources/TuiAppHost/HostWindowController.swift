import AppKit
import TuiAppHostCore

/// The window: unused keys go to the application, except Tab and Shift+Tab, which move the focus
/// unless a canvas asks for them.
final class HostWindow: NSWindow {
    weak var keyTarget: HostWindowController?

    override func sendEvent(_ event: NSEvent) {
        // Before the focused control: it would use them
        if event.type == .keyDown, keyTarget?.takeCanvasKey(event) == true {
            return
        }
        super.sendEvent(event)
    }

    override func keyDown(with event: NSEvent) {
        if event.keyCode == 48, event.modifierFlags.intersection([.command, .control, .option]).isEmpty {
            super.keyDown(with: event)
            return
        }
        keyTarget?.forwardKey(event)
    }
}

/// The window's content: takes dropped files.
final class DropView: NSView {
    var onDrop: (([String]) -> Void)?

    override init(frame: NSRect) {
        super.init(frame: frame)
        registerForDraggedTypes([.fileURL])
    }

    @available(*, unavailable)
    required init?(coder: NSCoder) { fatalError() }

    override func draggingEntered(_ sender: NSDraggingInfo) -> NSDragOperation {
        sender.draggingPasteboard.canReadObject(forClasses: [NSURL.self], options: [.urlReadingFileURLsOnly: true]) ? .copy : []
    }

    override func performDragOperation(_ sender: NSDraggingInfo) -> Bool {
        let urls = sender.draggingPasteboard.readObjects(forClasses: [NSURL.self], options: [.urlReadingFileURLsOnly: true]) as? [URL] ?? []
        guard !urls.isEmpty else { return false }
        onDrop?(urls.map(\.path))
        return true
    }
}

struct LaunchOptions {
    var selfTest = false
    var featureTest = false
    var canvasTest = false
    var manifestPath: String?
    var development: [String]?
    var paths: [String] = []
}

/// One application: its window, its process, its connection, its node tree.
@MainActor
final class HostWindowController: NSObject, NSWindowDelegate, NodeHost {
    let manifest: Manifest
    let manifestDirectory: String?
    let options: LaunchOptions
    private(set) var window: HostWindow!
    private let scroll = NSScrollView()
    private let document = FlippedView()
    private var connection: Connection!
    private var process: AppProcess?
    let log: AppLog
    private(set) var tree = NodeTree()
    private(set) lazy var reconciler = Reconciler(host: self)
    private var rootView: NodeView?
    private var flow = CloseFlow()
    private var helloSent = false
    private var pendingOpen: [String] = []
    private var closeTimer: Timer?
    private var authTimer: Timer?
    private var lastGrid: (columns: Int, rows: Int)?
    private var resizeThrottle = Throttle(interval: 0.05)
    private var resizeTimer: Timer?
    private var relayoutQueued = false
    private var programmaticFocus = false
    private var lastFocused: Int?
    private var responderObservation: NSKeyValueObservation?
    private var patchesDrawn = 0
    private(set) var finished = false
    let dock = DockProgress()
    let notifier: Notifier
    private var sentAppearance: String?
    weak var mainMenu: MainMenu?
    var selfTest: SelfTest?
    var featureTest: FeatureTest?
    var canvasTest: CanvasTest?
    var onClosed: (() -> Void)?

    let canvasFont: NSFont
    let cell: CellSize

    init(manifest: Manifest, manifestDirectory: String?, options: LaunchOptions) {
        self.manifest = manifest
        self.manifestDirectory = manifestDirectory
        self.options = options
        log = AppLog.make(manifest: manifest, manifestDirectory: manifestDirectory, development: options.development != nil)
        notifier = Notifier(log: log)
        let size = CGFloat(manifest.canvasFontSize)
        let family = manifest.window.canvasFont?.family
        canvasFont = family.flatMap { NSFont(name: $0, size: size) ?? NSFontManager.shared.font(withFamily: $0, traits: [], weight: 5, size: size) }
            ?? NSFont.monospacedSystemFont(ofSize: size, weight: .regular)
        cell = Self.cellSize(of: canvasFont)
        pendingOpen = options.paths
        super.init()
    }

    /// One cell of a monospaced font: the advance of a glyph by the line height.
    static func cellSize(of font: NSFont) -> CellSize {
        let ct = font as CTFont
        var glyph = CGGlyph()
        var character: UniChar = 0x4D // M
        CTFontGetGlyphsForCharacters(ct, &character, &glyph, 1)
        var advance = CGSize.zero
        CTFontGetAdvancesForGlyphs(ct, .horizontal, &glyph, &advance, 1)
        let height = ceil(CTFontGetAscent(ct) + CTFontGetDescent(ct) + CTFontGetLeading(ct))
        return CellSize(width: Double(advance.width), height: Double(height))
    }

    // MARK: Start-up

    func start() {
        makeWindow()
        connection = Connection(token: Token.make(), handlers: .init(
            authenticated: { [weak self] in self?.authenticated() },
            received: { [weak self] in self?.received($0) },
            closed: { [weak self] in self?.connectionClosed($0) },
            log: { [weak self] in self?.log.host($0) }
        ))
        do {
            try connection.listen()
        } catch {
            return fail("\(manifest.app.name) cannot start: \(error.localizedDescription)", detail: nil)
        }
        let plan: LaunchPlan
        do {
            plan = try launchPlan()
        } catch {
            return fail("\(manifest.app.name) cannot start.", detail: String(describing: error))
        }
        let process = AppProcess(plan: plan, log: log)
        self.process = process
        do {
            try process.start { [weak self] status in self?.processExited(status) }
        } catch {
            return fail("\(manifest.app.name) cannot start.", detail: "\(plan.executable): \(error.localizedDescription)")
        }
        log.host("started \(plan.executable) \(plan.arguments.joined(separator: " ")) (pid \(process.pid)), listening on \(connection.address)")
        authTimer = Timer.scheduledTimer(withTimeInterval: 10, repeats: false) { [weak self] _ in
            MainActor.assumeIsolated { self?.authTimedOut() }
        }
    }

    private func launchPlan() throws -> LaunchPlan {
        let environment = ProcessInfo.processInfo.environment
        let exists = { (path: String) in FileManager.default.isExecutableFile(atPath: path) }
        if let command = options.development {
            return try LaunchPlan.development(command: command, inherited: environment, currentDirectory: FileManager.default.currentDirectoryPath, address: connection.address, token: connection.token, exists: exists)
        }
        let directory = manifestDirectory ?? FileManager.default.currentDirectoryPath
        var php: String?
        if manifest.launch.mode == "system" {
            let minimum = manifest.launch.php?.minVersion
            var tried = [String]()
            for candidate in LaunchPlan.phpCandidates(manifest: manifest, manifestDirectory: directory, path: environment["PATH"], home: environment["HOME"], exists: exists) {
                let version = AppProcess.phpVersion(candidate)
                tried.append("\(candidate) (\(version ?? "does not run"))")
                if let version, Version.satisfies(version, minimum: minimum) {
                    php = candidate
                    break
                }
            }
            if php == nil {
                throw LaunchPlan.Error.noSuitablePHP(minimum: minimum, tried: tried)
            }
        }
        return try LaunchPlan.make(manifest: manifest, manifestDirectory: directory, inherited: environment, address: connection.address, token: connection.token, php: php)
    }

    private func makeWindow() {
        let w = manifest.window
        var style: NSWindow.StyleMask = [.titled, .closable, .miniaturizable]
        if w.resizable != false { style.insert(.resizable) }
        let window = HostWindow(contentRect: NSRect(x: 0, y: 0, width: w.width, height: w.height), styleMask: style, backing: .buffered, defer: false)
        window.isReleasedWhenClosed = false
        window.title = w.title
        window.keyTarget = self
        window.delegate = self
        window.contentMinSize = NSSize(width: w.minWidth ?? 200, height: w.minHeight ?? 120)
        // In the order of the nodes, not where they are: see keyViewLoop()
        window.autorecalculatesKeyViewLoop = false
        window.tabbingMode = .disallowed

        let content = DropView(frame: NSRect(x: 0, y: 0, width: w.width, height: w.height))
        content.onDrop = { [weak self] paths in self?.open(paths) }
        scroll.frame = content.bounds
        scroll.autoresizingMask = [.width, .height]
        scroll.hasVerticalScroller = true
        scroll.autohidesScrollers = true
        scroll.drawsBackground = false
        scroll.borderType = .noBorder
        scroll.documentView = document
        document.frame = NSRect(origin: .zero, size: scroll.contentSize)
        content.addSubview(scroll)
        window.contentView = content

        window.center()
        if w.rememberGeometry == true {
            window.setFrameAutosaveName("\(manifest.app.id).main")
        }
        self.window = window
        responderObservation = window.observe(\.firstResponder, options: [.new]) { [weak self] _, _ in
            MainActor.assumeIsolated { self?.firstResponderChanged() }
        }
        if options.selfTest || options.featureTest || options.canvasTest {
            window.orderFrontRegardless()
        } else {
            window.makeKeyAndOrderFront(nil)
            NSApp.activate(ignoringOtherApps: true)
        }
    }

    // MARK: Connection

    private func authenticated() {
        authTimer?.invalidate()
        flow.connected()
        let grid = currentGrid()
        lastGrid = grid
        sentAppearance = Self.appearance()
        send(.hello(Hello(
            columns: grid.columns, rows: grid.rows, cellWidth: cell.width, cellHeight: cell.height,
            scale: Double(window.backingScaleFactor), appearance: sentAppearance!,
            capabilities: ["menu", "notify", "progress", "appearance", "open", "beep", "canvas-controls", "password", "number", "slider", "listbox", "pointer", "contextmenu", "clipboard"]
        )))
        helloSent = true
        if !pendingOpen.isEmpty {
            send(.open(paths: pendingOpen))
            featureTest?.opened(pendingOpen)
            pendingOpen = []
        }
    }

    private func authTimedOut() {
        guard !helloSent, !finished else { return }
        process?.kill()
        let tail = log.lastLines()
        fail("\(manifest.app.name) did not start: it did not connect within 10 seconds.", detail: tail.isEmpty ? nil : tail)
    }

    private func connectionClosed(_ why: String) {
        log.host("connection closed: \(why)")
    }

    func send(_ message: Message) {
        canvasTest?.sent(message)
        connection?.send(message)
    }

    private func received(_ message: Message) {
        featureTest?.received(message)
        switch message {
        case let .patch(ops):
            applyPatch(ops)
        case let .focus(node):
            programmaticFocus = true
            if let node, let target = reconciler.views[node]?.focusTarget {
                window.makeFirstResponder(target)
            } else {
                window.makeFirstResponder(nil)
            }
            lastFocused = node
            programmaticFocus = false
        case let .window(title, progress):
            switch title {
            case .absent: break
            case .null: window.title = manifest.window.title
            case let .value(title): window.title = title
            }
            switch progress {
            case .absent: break
            case .null: dock.show(nil)
            case let .value(p): dock.show(p)
            }
        case let .notify(title, body):
            notifier.notify(title: title, body: body)
        case .beep:
            NSSound.beep()
        case let .menu(menus):
            mainMenu?.show(menus) { [weak self] id in self?.send(.menuSelect(id: id)) }
        case let .closeReply(accept):
            handle(flow.closeReply(accept: accept))
        case .bye:
            handle(flow.bye())
        case let .contextMenu(node, row, column, items):
            (reconciler.views[node] as? CanvasNodeView)?.surface.showContextMenu(items, row: row, column: column) { [weak self] in self?.send($0) }
        case let .clipboard(text):
            NSPasteboard.general.clearContents()
            NSPasteboard.general.setString(text, forType: .string)
        case let .cursor(node, shape):
            // Over a canvas only: another kind of node, or one that is gone, is ignored
            (reconciler.views[node] as? CanvasNodeView)?.surface.setCursorShape(CursorShape(keyword: shape))
        default:
            // Host-to-application types, and unknown ones: ignored.
            break
        }
    }

    // MARK: The tree

    private func applyPatch(_ ops: [PatchOp]) {
        let result = tree.apply(ops)
        for error in result.errors {
            log.host("patch: \(error)")
        }
        let root = reconciler.apply(result, tree: tree)
        if root !== rootView {
            rootView?.removeFromSuperview()
            rootView = root
            if let root { document.addSubview(root) }
        }
        relayout()
        patchesDrawn += 1
        if patchesDrawn == 1 {
            DispatchQueue.main.async { [weak self] in
                MainActor.assumeIsolated {
                    self?.selfTest?.firstPatchDrawn()
                    self?.featureTest?.firstPatchDrawn()
                    self?.canvasTest?.firstPatchDrawn()
                }
            }
        }
    }

    /// Lays the tree out in the content area, once, then tells the application where nodes are.
    /// Room between the window edges and the root, as AppKit windows have; rects still count
    /// from the content area's corner.
    static let margin = NSSize(width: 14, height: 10)

    private func relayout() {
        relayoutQueued = false
        let size = scroll.contentSize
        guard let root = rootView else {
            document.frame = NSRect(origin: .zero, size: size)
            return
        }
        let m = Self.margin
        let needed = root.measure(width: size.width - m.width * 2).height + m.height * 2
        document.frame = NSRect(x: 0, y: 0, width: size.width, height: max(size.height, needed))
        root.frame = document.bounds.insetBy(dx: m.width, dy: m.height)
        root.layoutTree()
        keyViewLoop()
        sendRects()
    }

    private var loop: [NSView] = []

    /// The key-view loop (Tab, Shift+Tab) in the order of the nodes, depth first: a canvas, then the
    /// controls over it, wherever they are placed. AppKit's own goes by where views are on the screen.
    /// Hidden ones stay in it, and AppKit skips them (canBecomeKeyView).
    private func keyViewLoop() {
        var views: [NSView] = []
        func visit(_ node: NodeView) {
            views += node.keyViews.filter { $0.window === window }
            node.nodeChildren.forEach(visit)
        }
        if let rootView { visit(rootView) }
        guard views.map(ObjectIdentifier.init) != loop.map(ObjectIdentifier.init) else { return }
        for view in loop { view.nextKeyView = nil }
        for (index, view) in views.enumerated() {
            view.nextKeyView = views[(index + 1) % views.count]
        }
        loop = views
        // Where Tab starts when nothing has the focus
        window.initialFirstResponder = views.first
    }

    /// For the canvas test: a patch as if the application sent it.
    func applyForTest(_ ops: [PatchOp]) {
        applyPatch(ops)
    }

    func receiveForTest(_ message: Message) {
        received(message)
    }

    func setNeedsRelayout() {
        guard !relayoutQueued else { return }
        relayoutQueued = true
        DispatchQueue.main.async { [weak self] in
            MainActor.assumeIsolated {
                guard let self, self.relayoutQueued else { return }
                self.relayout()
            }
        }
    }

    private func sendRects() {
        guard helloSent else { return }
        var rects = [Int: [Int]]()
        for id in tree.orderedIds {
            guard let view = reconciler.views[id] else { continue }
            if view.window == nil || view.isHiddenOrHasHiddenAncestor {
                rects[id] = [0, 0, 0, 0]
                continue
            }
            let frame = view.convert(view.bounds, to: document)
            rects[id] = cell.cells(x: frame.minX, y: frame.minY, width: frame.width, height: frame.height)
        }
        send(.rects(rects))
    }

    private func currentGrid() -> (columns: Int, rows: Int) {
        let size = scroll.contentSize
        return cell.grid(width: size.width, height: size.height)
    }

    func windowDidResize(_ notification: Notification) {
        relayout()
        scheduleResize()
    }

    private func scheduleResize() {
        guard helloSent, resizeTimer == nil else { return }
        let wait = resizeThrottle.delay(at: ProcessInfo.processInfo.systemUptime)
        if wait == 0 {
            sendResize()
            return
        }
        resizeTimer = Timer.scheduledTimer(withTimeInterval: wait, repeats: false) { [weak self] _ in
            MainActor.assumeIsolated {
                guard let self else { return }
                self.resizeTimer = nil
                self.resizeThrottle.sent(at: ProcessInfo.processInfo.systemUptime)
                self.sendResize()
            }
        }
    }

    private func sendResize() {
        let grid = currentGrid()
        guard lastGrid.map({ $0 != grid }) ?? true else { return }
        lastGrid = grid
        send(.resize(columns: grid.columns, rows: grid.rows))
    }

    // MARK: Input

    func sendEvent(_ node: Int, _ name: String, _ value: JSON?) {
        // The control shows it, and the application takes it: an update of its other props keeps it
        if tree.acknowledge(node: node, event: name, value: value), let props = tree[node]?.props {
            reconciler.views[node]?.acknowledge(props)
        }
        send(.event(node: node, name: name, value: value))
    }

    func forwardKey(_ event: NSEvent) {
        var modifiers = KeyModifiers()
        let flags = event.modifierFlags
        if flags.contains(.control) { modifiers.insert(.control) }
        if flags.contains(.shift) { modifiers.insert(.shift) }
        if flags.contains(.option) { modifiers.insert(.option) }
        if flags.contains(.command) { modifiers.insert(.command) }
        guard let press = KeyId.from(keyCode: event.keyCode, characters: event.characters, charactersIgnoringModifiers: event.charactersIgnoringModifiers, modifiers: modifiers) else { return }
        send(.key(key: press.key, text: press.text))
    }

    func sendPaste(_ text: String) {
        send(.paste(text: text))
    }

    func sendPointer(_ node: Int, _ pointer: Pointer) {
        send(.pointer(node: node, pointer))
    }

    func sendCommitted(_ text: String) {
        for press in KeyId.committed(text) {
            send(.key(key: press.key, text: press.text))
        }
    }

    /// The canvas a responder is in: the canvas itself, or a control placed over it.
    func canvas(of responder: NSResponder?) -> CanvasNodeView? {
        var view: NSView? = reconciler.view(for: responder)
        while let current = view {
            if let canvas = current as? CanvasNodeView { return canvas }
            view = current.superview
        }
        return nil
    }

    /// Sends a key the focused canvas, or the canvas of the focused control, asks for (`keys`)
    /// rather than letting the control use it.
    func takeCanvasKey(_ event: NSEvent) -> Bool {
        // While an input method composes in the canvas or in the focused control over it, the keys are its own
        guard let canvas = canvas(of: window.firstResponder), !canvas.keys.isEmpty, !canvas.surface.hasMarkedText(),
              (window.firstResponder as? NSTextInputClient)?.hasMarkedText() != true else { return false }
        var modifiers = KeyModifiers()
        let flags = event.modifierFlags
        if flags.contains(.control) { modifiers.insert(.control) }
        if flags.contains(.shift) { modifiers.insert(.shift) }
        if flags.contains(.option) { modifiers.insert(.option) }
        if flags.contains(.command) { modifiers.insert(.command) }
        guard let press = KeyId.from(keyCode: event.keyCode, characters: event.characters, charactersIgnoringModifiers: event.charactersIgnoringModifiers, modifiers: modifiers),
              canvas.keys.contains(press.key) else { return false }
        send(.key(key: press.key, text: press.text))
        return true
    }

    private func firstResponderChanged() {
        guard !programmaticFocus, let node = reconciler.view(for: window.firstResponder) else { return }
        if node.id != lastFocused {
            lastFocused = node.id
            sendEvent(node.id, "focus", nil)
        }
    }

    func open(_ paths: [String]) {
        guard !paths.isEmpty else { return }
        if helloSent {
            send(.open(paths: paths))
            featureTest?.opened(paths)
        } else {
            pendingOpen += paths
        }
    }

    /// The effective appearance changed, or may have: only a real change goes out.
    func appearanceChanged() {
        guard helloSent, (manifest.window.theme ?? "system") == "system" else { return }
        let appearance = Self.appearance()
        guard appearance != sentAppearance else { return }
        sentAppearance = appearance
        send(.appearance(appearance))
    }

    static func appearance() -> String {
        NSApp.effectiveAppearance.bestMatch(from: [.darkAqua, .aqua]) == .darkAqua ? "dark" : "light"
    }

    // MARK: Closing

    func windowShouldClose(_ sender: NSWindow) -> Bool {
        if finished { return true }
        requestClose()
        return false
    }

    func windowWillClose(_ notification: Notification) {
        closeTimer?.invalidate()
        authTimer?.invalidate()
        responderObservation = nil
        dock.show(nil)
        featureTest?.windowClosed()
        onClosed?()
    }

    /// The close button, Cmd+W and Quit come here.
    func requestClose() {
        if finished {
            window.close()
            return
        }
        switch flow.closeRequested() {
        case .closeNow:
            process?.kill()
            if process?.isRunning != true { finish() }
        case .askApplication:
            send(.close)
            arm(CloseFlow.answerSeconds)
        default:
            break
        }
    }

    private func handle(_ step: CloseFlow.Step) {
        switch step {
        case .stopWaiting:
            closeTimer?.invalidate()
            closeTimer = nil
            selfTest?.closeRefused()
            canvasTest?.closeRefused()
        case .waitForExit:
            arm(CloseFlow.exitSeconds)
        case .offerForceQuit:
            offerForceQuit()
        case .closeNow:
            requestClose()
        case .askApplication, .wait:
            break
        }
    }

    private func arm(_ seconds: Double) {
        closeTimer?.invalidate()
        closeTimer = Timer.scheduledTimer(withTimeInterval: seconds, repeats: false) { [weak self] _ in
            MainActor.assumeIsolated {
                guard let self else { return }
                self.closeTimer = nil
                self.handle(self.flow.timedOut())
            }
        }
    }

    private func offerForceQuit() {
        let question = "\(manifest.app.name) is not responding. Force quit?"
        if let selfTest {
            return selfTest.fail(question)
        }
        if let canvasTest {
            return canvasTest.fail(question)
        }
        let alert = NSAlert()
        alert.messageText = question
        alert.informativeText = "Force quitting loses any unsaved changes."
        alert.addButton(withTitle: "Force Quit")
        alert.addButton(withTitle: "Wait")
        featureTest?.forceQuitOffered(alert)
        if alert.runModal() == .alertFirstButtonReturn {
            flow.forceQuit()
            if process?.isRunning == true {
                process?.kill()
            } else {
                finish()
            }
        } else {
            handle(flow.keepWaiting())
        }
    }

    private func processExited(_ status: Int32) {
        authTimer?.invalidate()
        closeTimer?.invalidate()
        let saidBye = flow.saidBye
        let exit = flow.exited(status: status)
        log.host("the application exited with status \(status)")
        connection?.stop()
        featureTest?.processExited(status: status, saidBye: saidBye, exit: exit)
        if let selfTest {
            selfTest.processExited(status: status, saidBye: saidBye, exit: exit, stderr: log.lastLines())
            return
        }
        if let canvasTest {
            canvasTest.processExited(status: status, saidBye: saidBye, exit: exit, stderr: log.lastLines())
            return
        }
        switch exit {
        case .finished:
            finish()
        case .failedBeforeConnecting:
            fail("\(manifest.app.name) stopped before it started (status \(status)).", detail: log.lastLines())
        case .failed:
            fail("\(manifest.app.name) stopped unexpectedly (status \(status)).", detail: log.lastLines())
        }
    }

    /// Closes the window for good.
    private func finish() {
        guard !finished else { return }
        finished = true
        process?.kill()
        connection?.stop()
        window.close()
    }

    /// An error the user must see: a dialog, then the window goes.
    func fail(_ message: String, detail: String?) {
        if let selfTest {
            return selfTest.fail(message + (detail.map { "\n" + $0 } ?? ""))
        }
        if let featureTest {
            return featureTest.fail(message + (detail.map { "\n" + $0 } ?? ""))
        }
        if let canvasTest {
            return canvasTest.fail(message + (detail.map { "\n" + $0 } ?? ""))
        }
        log.host(message + (detail.map { "\n" + $0 } ?? ""))
        let alert = NSAlert()
        alert.alertStyle = .critical
        alert.messageText = message
        if let detail, !detail.isEmpty {
            alert.informativeText = String(detail.suffix(2000))
        }
        alert.addButton(withTitle: "Quit")
        alert.runModal()
        finish()
    }

    /// For the self-test: the process, so it can be killed on failure.
    func killApplication() {
        process?.kill()
    }

    /// For the feature test: the application's process id, while it runs.
    var applicationPid: Int32? {
        process.map(\.pid)
    }
}
