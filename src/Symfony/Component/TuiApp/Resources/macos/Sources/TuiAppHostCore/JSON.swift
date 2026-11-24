import Foundation

/// A JSON value. Integers and doubles compare by value, so `17` equals `17.0`.
public enum JSON: Sendable, Equatable, CustomStringConvertible {
    case null
    case bool(Bool)
    case int(Int64)
    case double(Double)
    case string(String)
    case array([JSON])
    case object([String: JSON])

    public enum ParseError: Error, Equatable {
        case invalid(String)
    }

    // MARK: Parsing

    public static func parse(_ data: Data) throws -> JSON {
        let object: Any
        do {
            object = try JSONSerialization.jsonObject(with: data, options: [.fragmentsAllowed])
        } catch {
            throw ParseError.invalid(String(describing: error))
        }
        return try from(foundation: object)
    }

    public static func parse(_ text: String) throws -> JSON {
        try parse(Data(text.utf8))
    }

    static func from(foundation value: Any) throws -> JSON {
        switch value {
        case is NSNull:
            return .null
        case let number as NSNumber:
            if CFGetTypeID(number) == CFBooleanGetTypeID() {
                return .bool(number.boolValue)
            }
            let type = String(cString: number.objCType)
            if type == "d" || type == "f" {
                return .double(number.doubleValue)
            }
            return .int(number.int64Value)
        case let string as String:
            return .string(string)
        case let array as [Any]:
            return .array(try array.map(from(foundation:)))
        case let dictionary as [String: Any]:
            return .object(try dictionary.mapValues(from(foundation:)))
        default:
            throw ParseError.invalid("unexpected \(type(of: value))")
        }
    }

    // MARK: Serializing

    /// Compact UTF-8 JSON, keys sorted, slashes and non-ASCII characters unescaped.
    public var data: Data {
        var out = ""
        write(to: &out)
        return Data(out.utf8)
    }

    public var text: String {
        var out = ""
        write(to: &out)
        return out
    }

    public var description: String { text }

    private func write(to out: inout String) {
        switch self {
        case .null:
            out += "null"
        case let .bool(value):
            out += value ? "true" : "false"
        case let .int(value):
            out += String(value)
        case let .double(value):
            if !value.isFinite {
                out += "null"
            } else if value == value.rounded(), abs(value) < 1e15 {
                out += String(Int64(value))
            } else {
                out += String(value)
            }
        case let .string(value):
            JSON.writeString(value, to: &out)
        case let .array(values):
            out += "["
            for (index, value) in values.enumerated() {
                if index > 0 { out += "," }
                value.write(to: &out)
            }
            out += "]"
        case let .object(members):
            out += "{"
            // "type" first, as a reader of a log expects; then the keys sorted, for stable bytes.
            let keys = members.keys.sorted { a, b in a == "type" ? b != "type" : (b == "type" ? false : a < b) }
            for (index, key) in keys.enumerated() {
                if index > 0 { out += "," }
                JSON.writeString(key, to: &out)
                out += ":"
                members[key]!.write(to: &out)
            }
            out += "}"
        }
    }

    private static func writeString(_ value: String, to out: inout String) {
        out += "\""
        for scalar in value.unicodeScalars {
            switch scalar {
            case "\"": out += "\\\""
            case "\\": out += "\\\\"
            case "\n": out += "\\n"
            case "\r": out += "\\r"
            case "\t": out += "\\t"
            case "\u{08}": out += "\\b"
            case "\u{0C}": out += "\\f"
            default:
                if scalar.value < 0x20 {
                    out += String(format: "\\u%04x", scalar.value)
                } else {
                    out.unicodeScalars.append(scalar)
                }
            }
        }
        out += "\""
    }

    // MARK: Equality

    public static func == (lhs: JSON, rhs: JSON) -> Bool {
        switch (lhs, rhs) {
        case (.null, .null): return true
        case let (.bool(a), .bool(b)): return a == b
        case let (.int(a), .int(b)): return a == b
        case let (.double(a), .double(b)): return a == b
        case let (.int(a), .double(b)), let (.double(b), .int(a)): return Double(a) == b
        case let (.string(a), .string(b)): return a == b
        case let (.array(a), .array(b)): return a == b
        case let (.object(a), .object(b)): return a == b
        default: return false
        }
    }

    // MARK: Accessors

    public subscript(key: String) -> JSON? {
        if case let .object(members) = self { return members[key] }
        return nil
    }

    public var isNull: Bool {
        if case .null = self { return true }
        return false
    }

    public var stringValue: String? {
        if case let .string(value) = self { return value }
        return nil
    }

    public var boolValue: Bool? {
        if case let .bool(value) = self { return value }
        return nil
    }

    /// An integer, or a double with no fractional part.
    public var intValue: Int? {
        switch self {
        case let .int(value): return Int(exactly: value)
        case let .double(value) where value == value.rounded() && abs(value) < 1e15: return Int(value)
        default: return nil
        }
    }

    public var doubleValue: Double? {
        switch self {
        case let .int(value): return Double(value)
        case let .double(value): return value
        default: return nil
        }
    }

    public var arrayValue: [JSON]? {
        if case let .array(values) = self { return values }
        return nil
    }

    public var objectValue: [String: JSON]? {
        if case let .object(members) = self { return members }
        return nil
    }
}

extension JSON: ExpressibleByStringLiteral, ExpressibleByIntegerLiteral, ExpressibleByBooleanLiteral,
    ExpressibleByFloatLiteral, ExpressibleByArrayLiteral, ExpressibleByDictionaryLiteral, ExpressibleByNilLiteral
{
    public init(stringLiteral value: String) { self = .string(value) }
    public init(integerLiteral value: Int64) { self = .int(value) }
    public init(booleanLiteral value: Bool) { self = .bool(value) }
    public init(floatLiteral value: Double) { self = .double(value) }
    public init(arrayLiteral elements: JSON...) { self = .array(elements) }
    public init(dictionaryLiteral elements: (String, JSON)...) {
        self = .object(Dictionary(elements, uniquingKeysWith: { $1 }))
    }
    public init(nilLiteral: ()) { self = .null }
}
