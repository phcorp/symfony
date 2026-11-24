import XCTest
@testable import TuiAppHostCore

final class ContextMenuTests: XCTestCase {
    func testTheFixturesDecodeTyped() throws {
        XCTAssertEqual(try Message.decode(Fixtures.message(named: "contextmenu")), .contextMenu(node: 7, row: 3, column: 12, items: [
            ContextMenuItem(id: "1", label: "Open Link"),
            ContextMenuItem(id: "2", label: "Copy Link Address"),
            .separator,
            ContextMenuItem(id: "3", label: "Paste", role: "paste"),
            ContextMenuItem(id: "4", label: "Nothing to undo", enabled: false),
        ]))
        XCTAssertEqual(try Message.decode(Fixtures.message(named: "contextmenu-select")), .contextMenuSelect(id: "2"))
        XCTAssertEqual(try Message.decode(Fixtures.message(named: "clipboard")), .clipboard(text: "https://example.com/docs"))
    }

    func testAnItemWithNoLabelIsRefused() {
        XCTAssertThrowsError(try Message.decode(Data(#"{"type":"contextmenu","node":7,"row":0,"column":0,"items":[{"id":"1"}]}"#.utf8)))
        XCTAssertThrowsError(try Message.decode(Data(#"{"type":"contextmenu","node":7,"row":0,"column":0}"#.utf8)))
        XCTAssertThrowsError(try Message.decode(Data(#"{"type":"clipboard"}"#.utf8)))
    }

    func testIdsAreOpaqueAndAnItemMaySaySeparatorFalse() throws {
        let message = try Message.decode(Data(#"{"type":"contextmenu","node":7,"row":0,"column":0,"items":[{"id":"1:1","label":"Open Link","separator":false},{"separator":true},{"id":"2:10","label":"Copy"}]}"#.utf8))
        XCTAssertEqual(message, .contextMenu(node: 7, row: 0, column: 0, items: [ContextMenuItem(id: "1:1", label: "Open Link"), .separator, ContextMenuItem(id: "2:10", label: "Copy")]))
        XCTAssertEqual(Message.contextMenuSelect(id: "1:1").json, ["type": "contextmenu-select", "id": "1:1"])
    }

    func testSelectEncodesItsId() {
        XCTAssertEqual(Message.contextMenuSelect(id: "2").json, ["type": "contextmenu-select", "id": "2"])
    }
}
