import XCTest
@testable import TuiAppHostCore

final class PointerTests: XCTestCase {
    func testTheFixturesDecodeTyped() throws {
        XCTAssertEqual(try Message.decode(Fixtures.message(named: "pointer-press")), .pointer(node: 21, Pointer(action: "press", row: 3, column: 12, button: "left")))
        XCTAssertEqual(try Message.decode(Fixtures.message(named: "pointer-drag")), .pointer(node: 21, Pointer(action: "move", row: -2, column: 15, button: "left", modifiers: ["ctrl", "alt"])))
        XCTAssertEqual(try Message.decode(Fixtures.message(named: "pointer-wheel")), .pointer(node: 21, Pointer(action: "wheel", row: 3, column: 12, modifiers: ["super"], rows: -3, columns: 0)))
    }

    func testModifiersComeInTheOrderOfKeyIds() {
        XCTAssertEqual(Pointer.modifiers([.command, .shift, .control, .option]), ["ctrl", "shift", "alt", "super"])
        XCTAssertEqual(Pointer.modifiers([]), [])
    }

    func testTheCellOfAPointIsRoundedDown() {
        XCTAssertTrue(Pointer.cell(x: 0, y: 0, cellWidth: 8, cellHeight: 16) == (0, 0))
        XCTAssertTrue(Pointer.cell(x: 15.9, y: 31.9, cellWidth: 8, cellHeight: 16) == (1, 1))
        XCTAssertTrue(Pointer.cell(x: -0.5, y: -1, cellWidth: 8, cellHeight: 16) == (-1, -1), "Past the edge while dragging")
    }

    func testAMouseWheelScrollsALineARow() {
        var wheel = WheelAccumulator()
        XCTAssertTrue(wheel.add(deltaX: 0, deltaY: -3, precise: false, cellWidth: 8, cellHeight: 16)! == (3, 0), "Down")
        XCTAssertTrue(wheel.add(deltaX: 1, deltaY: 1, precise: false, cellWidth: 8, cellHeight: 16)! == (-1, -1), "Up and left")
    }

    func testATrackpadIsAddedUpToWholeCells() {
        var wheel = WheelAccumulator()
        XCTAssertNil(wheel.add(deltaX: 0, deltaY: -10, precise: true, cellWidth: 8, cellHeight: 16))
        XCTAssertTrue(wheel.add(deltaX: 0, deltaY: -10, precise: true, cellWidth: 8, cellHeight: 16)! == (1, 0))
        XCTAssertTrue(wheel.add(deltaX: 0, deltaY: -28, precise: true, cellWidth: 8, cellHeight: 16)! == (2, 0), "With the 4 points left")
        XCTAssertNil(wheel.add(deltaX: -4, deltaY: 0, precise: true, cellWidth: 8, cellHeight: 16))
        wheel.reset()
        XCTAssertNil(wheel.add(deltaX: -4, deltaY: 0, precise: true, cellWidth: 8, cellHeight: 16), "Nothing left after a reset")
    }
}
