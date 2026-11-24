import AppKit
import TuiAppHostCore

/// The table of a `list`: Enter selects.
final class ListTableView: NSTableView {
    var onEnter: (() -> Void)?

    override func keyDown(with event: NSEvent) {
        if event.keyCode == 36 || event.keyCode == 76 {
            onEnter?()
            return
        }
        super.keyDown(with: event)
    }
}

/// `list`: one row per item, label and secondary description; `select` on double-click or Enter;
/// with `multiple`, a checkbox per row and `select` with the checked indexes.
final class ListNodeView: NodeView, NSTableViewDataSource, NSTableViewDelegate {
    let scroll = NSScrollView()
    let table = ListTableView()
    private let checkColumn = NSTableColumn(identifier: .init("check"))
    private let textColumn = NSTableColumn(identifier: .init("text"))
    private var items: [(label: String, description: String?)] = []
    private var checked = Set<Int>()
    private var multiple = false
    private static let plainRow: CGFloat = 22
    private static let detailedRow: CGFloat = 36

    override init(id: Int, kind: String, host: NodeHost) {
        super.init(id: id, kind: kind, host: host)
        checkColumn.width = 22
        checkColumn.minWidth = 22
        checkColumn.maxWidth = 22
        textColumn.resizingMask = .autoresizingMask
        table.addTableColumn(textColumn)
        table.headerView = nil
        table.style = .plain
        table.columnAutoresizingStyle = .lastColumnOnlyAutoresizingStyle
        table.dataSource = self
        table.delegate = self
        table.target = self
        table.doubleAction = #selector(doubleClicked)
        table.onEnter = { [weak self] in
            guard let self, self.table.selectedRow >= 0 else { return }
            self.choose(self.table.selectedRow)
        }
        table.allowsEmptySelection = true
        scroll.documentView = table
        scroll.hasVerticalScroller = true
        scroll.autohidesScrollers = true
        scroll.borderType = .bezelBorder
        addSubview(scroll)
    }

    override var nativeView: NSView { table }
    override var focusTarget: NSView? { table }
    override var stretches: Bool { true }

    override func apply(old: [String: JSON]) {
        items = (props["items"]?.arrayValue ?? []).map { ($0["label"]?.stringValue ?? "", $0["description"]?.stringValue) }
        checked = Set(props["checked"]?.arrayValue?.compactMap(\.intValue) ?? [])
        let wasMultiple = multiple
        multiple = props["multiple"]?.boolValue == true
        if multiple != wasMultiple || table.tableColumns.isEmpty {
            if multiple {
                table.addTableColumn(checkColumn)
                table.moveColumn(table.column(withIdentifier: checkColumn.identifier), toColumn: 0)
            } else if table.tableColumns.contains(checkColumn) {
                table.removeTableColumn(checkColumn)
            }
        }
        table.reloadData()
        if let selected = props["selected"]?.intValue, selected >= 0, selected < items.count {
            table.selectRowIndexes([selected], byExtendingSelection: false)
            table.scrollRowToVisible(selected)
        } else {
            table.deselectAll(nil)
        }
        table.isEnabled = !isDisabled
        table.refusesFirstResponder = !isFocusable
    }

    private func choose(_ row: Int) {
        guard row >= 0, row < items.count, !isDisabled else { return }
        if multiple {
            toggle(row)
        } else {
            host?.sendEvent(id, "select", .int(Int64(row)))
        }
    }

    @objc private func doubleClicked() {
        choose(table.clickedRow)
    }

    private func toggle(_ row: Int) {
        if checked.contains(row) { checked.remove(row) } else { checked.insert(row) }
        table.reloadData(forRowIndexes: [row], columnIndexes: IndexSet(integersIn: 0..<table.numberOfColumns))
        host?.sendEvent(id, "select", .array(checked.sorted().map { .int(Int64($0)) }))
    }

    @objc private func checkboxClicked(_ sender: NSButton) {
        toggle(sender.tag)
    }

    func numberOfRows(in tableView: NSTableView) -> Int { items.count }

    func tableView(_ tableView: NSTableView, heightOfRow row: Int) -> CGFloat {
        items[row].description == nil ? Self.plainRow : Self.detailedRow
    }

    func tableView(_ tableView: NSTableView, viewFor column: NSTableColumn?, row: Int) -> NSView? {
        if column === checkColumn {
            let box = NSButton(checkboxWithTitle: "", target: self, action: #selector(checkboxClicked(_:)))
            box.tag = row
            box.state = checked.contains(row) ? .on : .off
            box.isEnabled = !isDisabled
            box.refusesFirstResponder = true
            return box
        }
        let view = FlippedView()
        let title = NSTextField(labelWithString: items[row].label)
        title.lineBreakMode = .byTruncatingTail
        title.frame = NSRect(x: 2, y: 2, width: 2000, height: 17)
        title.autoresizingMask = [.width]
        view.addSubview(title)
        if let description = items[row].description {
            let detail = Style.secondaryLabel(description)
            detail.maximumNumberOfLines = 1
            detail.lineBreakMode = .byTruncatingTail
            detail.frame = NSRect(x: 2, y: 19, width: 2000, height: 15)
            detail.autoresizingMask = [.width]
            view.addSubview(detail)
        }
        return view
    }

    override func contentSize(width: CGFloat?) -> NSSize {
        let shown = items.prefix(8)
        let rows = shown.isEmpty ? Self.plainRow : shown.reduce(0) { $0 + ($1.description == nil ? Self.plainRow : Self.detailedRow) }
        let natural = max(220, (items.map { NSTextField(labelWithString: $0.label).fittingSize.width }.max() ?? 0) + 40)
        return NSSize(width: width ?? natural, height: rows + CGFloat(max(1, shown.count)) * table.intercellSpacing.height + 4)
    }

    override func layoutContent(_ rect: NSRect) {
        scroll.frame = rect
        textColumn.width = max(40, scroll.contentSize.width - (multiple ? checkColumn.width + table.intercellSpacing.width : 0) - 4)
    }
}

/// `form`: two columns, label then a popup (`choices`) or a text field, which only shows a
/// `readOnly` row; `setting` with `{id, value}` when a value is committed.
final class FormNodeView: NodeView, NSTextFieldDelegate {
    let grid = NSGridView()
    private var rows: [JSON] = []
    private var editors: [NSControl] = []

    override init(id: Int, kind: String, host: NodeHost) {
        super.init(id: id, kind: kind, host: host)
        grid.rowSpacing = 8
        grid.columnSpacing = 8
        grid.translatesAutoresizingMaskIntoConstraints = true
        addSubview(grid)
    }

    override var nativeView: NSView { grid }
    override var focusTarget: NSView? { editors.first }
    override var keyViews: [NSView] { editors }

    override func apply(old: [String: JSON]) {
        let newRows = props["rows"]?.arrayValue ?? []
        if newRows != rows || editors.isEmpty != newRows.isEmpty {
            rows = newRows
            rebuild()
        }
        editors.forEach { $0.isEnabled = !isDisabled }
    }

    private func rebuild() {
        while grid.numberOfRows > 0 { grid.removeRow(at: 0) }
        editors = []
        for (index, row) in rows.enumerated() {
            let label = NSTextField(labelWithString: (row["label"]?.stringValue ?? "") + ":")
            label.alignment = .right
            let value = row["value"]?.stringValue ?? ""
            let editor: NSControl
            if let choices = row["choices"]?.arrayValue?.compactMap(\.stringValue) {
                let popup = NSPopUpButton(frame: .zero, pullsDown: false)
                popup.addItems(withTitles: choices)
                if !choices.contains(value), !value.isEmpty { popup.addItem(withTitle: value) }
                popup.selectItem(withTitle: value)
                popup.target = self
                popup.action = #selector(popupChanged(_:))
                editor = popup
            } else {
                let field = NSTextField(string: value)
                field.delegate = self
                field.placeholderString = row["label"]?.stringValue
                if row["readOnly"]?.boolValue == true {
                    field.isEditable = false
                    field.isSelectable = true
                }
                editor = field
            }
            editor.tag = index
            editors.append(editor)
            var cell: NSView = editor
            if let description = row["description"]?.stringValue, !description.isEmpty {
                let detail = Style.secondaryLabel(description)
                let column = NSStackView(views: [editor, detail])
                column.orientation = .vertical
                column.alignment = .leading
                column.spacing = 2
                editor.setContentHuggingPriority(.defaultLow, for: .horizontal)
                cell = column
            }
            grid.addRow(with: [label, cell])
        }
        if grid.numberOfColumns == 2 {
            grid.column(at: 0).xPlacement = .trailing
            grid.column(at: 1).xPlacement = .fill
        }
        for index in 0..<grid.numberOfRows {
            grid.row(at: index).rowAlignment = .firstBaseline
        }
    }

    private func commit(_ index: Int, _ value: String) {
        guard index < rows.count, rows[index]["readOnly"]?.boolValue != true, let rowId = rows[index]["id"]?.stringValue else { return }
        host?.sendEvent(id, "setting", .object(["id": .string(rowId), "value": .string(value)]))
    }

    @objc private func popupChanged(_ sender: NSPopUpButton) {
        commit(sender.tag, sender.titleOfSelectedItem ?? "")
    }

    func controlTextDidEndEditing(_ notification: Notification) {
        guard let field = notification.object as? NSTextField else { return }
        let index = field.tag
        if index < rows.count, rows[index]["value"]?.stringValue != field.stringValue {
            commit(index, field.stringValue)
        }
    }

    override func contentSize(width: CGFloat?) -> NSSize {
        let natural = sizeColumns(width ?? 600)
        return NSSize(width: width ?? natural, height: grid.fittingSize.height)
    }

    /// Labels as wide as the widest; editors up to a comfortable width, left-aligned.
    private func sizeColumns(_ width: CGFloat) -> CGFloat {
        guard grid.numberOfColumns == 2 else { return 0 }
        let labels = (0..<grid.numberOfRows).compactMap { grid.cell(atColumnIndex: 0, rowIndex: $0).contentView?.fittingSize.width }.max() ?? 0
        let editors = max(200, min(width - labels - grid.columnSpacing, 380))
        grid.column(at: 0).width = labels
        grid.column(at: 1).width = editors
        return labels + grid.columnSpacing + editors
    }

    override func layoutContent(_ rect: NSRect) {
        let width = sizeColumns(rect.width)
        grid.frame = NSRect(x: rect.minX, y: rect.minY, width: width, height: grid.fittingSize.height)
    }
}
