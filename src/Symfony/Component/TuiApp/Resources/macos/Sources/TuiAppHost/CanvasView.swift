import AppKit
import CoreText
import TuiAppHostCore

/// The drawing surface of a `canvas`: `lines` of runs on a grid of canvas-font cells. It is an
/// input method client: what an input method composes is drawn at the cursor, what it commits
/// is typed.
final class CanvasSurface: NSView, NSMenuItemValidation, @preconcurrency NSTextInputClient {
    weak var node: CanvasNodeView?
    var lines: [[Style.Run]] = []
    var cursor: (row: Int, column: Int)?
    /// The cells of the controls shown over it, left undrawn: see CellBoxes.
    var covered = CellBoxes()
    var focusable = true
    /// What the input method is composing: drawn here only, never sent.
    private(set) var marked = ""
    private var markedSelection = NSRange(location: 0, length: 0)
    /// The key press being interpreted by the input method.
    private var interpreting: NSEvent?
    /// The cell the pointer was last sent at, so that a move is sent once per cell.
    private var pointerCell: (row: Int, column: Int)?
    private var wheel = WheelAccumulator()
    private var tracking: NSTrackingArea?

    override var isFlipped: Bool { true }
    override var acceptsFirstResponder: Bool { focusable }
    override var canBecomeKeyView: Bool { focusable }

    override func becomeFirstResponder() -> Bool {
        needsDisplay = true
        return true
    }

    override func resignFirstResponder() -> Bool {
        if !marked.isEmpty {
            inputContext?.discardMarkedText()
            setMarked("")
        }
        needsDisplay = true
        return true
    }

    // MARK: Pointer

    /// The left button held since a Control-click: the right one for the application, as
    /// Control-click is the gesture of context menus on macOS.
    private(set) var controlClick = false
    /// Where the mouse is, in the window, for the canvas test: else where it really is.
    var mouseLocationForTest: NSPoint?
    /// The right button is held, as the application was told (a right press or a Control-click):
    /// a context menu that opens meanwhile takes its release.
    private var rightHeld = false

    override func mouseDown(with event: NSEvent) {
        if event.modifierFlags.contains(.control) {
            controlClick = true
            rightHeld = true
            trackCursor(event)
            sendPointer("press", event, button: "right", dropping: .control)
            return
        }
        controlClick = false
        // Focused first: the host sends event focus before the press, like before
        if focusable { window?.makeFirstResponder(self) }
        trackCursor(event)
        sendPointer("press", event, button: "left")
    }

    override func mouseUp(with event: NSEvent) {
        if controlClick {
            controlClick = false
            rightHeld = false
            sendPointer("release", event, button: "right", dropping: .control)
        } else {
            sendPointer("release", event, button: "left")
        }
        released(event)
    }
    override func rightMouseDown(with event: NSEvent) {
        rightHeld = true
        sendPointer("press", event, button: "right")
    }
    override func rightMouseUp(with event: NSEvent) {
        rightHeld = false
        sendPointer("release", event, button: "right")
        released(event)
    }
    override func otherMouseDown(with event: NSEvent) { if event.buttonNumber == 2 { sendPointer("press", event, button: "middle") } }
    override func otherMouseUp(with event: NSEvent) {
        if event.buttonNumber == 2 { sendPointer("release", event, button: "middle") }
        released(event)
    }
    override func mouseDragged(with event: NSEvent) { sendMove(event, button: controlClick ? "right" : "left") }
    override func rightMouseDragged(with event: NSEvent) { sendMove(event, button: "right") }
    override func otherMouseDragged(with event: NSEvent) { sendMove(event, button: event.buttonNumber == 2 ? "middle" : nil) }
    /// The tracking area hands the surface the moves over the controls placed on it too: those
    /// are the controls', and moving onto one leaves the canvas.
    override func mouseMoved(with event: NSEvent) {
        trackCursor(event)
        if pointerOver {
            sendMove(event, button: nil)
        } else {
            sendLeave(event)
        }
    }

    /// A release off the surface (past the canvas's edge or over a control placed on it, at the
    /// end of a drag) with no other button held: the pointer left it.
    private func released(_ event: NSEvent) {
        trackCursor(event)
        if !pointerOver, NSEvent.pressedMouseButtons & ~(1 << event.buttonNumber) == 0 {
            sendLeave(event)
        }
    }

    override func mouseEntered(with event: NSEvent) { trackCursor(event) }

    override func cursorUpdate(with event: NSEvent) {
        trackCursor(event)
        if !pointerOver { super.cursorUpdate(with: event) }
    }

    override func mouseExited(with event: NSEvent) {
        // While a drag goes on past the edge, its pointer stays; the release puts it back
        if NSEvent.pressedMouseButtons == 0 {
            pointerOver = false
            restoreCursor()
        }
        // A drag goes on past the edge: its moves and its release are still the canvas's
        guard NSEvent.pressedMouseButtons == 0 else { return }
        sendLeave(event)
    }

    /// `leave` at the cell last sent, once.
    private func sendLeave(_ event: NSEvent) { sendLeave(modifiers: Self.modifiers(of: event)) }

    private func sendLeave(modifiers: [String]) {
        guard let cell = pointerCell else { return }
        pointerCell = nil
        node?.host?.sendPointer(node?.id ?? 0, Pointer(action: "leave", row: cell.row, column: cell.column, modifiers: modifiers))
    }

    override func scrollWheel(with event: NSEvent) {
        guard let host = node?.host else { return }
        if event.phase == .began { wheel.reset() }
        guard let delta = wheel.add(deltaX: Double(event.scrollingDeltaX), deltaY: Double(event.scrollingDeltaY), precise: event.hasPreciseScrollingDeltas,
                                    cellWidth: Double(host.cell.width), cellHeight: Double(host.cell.height)) else { return }
        let cell = cell(of: event)
        host.sendPointer(node?.id ?? 0, Pointer(action: "wheel", row: cell.row, column: cell.column, modifiers: Self.modifiers(of: event), rows: delta.rows, columns: delta.columns))
    }

    override func updateTrackingAreas() {
        super.updateTrackingAreas()
        if let tracking { removeTrackingArea(tracking) }
        let area = NSTrackingArea(rect: .zero, options: [.mouseMoved, .mouseEnteredAndExited, .cursorUpdate, .activeInKeyWindow, .inVisibleRect], owner: self, userInfo: nil)
        addTrackingArea(area)
        tracking = area
    }

    // MARK: Cursor

    /// The pointer the application asked for (`cursor`), kept while the mouse is away and shown
    /// again when it comes back.
    private(set) var cursorShape = CursorShape.arrow
    /// The mouse is over the surface itself, not a control placed on it, as the last event said.
    private(set) var pointerOver = false
    /// The NSCursor the surface set, while it shows its own.
    private(set) var shownCursor: NSCursor?

    func setCursorShape(_ shape: CursorShape) {
        cursorShape = shape
        if pointerOver { showCursor() }
    }

    /// Nothing tells the surface the mouse left it when it is hidden, goes from its window, or the
    /// window stops being the key one (its tracking area then sends nothing): it lets go of its pointer.
    func letGoOfCursor() {
        pointerOver = false
        restoreCursor()
    }

    private var keyObserver: NSObjectProtocol?

    override func viewWillMove(toWindow newWindow: NSWindow?) {
        if newWindow !== window { letGoOfCursor() }
        super.viewWillMove(toWindow: newWindow)
    }

    override func viewDidMoveToWindow() {
        super.viewDidMoveToWindow()
        if let keyObserver { NotificationCenter.default.removeObserver(keyObserver) }
        keyObserver = window.map {
            NotificationCenter.default.addObserver(forName: NSWindow.didResignKeyNotification, object: $0, queue: .main) { [weak self] _ in
                MainActor.assumeIsolated { self?.letGoOfCursor() }
            }
        }
    }

    override func viewDidHide() {
        letGoOfCursor()
        super.viewDidHide()
    }

    /// Over the surface, its pointer; over a control placed on it, the control's. A drag keeps it
    /// past the edge until the release (not called while dragging).
    private func trackCursor(_ event: NSEvent) {
        let over = isSurface(at: event.locationInWindow)
        pointerOver = over
        if over {
            showCursor()
        } else {
            restoreCursor()
        }
    }

    private func isSurface(at point: NSPoint) -> Bool {
        guard let content = window?.contentView else { return false }
        return content.hitTest(content.superview?.convert(point, from: nil) ?? point) === self
    }

    private func showCursor() {
        let cursor = Self.cursor(for: cursorShape)
        if NSCursor.current !== cursor { cursor.set() }
        shownCursor = cursor
    }

    /// The window's own pointer again, unless something else set one since (a text field placed
    /// on the canvas, with its I-beam).
    private func restoreCursor() {
        guard let shown = shownCursor else { return }
        shownCursor = nil
        if NSCursor.current === shown { NSCursor.arrow.set() }
    }

    /// `none`: a cursor with nothing drawn, which leaves with the surface like any other, where
    /// NSCursor.hide() would hide it until the mouse moves, wherever it goes.
    static let blankCursor = NSCursor(image: NSImage(size: NSSize(width: 16, height: 16), flipped: false) { _ in true }, hotSpot: .zero)

    static func cursor(for shape: CursorShape) -> NSCursor {
        switch shape {
        case .arrow: return .arrow
        case .hidden: return blankCursor
        case .pointingHand: return .pointingHand
        case .iBeam: return .iBeam
        case .verticalIBeam: return .iBeamCursorForVerticalLayout
        case .crosshair: return .crosshair
        case .openHand: return .openHand
        case .closedHand: return .closedHand
        case .notAllowed: return .operationNotAllowed
        case .dragCopy: return .dragCopy
        case .dragLink: return .dragLink
        case .contextualMenu: return .contextualMenu
        case .zoomIn:
            if #available(macOS 15, *) { return .zoomIn }
            return .arrow
        case .zoomOut:
            if #available(macOS 15, *) { return .zoomOut }
            return .arrow
        case .columnResize:
            if #available(macOS 15, *) { return .columnResize }
            return .resizeLeftRight
        case .rowResize:
            if #available(macOS 15, *) { return .rowResize }
            return .resizeUpDown
        case let .frameResize(edge):
            if #available(macOS 15, *) {
                return frameResizeCursors[edge] ?? .arrow
            }
            return edge.isHorizontal ? .resizeLeftRight : edge.isVertical ? .resizeUpDown : .arrow
        }
    }

    /// Made once: the same NSCursor each time, as NSCursor.current is compared by identity.
    @available(macOS 15, *)
    private static let frameResizeCursors: [CursorShape.FrameEdge: NSCursor] = {
        let positions: [CursorShape.FrameEdge: NSCursor.FrameResizePosition] = [
            .top: .top, .left: .left, .bottom: .bottom, .right: .right,
            .topLeft: .topLeft, .topRight: .topRight, .bottomLeft: .bottomLeft, .bottomRight: .bottomRight,
        ]
        return positions.mapValues { NSCursor.frameResize(position: $0, directions: .all) }
    }()

    // MARK: Context menu

    /// The `contextmenu` shown, until it closes.
    private(set) var contextMenu: CanvasContextMenu?

    /// Shown at the cell, as the native context menu. Only while the pointer is on the canvas (the
    /// press that asked for it was the last thing sent), and one at a time: else ignored.
    func showContextMenu(_ items: [ContextMenuItem], row: Int, column: Int, send: @escaping @MainActor (Message) -> Void) {
        guard contextMenu == nil, pointerCell != nil, window != nil else { return }
        let menu = CanvasContextMenu(items: items, send: send)
        menu.opened = { [weak self] in self?.menuTookTheRelease() }
        contextMenu = menu
        let cell = node?.host?.cell ?? CellSize(width: 8, height: 16)
        let at = NSPoint(x: CGFloat(column) * cell.width, y: CGFloat(row) * cell.height)
        // Not in the handler of the message: popUp tracks the menu until it closes
        DispatchQueue.main.async { [weak self] in
            MainActor.assumeIsolated {
                guard let self else { return }
                menu.menu.popUp(positioning: nil, at: at, in: self)
                self.contextMenu = nil
                self.menuClosed()
            }
        }
    }

    /// The menu opened with the right button held: its tracking takes the release, which the
    /// application is told now, and the left button of a Control-click is the left one again.
    private func menuTookTheRelease() {
        guard rightHeld else { return }
        rightHeld = false
        let dropping: NSEvent.ModifierFlags = controlClick ? .control : []
        controlClick = false
        // Left already (a leave was sent): no cell to send the release at
        guard let cell = pointerCell else { return }
        node?.host?.sendPointer(node?.id ?? 0, Pointer(action: "release", row: cell.row, column: cell.column, button: "right", modifiers: Self.modifiers(NSEvent.modifierFlags.subtracting(dropping))))
    }

    /// After a menu: `leave` (if a cell was sent) when it closed with the pointer off the surface,
    /// as its tracking took the moves, the exit and any release that would have told. From where
    /// the pointer is alone: a click outside the menu closes it with the button still down.
    private func menuClosed() {
        guard let window else { return }
        if !isSurface(at: mouseLocationForTest ?? window.mouseLocationOutsideOfEventStream) {
            pointerOver = false
            restoreCursor()
            sendLeave(modifiers: Self.modifiers(NSEvent.modifierFlags))
        }
    }

    private func cell(of event: NSEvent) -> (row: Int, column: Int) {
        let point = convert(event.locationInWindow, from: nil)
        let size = node?.host?.cell ?? CellSize(width: 8, height: 16)
        return Pointer.cell(x: Double(point.x), y: Double(point.y), cellWidth: Double(size.width), cellHeight: Double(size.height))
    }

    private func sendPointer(_ action: String, _ event: NSEvent, button: String, dropping: NSEvent.ModifierFlags = []) {
        let cell = cell(of: event)
        pointerCell = cell
        node?.host?.sendPointer(node?.id ?? 0, Pointer(action: action, row: cell.row, column: cell.column, button: button, modifiers: Self.modifiers(of: event, dropping: dropping)))
    }

    /// A move to another cell; within a cell, nothing.
    private func sendMove(_ event: NSEvent, button: String?) {
        let cell = cell(of: event)
        if let last = pointerCell, last == cell { return }
        pointerCell = cell
        node?.host?.sendPointer(node?.id ?? 0, Pointer(action: "move", row: cell.row, column: cell.column, button: button, modifiers: Self.modifiers(of: event, dropping: controlClick ? .control : [])))
    }

    static func modifiers(of event: NSEvent, dropping: NSEvent.ModifierFlags = []) -> [String] {
        modifiers(event.modifierFlags.subtracting(dropping))
    }

    static func modifiers(_ flags: NSEvent.ModifierFlags) -> [String] {
        var modifiers = KeyModifiers()
        if flags.contains(.control) { modifiers.insert(.control) }
        if flags.contains(.shift) { modifiers.insert(.shift) }
        if flags.contains(.option) { modifiers.insert(.option) }
        if flags.contains(.command) { modifiers.insert(.command) }
        return Pointer.modifiers(modifiers)
    }

    override func keyDown(with event: NSEvent) {
        // Tab and Shift+Tab move the native focus, even out of a canvas.
        if event.keyCode == 48, event.modifierFlags.intersection([.command, .control, .option]).isEmpty {
            if event.modifierFlags.contains(.shift) {
                window?.selectPreviousKeyView(nil)
            } else {
                window?.selectNextKeyView(nil)
            }
            return
        }
        // Control and Command keys are the application's; the others go through the input method,
        // which composes (dead keys, Chinese or Japanese) or hands them back.
        if marked.isEmpty, !event.modifierFlags.intersection([.command, .control]).isEmpty {
            node?.host?.forwardKey(event)
            return
        }
        interpreting = event
        interpretKeyEvents([event])
        interpreting = nil
    }

    // MARK: Input method

    private func setMarked(_ text: String) {
        marked = text
        needsDisplay = true
    }

    /// Text the input method commits, or a key it typed as is.
    func insertText(_ string: Any, replacementRange: NSRange) {
        let text = (string as? NSAttributedString)?.string ?? (string as? String) ?? ""
        let composed = !marked.isEmpty
        setMarked("")
        // A key that types its character: sent as the key, with its id, like before input methods
        if !composed, let event = interpreting, event.characters == text {
            node?.host?.forwardKey(event)
            return
        }
        node?.host?.sendCommitted(text)
    }

    /// A key the input method has no text for (arrows, Enter, Backspace…): the application's.
    override func doCommand(by selector: Selector) {
        if let event = interpreting {
            node?.host?.forwardKey(event)
        }
    }

    func setMarkedText(_ string: Any, selectedRange: NSRange, replacementRange: NSRange) {
        setMarked((string as? NSAttributedString)?.string ?? (string as? String) ?? "")
        markedSelection = selectedRange
    }

    func unmarkText() {
        // What was composed is taken as it is
        let text = marked
        setMarked("")
        if !text.isEmpty {
            node?.host?.sendCommitted(text)
        }
    }

    func hasMarkedText() -> Bool { !marked.isEmpty }

    func markedRange() -> NSRange {
        marked.isEmpty ? NSRange(location: NSNotFound, length: 0) : NSRange(location: 0, length: (marked as NSString).length)
    }

    func selectedRange() -> NSRange {
        marked.isEmpty ? NSRange(location: 0, length: 0) : markedSelection
    }

    func validAttributesForMarkedText() -> [NSAttributedString.Key] { [.underlineStyle] }

    func attributedSubstring(forProposedRange range: NSRange, actualRange: NSRangePointer?) -> NSAttributedString? {
        let text = marked as NSString
        guard range.location != NSNotFound, NSMaxRange(range) <= text.length else { return nil }
        actualRange?.pointee = range
        return NSAttributedString(string: text.substring(with: range))
    }

    func characterIndex(for point: NSPoint) -> Int { NSNotFound }

    /// The cell the input method composes at, for its candidate window.
    func firstRect(forCharacterRange range: NSRange, actualRange: NSRangePointer?) -> NSRect {
        guard let window, let cell = node?.host?.cell else { return .zero }
        let origin = cursor ?? (0, 0)
        let rect = NSRect(x: CGFloat(origin.column) * cell.width, y: CGFloat(origin.row) * cell.height, width: cell.width, height: cell.height)
        return window.convertToScreen(convert(rect, to: nil))
    }

    /// No text, or an empty one: nothing to paste, as in a context menu.
    @objc func paste(_ sender: Any?) {
        if let text = NSPasteboard.general.string(forType: .string), !text.isEmpty {
            node?.host?.sendPaste(text)
        }
    }

    func validateMenuItem(_ item: NSMenuItem) -> Bool {
        if item.action == #selector(paste(_:)) {
            return NSPasteboard.general.string(forType: .string)?.isEmpty == false
        }
        return true
    }

    override func draw(_ dirtyRect: NSRect) {
        guard let node, let host = node.host, let context = NSGraphicsContext.current?.cgContext else { return }
        let font = host.canvasFont
        let cell = host.cell
        let cw = CGFloat(cell.width)
        let ch = CGFloat(cell.height)
        let defaultFG = Style.color(node.style["fg"]) ?? .textColor
        let defaultBG = Style.color(node.style["bg"]) ?? .textBackgroundColor
        defaultBG.setFill()
        bounds.fill()
        let ascent = CTFontGetAscent(font as CTFont)
        let descent = CTFontGetDescent(font as CTFont)
        let baselineOffset = (ch - (ascent + descent)) / 2 + ascent

        for (row, runs) in lines.enumerated() {
            let top = CGFloat(row) * ch
            guard top < dirtyRect.maxY, top + ch > dirtyRect.minY else { continue }
            var column = 0
            for run in runs {
                let colors = run.colors(fg: defaultFG, bg: defaultBG)
                let runFont = Style.font(font, bold: run.bold, italic: run.italic)
                for character in run.text {
                    let width = Self.cellWidth(of: character)
                    if covered.contains(row: row, column: column) {
                        column += width
                        continue
                    }
                    let rect = NSRect(x: CGFloat(column) * cw, y: top, width: CGFloat(width) * cw, height: ch)
                    if let bg = colors.bg {
                        bg.setFill()
                        rect.fill()
                    }
                    if character != " " {
                        let text = NSAttributedString(string: String(character), attributes: [.font: runFont, .foregroundColor: colors.fg])
                        let line = CTLineCreateWithAttributedString(text)
                        context.saveGState()
                        // CoreText draws upward: flip back around the baseline.
                        context.textMatrix = .identity
                        context.translateBy(x: rect.minX, y: top + baselineOffset)
                        context.scaleBy(x: 1, y: -1)
                        context.textPosition = .zero
                        CTLineDraw(line, context)
                        context.restoreGState()
                    }
                    if run.underline || run.strike {
                        colors.fg.setFill()
                        if run.underline {
                            NSRect(x: rect.minX, y: top + baselineOffset + 1.5, width: rect.width, height: 1).fill()
                        }
                        if run.strike {
                            NSRect(x: rect.minX, y: top + baselineOffset - ascent * 0.35, width: rect.width, height: 1).fill()
                        }
                    }
                    column += width
                }
            }
        }

        if !marked.isEmpty {
            drawMarked(context: context, font: font, cell: cell, fg: defaultFG, bg: defaultBG, baselineOffset: baselineOffset)
        } else if let cursor {
            let rect = NSRect(x: CGFloat(cursor.column) * cw, y: CGFloat(cursor.row) * ch, width: cw, height: ch)
            let focused = window?.firstResponder === self && window?.isKeyWindow == true
            NSColor.controlAccentColor.withAlphaComponent(focused ? 0.6 : 0.9).set()
            if focused {
                rect.fill(using: .sourceOver)
            } else {
                NSBezierPath(rect: rect.insetBy(dx: 0.5, dy: 0.5)).stroke()
            }
        }
    }

    /// What the input method composes, underlined, from the cursor cell over the lines.
    private func drawMarked(context: CGContext, font: NSFont, cell: CellSize, fg: NSColor, bg: NSColor, baselineOffset: CGFloat) {
        let origin = cursor ?? (0, 0)
        var column = origin.column
        let top = CGFloat(origin.row) * CGFloat(cell.height)
        for character in marked {
            let width = Self.cellWidth(of: character)
            let rect = NSRect(x: CGFloat(column) * CGFloat(cell.width), y: top, width: CGFloat(width) * CGFloat(cell.width), height: CGFloat(cell.height))
            bg.setFill()
            rect.fill()
            let line = CTLineCreateWithAttributedString(NSAttributedString(string: String(character), attributes: [.font: font, .foregroundColor: fg]))
            context.saveGState()
            context.textMatrix = .identity
            context.translateBy(x: rect.minX, y: top + baselineOffset)
            context.scaleBy(x: 1, y: -1)
            context.textPosition = .zero
            CTLineDraw(line, context)
            context.restoreGState()
            fg.setFill()
            NSRect(x: rect.minX, y: top + baselineOffset + 1.5, width: rect.width, height: 1).fill()
            column += width
        }
    }

    /// East Asian wide characters and emoji take two cells.
    static func cellWidth(of character: Character) -> Int {
        guard let scalar = character.unicodeScalars.first else { return 1 }
        if character.unicodeScalars.contains(where: { $0.properties.isEmojiPresentation }) { return 2 }
        switch scalar.value {
        case 0x1100...0x115F, 0x2E80...0x303E, 0x3041...0x33FF, 0x3400...0x4DBF, 0x4E00...0x9FFF,
             0xA000...0xA4CF, 0xAC00...0xD7A3, 0xF900...0xFAFF, 0xFE30...0xFE4F, 0xFF00...0xFF60,
             0xFFE0...0xFFE6, 0x20000...0x3FFFD:
            return 2
        default:
            return 1
        }
    }

    override func viewDidChangeEffectiveAppearance() {
        super.viewDidChangeEffectiveAppearance()
        needsDisplay = true
        node?.host?.setNeedsRelayout()
    }
}

/// The NSMenu of a `contextmenu`: an item sends `contextmenu-select` with its id, or, with role
/// `paste`, the text of the clipboard as `paste` (nothing when it holds none, or an empty one).
/// Dismissed, nothing.
@MainActor
final class CanvasContextMenu: NSObject, NSMenuDelegate {
    let menu = NSMenu()
    private(set) var isOpen = false
    private let items: [ContextMenuItem]
    private let send: @MainActor (Message) -> Void

    init(items: [ContextMenuItem], send: @escaping @MainActor (Message) -> Void) {
        self.items = items
        self.send = send
        super.init()
        menu.autoenablesItems = false
        menu.delegate = self
        for item in items {
            if item.separator {
                menu.addItem(.separator())
                continue
            }
            let menuItem = NSMenuItem(title: item.label ?? "", action: #selector(choose(_:)), keyEquivalent: "")
            menuItem.target = self
            menuItem.isEnabled = item.enabled ?? true
            menu.addItem(menuItem)
        }
    }

    @objc func choose(_ sender: NSMenuItem) {
        let index = menu.index(of: sender)
        guard items.indices.contains(index) else { return }
        let item = items[index]
        if item.role == "paste" {
            // No text, or an empty one: nothing to paste
            if let text = NSPasteboard.general.string(forType: .string), !text.isEmpty { send(.paste(text: text)) }
        } else if let id = item.id {
            send(.contextMenuSelect(id: id))
        }
    }

    /// When it opens: the surface hands over the release the menu's tracking takes.
    var opened: (@MainActor () -> Void)?

    func menuWillOpen(_ menu: NSMenu) {
        isOpen = true
        opened?()
    }
    func menuDidClose(_ menu: NSMenu) { isOpen = false }
}

/// Holds a control placed over a canvas, and cuts it to its `clip`.
final class ClipView: FlippedView {
    override init(frame: NSRect) {
        super.init(frame: frame)
        wantsLayer = true
    }

    @available(*, unavailable)
    required init?(coder: NSCoder) { fatalError() }
}

/// `canvas`: lines of runs in the canvas font on a cell grid, a cursor, and every key sent as `key`.
/// Its children are native controls placed over it (`place`, `clip`).
final class CanvasNodeView: NodeView {
    let surface = CanvasSurface()
    private var clips: [Int: ClipView] = [:]
    /// The keys it and its controls send rather than use.
    var keys: Set<String> { Set(props["keys"]?.arrayValue?.compactMap(\.stringValue) ?? []) }

    override init(id: Int, kind: String, host: NodeHost) {
        super.init(id: id, kind: kind, host: host)
        surface.node = self
        addSubview(surface)
    }

    override var nativeView: NSView { surface }
    override var focusTarget: NSView? { surface.focusable ? surface : nil }
    override var stretches: Bool { true }

    override func apply(old: [String: JSON]) {
        surface.lines = (props["lines"]?.arrayValue ?? []).map { ($0.arrayValue ?? []).map(Style.Run.init) }
        if let cursor = props["cursor"]?.arrayValue?.compactMap(\.intValue), cursor.count == 2 {
            surface.cursor = (cursor[0], cursor[1])
        } else {
            surface.cursor = nil
        }
        surface.focusable = isFocusable && !isDisabled
        surface.setAccessibilityRole(.textArea)
        surface.setAccessibilityElement(true)
        surface.setAccessibilityValue(surface.lines.map { $0.map(\.text).joined() }.joined(separator: "\n"))
        surface.needsDisplay = true
    }

    override func contentSize(width: CGFloat?) -> NSSize {
        let columns = props["columns"]?.intValue ?? (surface.lines.map { $0.reduce(0) { $0 + $1.text.count } }.max() ?? 1)
        let rows = max(1, surface.lines.count)
        return NSSize(width: width ?? CGFloat(columns) * cell.width, height: CGFloat(rows) * cell.height)
    }

    override func setChildren(_ views: [NodeView]) {
        super.setChildren(views)
        let ids = Set(views.map(\.id))
        for (id, clip) in clips where !ids.contains(id) {
            clip.removeFromSuperview()
            clips[id] = nil
        }
        for view in views {
            let clip = clips[view.id] ?? ClipView()
            clips[view.id] = clip
            if clip.superview !== self {
                addSubview(clip, positioned: .above, relativeTo: surface)
            }
            if view.superview !== clip {
                view.removeFromSuperview()
                clip.addSubview(view)
            }
            view.placed = true
        }
    }

    /// A box of cells of the canvas, `[row, column, columns, rows]`, in points from the surface.
    private func cellRect(_ json: JSON?, in rect: NSRect) -> NSRect? {
        guard let box = json?.arrayValue?.compactMap(\.intValue), box.count == 4 else { return nil }
        return NSRect(x: rect.minX + CGFloat(box[1]) * cell.width, y: rect.minY + CGFloat(box[0]) * cell.height,
                      width: CGFloat(max(0, box[2])) * cell.width, height: CGFloat(max(0, box[3])) * cell.height)
    }

    override func layoutContent(_ rect: NSRect) {
        surface.frame = rect
        surface.needsDisplay = true
        var covered: [[Int]] = []
        defer { surface.covered = CellBoxes(covered) }
        for child in nodeChildren {
            guard let clip = clips[child.id] else { continue }
            // A hidden control leaves the canvas seen: its opaque box goes with it
            guard !child.isNodeHidden, let frame = cellRect(child.props["place"], in: rect) else {
                clip.isHidden = true
                continue
            }
            let shown = cellRect(child.props["clip"], in: rect)
            // Clipped to nothing: not shown, and Tab skips it
            if let shown, shown.width <= 0 || shown.height <= 0 {
                clip.isHidden = true
                continue
            }
            covered.append((child.props["clip"] ?? child.props["place"])?.arrayValue?.compactMap(\.intValue) ?? [])
            clip.isHidden = false
            // Opaque, in the colour of the canvas: what the canvas draws under the control does not show through
            effectiveAppearance.performAsCurrentDrawingAppearance {
                clip.layer?.backgroundColor = (Style.color(self.style["bg"]) ?? .textBackgroundColor).cgColor
            }
            // On the cells, not rounded to points: the canvas draws its cells where they fall
            clip.frame = shown ?? frame
            clip.layer?.masksToBounds = shown != nil
            child.frame = frame.offsetBy(dx: -clip.frame.minX, dy: -clip.frame.minY)
            child.layoutTree()
        }
    }
}
