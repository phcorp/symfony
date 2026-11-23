import XCTest
@testable import TuiAppHostCore

final class CursorTests: XCTestCase {
    func testTheFixtureDecodesTyped() throws {
        XCTAssertEqual(try Message.decode(Fixtures.message(named: "cursor")), .cursor(node: 7, shape: "pointer"))
        XCTAssertEqual(Message.cursor(node: 7, shape: "pointer").json, ["type": "cursor", "node": 7, "shape": "pointer"])
    }

    func testAMissingShapeIsRefused() {
        XCTAssertThrowsError(try Message.decode(JSON.parse(Data(#"{"type":"cursor","node":7}"#.utf8))))
    }

    func testEveryKeywordOfTheContractHasAShape() throws {
        let schema = try Fixtures.json("contract/protocol.json")
        let keywords = schema.objectValue?["$defs"]?.objectValue?["cursor"]?.objectValue?["properties"]?.objectValue?["shape"]?.objectValue?["enum"]?.arrayValue?.compactMap(\.stringValue) ?? []
        XCTAssertEqual(keywords.count, 35)
        // Only these have no closer shape than the arrow
        let arrows = keywords.filter { CursorShape(keyword: $0) == .arrow }
        XCTAssertEqual(arrows, ["default", "help", "progress", "wait", "move", "all-scroll"])
    }

    func testKeywordsMapToTheClosestShape() {
        let expected: [String: CursorShape] = [
            "none": .hidden, "pointer": .pointingHand, "text": .iBeam, "vertical-text": .verticalIBeam,
            "crosshair": .crosshair, "cell": .crosshair, "grab": .openHand, "grabbing": .closedHand,
            "not-allowed": .notAllowed, "no-drop": .notAllowed, "copy": .dragCopy, "alias": .dragLink,
            "context-menu": .contextualMenu, "zoom-in": .zoomIn, "zoom-out": .zoomOut,
            "col-resize": .columnResize, "row-resize": .rowResize,
            "ew-resize": .frameResize(.right), "e-resize": .frameResize(.right), "w-resize": .frameResize(.left),
            "ns-resize": .frameResize(.bottom), "n-resize": .frameResize(.top), "s-resize": .frameResize(.bottom),
            "nesw-resize": .frameResize(.topRight), "nwse-resize": .frameResize(.bottomRight),
            "ne-resize": .frameResize(.topRight), "sw-resize": .frameResize(.bottomLeft),
            "future-shape": .arrow,
        ]
        for (keyword, shape) in expected {
            XCTAssertEqual(CursorShape(keyword: keyword), shape, keyword)
        }
        XCTAssertTrue(CursorShape.FrameEdge.left.isHorizontal)
        XCTAssertTrue(CursorShape.FrameEdge.bottom.isVertical)
        XCTAssertFalse(CursorShape.FrameEdge.topRight.isHorizontal || CursorShape.FrameEdge.topRight.isVertical)
    }
}
