import XCTest
@testable import TuiAppHostCore

/// What the user changes on a control is its prop from then on, as the application takes it.
final class AcknowledgeTests: XCTestCase {
    private func tree() -> NodeTree {
        var tree = NodeTree()
        tree.apply([
            .create(id: 1, kind: "stack", parent: nil, index: 0, props: [:]),
            .create(id: 2, kind: "textfield", parent: 1, index: 0, props: ["value": "Ada", "maxLength": .int(40)]),
            .create(id: 3, kind: "checkbox", parent: 1, index: 1, props: ["checked": .bool(false)]),
            .create(id: 4, kind: "popup", parent: 1, index: 2, props: ["choices": ["a", "b"], "selected": .int(0)]),
            .create(id: 5, kind: "list", parent: 1, index: 3, props: ["multiple": .bool(true)]),
            .create(id: 6, kind: "listbox", parent: 1, index: 4, props: ["selected": [.int(0)]]),
            .create(id: 7, kind: "slider", parent: 1, index: 5, props: ["value": .int(5)]),
        ])
        return tree
    }

    func testAnEditIsKeptWhenOtherPropsChange() {
        var tree = tree()
        XCTAssertTrue(tree.acknowledge(node: 2, event: "change", value: "Ada Lovelace"))
        // The application lowers the limit: the value it took stays
        tree.apply([.update(id: 2, props: ["maxLength": .int(30)])])
        XCTAssertEqual(tree[2]?.props["value"], "Ada Lovelace")
        XCTAssertEqual(tree[2]?.props["maxLength"], .int(30))
        // Its own update wins
        tree.apply([.update(id: 2, props: ["value": "Ada"])])
        XCTAssertEqual(tree[2]?.props["value"], "Ada")
    }

    func testEachKindKeepsTheProp() {
        var tree = tree()
        XCTAssertTrue(tree.acknowledge(node: 3, event: "toggle", value: .bool(true)))
        XCTAssertTrue(tree.acknowledge(node: 4, event: "select", value: .int(1)))
        XCTAssertTrue(tree.acknowledge(node: 5, event: "select", value: [.int(0), .int(2)]))
        XCTAssertTrue(tree.acknowledge(node: 6, event: "select", value: [.int(1), .int(2)]))
        XCTAssertTrue(tree.acknowledge(node: 7, event: "change", value: .double(7.5)))
        XCTAssertEqual(tree[3]?.props["checked"], .bool(true))
        XCTAssertEqual(tree[4]?.props["selected"], .int(1))
        XCTAssertEqual(tree[5]?.props["checked"], [.int(0), .int(2)], "a multiple list: the checked rows")
        XCTAssertEqual(tree[6]?.props["selected"], [.int(1), .int(2)])
        XCTAssertEqual(tree[7]?.props["value"], .double(7.5))
    }

    func testOtherEventsChangeNothing() {
        var tree = tree()
        XCTAssertFalse(tree.acknowledge(node: 2, event: "cancel", value: nil))
        XCTAssertFalse(tree.acknowledge(node: 2, event: "focus", value: nil))
        XCTAssertFalse(tree.acknowledge(node: 3, event: "toggle", value: .bool(false)), "no change")
        XCTAssertFalse(tree.acknowledge(node: 4, event: "select", value: "b"), "not an index")
        XCTAssertFalse(tree.acknowledge(node: 99, event: "change", value: "x"), "no such node")
        XCTAssertEqual(tree[2]?.props["value"], "Ada")
    }
}
