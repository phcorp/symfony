/// Modifier keys held during a key press. Mirrors the device-independent NSEvent flags.
public struct KeyModifiers: OptionSet, Sendable, Hashable {
    public let rawValue: Int
    public init(rawValue: Int) { self.rawValue = rawValue }

    public static let control = KeyModifiers(rawValue: 1 << 0)
    public static let shift = KeyModifiers(rawValue: 1 << 1)
    public static let option = KeyModifiers(rawValue: 1 << 2)
    public static let command = KeyModifiers(rawValue: 1 << 3)
}

/// A key press as a Tui key id: modifiers in the order `ctrl+shift+alt+super+`, then the key.
public struct KeyPress: Sendable, Equatable {
    public var key: String
    public var text: String?

    public init(key: String, text: String?) {
        self.key = key
        self.text = text
    }
}

/// Maps macOS key presses (virtual key code, characters, modifiers) to Tui key ids.
public enum KeyId {
    /// macOS virtual key codes (Carbon `kVK_*`) of the named keys.
    public static let namedKeys: [UInt16: String] = [
        36: "enter", 76: "enter", // Return, keypad Enter
        53: "escape",
        48: "tab",
        49: "space",
        51: "backspace",
        117: "delete", // forward delete
        114: "insert", // Help, where PC keyboards have Insert
        115: "home",
        119: "end",
        116: "page_up",
        121: "page_down",
        126: "up",
        125: "down",
        123: "left",
        124: "right",
        122: "f1", 120: "f2", 99: "f3", 118: "f4", 96: "f5", 97: "f6",
        98: "f7", 100: "f8", 101: "f9", 109: "f10", 103: "f11", 111: "f12",
    ]

    /// The id of a key press, or nil for a key with no Tui id (a bare modifier, a dead key alone,
    /// a function key beyond F12).
    ///
    /// - `characters`: what the key types with its modifiers (`NSEvent.characters`).
    /// - `charactersIgnoringModifiers`: the same, ignoring every modifier but Shift.
    ///
    /// The character is lowercased. Shift is part of the id only when it changes a letter's case
    /// (`shift+a`); for other characters Shift is what chose the character (`!`, not `shift+1`).
    /// Option alone types characters on macOS (Option+E then E types é): when it does, the
    /// character it typed is the key, without `alt+`.
    public static func from(keyCode: UInt16, characters: String?, charactersIgnoringModifiers: String?, modifiers: KeyModifiers) -> KeyPress? {
        if let name = namedKeys[keyCode] {
            let text: String? = name == "space" && modifiers.isDisjoint(with: [.control, .option, .command]) ? " " : nil
            return KeyPress(key: prefix(modifiers) + name, text: text)
        }
        let typed = characters ?? ""
        let base = charactersIgnoringModifiers ?? typed
        // Option without Control or Command composes a character: it is typed text.
        if modifiers.contains(.option), modifiers.isDisjoint(with: [.control, .command]), isPrintable(typed), typed != base {
            var rest = modifiers
            rest.remove(.option)
            return character(typed, typed: typed, modifiers: rest)
        }
        guard isPrintable(base) else {
            return nil
        }
        let text = modifiers.isDisjoint(with: [.control, .option, .command]) && isPrintable(typed) ? typed : nil
        return character(base, typed: text, modifiers: modifiers)
    }

    /// The key presses that type the text an input method commits (a dead key and a letter, a
    /// word of a Chinese or Japanese input method, an emoji from the picker): one per character,
    /// the character as key, lowercased with `shift+` for an uppercase letter (also one with
    /// combining marks), a space as `space`, and as text. Control characters are dropped.
    public static func committed(_ text: String) -> [KeyPress] {
        text.compactMap { character in
            let typed = String(character)
            guard typed.unicodeScalars.allSatisfy({ !($0.value < 0x20 || (0x7f...0x9f).contains($0.value)) }) else { return nil }
            if typed == " " { return KeyPress(key: "space", text: typed) }
            let lower = typed.lowercased()
            return KeyPress(key: (lower != typed ? "shift+" : "") + lower, text: typed)
        }
    }

    private static func character(_ character: String, typed text: String?, modifiers: KeyModifiers) -> KeyPress {
        let lower = character.lowercased()
        var modifiers = modifiers
        if lower == character.uppercased() {
            // No case: Shift only picked the character.
            modifiers.remove(.shift)
        }
        return KeyPress(key: prefix(modifiers) + lower, text: text)
    }

    public static func prefix(_ modifiers: KeyModifiers) -> String {
        var out = ""
        if modifiers.contains(.control) { out += "ctrl+" }
        if modifiers.contains(.shift) { out += "shift+" }
        if modifiers.contains(.option) { out += "alt+" }
        if modifiers.contains(.command) { out += "super+" }
        return out
    }

    /// One printable character: no control characters, no AppKit function-key private use area.
    static func isPrintable(_ text: String) -> Bool {
        guard text.count == 1 else { return false }
        return text.unicodeScalars.allSatisfy { scalar in
            !(scalar.value < 0x20 || scalar.value == 0x7f || (0xF700...0xF8FF).contains(scalar.value))
        }
    }

    // MARK: Menu shortcuts

    /// A Tui key id as an AppKit key equivalent: the string and the modifiers. Nil when AppKit
    /// cannot show it.
    public static func keyEquivalent(_ id: String) -> (key: String, modifiers: KeyModifiers)? {
        var parts = id.split(separator: "+", omittingEmptySubsequences: false).map(String.init)
        // "ctrl++" names the plus key.
        if id.hasSuffix("++") {
            parts.removeLast(2)
            parts.append("+")
        }
        guard let key = parts.popLast(), !key.isEmpty else { return nil }
        var modifiers = KeyModifiers()
        for part in parts {
            switch part {
            case "ctrl": modifiers.insert(.control)
            case "shift": modifiers.insert(.shift)
            case "alt": modifiers.insert(.option)
            case "super", "cmd": modifiers.insert(.command)
            default: return nil
            }
        }
        let named: [String: UInt32] = [
            "enter": 0x0D, "escape": 0x1B, "tab": 0x09, "space": 0x20, "backspace": 0x08,
            "delete": 0xF728, "insert": 0xF727, "home": 0xF729, "end": 0xF72B,
            "page_up": 0xF72C, "page_down": 0xF72D,
            "up": 0xF700, "down": 0xF701, "left": 0xF702, "right": 0xF703,
        ]
        if let scalar = named[key] {
            return (String(UnicodeScalar(scalar)!), modifiers)
        }
        if key.count >= 2, key.hasPrefix("f"), let n = Int(key.dropFirst()), (1...35).contains(n) {
            return (String(UnicodeScalar(0xF704 + UInt32(n - 1))!), modifiers)
        }
        guard key.count == 1 else { return nil }
        return (key, modifiers)
    }
}
