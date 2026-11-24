import Foundation

/// `app.manifest.json`, version 1. Optional members get the defaults of the schema.
public struct Manifest: Sendable, Equatable, Decodable {
    public struct App: Sendable, Equatable, Decodable {
        public var name: String
        public var id: String
        public var version: String
        public var description: String?
        public var publisher: String?
        public var copyright: String?
        public var homepage: String?
        public var singleInstance: Bool?
        /// `productivity`, `developer-tools`…: LSApplicationCategoryType `public.app-category.<category>`.
        public var category: String?
        public var fileAssociations: [FileAssociation]?
        public var urlSchemes: [String]?
    }

    public struct FileAssociation: Sendable, Equatable, Decodable {
        /// Without the dot.
        public var `extension`: String
        public var name: String
        public var mimeType: String?
        /// `editor` or `viewer`.
        public var role: String
    }

    public struct Font: Sendable, Equatable, Decodable {
        public var family: String?
        public var size: Double?
    }

    public struct Window: Sendable, Equatable, Decodable {
        public var title: String
        public var width: Int
        public var height: Int
        public var minWidth: Int?
        public var minHeight: Int?
        public var resizable: Bool?
        public var rememberGeometry: Bool?
        public var theme: String?
        public var canvasFont: Font?
    }

    public struct MenuSettings: Sendable, Equatable, Decodable {
        public var native: Bool?
        public var about: Bool?
        public var quit: Bool?
    }

    public struct PHP: Sendable, Equatable, Decodable {
        public var path: String?
        public var minVersion: String?
    }

    public struct Env: Sendable, Equatable, Decodable {
        public var pass: [String]?
        public var set: [String: String]?
    }

    public struct Launch: Sendable, Equatable, Decodable {
        public var mode: String
        public var command: String?
        public var args: [String]
        public var php: PHP?
        public var env: Env?
    }

    public struct Logging: Sendable, Equatable, Decodable {
        public var file: String?
        public var level: String?
    }

    public var manifest: Int
    public var app: App
    public var window: Window
    public var menu: MenuSettings
    public var launch: Launch
    public var logging: Logging?

    public enum Error: Swift.Error, Equatable, CustomStringConvertible {
        case unreadable(String)
        case unsupportedVersion(Int)
        case unknownMode(String)

        public var description: String {
            switch self {
            case let .unreadable(why): return "The manifest cannot be read: \(why)"
            case let .unsupportedVersion(v): return "Manifest version \(v) is not supported."
            case let .unknownMode(mode): return "Unknown launch mode \(mode)."
            }
        }
    }

    public static func parse(_ data: Data) throws -> Manifest {
        let manifest: Manifest
        do {
            manifest = try JSONDecoder().decode(Manifest.self, from: data)
        } catch {
            throw Error.unreadable(String(describing: error))
        }
        guard manifest.manifest == 1 else { throw Error.unsupportedVersion(manifest.manifest) }
        guard ["embedded", "custom", "system", "elephc"].contains(manifest.launch.mode) else {
            throw Error.unknownMode(manifest.launch.mode)
        }
        return manifest
    }

    /// The manifest a `--dev` launch runs with: no packaging, a plain window.
    public static func development(command: [String]) -> Manifest {
        Manifest(
            manifest: 1,
            app: App(name: "TuiApp", id: "dev.tuiapp.host", version: "0.0.0", description: nil, publisher: nil, copyright: nil, homepage: nil, singleInstance: false, category: nil, fileAssociations: nil, urlSchemes: nil),
            window: Window(title: "TuiApp", width: 900, height: 720, minWidth: 320, minHeight: 240, resizable: true, rememberGeometry: false, theme: "system", canvasFont: Font(family: nil, size: 13)),
            menu: MenuSettings(native: true, about: true, quit: true),
            launch: Launch(mode: "custom", command: command.first, args: Array(command.dropFirst()), php: nil, env: nil),
            logging: nil
        )
    }

    public var canvasFontSize: Double { window.canvasFont?.size ?? 13 }
}
