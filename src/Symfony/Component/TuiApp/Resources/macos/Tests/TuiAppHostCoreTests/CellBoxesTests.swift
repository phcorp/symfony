import XCTest
@testable import TuiAppHostCore

/// The canvas leaves the cells of the controls over it undrawn: a glyph there reaches past its cell.
final class CellBoxesTests: XCTestCase {
    func testTheCellsOfABoxAreCoveredAndNoOther() {
        // A text area of 4 rows at column 11, like the one of an HTML view after its labels
        let boxes = CellBoxes([[17, 11, 100, 4]])
        XCTAssertTrue(boxes.contains(row: 17, column: 11), "Its first cell, where the canvas draws ─")
        XCTAssertTrue(boxes.contains(row: 20, column: 110), "Its last one")
        XCTAssertFalse(boxes.contains(row: 17, column: 10), "The cell before it is drawn")
        XCTAssertFalse(boxes.contains(row: 21, column: 11), "The row after it")
        XCTAssertFalse(boxes.contains(row: 17, column: 111), "The column after it")
    }

    func testAnEmptyOrMalformedBoxCoversNothing() {
        XCTAssertFalse(CellBoxes([[3, 11, 0, 1]]).contains(row: 3, column: 11), "A control clipped to nothing")
        XCTAssertFalse(CellBoxes([[3, 11, 5]]).contains(row: 3, column: 11))
        XCTAssertFalse(CellBoxes().contains(row: 0, column: 0))
    }
}
