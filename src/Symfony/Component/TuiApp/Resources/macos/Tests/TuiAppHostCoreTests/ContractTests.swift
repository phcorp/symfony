import Foundation
import XCTest
@testable import TuiAppHostCore

final class ContractTests: XCTestCase {
    func testBatchedFramesKeepAnIncompleteFollowingFrame() throws {
        let payload = Data("{\"type\":\"ping\"}".utf8)
        let frame = try Frame.encode(payload)
        var bytes = Data()
        for _ in 0..<1000 { bytes.append(frame) }
        bytes.append(frame.prefix(7))
        var decoder = Frame.Decoder()
        decoder.append(bytes)
        for _ in 0..<1000 { XCTAssertEqual(try decoder.next(), payload) }
        XCTAssertNil(try decoder.next())
        decoder.append(Data(frame.dropFirst(7)))
        XCTAssertEqual(try decoder.next(), payload)
        XCTAssertNil(try decoder.next())
        decoder.append(frame)
        XCTAssertEqual(try decoder.next(), payload)
        XCTAssertNil(try decoder.next())
    }

    func testFramesDecodeAndEncodeByteForByte() throws {
        let fixture = try Fixtures.json("contract/fixtures/frames.json")
        let frames = try XCTUnwrap(fixture["frames"]?.arrayValue)
        XCTAssertFalse(frames.isEmpty)
        for frame in frames {
            let name = frame["name"]?.stringValue ?? "?"
            let json = try XCTUnwrap(frame["json"]?.stringValue)
            let bytes = try XCTUnwrap(Hex.decode(try XCTUnwrap(frame["hex"]?.stringValue)))

            XCTAssertEqual(try Frame.encode(Data(json.utf8)), bytes, name)

            // Fed one byte at a time, the decoder still finds exactly one payload.
            var decoder = Frame.Decoder()
            var payloads = [Data]()
            for byte in bytes {
                decoder.append(Data([byte]))
                while let payload = try decoder.next() { payloads.append(payload) }
            }
            XCTAssertEqual(payloads, [Data(json.utf8)], name)

            // Our own encoder writes the same bytes for the same message.
            let message = try Message.decode(Data(json.utf8))
            XCTAssertEqual(try Frame.encode(message.encode()), bytes, "\(name) re-encoded")
        }
    }

    func testTwoFramesInOneRead() throws {
        var decoder = Frame.Decoder()
        decoder.append(try Frame.encode(Data("{\"type\":\"beep\"}".utf8)) + Frame.encode(Data("{\"type\":\"close\"}".utf8)))
        XCTAssertEqual(try decoder.next().map { String(decoding: $0, as: UTF8.self) }, "{\"type\":\"beep\"}")
        XCTAssertEqual(try decoder.next().map { String(decoding: $0, as: UTF8.self) }, "{\"type\":\"close\"}")
        XCTAssertNil(try decoder.next())
    }

    func testAFrameOverTheLimitIsRefused() {
        var decoder = Frame.Decoder()
        decoder.append(Data([0x01, 0x00, 0x00, 0x01]))
        XCTAssertThrowsError(try decoder.next())
        XCTAssertThrowsError(try Frame.encode(Data(count: Frame.maxBytes + 1)))
    }

    func testEveryMessageDecodesAndEncodesBackToEqualJSON() throws {
        let messages = try Fixtures.messages()
        XCTAssertGreaterThan(messages.count, 20)
        for (name, json) in messages {
            let message = try Message.decode(json.data)
            if case .unknown = message {
                XCTFail("\(name): decoded as unknown")
            }
            XCTAssertEqual(message.json, json, name)
            XCTAssertEqual(try JSON.parse(message.encode()), json, "\(name) through bytes")
        }
    }

    func testEveryMessageTypeOfTheProtocolHasAFixture() throws {
        let protocolJSON = try Fixtures.json("contract/protocol.json")
        let defs = try XCTUnwrap(protocolJSON["$defs"]?.objectValue)
        let types = defs.values.compactMap { $0["properties"]?["type"]?["const"]?.stringValue }
        XCTAssertEqual(types.count, 24)
        let covered = Set(try Fixtures.messages().compactMap { $0.message["type"]?.stringValue })
        XCTAssertEqual(Set(types).subtracting(covered), [])
    }

    func testUnknownTypesAreKeptAsUnknown() throws {
        let message = try Message.decode(Data("{\"type\":\"future\",\"x\":1}".utf8))
        XCTAssertEqual(message, .unknown(type: "future", ["type": "future", "x": 1]))
    }

    func testAbsentAndNullProgressDiffer() throws {
        XCTAssertEqual(try Message.decode(Fixtures.message(named: "window-no-progress")), .window(title: .absent, progress: .null))
        XCTAssertEqual(try Message.decode(Fixtures.message(named: "window-host-title")), .window(title: .null, progress: .absent))
        XCTAssertEqual(try Message.decode(Data("{\"type\":\"window\",\"title\":\"x\"}".utf8)), .window(title: .value("x"), progress: .absent))
    }

    func testDecodedFieldsAreTyped() throws {
        guard case let .hello(hello) = try Message.decode(Fixtures.message(named: "hello")) else { return XCTFail() }
        XCTAssertEqual(hello.cellWidth, 8.4)
        XCTAssertEqual(hello.columns, 100)
        guard case let .patch(ops) = try Message.decode(Fixtures.message(named: "patch-create")) else { return XCTFail() }
        XCTAssertEqual(ops.first, .create(id: 1, kind: "stack", parent: nil, index: 0, props: ["direction": "vertical", "gap": 1, "grow": 1]))
        XCTAssertEqual(try Message.decode(Fixtures.message(named: "event-cancel")), .event(node: 3, name: "cancel", value: nil))
    }

    func testJSONWritesUnicodeAndSlashesUnescaped() throws {
        XCTAssertEqual(JSON.object(["t": "é/\n\u{1}"]).text, "{\"t\":\"é/\\n\\u0001\"}")
        XCTAssertEqual(try JSON.parse("[1, 1.5, true, null]"), [1, 1.5, true, nil])
        XCTAssertNotEqual(try JSON.parse("[1]"), [true])
    }

    func testTheSessionGivesTheExpectedTrees() throws {
        let session = try Fixtures.json("contract/fixtures/session.json")
        let steps = try XCTUnwrap(session["steps"]?.arrayValue)
        XCTAssertEqual(steps.count, 4)
        var tree = NodeTree()
        for step in steps {
            let name = try XCTUnwrap(step["patch"]?.stringValue)
            guard case let .patch(ops) = try Message.decode(Fixtures.message(named: name)) else { return XCTFail(name) }
            let result = tree.apply(ops)
            XCTAssertEqual(result.errors, [], name)
            XCTAssertEqual(tree.json(), step["tree"], name)
        }
    }

    func testPatchResultsSayWhatChanged() throws {
        var tree = NodeTree()
        guard case let .patch(create) = try Message.decode(Fixtures.message(named: "patch-create")),
              case let .patch(all) = try Message.decode(Fixtures.message(named: "patch-all-kinds")),
              case let .patch(update) = try Message.decode(Fixtures.message(named: "patch-update-move-remove"))
        else { return XCTFail() }
        let first = tree.apply(create)
        XCTAssertEqual(first.created, [1, 2, 3])
        XCTAssertTrue(first.rootChanged)
        let second = tree.apply(all)
        XCTAssertEqual(second.created, Array(10...21))
        XCTAssertEqual(second.restructured, [1, 13, 16])
        let third = tree.apply(update)
        XCTAssertEqual(third.updated, [2])
        XCTAssertEqual(third.removed, [3])
        XCTAssertEqual(third.restructured, [1])
        XCTAssertEqual(tree.orderedIds, [1, 2, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21])
    }

    func testBadOpsAreSkippedNotFatal() {
        var tree = NodeTree()
        let result = tree.apply([
            .create(id: 1, kind: "stack", parent: nil, index: 0, props: [:]),
            .create(id: 2, kind: "label", parent: 99, index: 0, props: [:]),
            .update(id: 5, props: ["text": "x"]),
            .create(id: 3, kind: "stack", parent: 1, index: 0, props: [:]),
            .move(id: 1, parent: 3, index: 0),
        ])
        XCTAssertEqual(result.errors.count, 3)
        XCTAssertEqual(tree.orderedIds, [1, 3])
    }

    func testTheManifestFixtureParses() throws {
        let manifest = try Manifest.parse(Fixtures.data("contract/fixtures/manifest.json"))
        XCTAssertEqual(manifest.app.name, "Acme Notes")
        XCTAssertEqual(manifest.app.singleInstance, true)
        XCTAssertEqual(manifest.window.canvasFont, Manifest.Font(family: nil, size: 13))
        XCTAssertEqual(manifest.launch.mode, "embedded")
        XCTAssertEqual(manifest.launch.env?.pass, ["LANG"])
        XCTAssertNil(manifest.logging?.file)
        XCTAssertEqual(manifest.app.category, "productivity")
        XCTAssertEqual(manifest.app.fileAssociations, [.init(extension: "acme", name: "Acme note", mimeType: nil, role: "editor")])
        XCTAssertEqual(manifest.app.urlSchemes, ["acme"])
        XCTAssertEqual(manifest.launch.command, "runtime/php")
        XCTAssertEqual(manifest.launch.args.first, "-n")
    }
}
