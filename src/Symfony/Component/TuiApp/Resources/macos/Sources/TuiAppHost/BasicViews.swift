import AppKit
import TuiAppHostCore

/// `label`: selectable, read-only, wrapping text; `runs` as attributed text.
final class LabelNodeView: NodeView {
    let field = NSTextField(wrappingLabelWithString: "")

    override init(id: Int, kind: String, host: NodeHost) {
        super.init(id: id, kind: kind, host: host)
        field.isSelectable = true
        // Without it, selecting the text drops the attributes.
        field.allowsEditingTextAttributes = true
        field.drawsBackground = false
        field.isBordered = false
        addSubview(field)
    }

    override var nativeView: NSView { field }
    override var focusTarget: NSView? { field }

    override func apply(old: [String: JSON]) {
        let alignment: NSTextAlignment
        switch props["align"]?.stringValue {
        case "center": alignment = .center
        case "right": alignment = .right
        default: alignment = .natural
        }
        let runs: [Style.Run]
        if let list = props["runs"]?.arrayValue {
            runs = list.map(Style.Run.init)
        } else {
            runs = [Style.Run(text: props["text"]?.stringValue ?? "", style: style)]
        }
        field.attributedStringValue = Style.attributed(runs, font: .systemFont(ofSize: NSFont.systemFontSize), alignment: alignment)
        field.isEnabled = !isDisabled
        field.alphaValue = isDisabled ? 0.5 : 1
    }

    override func contentSize(width: CGFloat?) -> NSSize {
        let natural = Style.naturalWidth(of: field) + 4
        let w = min(width ?? natural, natural)
        return NSSize(width: w, height: max(Style.height(of: field, width: width ?? natural), field.stringValue.isEmpty ? 0 : 16))
    }

    override func layoutContent(_ rect: NSRect) {
        field.frame = rect
    }
}

/// A text view that says when an input method sets its marked text, which `maxLength` lets in whole:
/// what is composed is not the value yet, only what it commits.
protocol ComposingText: NSTextView {
    var composing: Bool { get }
}

/// The field editor of a text field: see ComposingText.
final class ComposingFieldEditor: NSTextView, ComposingText {
    private(set) var composing = false

    override func setMarkedText(_ string: Any, selectedRange: NSRange, replacementRange: NSRange) {
        composing = true
        defer { composing = false }
        super.setMarkedText(string, selectedRange: selectedRange, replacementRange: replacementRange)
    }
}

/// Inserts what `maxLength` lets in of an edit of `textView`, the field editor of a text field or
/// a text area: false when the edit is cut or refused, after inserting the part that fits. Marked
/// text is let in whole, and not counted: the commit that replaces it is.
@MainActor
func keepToMaxLength(_ textView: NSTextView, _ range: NSRange, _ replacement: String?, _ limit: Int?) -> Bool {
    guard let replacement, limit != nil else { return true }
    if (textView as? ComposingText)?.composing == true { return true }
    let allowed = MaxLength.allowed(current: textView.string, range: range, replacement: replacement, limit: limit, marked: textView.hasMarkedText() ? textView.markedRange() : nil)
    if allowed == replacement { return true }
    NSSound.beep()
    if !allowed.isEmpty || range.length > 0 {
        // Goes through this check again, and fits now; undo and textDidChange as for typing.
        textView.insertText(allowed, replacementRange: range)
    }
    return false
}

/// The text field of a `textfield`: its field editor asks it, its delegate, before each edit,
/// for `maxLength`. NSTextField answers that question itself without declaring it: this takes it
/// over and then asks NSTextField's own answer.
/// A text field that keeps to `maxLength`.
protocol LimitedField: NSTextField {
    var limit: () -> Int? { get set }
}

final class LimitedTextField: NSTextField, LimitedField {
    var limit: () -> Int? = { nil }

    override class var cellClass: AnyClass? {
        get { LimitedTextFieldCell.self }
        set {}
    }

    @objc(textView:shouldChangeTextInRange:replacementString:)
    func limitedTextView(_ textView: NSTextView, shouldChangeTextIn range: NSRange, replacementString: String?) -> Bool {
        guard keepToMaxLength(textView, range, replacementString, limit()) else { return false }
        let selector = #selector(limitedTextView(_:shouldChangeTextIn:replacementString:))
        guard NSTextField.instancesRespond(to: selector), let inherited = class_getMethodImplementation(NSTextField.self, selector) else { return true }
        typealias ShouldChange = @convention(c) (AnyObject, Selector, NSTextView, NSRange, NSString?) -> Bool
        return unsafeBitCast(inherited, to: ShouldChange.self)(self, selector, textView, range, replacementString as NSString?)
    }
}

/// The secure text field of a `password`: bullets, and nothing of it on the pasteboard. Its field
/// editor is AppKit's secure one, which input methods do not compose in.
final class LimitedSecureTextField: NSSecureTextField, LimitedField {
    var limit: () -> Int? = { nil }

    @objc(textView:shouldChangeTextInRange:replacementString:)
    func limitedTextView(_ textView: NSTextView, shouldChangeTextIn range: NSRange, replacementString: String?) -> Bool {
        guard keepToMaxLength(textView, range, replacementString, limit()) else { return false }
        let selector = #selector(limitedTextView(_:shouldChangeTextIn:replacementString:))
        guard NSSecureTextField.instancesRespond(to: selector), let inherited = class_getMethodImplementation(NSSecureTextField.self, selector) else { return true }
        typealias ShouldChange = @convention(c) (AnyObject, Selector, NSTextView, NSRange, NSString?) -> Bool
        return unsafeBitCast(inherited, to: ShouldChange.self)(self, selector, textView, range, replacementString as NSString?)
    }
}

/// Edits its text field with a ComposingFieldEditor.
final class LimitedTextFieldCell: NSTextFieldCell {
    private var editor: ComposingFieldEditor?

    override func fieldEditor(for controlView: NSView) -> NSTextView? {
        if let editor { return editor }
        let editor = ComposingFieldEditor()
        editor.isFieldEditor = true
        self.editor = editor
        return editor
    }
}

/// `textfield`: one line, `prompt` as a leading label; change, submit (Enter), cancel (Escape).
/// `password`: the same in a secure text field, without a prompt; its value is never logged.
final class TextFieldNodeView: NodeView, NSTextFieldDelegate {
    let prompt = NSTextField(labelWithString: "")
    let field: NSTextField & LimitedField

    override init(id: Int, kind: String, host: NodeHost) {
        field = kind == "password" ? LimitedSecureTextField(string: "") : LimitedTextField(string: "")
        super.init(id: id, kind: kind, host: host)
        field.delegate = self
        field.limit = { [weak self] in self.flatMap { MaxLength.limit($0.props) } }
        field.lineBreakMode = .byClipping
        field.usesSingleLineMode = true
        field.cell?.isScrollable = true
        prompt.textColor = .secondaryLabelColor
        addSubview(prompt)
        addSubview(field)
    }

    override var nativeView: NSView { field }
    override var focusTarget: NSView? { field }

    override func placedChanged() {
        // In the cells of the canvas: its font, no bezel taller than a row
        field.font = placed ? host?.canvasFont : nil
        field.isBezeled = !placed
        field.isBordered = false
        field.drawsBackground = placed
        field.backgroundColor = .textBackgroundColor
        apply(old: props)
    }

    override func apply(old: [String: JSON]) {
        prompt.stringValue = placed || kind == "password" ? "" : props["prompt"]?.stringValue ?? ""
        prompt.isHidden = prompt.stringValue.isEmpty
        let value = props["value"]?.stringValue ?? ""
        // The application echoes what was typed: leave the text, and the caret, alone then.
        if value != field.stringValue, value != old["value"]?.stringValue || field.currentEditor() == nil {
            field.stringValue = value
        }
        field.placeholderString = props["label"]?.stringValue
        field.isEnabled = !isDisabled
        if let fg = Style.color(style["fg"]) { field.textColor = fg }
    }

    override func contentSize(width: CGFloat?) -> NSSize {
        let promptWidth = prompt.isHidden ? 0 : prompt.fittingSize.width + 4
        let height = max(field.fittingSize.height, prompt.isHidden ? 0 : prompt.fittingSize.height)
        return NSSize(width: width ?? promptWidth + max(200, cell.width * 30), height: height)
    }

    override func layoutContent(_ rect: NSRect) {
        if placed {
            field.frame = rect
            return
        }
        let fieldHeight = field.fittingSize.height
        var x = rect.minX
        if !prompt.isHidden {
            let size = prompt.fittingSize
            prompt.frame = NSRect(x: x, y: rect.minY + (fieldHeight - size.height) / 2, width: size.width, height: size.height)
            x += size.width + 4
        }
        field.frame = NSRect(x: x, y: rect.minY, width: max(0, rect.maxX - x), height: fieldHeight)
    }

    func controlTextDidChange(_ notification: Notification) {
        // What an input method composes (marked text) is not the value yet: its commit is
        if (field.currentEditor() as? NSTextView)?.hasMarkedText() == true { return }
        host?.sendEvent(id, "change", .string(field.stringValue))
    }

    func control(_ control: NSControl, textView: NSTextView, doCommandBy selector: Selector) -> Bool {
        switch selector {
        case #selector(NSResponder.insertNewline(_:)):
            host?.sendEvent(id, "submit", .string(field.stringValue))
            return true
        case #selector(NSResponder.cancelOperation(_:)):
            host?.sendEvent(id, "cancel", nil)
            return true
        case Selector(("noop:")):
            // A key the field has no use for (Ctrl+S, F5…): the application's.
            if let event = NSApp.currentEvent, event.type == .keyDown { host?.forwardKey(event) }
            return true
        default:
            return false
        }
    }
}

/// The text view of a `textarea`: Ctrl+Enter and Cmd+Enter submit.
final class SubmittingTextView: NSTextView, ComposingText {
    private(set) var composing = false
    var onSubmit: (() -> Void)?

    override func setMarkedText(_ string: Any, selectedRange: NSRange, replacementRange: NSRange) {
        composing = true
        defer { composing = false }
        super.setMarkedText(string, selectedRange: selectedRange, replacementRange: replacementRange)
    }

    var onUnusedKey: ((NSEvent) -> Void)?

    private func isSubmit(_ event: NSEvent) -> Bool {
        (event.keyCode == 36 || event.keyCode == 76) && !event.modifierFlags.intersection([.command, .control]).isEmpty
    }

    override func keyDown(with event: NSEvent) {
        if isSubmit(event) {
            onSubmit?()
            return
        }
        super.keyDown(with: event)
    }

    override func performKeyEquivalent(with event: NSEvent) -> Bool {
        if window?.firstResponder === self, event.type == .keyDown, isSubmit(event) {
            onSubmit?()
            return true
        }
        return super.performKeyEquivalent(with: event)
    }

    override func doCommand(by selector: Selector) {
        if selector == Selector(("noop:")), let event = NSApp.currentEvent, event.type == .keyDown {
            onUnusedKey?(event)
            return
        }
        super.doCommand(by: selector)
    }
}

/// `textarea`: multi-line, scrolls; change; submit on Ctrl/Cmd+Enter; between minLines and maxLines.
final class TextAreaNodeView: NodeView, NSTextViewDelegate {
    let scroll = NSScrollView()
    let text = SubmittingTextView(frame: NSRect(x: 0, y: 0, width: 200, height: 60))
    private var lastLineCount = 0

    override init(id: Int, kind: String, host: NodeHost) {
        super.init(id: id, kind: kind, host: host)
        scroll.hasVerticalScroller = true
        scroll.autohidesScrollers = true
        scroll.borderType = .bezelBorder
        text.isRichText = false
        text.allowsUndo = true
        text.font = .systemFont(ofSize: NSFont.systemFontSize)
        text.textContainerInset = NSSize(width: 2, height: 3)
        text.isVerticallyResizable = true
        text.isHorizontallyResizable = false
        text.autoresizingMask = [.width]
        text.minSize = .zero
        text.maxSize = NSSize(width: CGFloat.greatestFiniteMagnitude, height: CGFloat.greatestFiniteMagnitude)
        text.textContainer?.widthTracksTextView = true
        text.isAutomaticQuoteSubstitutionEnabled = false
        text.isAutomaticDashSubstitutionEnabled = false
        text.isAutomaticTextReplacementEnabled = false
        text.delegate = self
        text.onSubmit = { [weak self] in
            guard let self else { return }
            self.host?.sendEvent(self.id, "submit", .string(self.text.string))
        }
        text.onUnusedKey = { [weak self] event in self?.host?.forwardKey(event) }
        scroll.documentView = text
        addSubview(scroll)
    }

    override var nativeView: NSView { text }
    override var focusTarget: NSView? { text }
    override var stretches: Bool { true }

    override func placedChanged() {
        scroll.borderType = placed ? .noBorder : .bezelBorder
        text.font = placed ? host?.canvasFont : .systemFont(ofSize: NSFont.systemFontSize)
        text.textContainerInset = placed ? .zero : NSSize(width: 2, height: 3)
        text.textContainer?.lineFragmentPadding = placed ? 0 : 5
    }

    override func apply(old: [String: JSON]) {
        let value = props["value"]?.stringValue ?? ""
        if value != text.string, value != old["value"]?.stringValue || window?.firstResponder !== text {
            text.string = value
        }
        text.isEditable = !isDisabled
        text.isSelectable = !isDisabled
        text.textColor = Style.color(style["fg"]) ?? .textColor
        lastLineCount = lineCount
    }

    private var lineCount: Int { max(1, text.string.components(separatedBy: "\n").count) }

    private var lineHeight: CGFloat {
        ceil(NSLayoutManager().defaultLineHeight(for: text.font ?? .systemFont(ofSize: NSFont.systemFontSize)))
    }

    override func contentSize(width: CGFloat?) -> NSSize {
        let minLines = max(1, props["minLines"]?.intValue ?? 3)
        let maxLines = max(minLines, props["maxLines"]?.intValue ?? 10)
        let lines = min(max(lineCount, minLines), maxLines)
        return NSSize(width: width ?? 320, height: CGFloat(lines) * lineHeight + text.textContainerInset.height * 2 + 4)
    }

    override func layoutContent(_ rect: NSRect) {
        scroll.frame = rect
    }

    func textView(_ textView: NSTextView, shouldChangeTextIn affectedCharRange: NSRange, replacementString: String?) -> Bool {
        keepToMaxLength(textView, affectedCharRange, replacementString, MaxLength.limit(props))
    }

    func textDidChange(_ notification: Notification) {
        // What an input method composes (marked text) is not the value yet: its commit is
        if !text.hasMarkedText() {
            host?.sendEvent(id, "change", .string(text.string))
        }
        if lineCount != lastLineCount {
            lastLineCount = lineCount
            host?.setNeedsRelayout()
        }
    }
}

/// `progress`: determinate from 0 to 1, indeterminate when null; `text` above the bar.
final class ProgressNodeView: NodeView {
    let bar = NSProgressIndicator()
    let label = NSTextField(labelWithString: "")

    override init(id: Int, kind: String, host: NodeHost) {
        super.init(id: id, kind: kind, host: host)
        bar.style = .bar
        bar.minValue = 0
        bar.maxValue = 1
        label.font = .systemFont(ofSize: NSFont.smallSystemFontSize)
        label.textColor = .secondaryLabelColor
        label.lineBreakMode = .byTruncatingTail
        addSubview(label)
        addSubview(bar)
    }

    override var nativeView: NSView { bar }

    override func apply(old: [String: JSON]) {
        label.stringValue = props["text"]?.stringValue ?? ""
        label.isHidden = label.stringValue.isEmpty
        if let fg = Style.color(style["fg"]) { label.textColor = fg }
        if let value = props["progress"]?.doubleValue {
            bar.stopAnimation(nil)
            bar.isIndeterminate = false
            bar.doubleValue = min(1, max(0, value))
        } else {
            bar.isIndeterminate = true
            bar.startAnimation(nil)
        }
        bar.alphaValue = isDisabled ? 0.5 : 1
    }

    private var labelHeight: CGFloat { label.isHidden ? 0 : label.fittingSize.height + 2 }

    override func contentSize(width: CGFloat?) -> NSSize {
        NSSize(width: width ?? max(200, label.fittingSize.width), height: labelHeight + 12)
    }

    override func layoutContent(_ rect: NSRect) {
        label.frame = NSRect(x: rect.minX, y: rect.minY, width: rect.width, height: labelHeight)
        bar.frame = NSRect(x: rect.minX, y: rect.minY + labelHeight, width: rect.width, height: 12)
    }
}

/// `spinner`: spinning while `running`, with `text`, and a Cancel button when `cancellable`.
final class SpinnerNodeView: NodeView {
    let spinner = NSProgressIndicator()
    let label = NSTextField(labelWithString: "")
    let cancel = NSButton(title: "Cancel", target: nil, action: nil)

    override init(id: Int, kind: String, host: NodeHost) {
        super.init(id: id, kind: kind, host: host)
        spinner.style = .spinning
        spinner.controlSize = .small
        spinner.isDisplayedWhenStopped = false
        label.lineBreakMode = .byTruncatingTail
        cancel.bezelStyle = .rounded
        cancel.controlSize = .small
        cancel.font = .systemFont(ofSize: NSFont.smallSystemFontSize)
        cancel.target = self
        cancel.action = #selector(cancelled)
        addSubview(spinner)
        addSubview(label)
        addSubview(cancel)
    }

    override var nativeView: NSView { spinner }
    override var focusTarget: NSView? { cancel.isHidden ? nil : cancel }

    override func apply(old: [String: JSON]) {
        label.stringValue = props["text"]?.stringValue ?? ""
        if let fg = Style.color(style["fg"]) { label.textColor = fg }
        if props["running"]?.boolValue != false {
            spinner.startAnimation(nil)
        } else {
            spinner.stopAnimation(nil)
        }
        cancel.isHidden = props["cancellable"]?.boolValue != true
        cancel.isEnabled = !isDisabled
    }

    @objc private func cancelled() {
        host?.sendEvent(id, "cancel", nil)
    }

    override func contentSize(width: CGFloat?) -> NSSize {
        let button = cancel.isHidden ? NSSize.zero : cancel.fittingSize
        let natural = 16 + 6 + label.fittingSize.width + (cancel.isHidden ? 0 : 8 + button.width)
        return NSSize(width: width ?? natural, height: max(16, label.fittingSize.height, button.height))
    }

    override func layoutContent(_ rect: NSRect) {
        let mid = rect.minY + contentSize(width: rect.width).height / 2
        spinner.frame = NSRect(x: rect.minX, y: mid - 8, width: 16, height: 16)
        let button = cancel.fittingSize
        let labelSize = label.fittingSize
        let buttonX = rect.minX + 22 + labelSize.width + 8
        label.frame = NSRect(x: rect.minX + 22, y: mid - labelSize.height / 2, width: labelSize.width, height: labelSize.height)
        cancel.frame = NSRect(x: min(buttonX, max(rect.minX, rect.maxX - button.width)), y: mid - button.height / 2, width: button.width, height: button.height)
    }
}
