import Foundation
import XCTest
@testable import TuiAppHostCore

/// The contract, read from the repository: `macos/Tests/TuiAppHostCoreTests/` → `../contract`.
enum Fixtures {
    static let repository = URL(fileURLWithPath: #filePath)
        .deletingLastPathComponent().deletingLastPathComponent().deletingLastPathComponent().deletingLastPathComponent()

    static func data(_ path: String) throws -> Data {
        try Data(contentsOf: repository.appendingPathComponent(path))
    }

    static func json(_ path: String) throws -> JSON {
        try JSON.parse(data(path))
    }

    /// messages.json as (name, message JSON), in file order.
    static func messages() throws -> [(name: String, message: JSON)] {
        guard let entries = try json("contract/fixtures/messages.json").arrayValue else {
            throw XCTSkip("messages.json is not an array")
        }
        return entries.map { ($0["name"]?.stringValue ?? "?", $0["message"] ?? .null) }
    }

    static func message(named name: String) throws -> JSON {
        guard let found = try messages().first(where: { $0.name == name }) else {
            throw NSError(domain: "fixtures", code: 1, userInfo: [NSLocalizedDescriptionKey: "no message \(name)"])
        }
        return found.message
    }
}
