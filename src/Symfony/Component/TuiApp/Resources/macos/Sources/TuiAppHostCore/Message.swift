import Foundation

/// A property that can be absent, explicitly null, or set. `window.progress: null` clears the
/// progress and `window.title: null` gives back the title of the manifest, while a missing
/// property leaves it alone, so the difference matters.
public enum Field<Value: Sendable & Equatable>: Sendable, Equatable {
    case absent
    case null
    case value(Value)

    public var value: Value? {
        if case let .value(value) = self { return value }
        return nil
    }
}

public struct Hello: Sendable, Equatable {
    public var columns: Int
    public var rows: Int
    public var cellWidth: Double
    public var cellHeight: Double
    public var scale: Double?
    public var appearance: String
    public var capabilities: [String]?
    public var os: String

    public init(columns: Int, rows: Int, cellWidth: Double, cellHeight: Double, scale: Double?, appearance: String, capabilities: [String]?, os: String = "macos") {
        self.columns = columns
        self.rows = rows
        self.cellWidth = cellWidth
        self.cellHeight = cellHeight
        self.scale = scale
        self.appearance = appearance
        self.capabilities = capabilities
        self.os = os
    }
}

public enum PatchOp: Sendable, Equatable {
    case create(id: Int, kind: String, parent: Int?, index: Int, props: [String: JSON])
    case update(id: Int, props: [String: JSON])
    case move(id: Int, parent: Int, index: Int)
    case remove(id: Int)
}

public struct MenuItem: Sendable, Equatable {
    public var id: String?
    public var label: String?
    public var shortcut: Field<String>
    public var separator: Bool?
    public var enabled: Bool?
    public var checked: Field<Bool>

    public init(id: String? = nil, label: String? = nil, shortcut: Field<String> = .absent, separator: Bool? = nil, enabled: Bool? = nil, checked: Field<Bool> = .absent) {
        self.id = id
        self.label = label
        self.shortcut = shortcut
        self.separator = separator
        self.enabled = enabled
        self.checked = checked
    }
}

/// An item of a `contextmenu`: a separator, or an item with its id and label.
public struct ContextMenuItem: Sendable, Equatable {
    public var id: String?
    public var label: String?
    public var separator: Bool
    /// nil when not sent: enabled.
    public var enabled: Bool?
    /// `paste`: the host pastes instead of sending `contextmenu-select`.
    public var role: String?

    public init(id: String? = nil, label: String? = nil, separator: Bool = false, enabled: Bool? = nil, role: String? = nil) {
        self.id = id
        self.label = label
        self.separator = separator
        self.enabled = enabled
        self.role = role
    }

    public static let separator = ContextMenuItem(separator: true)
}

public struct Menu: Sendable, Equatable {
    public var title: String
    public var items: [MenuItem]

    public init(title: String, items: [MenuItem]) {
        self.title = title
        self.items = items
    }
}

public struct WindowProgress: Sendable, Equatable {
    public var state: String
    public var value: Field<Double>

    public init(state: String, value: Field<Double>) {
        self.state = state
        self.value = value
    }
}

/// Every message of protocol 1, both directions.
public enum Message: Sendable, Equatable {
    case auth(token: String, protocol: Int)
    case hello(Hello)
    case patch([PatchOp])
    case focus(node: Int?)
    case window(title: Field<String>, progress: Field<WindowProgress>)
    case notify(title: String, body: String?)
    case beep
    case menu([Menu])
    case closeReply(accept: Bool)
    case bye(status: Int?)
    case resize(columns: Int, rows: Int)
    /// value nil: absent; `.null`: explicitly null.
    case event(node: Int, name: String, value: JSON?)
    case key(key: String, text: String?)
    case paste(text: String)
    case pointer(node: Int, Pointer)
    /// The CSS cursor keyword to show over a canvas, kept as sent: see CursorShape.
    case cursor(node: Int, shape: String)
    case rects([Int: [Int]])
    /// A menu to show at a cell of a canvas, with the `contextmenu` capability.
    case contextMenu(node: Int, row: Int, column: Int, items: [ContextMenuItem])
    case contextMenuSelect(id: String)
    /// Text to put on the clipboard, with the `clipboard` capability.
    case clipboard(text: String)
    case menuSelect(id: String)
    case appearance(String)
    case open(paths: [String])
    case close
    /// A message of a type this host does not know: ignored.
    case unknown(type: String, JSON)

    public enum DecodeError: Error, Equatable {
        case notAnObject
        case missing(String, in: String)
        case invalid(String, in: String)
    }

    public var type: String {
        switch self {
        case .auth: return "auth"
        case .hello: return "hello"
        case .patch: return "patch"
        case .focus: return "focus"
        case .window: return "window"
        case .notify: return "notify"
        case .beep: return "beep"
        case .menu: return "menu"
        case .closeReply: return "close-reply"
        case .bye: return "bye"
        case .resize: return "resize"
        case .event: return "event"
        case .key: return "key"
        case .paste: return "paste"
        case .pointer: return "pointer"
        case .cursor: return "cursor"
        case .rects: return "rects"
        case .menuSelect: return "menu-select"
        case .contextMenu: return "contextmenu"
        case .contextMenuSelect: return "contextmenu-select"
        case .clipboard: return "clipboard"
        case .appearance: return "appearance"
        case .open: return "open"
        case .close: return "close"
        case let .unknown(type, _): return type
        }
    }

    // MARK: Decoding

    public static func decode(_ data: Data) throws -> Message {
        try decode(JSON.parse(data))
    }

    public static func decode(_ json: JSON) throws -> Message {
        guard case let .object(o) = json else { throw DecodeError.notAnObject }
        let r = Reader(o: o, type: o["type"]?.stringValue ?? "")
        switch r.type {
        case "auth":
            return .auth(token: try r.string("token"), protocol: try r.int("protocol"))
        case "hello":
            guard case let .object(cell)? = o["cell"], let width = cell["width"]?.doubleValue, let height = cell["height"]?.doubleValue else {
                throw DecodeError.invalid("cell", in: r.type)
            }
            return .hello(Hello(
                columns: try r.int("columns"), rows: try r.int("rows"), cellWidth: width, cellHeight: height,
                scale: o["scale"]?.doubleValue, appearance: try r.string("appearance"),
                capabilities: try o["capabilities"].map { try strings($0, "capabilities", r.type) },
                os: try r.string("os")
            ))
        case "patch":
            guard case let .array(ops)? = o["ops"] else { throw DecodeError.missing("ops", in: r.type) }
            return .patch(try ops.map(op))
        case "focus":
            guard let node = o["node"] else { throw DecodeError.missing("node", in: r.type) }
            return .focus(node: node.intValue)
        case "window":
            let progress: Field<WindowProgress>
            switch o["progress"] {
            case nil: progress = .absent
            case .null?: progress = .null
            case let .object(p)?:
                guard let state = p["state"]?.stringValue else { throw DecodeError.missing("progress.state", in: r.type) }
                progress = .value(WindowProgress(state: state, value: field(p["value"]) { $0.doubleValue }))
            default: throw DecodeError.invalid("progress", in: r.type)
            }
            return .window(title: field(o["title"]) { $0.stringValue }, progress: progress)
        case "notify":
            return .notify(title: try r.string("title"), body: o["body"]?.stringValue)
        case "beep":
            return .beep
        case "menu":
            guard case let .array(menus)? = o["menus"] else { throw DecodeError.missing("menus", in: r.type) }
            return .menu(try menus.map { menu in
                guard case let .object(m) = menu, let title = m["title"]?.stringValue, case let .array(items)? = m["items"] else {
                    throw DecodeError.invalid("menus", in: r.type)
                }
                return Menu(title: title, items: items.map { item in
                    let i = item.objectValue ?? [:]
                    return MenuItem(
                        id: i["id"]?.stringValue, label: i["label"]?.stringValue,
                        shortcut: field(i["shortcut"]) { $0.stringValue },
                        separator: i["separator"]?.boolValue, enabled: i["enabled"]?.boolValue,
                        checked: field(i["checked"]) { $0.boolValue }
                    )
                })
            })
        case "close-reply":
            return .closeReply(accept: try r.bool("accept"))
        case "bye":
            return .bye(status: o["status"]?.intValue)
        case "resize":
            return .resize(columns: try r.int("columns"), rows: try r.int("rows"))
        case "event":
            return .event(node: try r.int("node"), name: try r.string("name"), value: o["value"])
        case "key":
            return .key(key: try r.string("key"), text: o["text"]?.stringValue)
        case "paste":
            return .paste(text: try r.string("text"))
        case "pointer":
            let button: String?
            switch o["button"] {
            case nil, .null?: button = nil
            case let value?:
                guard let name = value.stringValue else { throw DecodeError.invalid("button", in: r.type) }
                button = name
            }
            return .pointer(node: try r.int("node"), Pointer(
                action: try r.string("action"), row: try r.int("row"), column: try r.int("column"), button: button,
                modifiers: try o["modifiers"].map { try strings($0, "modifiers", r.type) } ?? [],
                rows: o["rows"]?.intValue, columns: o["columns"]?.intValue
            ))
        case "cursor":
            return .cursor(node: try r.int("node"), shape: try r.string("shape"))
        case "rects":
            guard case let .object(rects)? = o["rects"] else { throw DecodeError.missing("rects", in: r.type) }
            var out = [Int: [Int]]()
            for (key, value) in rects {
                guard let id = Int(key), let cells = value.arrayValue?.compactMap(\.intValue), cells.count == 4 else {
                    throw DecodeError.invalid("rects", in: r.type)
                }
                out[id] = cells
            }
            return .rects(out)
        case "menu-select":
            return .menuSelect(id: try r.string("id"))
        case "contextmenu":
            guard case let .array(items)? = o["items"] else { throw DecodeError.missing("items", in: r.type) }
            return .contextMenu(node: try r.int("node"), row: try r.int("row"), column: try r.int("column"), items: try items.map { item in
                guard case let .object(i) = item else { throw DecodeError.invalid("items", in: r.type) }
                if i["separator"]?.boolValue == true { return .separator }
                guard let id = i["id"]?.stringValue, let label = i["label"]?.stringValue else { throw DecodeError.invalid("items", in: r.type) }
                return ContextMenuItem(id: id, label: label, enabled: i["enabled"]?.boolValue, role: i["role"]?.stringValue)
            })
        case "contextmenu-select":
            return .contextMenuSelect(id: try r.string("id"))
        case "clipboard":
            return .clipboard(text: try r.string("text"))
        case "appearance":
            return .appearance(try r.string("value"))
        case "open":
            guard let paths = o["paths"] else { throw DecodeError.missing("paths", in: r.type) }
            return .open(paths: try strings(paths, "paths", r.type))
        case "close":
            return .close
        default:
            return .unknown(type: r.type, json)
        }
    }

    private struct Reader {
        let o: [String: JSON]
        let type: String

        func string(_ key: String) throws -> String {
            guard let value = o[key] else { throw DecodeError.missing(key, in: type) }
            guard let string = value.stringValue else { throw DecodeError.invalid(key, in: type) }
            return string
        }

        func int(_ key: String) throws -> Int {
            guard let value = o[key] else { throw DecodeError.missing(key, in: type) }
            guard let int = value.intValue else { throw DecodeError.invalid(key, in: type) }
            return int
        }

        func bool(_ key: String) throws -> Bool {
            guard let value = o[key] else { throw DecodeError.missing(key, in: type) }
            guard let bool = value.boolValue else { throw DecodeError.invalid(key, in: type) }
            return bool
        }
    }

    private static func strings(_ json: JSON, _ key: String, _ type: String) throws -> [String] {
        guard let values = json.arrayValue else { throw DecodeError.invalid(key, in: type) }
        return try values.map {
            guard let string = $0.stringValue else { throw DecodeError.invalid(key, in: type) }
            return string
        }
    }

    private static func field<T>(_ json: JSON?, _ convert: (JSON) -> T?) -> Field<T> {
        switch json {
        case nil: return .absent
        case .null?: return .null
        case let value?: return convert(value).map(Field.value) ?? .null
        }
    }

    private static func op(_ json: JSON) throws -> PatchOp {
        guard case let .object(o) = json, let name = o["op"]?.stringValue, let id = o["id"]?.intValue else {
            throw DecodeError.invalid("op", in: "patch")
        }
        let props = o["props"]?.objectValue ?? [:]
        switch name {
        case "create":
            guard let kind = o["kind"]?.stringValue else { throw DecodeError.missing("kind", in: "patch") }
            return .create(id: id, kind: kind, parent: o["parent"]?.intValue, index: o["index"]?.intValue ?? 0, props: props)
        case "update":
            return .update(id: id, props: props)
        case "move":
            guard let parent = o["parent"]?.intValue else { throw DecodeError.missing("parent", in: "patch") }
            return .move(id: id, parent: parent, index: o["index"]?.intValue ?? 0)
        case "remove":
            return .remove(id: id)
        default:
            throw DecodeError.invalid("op \(name)", in: "patch")
        }
    }

    // MARK: Encoding

    public func encode() -> Data {
        json.data
    }

    public var json: JSON {
        var o: [String: JSON] = ["type": .string(type)]
        func put<T>(_ key: String, _ field: Field<T>, _ convert: (T) -> JSON) {
            switch field {
            case .absent: break
            case .null: o[key] = .null
            case let .value(value): o[key] = convert(value)
            }
        }
        switch self {
        case let .auth(token, version):
            o["token"] = .string(token)
            o["protocol"] = .int(Int64(version))
        case let .hello(hello):
            o["protocol"] = 1
            o["os"] = .string(hello.os)
            o["columns"] = .int(Int64(hello.columns))
            o["rows"] = .int(Int64(hello.rows))
            o["cell"] = .object(["width": .double(hello.cellWidth), "height": .double(hello.cellHeight)])
            if let scale = hello.scale { o["scale"] = .double(scale) }
            o["appearance"] = .string(hello.appearance)
            if let capabilities = hello.capabilities { o["capabilities"] = .array(capabilities.map(JSON.string)) }
        case let .patch(ops):
            o["ops"] = .array(ops.map { op in
                switch op {
                case let .create(id, kind, parent, index, props):
                    return ["op": "create", "id": .int(Int64(id)), "kind": .string(kind), "parent": parent.map { .int(Int64($0)) } ?? .null, "index": .int(Int64(index)), "props": .object(props)]
                case let .update(id, props):
                    return ["op": "update", "id": .int(Int64(id)), "props": .object(props)]
                case let .move(id, parent, index):
                    return ["op": "move", "id": .int(Int64(id)), "parent": .int(Int64(parent)), "index": .int(Int64(index))]
                case let .remove(id):
                    return ["op": "remove", "id": .int(Int64(id))]
                }
            })
        case let .focus(node):
            o["node"] = node.map { .int(Int64($0)) } ?? .null
        case let .window(title, progress):
            put("title", title) { .string($0) }
            put("progress", progress) { p in
                var po: [String: JSON] = ["state": .string(p.state)]
                switch p.value {
                case .absent: break
                case .null: po["value"] = .null
                case let .value(v): po["value"] = .double(v)
                }
                return .object(po)
            }
        case let .notify(title, body):
            o["title"] = .string(title)
            if let body { o["body"] = .string(body) }
        case .beep, .close:
            break
        case let .menu(menus):
            o["menus"] = .array(menus.map { menu in
                .object(["title": .string(menu.title), "items": .array(menu.items.map { item in
                    var io = [String: JSON]()
                    if let id = item.id { io["id"] = .string(id) }
                    if let label = item.label { io["label"] = .string(label) }
                    switch item.shortcut {
                    case .absent: break
                    case .null: io["shortcut"] = .null
                    case let .value(v): io["shortcut"] = .string(v)
                    }
                    if let separator = item.separator { io["separator"] = .bool(separator) }
                    if let enabled = item.enabled { io["enabled"] = .bool(enabled) }
                    switch item.checked {
                    case .absent: break
                    case .null: io["checked"] = .null
                    case let .value(v): io["checked"] = .bool(v)
                    }
                    return .object(io)
                })])
            })
        case let .closeReply(accept):
            o["accept"] = .bool(accept)
        case let .bye(status):
            if let status { o["status"] = .int(Int64(status)) }
        case let .resize(columns, rows):
            o["columns"] = .int(Int64(columns))
            o["rows"] = .int(Int64(rows))
        case let .event(node, name, value):
            o["node"] = .int(Int64(node))
            o["name"] = .string(name)
            if let value { o["value"] = value }
        case let .key(key, text):
            o["key"] = .string(key)
            o["text"] = text.map(JSON.string) ?? .null
        case let .paste(text):
            o["text"] = .string(text)
        case let .pointer(node, pointer):
            o["node"] = .int(Int64(node))
            o["action"] = .string(pointer.action)
            o["row"] = .int(Int64(pointer.row))
            o["column"] = .int(Int64(pointer.column))
            o["button"] = pointer.button.map(JSON.string) ?? .null
            o["modifiers"] = .array(pointer.modifiers.map(JSON.string))
            if let rows = pointer.rows { o["rows"] = .int(Int64(rows)) }
            if let columns = pointer.columns { o["columns"] = .int(Int64(columns)) }
        case let .cursor(node, shape):
            o["node"] = .int(Int64(node))
            o["shape"] = .string(shape)
        case let .rects(rects):
            o["rects"] = .object(Dictionary(uniqueKeysWithValues: rects.map { (String($0.key), JSON.array($0.value.map { .int(Int64($0)) })) }))
        case let .menuSelect(id), let .contextMenuSelect(id):
            o["id"] = .string(id)
        case let .contextMenu(node, row, column, items):
            o["node"] = .int(Int64(node))
            o["row"] = .int(Int64(row))
            o["column"] = .int(Int64(column))
            o["items"] = .array(items.map { item in
                if item.separator { return ["separator": true] }
                var io = [String: JSON]()
                if let id = item.id { io["id"] = .string(id) }
                if let label = item.label { io["label"] = .string(label) }
                if let enabled = item.enabled { io["enabled"] = .bool(enabled) }
                if let role = item.role { io["role"] = .string(role) }
                return .object(io)
            })
        case let .clipboard(text):
            o["text"] = .string(text)
        case let .appearance(value):
            o["value"] = .string(value)
        case let .open(paths):
            o["paths"] = .array(paths.map(JSON.string))
        case let .unknown(_, json):
            return json
        }
        return .object(o)
    }
}
