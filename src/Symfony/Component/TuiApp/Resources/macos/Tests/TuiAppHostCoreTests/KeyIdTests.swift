import Foundation
import XCTest
@testable import TuiAppHostCore

final class KeyIdTests: XCTestCase {
    /// The key names HOSTS.md lists under Input ("Then the key: `enter`, … or the lowercase character").
    private func documentedKeys() throws -> [String] {
        let hosts = String(decoding: try Fixtures.data("HOSTS.md"), as: UTF8.self)
        let start = try XCTUnwrap(hosts.range(of: "Then the key:"), "HOSTS.md no longer says 'Then the key:'")
        let end = try XCTUnwrap(hosts.range(of: "or the lowercase character", range: start.upperBound..<hosts.endIndex))
        let list = hosts[start.upperBound..<end.lowerBound]
        var keys = [String]()
        var rest = Substring(list)
        while let open = rest.firstIndex(of: "`") {
            let after = rest.index(after: open)
            guard let close = rest[after...].firstIndex(of: "`") else { break }
            let name = String(rest[after..<close])
            rest = rest[rest.index(after: close)...]
            // `f1`–`f12` is a range.
            if name == "f1", rest.hasPrefix("–`f") || rest.hasPrefix("-`f") {
                let rangeEnd = rest.dropFirst(2).prefix { $0 != "`" }
                let last = try XCTUnwrap(Int(rangeEnd.dropFirst()))
                keys += (1...last).map { "f\($0)" }
                rest = rest.dropFirst(2 + rangeEnd.count + 1)
                continue
            }
            keys.append(name)
        }
        return keys
    }

    func testCommittedTextIsTypedOneCharacterAtATime() {
        XCTAssertEqual(KeyId.committed("é"), [KeyPress(key: "é", text: "é")])
        XCTAssertEqual(KeyId.committed("日本"), [KeyPress(key: "日", text: "日"), KeyPress(key: "本", text: "本")])
        XCTAssertEqual(KeyId.committed("É a"), [KeyPress(key: "shift+é", text: "É"), KeyPress(key: "space", text: " "), KeyPress(key: "a", text: "a")])
        XCTAssertEqual(KeyId.committed("E\u{301}"), [KeyPress(key: "shift+e\u{301}", text: "E\u{301}")], "E and a combining acute is an uppercase letter")
        // A grapheme of several scalars is one key: a skin tone, a family, a flag, a combining accent
        for emoji in ["👍🏽", "👨‍👩‍👧", "🇫🇷", "e\u{301}"] {
            XCTAssertEqual(KeyId.committed(emoji), [KeyPress(key: emoji, text: emoji)], emoji)
        }
        XCTAssertEqual(KeyId.committed("a\nb\u{7f}"), [KeyPress(key: "a", text: "a"), KeyPress(key: "b", text: "b")], "control characters are dropped")
        XCTAssertEqual(KeyId.committed(""), [])
    }

    func testEveryKeyHostsMdListsIsMapped() throws {
        let documented = try documentedKeys()
        XCTAssertEqual(documented.count, 27, "parsed \(documented)")
        let produced = Set(KeyId.namedKeys.compactMap { code, _ in
            KeyId.from(keyCode: code, characters: nil, charactersIgnoringModifiers: nil, modifiers: [])?.key
        })
        for key in documented {
            XCTAssertTrue(produced.contains(key), "HOSTS.md lists `\(key)`, which no macOS key produces")
        }
        XCTAssertEqual(produced.subtracting(documented), [], "keys produced but not documented")
    }

    func testModifiersComeInTheDocumentedOrder() {
        let all: KeyModifiers = [.command, .option, .shift, .control]
        XCTAssertEqual(KeyId.from(keyCode: 36, characters: "\r", charactersIgnoringModifiers: "\r", modifiers: all)?.key, "ctrl+shift+alt+super+enter")
        XCTAssertEqual(KeyId.prefix([.command, .control]), "ctrl+super+")
    }

    func testCharacters() {
        // a
        XCTAssertEqual(KeyId.from(keyCode: 0, characters: "a", charactersIgnoringModifiers: "a", modifiers: []), KeyPress(key: "a", text: "a"))
        // Shift+A: the id is lowercase with shift, the text has the case.
        XCTAssertEqual(KeyId.from(keyCode: 0, characters: "A", charactersIgnoringModifiers: "A", modifiers: .shift), KeyPress(key: "shift+a", text: "A"))
        // Ctrl+S, Cmd+S: no text.
        XCTAssertEqual(KeyId.from(keyCode: 1, characters: "\u{13}", charactersIgnoringModifiers: "s", modifiers: .control), KeyPress(key: "ctrl+s", text: nil))
        XCTAssertEqual(KeyId.from(keyCode: 1, characters: "s", charactersIgnoringModifiers: "s", modifiers: .command), KeyPress(key: "super+s", text: nil))
        // é on a French keyboard, as in the key-text fixture.
        XCTAssertEqual(KeyId.from(keyCode: 19, characters: "é", charactersIgnoringModifiers: "é", modifiers: []), KeyPress(key: "é", text: "é"))
        // Shift+1 on a US keyboard is "!", not shift+1.
        XCTAssertEqual(KeyId.from(keyCode: 18, characters: "!", charactersIgnoringModifiers: "!", modifiers: .shift), KeyPress(key: "!", text: "!"))
        // Option+( on a French keyboard types {: a character, not alt+(.
        XCTAssertEqual(KeyId.from(keyCode: 23, characters: "{", charactersIgnoringModifiers: "(", modifiers: .option), KeyPress(key: "{", text: "{"))
        // Option+X typing nothing (a dead key) keeps alt.
        XCTAssertEqual(KeyId.from(keyCode: 14, characters: "", charactersIgnoringModifiers: "e", modifiers: .option), KeyPress(key: "alt+e", text: nil))
        // Space types a space; Shift+Tab is shift+tab.
        XCTAssertEqual(KeyId.from(keyCode: 49, characters: " ", charactersIgnoringModifiers: " ", modifiers: []), KeyPress(key: "space", text: " "))
        XCTAssertEqual(KeyId.from(keyCode: 48, characters: "\u{19}", charactersIgnoringModifiers: "\u{19}", modifiers: .shift), KeyPress(key: "shift+tab", text: nil))
        // A bare function key beyond F12, or nothing printable: no id.
        XCTAssertNil(KeyId.from(keyCode: 105, characters: "\u{F70E}", charactersIgnoringModifiers: "\u{F70E}", modifiers: []))
    }

    func testMenuShortcutsBecomeKeyEquivalents() {
        XCTAssertEqual(KeyId.keyEquivalent("ctrl+s")?.key, "s")
        XCTAssertEqual(KeyId.keyEquivalent("ctrl+s")?.modifiers, .control)
        XCTAssertEqual(KeyId.keyEquivalent("super+shift+p")?.modifiers, [.command, .shift])
        XCTAssertEqual(KeyId.keyEquivalent("f5")?.key, "\u{F708}")
        XCTAssertEqual(KeyId.keyEquivalent("ctrl++")?.key, "+")
        XCTAssertEqual(KeyId.keyEquivalent("enter")?.key, "\r")
        XCTAssertNil(KeyId.keyEquivalent("hyper+x"))
        XCTAssertNil(KeyId.keyEquivalent(""))
    }
}
