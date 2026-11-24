import AppKit
import TuiAppHostCore

/// What a node view needs from its window.
@MainActor
protocol NodeHost: AnyObject {
    var cell: CellSize { get }
    var canvasFont: NSFont { get }
    func sendEvent(_ node: Int, _ name: String, _ value: JSON?)
    /// A key the focused control did not use, or any key in a canvas.
    func forwardKey(_ event: NSEvent)
    func sendPaste(_ text: String)
    /// Text an input method committed in a canvas: typed one key per character.
    func sendCommitted(_ text: String)
    /// What the mouse does on the surface of a canvas (`pointer`).
    func sendPointer(_ node: Int, _ pointer: Pointer)
    /// Something changed size locally (a disclosure opened, a tab switched).
    func setNeedsRelayout()
}

/// One node of the tree: a flipped view that holds the native control, lays it out, and tells
/// its parent how much room it needs. Padding and border are handled here for every kind.
@MainActor
class NodeView: NSView {
    let id: Int
    let kind: String
    weak var host: NodeHost?
    private(set) var props: [String: JSON] = [:]
    private(set) var nodeChildren: [NodeView] = []

    init(id: Int, kind: String, host: NodeHost) {
        self.id = id
        self.kind = kind
        self.host = host
        super.init(frame: NSRect(x: 0, y: 0, width: 100, height: 20))
        wantsLayer = true
        layerContentsRedrawPolicy = .onSetNeedsDisplay
        setAccessibilityElement(false)
    }

    @available(*, unavailable)
    required init?(coder: NSCoder) { fatalError() }

    override var isFlipped: Bool { true }

    // MARK: Props

    var grow: Int { max(0, props["grow"]?.intValue ?? 0) }
    var isNodeHidden: Bool { props["hidden"]?.boolValue == true }
    var isDisabled: Bool { props["disabled"]?.boolValue == true }
    var isFocusable: Bool { props["focusable"]?.boolValue != false }
    var style: [String: JSON] { props["style"]?.objectValue ?? [:] }
    var cell: CellSize { host?.cell ?? CellSize(width: 8, height: 16) }

    /// The AppKit view that stands for this node: what `--self-test` checks exists.
    var nativeView: NSView { self }
    /// What takes the keyboard focus for this node.
    var focusTarget: NSView? { nil }
    /// What Tab goes through for this node, before its children: see HostWindowController.keyViewLoop.
    var keyViews: [NSView] { focusTarget.map { [$0] } ?? [] }
    /// In a horizontal stack, whether the node takes the row's whole height.
    var stretches: Bool { false }
    /// Whether it is a control placed over a canvas, which fills its place in the canvas font.
    var placed = false {
        didSet { if placed != oldValue { placedChanged() } }
    }

    func placedChanged() {}

    func update(props: [String: JSON]) {
        let old = self.props
        self.props = props
        isHidden = isNodeHidden
        if let bg = Style.color(style["bg"]) {
            layer?.backgroundColor = bg.cgColor
        } else {
            layer?.backgroundColor = nil
        }
        updateBorder()
        if let label = props["label"]?.stringValue {
            (focusTarget ?? nativeView).setAccessibilityLabel(label)
        }
        apply(old: old)
        if let target = focusTarget {
            (target as? NSControl)?.refusesFirstResponder = !isFocusable
        }
        needsDisplay = true
    }

    /// The props once the user changed one on the control, which shows it already.
    func acknowledge(_ props: [String: JSON]) {
        self.props = props
    }

    /// Kind-specific props. `old` is what they were before, to skip work that is not needed.
    func apply(old: [String: JSON]) {}

    func setChildren(_ views: [NodeView]) {
        nodeChildren = views
    }

    private func updateBorder() {
        if props["border"]?.boolValue == true {
            layer?.borderWidth = 1
            layer?.cornerRadius = 6
            effectiveAppearance.performAsCurrentDrawingAppearance {
                layer?.borderColor = NSColor.separatorColor.cgColor
            }
        } else {
            layer?.borderWidth = 0
            layer?.cornerRadius = 0
        }
    }

    override func viewDidChangeEffectiveAppearance() {
        super.viewDidChangeEffectiveAppearance()
        updateBorder()
    }

    // MARK: Layout

    /// Padding (`[top, right, bottom, left]` in cells) and the border.
    var insets: NSEdgeInsets {
        let p = props["padding"]?.arrayValue?.compactMap(\.intValue) ?? []
        let border: CGFloat = props["border"]?.boolValue == true ? 4 : 0
        guard p.count == 4 else { return NSEdgeInsets(top: border, left: border, bottom: border, right: border) }
        return NSEdgeInsets(
            top: CGFloat(p[0]) * cell.height + border, left: CGFloat(p[3]) * cell.width + border,
            bottom: CGFloat(p[2]) * cell.height + border, right: CGFloat(p[1]) * cell.width + border
        )
    }

    /// The size this node wants: its height for a given width, or its natural size when the
    /// width is nil. Padding and border included.
    final func measure(width: CGFloat?) -> NSSize {
        if isNodeHidden { return .zero }
        let i = insets
        let inner = contentSize(width: width.map { max(0, $0 - i.left - i.right) })
        return NSSize(width: ceil(inner.width + i.left + i.right), height: ceil(inner.height + i.top + i.bottom))
    }

    func contentSize(width: CGFloat?) -> NSSize { .zero }

    /// Lays out this node's content and then its children, recursively.
    final func layoutTree() {
        let i = insets
        let rect = NSRect(x: i.left, y: i.top, width: max(0, bounds.width - i.left - i.right), height: max(0, bounds.height - i.top - i.bottom))
        layoutContent(rect)
    }

    func layoutContent(_ rect: NSRect) {}

    /// Sets a child's frame and lays it out.
    func place(_ child: NodeView, _ frame: NSRect) {
        child.frame = frame.integral
        child.layoutTree()
    }
}

/// A plain flipped view.
class FlippedView: NSView {
    override var isFlipped: Bool { true }
}

/// Colours, fonts and attributed text from the protocol's styles and runs.
@MainActor
enum Style {
    nonisolated static func color(_ json: JSON?) -> NSColor? {
        guard let rgb = RGB(hex: json?.stringValue) else { return nil }
        return NSColor(srgbRed: rgb.red, green: rgb.green, blue: rgb.blue, alpha: 1)
    }

    static func font(_ base: NSFont, bold: Bool, italic: Bool) -> NSFont {
        var font = base
        if bold { font = NSFontManager.shared.convert(font, toHaveTrait: .boldFontMask) }
        if italic {
            let converted = NSFontManager.shared.convert(font, toHaveTrait: .italicFontMask)
            if converted.fontDescriptor.symbolicTraits.contains(.italic) {
                font = converted
            } else {
                // No italic face (SF Mono has none at some sizes): slant the regular one.
                let matrix = AffineTransform(m11: 1, m12: 0, m21: 0.2, m22: 1, tX: 0, tY: 0)
                font = NSFont(descriptor: font.fontDescriptor.withMatrix(matrix), size: 0) ?? font
            }
        }
        return font
    }

    struct Run {
        var text: String
        var fg: NSColor?
        var bg: NSColor?
        var bold = false
        var dim = false
        var italic = false
        var underline = false
        var strike = false
        var reverse = false

        init(_ json: JSON) {
            text = json["t"]?.stringValue ?? ""
            fg = Style.color(json["fg"])
            bg = Style.color(json["bg"])
            bold = json["b"]?.boolValue == true
            dim = json["d"]?.boolValue == true
            italic = json["i"]?.boolValue == true
            underline = json["u"]?.boolValue == true
            strike = json["s"]?.boolValue == true
            reverse = json["r"]?.boolValue == true
        }

        /// A whole-label style (`style`) as one run.
        init(text: String, style: [String: JSON]) {
            self.text = text
            fg = Style.color(style["fg"])
            bg = nil
            bold = style["bold"]?.boolValue == true
            dim = style["dim"]?.boolValue == true
            italic = style["italic"]?.boolValue == true
            underline = style["underline"]?.boolValue == true
            strike = style["strike"]?.boolValue == true
        }

        /// Foreground and background after `reverse`, with the defaults filled in.
        func colors(fg defaultFG: NSColor, bg defaultBG: NSColor) -> (fg: NSColor, bg: NSColor?) {
            var f = fg ?? defaultFG
            var b = bg
            if reverse {
                (f, b) = (bg ?? defaultBG, fg ?? defaultFG)
            }
            if dim { f = f.withAlphaComponent(0.6) }
            return (f, b)
        }
    }

    static func attributed(_ runs: [Run], font base: NSFont, fg: NSColor = .labelColor, bg: NSColor = .textBackgroundColor, alignment: NSTextAlignment = .natural) -> NSAttributedString {
        let paragraph = NSMutableParagraphStyle()
        paragraph.alignment = alignment
        paragraph.lineBreakMode = .byWordWrapping
        let out = NSMutableAttributedString()
        for run in runs {
            let colors = run.colors(fg: fg, bg: bg)
            var attributes: [NSAttributedString.Key: Any] = [
                .font: font(base, bold: run.bold, italic: run.italic),
                .foregroundColor: colors.fg,
                .paragraphStyle: paragraph,
            ]
            if let b = colors.bg { attributes[.backgroundColor] = b }
            if run.underline { attributes[.underlineStyle] = NSUnderlineStyle.single.rawValue }
            if run.strike { attributes[.strikethroughStyle] = NSUnderlineStyle.single.rawValue }
            out.append(NSAttributedString(string: run.text, attributes: attributes))
        }
        return out
    }

    static func secondaryLabel(_ text: String = "") -> NSTextField {
        let label = NSTextField(wrappingLabelWithString: text)
        label.font = .systemFont(ofSize: NSFont.smallSystemFontSize)
        label.textColor = .secondaryLabelColor
        label.isSelectable = false
        return label
    }

    /// Height of a wrapping text field at a width.
    static func height(of field: NSTextField, width: CGFloat) -> CGFloat {
        guard !field.stringValue.isEmpty, let cell = field.cell else { return 0 }
        return ceil(cell.cellSize(forBounds: NSRect(x: 0, y: 0, width: max(width, 1), height: 100_000)).height)
    }

    static func naturalWidth(of field: NSTextField) -> CGFloat {
        guard !field.stringValue.isEmpty, let cell = field.cell else { return 0 }
        return ceil(cell.cellSize(forBounds: NSRect(x: 0, y: 0, width: 100_000, height: 100_000)).width)
    }
}

/// A kind this host does not know: an empty box.
final class UnknownNodeView: NodeView {
    override init(id: Int, kind: String, host: NodeHost) {
        super.init(id: id, kind: kind, host: host)
        layer?.borderWidth = 1
        layer?.cornerRadius = 4
        layer?.borderColor = NSColor.tertiaryLabelColor.cgColor
        toolTip = kind
    }

    override var stretches: Bool { true }

    override func contentSize(width: CGFloat?) -> NSSize {
        NSSize(width: width ?? cell.width * 4, height: cell.height)
    }
}
