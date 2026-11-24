import AppKit
import TuiAppHostCore

/// The control size that fits a height: a placed control is a row of cells high.
@MainActor
private func fittingControlSize(for height: CGFloat) -> NSControl.ControlSize {
    height >= 24 ? .regular : height >= 19 ? .small : .mini
}

/// `number`: a text field with a stepper. Up and Down in the field, and the stepper, go to the
/// next step (Stepping), within min and max; what is typed is sent as it is, valid or not, as
/// `change`. submit (Enter), cancel (Escape).
final class NumberNodeView: NodeView, NSTextFieldDelegate {
    let field = NSTextField(string: "")
    let stepper = NSStepper()

    override init(id: Int, kind: String, host: NodeHost) {
        super.init(id: id, kind: kind, host: host)
        field.delegate = self
        field.lineBreakMode = .byClipping
        field.usesSingleLineMode = true
        field.cell?.isScrollable = true
        // The stepper only says which way: back to the middle after each click
        stepper.minValue = -1
        stepper.maxValue = 1
        stepper.increment = 1
        stepper.integerValue = 0
        stepper.valueWraps = false
        stepper.autorepeat = true
        stepper.target = self
        stepper.action = #selector(stepped)
        stepper.refusesFirstResponder = true
        addSubview(field)
        addSubview(stepper)
    }

    override var nativeView: NSView { field }
    override var focusTarget: NSView? { field }

    override func placedChanged() {
        field.font = placed ? host?.canvasFont : nil
        field.isBezeled = !placed
        field.isBordered = false
        field.drawsBackground = placed
        field.backgroundColor = .textBackgroundColor
        apply(old: props)
    }

    override func apply(old: [String: JSON]) {
        let value = props["value"]?.stringValue ?? ""
        // The application echoes what was typed: leave the text, and the caret, alone then
        if value != field.stringValue, value != old["value"]?.stringValue || field.currentEditor() == nil {
            field.stringValue = value
        }
        field.placeholderString = props["label"]?.stringValue
        field.isEnabled = !isDisabled
        stepper.isEnabled = !isDisabled
    }

    /// Up (1) or Down (-1): the next step, sent as a change.
    func step(_ direction: Int) {
        guard !isDisabled else { return }
        let range = Stepping.range(props)
        let next = Stepping.step(field.stringValue, by: direction, min: range.min, max: range.max, step: range.step)
        guard next != field.stringValue else { return }
        if let editor = field.currentEditor() as? NSTextView {
            editor.string = next
            editor.setSelectedRange(NSRange(location: (next as NSString).length, length: 0))
        }
        field.stringValue = next
        host?.sendEvent(id, "change", .string(next))
    }

    @objc private func stepped() {
        let direction = stepper.integerValue
        stepper.integerValue = 0
        if direction != 0 {
            window?.makeFirstResponder(field)
            step(direction)
        }
    }

    func controlTextDidChange(_ notification: Notification) {
        if (field.currentEditor() as? NSTextView)?.hasMarkedText() == true { return }
        host?.sendEvent(id, "change", .string(field.stringValue))
    }

    func control(_ control: NSControl, textView: NSTextView, doCommandBy selector: Selector) -> Bool {
        switch selector {
        case #selector(NSResponder.moveUp(_:)):
            step(1)
            return true
        case #selector(NSResponder.moveDown(_:)):
            step(-1)
            return true
        case #selector(NSResponder.insertNewline(_:)):
            host?.sendEvent(id, "submit", .string(field.stringValue))
            return true
        case #selector(NSResponder.cancelOperation(_:)):
            host?.sendEvent(id, "cancel", nil)
            return true
        case Selector(("noop:")):
            if let event = NSApp.currentEvent, event.type == .keyDown { host?.forwardKey(event) }
            return true
        default:
            return false
        }
    }

    override func contentSize(width: CGFloat?) -> NSSize {
        NSSize(width: width ?? 120, height: max(field.fittingSize.height, stepper.fittingSize.height))
    }

    override func layoutContent(_ rect: NSRect) {
        stepper.controlSize = fittingControlSize(for: rect.height)
        // Its arrows are drawn at their own size whatever the frame (28 points high for a mini one on
        // macOS 26): scaled down to the cells, whole, by bounds at that size in a smaller frame
        let size = stepper.fittingSize
        let scale = min(1, rect.height / max(1, size.height), rect.width / 2 / max(1, size.width))
        let width = size.width * scale
        let height = size.height * scale
        stepper.frame = NSRect(x: rect.maxX - width, y: rect.minY + (rect.height - height) / 2, width: width, height: height)
        stepper.bounds = NSRect(origin: .zero, size: size)
        field.frame = NSRect(x: rect.minX, y: rect.minY, width: max(0, rect.width - width - 2), height: rect.height)
    }
}

/// A slider that the keys move by its step, as TuiHtml's does: Left and Down back, Right and Up on,
/// Page Up and Page Down by a tenth of the range, Home and End to the bounds.
final class SteppingSlider: NSSlider {
    var step: Double = 1

    override var acceptsFirstResponder: Bool { isEnabled }
    override var canBecomeKeyView: Bool { isEnabled && !refusesFirstResponder && !isHiddenOrHasHiddenAncestor }

    override func keyDown(with event: NSEvent) {
        let page = max(step, (maxValue - minValue) / 10)
        let moved: Double? = switch event.keyCode {
        case 124, 126: doubleValue + step
        case 123, 125: doubleValue - step
        case 116: doubleValue + page
        case 121: doubleValue - page
        case 115: minValue
        case 119: maxValue
        default: nil
        }
        guard let moved, event.modifierFlags.intersection([.command, .control, .option]).isEmpty else {
            return super.keyDown(with: event)
        }
        doubleValue = Stepping.snap(moved, min: minValue, max: maxValue, step: step)
        sendAction(action, to: target)
    }
}

/// `slider`: between min (0) and max (100) by step (1); `change` with the value, on a step, as it
/// is moved.
final class SliderNodeView: NodeView {
    let slider = SteppingSlider(value: 50, minValue: 0, maxValue: 100, target: nil, action: nil)
    private var sent: Double?

    override init(id: Int, kind: String, host: NodeHost) {
        super.init(id: id, kind: kind, host: host)
        slider.isContinuous = true
        slider.target = self
        slider.action = #selector(moved)
        addSubview(slider)
    }

    override var nativeView: NSView { slider }
    override var focusTarget: NSView? { slider }

    override func apply(old: [String: JSON]) {
        let range = Stepping.range(props)
        let min = range.min ?? 0
        let max = Swift.max(min, range.max ?? 100)
        slider.minValue = min
        slider.maxValue = max
        slider.step = range.step
        // Tick marks for a few steps, which the slider then snaps to by itself
        let ticks = Int(((max - min) / range.step).rounded()) + 1
        slider.numberOfTickMarks = ticks <= 21 && ticks > 1 ? ticks : 0
        slider.allowsTickMarkValuesOnly = slider.numberOfTickMarks > 0
        let value = Stepping.snap(props["value"]?.doubleValue ?? min + (max - min) / 2, min: min, max: max, step: range.step)
        if slider.doubleValue != value { slider.doubleValue = value }
        sent = value
        slider.isEnabled = !isDisabled
    }

    @objc private func moved() {
        let range = Stepping.range(props)
        let value = Stepping.snap(slider.doubleValue, min: slider.minValue, max: slider.maxValue, step: range.step)
        if slider.doubleValue != value { slider.doubleValue = value }
        guard value != sent else { return }
        sent = value
        host?.sendEvent(id, "change", Stepping.json(value))
    }

    override func contentSize(width: CGFloat?) -> NSSize {
        NSSize(width: width ?? 160, height: slider.fittingSize.height)
    }

    override func layoutContent(_ rect: NSRect) {
        slider.controlSize = fittingControlSize(for: rect.height)
        let height = min(rect.height, slider.fittingSize.height)
        slider.frame = NSRect(x: rect.minX, y: rect.minY + (rect.height - height) / 2, width: rect.width, height: height)
    }
}

/// `listbox`: a list of `choices`, `selected` the selected rows, several with `multiple`;
/// `select` with the selected indexes when the user changes them.
final class ListboxNodeView: NodeView, NSTableViewDataSource, NSTableViewDelegate {
    let scroll = NSScrollView()
    let table = NSTableView()
    private var choices: [String] = []
    private var programmatic = false

    override init(id: Int, kind: String, host: NodeHost) {
        super.init(id: id, kind: kind, host: host)
        let column = NSTableColumn(identifier: NSUserInterfaceItemIdentifier("choice"))
        column.resizingMask = .autoresizingMask
        table.addTableColumn(column)
        table.headerView = nil
        table.columnAutoresizingStyle = .uniformColumnAutoresizingStyle
        table.intercellSpacing = NSSize(width: 0, height: 0)
        table.style = .plain
        table.dataSource = self
        table.delegate = self
        scroll.documentView = table
        scroll.hasVerticalScroller = true
        scroll.autohidesScrollers = true
        scroll.borderType = .noBorder
        scroll.drawsBackground = true
        addSubview(scroll)
    }

    override var nativeView: NSView { table }
    override var focusTarget: NSView? { table }

    private var selectedRows: IndexSet {
        IndexSet((props["selected"]?.arrayValue ?? []).compactMap(\.intValue).filter { (0..<choices.count).contains($0) })
    }

    override func apply(old: [String: JSON]) {
        programmatic = true
        defer { programmatic = false }
        let choices = props["choices"]?.arrayValue?.map { $0.stringValue ?? "" } ?? []
        table.allowsMultipleSelection = props["multiple"]?.boolValue == true
        // One row stays selected in a single list, like a <select size>
        table.allowsEmptySelection = table.allowsMultipleSelection || choices.isEmpty
        if choices != self.choices {
            self.choices = choices
            table.reloadData()
        }
        let rows = table.allowsMultipleSelection ? selectedRows : IndexSet(selectedRows.prefix(1))
        if table.selectedRowIndexes != rows {
            table.selectRowIndexes(rows, byExtendingSelection: false)
        }
        table.isEnabled = !isDisabled
    }

    override func placedChanged() {
        // A row a line of cells, in the canvas font
        table.rowHeight = placed ? max(1, cell.height) : 17
        table.reloadData()
        apply(old: props)
    }

    func numberOfRows(in tableView: NSTableView) -> Int { choices.count }

    func tableView(_ tableView: NSTableView, viewFor tableColumn: NSTableColumn?, row: Int) -> NSView? {
        let label = tableView.makeView(withIdentifier: NSUserInterfaceItemIdentifier("choice"), owner: self) as? NSTextField ?? {
            let label = NSTextField(labelWithString: "")
            label.identifier = NSUserInterfaceItemIdentifier("choice")
            label.lineBreakMode = .byTruncatingTail
            return label
        }()
        label.stringValue = row < choices.count ? choices[row] : ""
        label.font = placed ? host?.canvasFont : .systemFont(ofSize: NSFont.systemFontSize)
        return label
    }

    func tableViewSelectionDidChange(_ notification: Notification) {
        guard !programmatic else { return }
        host?.sendEvent(id, "select", .array(table.selectedRowIndexes.map { .int(Int64($0)) }))
    }

    override func contentSize(width: CGFloat?) -> NSSize {
        NSSize(width: width ?? 160, height: CGFloat(max(1, min(choices.count, 6))) * max(table.rowHeight, 17))
    }

    override func layoutContent(_ rect: NSRect) {
        scroll.frame = rect
    }
}
