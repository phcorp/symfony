import AppKit
import TuiAppHostCore

/// Lays children along one axis: `grow` > 0 share the free space by weight, the others take
/// their natural size. NSStackView cannot weigh its free space, so this is done by hand.
@MainActor
enum StackLayout {
    static func sizes(_ children: [NodeView], vertical: Bool, length: CGFloat, across: CGFloat?, gap: CGFloat) -> [CGFloat] {
        let visible = children.filter { !$0.isNodeHidden }
        guard !visible.isEmpty else { return children.map { _ in 0 } }
        let gaps = gap * CGFloat(visible.count - 1)
        let naturals = children.map { child -> CGFloat in
            guard !child.isNodeHidden else { return 0 }
            return vertical ? child.measure(width: across).height : child.measure(width: nil).width
        }
        let weights = children.map { $0.isNodeHidden ? 0 : $0.grow }
        let total = weights.reduce(0, +)
        let fixed = zip(naturals, weights).filter { $0.1 == 0 }.map(\.0).reduce(0, +)
        var free = length - gaps - fixed
        var out = naturals
        if total > 0 {
            free = max(0, free)
            for index in children.indices where weights[index] > 0 {
                let share = free * CGFloat(weights[index]) / CGFloat(total)
                // Vertically a grower never gets less than it needs; across a row it may shrink.
                out[index] = vertical ? max(share, naturals[index]) : share
            }
        } else if !vertical, free < 0, fixed > 0 {
            // Too wide for the row: every child gives up room in proportion.
            let scale = max(0, length - gaps) / fixed
            out = naturals.map { floor($0 * scale) }
        }
        return out
    }
}

/// `stack`: a vertical or horizontal box; `gap` and `padding` in cells.
final class StackNodeView: NodeView {
    var vertical: Bool { props["direction"]?.stringValue != "horizontal" }
    var gap: CGFloat { CGFloat(max(0, props["gap"]?.intValue ?? 0)) * (vertical ? cell.height : cell.width) }

    override var stretches: Bool { true }

    override func setChildren(_ views: [NodeView]) {
        super.setChildren(views)
        for view in subviews where !views.contains(where: { $0 === view }) {
            view.removeFromSuperview()
        }
        for view in views where view.superview !== self {
            view.removeFromSuperview()
            addSubview(view)
        }
    }

    override func contentSize(width: CGFloat?) -> NSSize {
        let visible = nodeChildren.filter { !$0.isNodeHidden }
        guard !visible.isEmpty else { return .zero }
        let gaps = gap * CGFloat(visible.count - 1)
        if vertical {
            let natural = visible.map { $0.measure(width: nil).width }.max() ?? 0
            let w = width ?? natural
            return NSSize(width: w, height: visible.map { $0.measure(width: w).height }.reduce(gaps, +))
        }
        if let width {
            let widths = StackLayout.sizes(nodeChildren, vertical: false, length: width, across: nil, gap: gap)
            let height = zip(nodeChildren, widths).filter { !$0.0.isNodeHidden }.map { $0.0.measure(width: $0.1).height }.max() ?? 0
            return NSSize(width: width, height: height)
        }
        let sizes = visible.map { $0.measure(width: nil) }
        return NSSize(width: sizes.map(\.width).reduce(gaps, +), height: sizes.map(\.height).max() ?? 0)
    }

    override func layoutContent(_ rect: NSRect) {
        if vertical {
            let heights = StackLayout.sizes(nodeChildren, vertical: true, length: rect.height, across: rect.width, gap: gap)
            var y = rect.minY
            for (child, height) in zip(nodeChildren, heights) where !child.isNodeHidden {
                place(child, NSRect(x: rect.minX, y: y, width: rect.width, height: height))
                y += height + gap
            }
        } else {
            let widths = StackLayout.sizes(nodeChildren, vertical: false, length: rect.width, across: nil, gap: gap)
            var x = rect.minX
            for (child, width) in zip(nodeChildren, widths) where !child.isNodeHidden {
                let height = child.stretches ? rect.height : min(rect.height, child.measure(width: width).height)
                place(child, NSRect(x: x, y: rect.minY + (rect.height - height) / 2, width: width, height: height))
                x += width + gap
            }
        }
    }
}

/// `tabs`: an NSTabView; tab i shows child i; `tab` with the index when the user switches.
final class TabsNodeView: NodeView, NSTabViewDelegate {
    let tabView = NSTabView()
    private var programmatic = false

    override init(id: Int, kind: String, host: NodeHost) {
        super.init(id: id, kind: kind, host: host)
        tabView.delegate = self
        addSubview(tabView)
    }

    override var nativeView: NSView { tabView }
    override var focusTarget: NSView? { tabView }
    override var stretches: Bool { true }

    private var titles: [String] { props["tabs"]?.arrayValue?.compactMap(\.stringValue) ?? [] }

    override func apply(old: [String: JSON]) {
        switch props["position"]?.stringValue {
        case "bottom": tabView.tabPosition = .bottom
        case "left": tabView.tabPosition = .left
        case "right": tabView.tabPosition = .right
        default: tabView.tabPosition = .top
        }
        syncItems()
    }

    override func setChildren(_ views: [NodeView]) {
        super.setChildren(views)
        syncItems()
    }

    private func syncItems() {
        programmatic = true
        defer { programmatic = false }
        let count = max(nodeChildren.count, titles.count)
        while tabView.numberOfTabViewItems > count {
            tabView.removeTabViewItem(tabView.tabViewItems.last!)
        }
        while tabView.numberOfTabViewItems < count {
            let item = NSTabViewItem(identifier: nil)
            item.view = FlippedView()
            tabView.addTabViewItem(item)
        }
        for (index, item) in tabView.tabViewItems.enumerated() {
            item.label = index < titles.count ? titles[index] : "Tab \(index + 1)"
            let pane = item.view!
            if index < nodeChildren.count {
                let child = nodeChildren[index]
                if child.superview !== pane {
                    pane.subviews.forEach { $0.removeFromSuperview() }
                    child.removeFromSuperview()
                    pane.addSubview(child)
                }
            } else {
                pane.subviews.forEach { $0.removeFromSuperview() }
            }
        }
        let active = props["active"]?.intValue ?? 0
        if active >= 0, active < tabView.numberOfTabViewItems, tabView.indexOfTabViewItem(tabView.selectedTabViewItem ?? NSTabViewItem()) != active {
            tabView.selectTabViewItem(at: active)
        }
    }

    private var chrome: NSSize {
        let probe = NSRect(x: 0, y: 0, width: 400, height: 300)
        let saved = tabView.frame
        tabView.frame = probe
        let content = tabView.contentRect
        tabView.frame = saved
        return NSSize(width: probe.width - content.width, height: probe.height - content.height)
    }

    override func contentSize(width: CGFloat?) -> NSSize {
        let c = chrome
        let paneWidth = width.map { max(0, $0 - c.width) }
        let panes = nodeChildren.map { $0.measure(width: paneWidth) }
        let height = max(panes.map(\.height).max() ?? 0, cell.height * 2)
        let natural = max(panes.map(\.width).max() ?? 0, CGFloat(titles.count) * 90)
        return NSSize(width: width ?? natural + c.width, height: height + c.height)
    }

    override func layoutContent(_ rect: NSRect) {
        tabView.frame = rect
        let content = tabView.contentRect
        for (index, item) in tabView.tabViewItems.enumerated() where index < nodeChildren.count {
            item.view?.frame = content
            place(nodeChildren[index], NSRect(origin: .zero, size: content.size))
        }
    }

    func tabView(_ tabView: NSTabView, shouldSelect item: NSTabViewItem?) -> Bool {
        programmatic || !isDisabled
    }

    func tabView(_ tabView: NSTabView, didSelect item: NSTabViewItem?) {
        host?.setNeedsRelayout()
        guard !programmatic, let item else { return }
        host?.sendEvent(id, "tab", .int(Int64(tabView.indexOfTabViewItem(item))))
    }
}

/// `disclosure`: a disclosure triangle with `summary`, `description` in secondary text, and the
/// children as its content; `toggle` with the new state.
final class DisclosureNodeView: NodeView {
    let button = NSButton()
    let summary = NSTextField(labelWithString: "")
    let detail = Style.secondaryLabel()
    let content = FlippedView()
    private var expanded = false
    private let indent: CGFloat = 20
    private let spacing: CGFloat = 4

    override init(id: Int, kind: String, host: NodeHost) {
        super.init(id: id, kind: kind, host: host)
        button.bezelStyle = .disclosure
        button.setButtonType(.pushOnPushOff)
        button.title = ""
        button.target = self
        button.action = #selector(toggled)
        summary.font = .systemFont(ofSize: NSFont.systemFontSize, weight: .medium)
        summary.addGestureRecognizer(NSClickGestureRecognizer(target: self, action: #selector(summaryClicked)))
        addSubview(button)
        addSubview(summary)
        addSubview(detail)
        addSubview(content)
    }

    override var nativeView: NSView { button }
    override var focusTarget: NSView? { button }
    override var stretches: Bool { expanded }

    override func apply(old: [String: JSON]) {
        summary.stringValue = props["summary"]?.stringValue ?? ""
        detail.stringValue = props["description"]?.stringValue ?? ""
        detail.isHidden = detail.stringValue.isEmpty
        if let fg = Style.color(style["fg"]) { summary.textColor = fg }
        if old["expanded"] != props["expanded"] || old.isEmpty {
            setExpanded(props["expanded"]?.boolValue == true)
        }
        button.isEnabled = !isDisabled
    }

    private func setExpanded(_ value: Bool) {
        expanded = value
        button.state = value ? .on : .off
        content.isHidden = !value
    }

    override func setChildren(_ views: [NodeView]) {
        super.setChildren(views)
        content.subviews.filter { view in !views.contains { $0 === view } }.forEach { $0.removeFromSuperview() }
        for view in views where view.superview !== content {
            view.removeFromSuperview()
            content.addSubview(view)
        }
    }

    @objc private func toggled() {
        setExpanded(button.state == .on)
        host?.sendEvent(id, "toggle", .bool(expanded))
        host?.setNeedsRelayout()
    }

    @objc private func summaryClicked() {
        guard button.isEnabled else { return }
        button.state = expanded ? .off : .on
        toggled()
    }

    private var headerHeight: CGFloat { max(button.fittingSize.height, summary.fittingSize.height) }

    private func detailHeight(_ width: CGFloat) -> CGFloat {
        detail.isHidden ? 0 : Style.height(of: detail, width: width - indent)
    }

    private func childHeights(_ width: CGFloat) -> [CGFloat] {
        nodeChildren.map { $0.measure(width: width - indent).height }
    }

    override func contentSize(width: CGFloat?) -> NSSize {
        let natural = indent + max(summary.fittingSize.width, detail.isHidden ? 0 : Style.naturalWidth(of: detail), nodeChildren.map { $0.measure(width: nil).width }.max() ?? 0)
        let w = width ?? natural
        var height = headerHeight + detailHeight(w)
        if expanded {
            let children = childHeights(w).filter { $0 > 0 }
            height += children.reduce(0) { $0 + $1 + spacing }
        }
        return NSSize(width: w, height: height)
    }

    override func layoutContent(_ rect: NSRect) {
        let header = headerHeight
        let buttonSize = button.fittingSize
        button.frame = NSRect(x: rect.minX, y: rect.minY + (header - buttonSize.height) / 2, width: buttonSize.width, height: buttonSize.height)
        let summarySize = summary.fittingSize
        summary.frame = NSRect(x: rect.minX + indent, y: rect.minY + (header - summarySize.height) / 2, width: max(0, rect.width - indent), height: summarySize.height)
        let detailH = detailHeight(rect.width)
        detail.frame = NSRect(x: rect.minX + indent, y: rect.minY + header, width: max(0, rect.width - indent), height: detailH)
        let top = rect.minY + header + detailH
        content.frame = NSRect(x: rect.minX + indent, y: top, width: max(0, rect.width - indent), height: max(0, rect.maxY - top))
        var y: CGFloat = spacing
        for (child, height) in zip(nodeChildren, childHeights(rect.width)) where !child.isNodeHidden {
            place(child, NSRect(x: 0, y: y, width: content.bounds.width, height: height))
            y += height + spacing
        }
    }
}
