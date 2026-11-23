import AppKit
import TuiAppHostCore

/// A button that takes the focus whatever Full Keyboard Access says, as the application may give
/// it the focus, and that Space or Enter presses. Tab skips it when hidden or `focusable: false`.
final class FocusableButton: NSButton {
    override var acceptsFirstResponder: Bool { isEnabled }
    override var canBecomeKeyView: Bool { isEnabled && !refusesFirstResponder && !isHiddenOrHasHiddenAncestor }

    override func keyDown(with event: NSEvent) {
        if [36, 49, 76].contains(event.keyCode), event.modifierFlags.intersection([.command, .control, .option]).isEmpty {
            performClick(nil)
            return
        }
        super.keyDown(with: event)
    }
}

/// A pop-up button that takes the focus whatever Full Keyboard Access says.
final class FocusablePopUpButton: NSPopUpButton {
    override var acceptsFirstResponder: Bool { isEnabled }
    override var canBecomeKeyView: Bool { isEnabled && !refusesFirstResponder && !isHiddenOrHasHiddenAncestor }
}

/// The control size that fits a height: a placed control is a row of cells high. A check box is
/// smaller than a button of the same size.
@MainActor
private func controlSize(for height: CGFloat, box: Bool = false) -> NSControl.ControlSize {
    let height = box ? height + 5 : height
    return height >= 24 ? .regular : height >= 19 ? .small : .mini
}

/// Frames `control` at `rect`, then narrows it by what its own views draw past its edges (the
/// label of a check box runs past its right one), so that none of it leaves the rect: a control
/// placed on a canvas stays in its cells.
@MainActor
func fit(_ control: NSView, in rect: NSRect) {
    control.frame = rect
    control.layoutSubtreeIfNeeded()
    let bounds = control.bounds
    let drawn = control.subviews.filter { !$0.isHidden }.reduce(bounds) { $0.union($1.frame) }
    let left = bounds.minX - drawn.minX
    let right = drawn.maxX - bounds.maxX
    let top = control.isFlipped ? bounds.minY - drawn.minY : drawn.maxY - bounds.maxY
    let bottom = control.isFlipped ? drawn.maxY - bounds.maxY : bounds.minY - drawn.minY
    guard max(left, right, top, bottom) > 0 else { return }
    control.frame = NSRect(x: rect.minX + left, y: rect.minY + top, width: max(0, rect.width - left - right), height: max(0, rect.height - top - bottom))
    control.layoutSubtreeIfNeeded()
}

/// `button`: a push button showing `text`; `press`.
final class ButtonNodeView: NodeView {
    let button = FocusableButton(title: "", target: nil, action: nil)

    override init(id: Int, kind: String, host: NodeHost) {
        super.init(id: id, kind: kind, host: host)
        button.bezelStyle = .rounded
        button.target = self
        button.action = #selector(pressed)
        addSubview(button)
    }

    override var nativeView: NSView { button }
    override var focusTarget: NSView? { button }

    override func apply(old: [String: JSON]) {
        button.title = props["text"]?.stringValue ?? ""
        button.isEnabled = !isDisabled
    }

    @objc private func pressed() {
        host?.sendEvent(id, "press", nil)
    }

    override func contentSize(width: CGFloat?) -> NSSize {
        let size = button.fittingSize
        return NSSize(width: width ?? size.width, height: size.height)
    }

    override func layoutContent(_ rect: NSRect) {
        button.controlSize = controlSize(for: rect.height)
        button.font = .systemFont(ofSize: NSFont.systemFontSize(for: button.controlSize))
        button.frame = rect
    }
}

/// `checkbox`: a check box, or a radio button with `group`; `toggle` with the new state. The
/// application unchecks the other radios of a group: each is alone in its superview, so AppKit
/// does not.
final class CheckboxNodeView: NodeView {
    private(set) var box = FocusableButton(checkboxWithTitle: "", target: nil, action: nil)
    private var radio = false

    override init(id: Int, kind: String, host: NodeHost) {
        super.init(id: id, kind: kind, host: host)
        box.target = self
        box.action = #selector(toggled)
        addSubview(box)
    }

    override var nativeView: NSView { box }
    override var focusTarget: NSView? { box }

    override func apply(old: [String: JSON]) {
        // A radio button is made as one: a check box whose type is changed keeps the look of a check box
        if (props["group"]?.stringValue != nil) != radio {
            radio.toggle()
            let replacement = radio ? FocusableButton(radioButtonWithTitle: "", target: self, action: #selector(toggled)) : FocusableButton(checkboxWithTitle: "", target: self, action: #selector(toggled))
            replacement.frame = box.frame
            box.removeFromSuperview()
            box = replacement
            addSubview(box)
        }
        box.title = props["text"]?.stringValue ?? ""
        box.state = props["checked"]?.boolValue == true ? .on : .off
        box.isEnabled = !isDisabled
    }

    @objc private func toggled() {
        host?.sendEvent(id, "toggle", .bool(box.state == .on))
    }

    override func contentSize(width: CGFloat?) -> NSSize {
        let size = box.fittingSize
        return NSSize(width: width ?? size.width, height: size.height)
    }

    override func layoutContent(_ rect: NSRect) {
        box.controlSize = controlSize(for: rect.height, box: true)
        box.font = .systemFont(ofSize: NSFont.systemFontSize(for: box.controlSize))
        // Without a title, the box alone, in the middle of its cells, where the canvas drew its mark
        let width = box.title.isEmpty ? min(rect.width, box.fittingSize.width) : rect.width
        fit(box, in: NSRect(x: rect.midX - width / 2, y: rect.minY, width: width, height: rect.height))
    }
}

/// `popup`: a pop-up button of `choices` showing `selected`; `select` with the index picked.
final class PopupNodeView: NodeView {
    let popup = FocusablePopUpButton(frame: .zero, pullsDown: false)

    override init(id: Int, kind: String, host: NodeHost) {
        super.init(id: id, kind: kind, host: host)
        popup.target = self
        popup.action = #selector(picked)
        addSubview(popup)
    }

    override var nativeView: NSView { popup }
    override var focusTarget: NSView? { popup }

    override func apply(old: [String: JSON]) {
        let choices = props["choices"]?.arrayValue?.map { $0.stringValue ?? "" } ?? []
        if choices != popup.itemTitles || popup.numberOfItems != choices.count {
            popup.removeAllItems()
            // addItems(withTitles:) drops duplicate titles: one item per choice, whatever its title
            for choice in choices {
                popup.menu?.addItem(NSMenuItem(title: choice, action: nil, keyEquivalent: ""))
            }
        }
        let selected = props["selected"]?.intValue ?? -1
        popup.selectItem(at: (0..<choices.count).contains(selected) ? selected : -1)
        popup.isEnabled = !isDisabled
    }

    @objc private func picked() {
        host?.sendEvent(id, "select", .int(Int64(popup.indexOfSelectedItem)))
    }

    override func contentSize(width: CGFloat?) -> NSSize {
        let size = popup.fittingSize
        return NSSize(width: width ?? size.width, height: size.height)
    }

    override func layoutContent(_ rect: NSRect) {
        popup.controlSize = controlSize(for: rect.height)
        popup.font = .systemFont(ofSize: NSFont.systemFontSize(for: popup.controlSize))
        popup.frame = rect
    }
}
