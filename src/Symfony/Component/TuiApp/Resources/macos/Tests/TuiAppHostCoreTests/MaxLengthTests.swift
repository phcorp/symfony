import Foundation
import XCTest
@testable import TuiAppHostCore

final class MaxLengthTests: XCTestCase {
    private func allowed(_ current: String, _ location: Int, _ length: Int, _ replacement: String, _ limit: Int?) -> String {
        MaxLength.allowed(current: current, range: NSRange(location: location, length: length), replacement: replacement, limit: limit)
    }

    func testTheLimitComesFromTheProps() {
        XCTAssertEqual(MaxLength.limit(["maxLength": .int(3)]), 3)
        XCTAssertEqual(MaxLength.limit(["maxLength": .int(0)]), 0)
        XCTAssertNil(MaxLength.limit([:]))
        XCTAssertNil(MaxLength.limit(["maxLength": .null]), "null lifts the limit")
        XCTAssertNil(MaxLength.limit(["maxLength": .int(-1)]))
    }

    func testTypingStopsAtTheLimit() {
        XCTAssertEqual(allowed("ab", 2, 0, "c", 3), "c")
        XCTAssertEqual(allowed("abc", 3, 0, "d", 3), "")
        XCTAssertEqual(allowed("", 0, 0, "a", 0), "", "0 allows nothing")
        XCTAssertEqual(allowed("abc", 3, 0, "d", nil), "d", "no limit")
    }

    func testAPasteIsCut() {
        XCTAssertEqual(allowed("a", 1, 0, "bcdef", 3), "bc")
        XCTAssertEqual(allowed("abc", 1, 1, "XYZ", 3), "X", "the selection it replaces makes room")
    }

    func testCharactersAreCodePointsLikeMbStrlen() {
        // é as one code point, 日 (one UTF-16 unit), 😀 (two units, one code point)
        XCTAssertEqual(allowed("é日", 2, 0, "😀x", 3), "😀")
        XCTAssertEqual(allowed("😀😀", 4, 0, "a", 3), "a", "a surrogate pair is one character")
        // e + combining acute is two code points, as mb_strlen counts it
        XCTAssertEqual(allowed("", 0, 0, "e\u{301}e\u{301}", 3), "e\u{301}e")
        XCTAssertEqual(allowed("😀a", 0, 2, "bc", 2), "b", "replacing a surrogate pair frees one character")
    }

    func testMarkedTextIsNotCountedItsCommitIs() {
        // "ab" then the marked にほん, limit 3: the commit of 日本 replaces it, with room for one
        let marked = NSRange(location: 2, length: 3)
        XCTAssertEqual(MaxLength.allowed(current: "abにほん", range: marked, replacement: "日本", limit: 3, marked: marked), "日")
        XCTAssertEqual(MaxLength.allowed(current: "abにほん", range: marked, replacement: "日本", limit: 4, marked: marked), "日本")
        XCTAssertEqual(MaxLength.allowed(current: "abcにほん", range: NSRange(location: 3, length: 3), replacement: "日", limit: 3, marked: NSRange(location: 3, length: 3)), "", "no room")
        // Counted as text when the edit does not replace it all
        XCTAssertEqual(MaxLength.allowed(current: "abにほん", range: NSRange(location: 5, length: 0), replacement: "x", limit: 3, marked: marked), "")
    }

    func testAValuePastTheLimitMayChangeButNotGrow() {
        XCTAssertEqual(allowed("abcd", 1, 1, "X", 2), "X", "replacing one by one keeps the length")
        XCTAssertEqual(allowed("abcd", 1, 1, "XY", 2), "X")
        XCTAssertEqual(allowed("abcd", 4, 0, "e", 2), "")
        XCTAssertEqual(allowed("abcd", 3, 1, "", 2), "", "deleting is always fine")
    }
}
