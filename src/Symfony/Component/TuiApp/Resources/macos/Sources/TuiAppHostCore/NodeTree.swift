/// One node of the widget tree, as the application describes it.
public struct Node: Sendable, Equatable {
    public let id: Int
    public let kind: String
    public internal(set) var props: [String: JSON]
    public internal(set) var parent: Int?
    public internal(set) var children: [Int]
}

/// What a patch changed, for the UI to rebuild only that.
public struct PatchResult: Sendable, Equatable {
    /// New nodes, in creation order.
    public var created: [Int] = []
    /// Existing nodes whose props changed.
    public var updated: Set<Int> = []
    /// Nodes gone, descendants included.
    public var removed: [Int] = []
    /// Nodes whose list of children changed.
    public var restructured: Set<Int> = []
    public var rootChanged = false
    /// Ops that could not be applied, with why. They are skipped.
    public var errors: [String] = []
}

/// The node tree. Patches are applied in order; a prop set to null goes back to its default,
/// which means it is dropped.
public struct NodeTree: Sendable {
    public private(set) var nodes: [Int: Node] = [:]
    public private(set) var root: Int?

    public init() {}

    public subscript(id: Int) -> Node? { nodes[id] }

    /// Every node id, parents before children.
    public var orderedIds: [Int] {
        var out = [Int]()
        func walk(_ id: Int) {
            out.append(id)
            nodes[id]?.children.forEach(walk)
        }
        root.map(walk)
        return out
    }

    @discardableResult
    public mutating func apply(_ ops: [PatchOp]) -> PatchResult {
        var result = PatchResult()
        for op in ops {
            switch op {
            case let .create(id, kind, parent, index, props):
                if nodes[id] != nil {
                    removeSubtree(id, &result)
                }
                let clean = props.filter { !$0.value.isNull }
                if let parent {
                    guard nodes[parent] != nil else {
                        result.errors.append("create \(id): no parent \(parent)")
                        continue
                    }
                    nodes[id] = Node(id: id, kind: kind, props: clean, parent: parent, children: [])
                    insert(id, into: parent, at: index)
                    result.restructured.insert(parent)
                } else {
                    if let old = root, old != id {
                        removeSubtree(old, &result)
                    }
                    nodes[id] = Node(id: id, kind: kind, props: clean, parent: nil, children: [])
                    root = id
                    result.rootChanged = true
                }
                result.removed.removeAll { $0 == id }
                result.created.append(id)
            case let .update(id, props):
                guard var node = nodes[id] else {
                    result.errors.append("update \(id): no such node")
                    continue
                }
                for (key, value) in props {
                    if value.isNull {
                        node.props.removeValue(forKey: key)
                    } else {
                        node.props[key] = value
                    }
                }
                nodes[id] = node
                if !result.created.contains(id) {
                    result.updated.insert(id)
                }
            case let .move(id, parent, index):
                guard let node = nodes[id], nodes[parent] != nil else {
                    result.errors.append("move \(id): no such node or parent \(parent)")
                    continue
                }
                guard !isAncestor(id, of: parent) else {
                    result.errors.append("move \(id): into its own subtree")
                    continue
                }
                if let old = node.parent {
                    nodes[old]?.children.removeAll { $0 == id }
                    result.restructured.insert(old)
                } else if root == id {
                    root = nil
                    result.rootChanged = true
                }
                nodes[id]?.parent = parent
                insert(id, into: parent, at: index)
                result.restructured.insert(parent)
            case let .remove(id):
                guard nodes[id] != nil else {
                    result.errors.append("remove \(id): no such node")
                    continue
                }
                removeSubtree(id, &result)
            }
        }
        result.restructured = result.restructured.filter { nodes[$0] != nil }
        result.updated = result.updated.filter { nodes[$0] != nil }
        result.created = result.created.filter { nodes[$0] != nil }
        return result
    }

    private func isAncestor(_ ancestor: Int, of id: Int) -> Bool {
        var current: Int? = id
        while let c = current {
            if c == ancestor { return true }
            current = nodes[c]?.parent
        }
        return false
    }

    private mutating func insert(_ id: Int, into parent: Int, at index: Int) {
        var children = nodes[parent]!.children
        children.insert(id, at: max(0, min(index, children.count)))
        nodes[parent]!.children = children
    }

    private mutating func removeSubtree(_ id: Int, _ result: inout PatchResult) {
        guard let node = nodes[id] else { return }
        if let parent = node.parent {
            nodes[parent]?.children.removeAll { $0 == id }
            result.restructured.insert(parent)
        }
        if root == id {
            root = nil
            result.rootChanged = true
        }
        var stack = [id]
        while let next = stack.popLast() {
            if let removed = nodes.removeValue(forKey: next) {
                stack.append(contentsOf: removed.children)
                result.removed.append(next)
                result.updated.remove(next)
                result.restructured.remove(next)
                result.created.removeAll { $0 == next }
            }
        }
    }

    /// The prop a user event changes on a control of a kind, as the application takes it: it does not
    /// send it back (TuiApp's HostEvent::acknowledge), so an update of its other props must not put the
    /// old one back. `change` and `submit` set the value of a text field, a password field, a text area or
    /// a number field, `change` the value of a slider, `toggle` the checked of a check box and the
    /// expanded of a disclosure, `select` the selected of a pop-up, a list box or a list (the checked
    /// indexes of a multiple list), `tab` the active tab.
    public static func acknowledged(kind: String, event: String, value: JSON?) -> (name: String, value: JSON)? {
        guard let value else { return nil }
        switch (kind, event, value) {
        case ("textfield", "change", .string), ("textfield", "submit", .string), ("password", "change", .string), ("password", "submit", .string),
             ("textarea", "change", .string), ("textarea", "submit", .string), ("number", "change", .string), ("number", "submit", .string):
            return ("value", value)
        case ("slider", "change", .int), ("slider", "change", .double):
            return ("value", value)
        case ("checkbox", "toggle", .bool):
            return ("checked", value)
        case ("disclosure", "toggle", .bool):
            return ("expanded", value)
        case ("popup", "select", .int), ("list", "select", .int), ("listbox", "select", .array):
            return ("selected", value)
        case ("list", "select", .array):
            return ("checked", value)
        case ("tabs", "tab", .int):
            return ("active", value)
        default:
            return nil
        }
    }

    /// Records what a user event changed on a node (see `acknowledged`); true when a prop changed.
    @discardableResult
    public mutating func acknowledge(node id: Int, event: String, value: JSON?) -> Bool {
        guard let node = nodes[id], let (name, value) = Self.acknowledged(kind: node.kind, event: event, value: value), node.props[name] != value else { return false }
        nodes[id]?.props[name] = value
        return true
    }

    /// The tree as `{id, kind, props, children}`, the shape of `session.json`.
    public func json(_ id: Int? = nil) -> JSON {
        guard let id = id ?? root, let node = nodes[id] else { return .null }
        return [
            "id": .int(Int64(node.id)),
            "kind": .string(node.kind),
            "props": .object(node.props),
            "children": .array(node.children.map { json($0) }),
        ]
    }
}
