import Foundation

/// Which variables the application inherits from the host.
public enum EnvironmentFilter {
    /// Always kept, on every OS.
    public static let allowed: Set<String> = [
        "PATH", "HOME", "USER", "LANG", "TMPDIR", "TEMP", "TMP", "SystemRoot", "APPDATA", "LOCALAPPDATA",
        "DISPLAY", "WAYLAND_DISPLAY",
    ]
    public static let allowedPrefixes = ["LC_", "XDG_"]

    public static func filter(_ inherited: [String: String], pass: [String] = [], set: [String: String] = [:]) -> [String: String] {
        let passed = Set(pass)
        var out = inherited.filter { name, _ in
            allowed.contains(name) || passed.contains(name) || allowedPrefixes.contains { name.hasPrefix($0) }
        }
        out.merge(set) { $1 }
        return out
    }
}

/// Dotted version numbers, compared numerically: 8.10 is after 8.9.
public enum Version {
    public static func components(_ version: String) -> [Int] {
        // "8.4.1-dev" counts as 8.4.1.
        let core = version.prefix { $0.isNumber || $0 == "." }
        return core.split(separator: ".").map { Int($0) ?? 0 }
    }

    public static func satisfies(_ version: String, minimum: String?) -> Bool {
        guard let minimum, !minimum.isEmpty else { return true }
        var a = components(version)
        var b = components(minimum)
        guard !a.isEmpty else { return false }
        let count = max(a.count, b.count)
        a += Array(repeating: 0, count: count - a.count)
        b += Array(repeating: 0, count: count - b.count)
        return a.lexicographicallyPrecedes(b) == false
    }
}

/// How to start the application: what to run, with which arguments and environment.
public struct LaunchPlan: Sendable, Equatable {
    public var executable: String
    public var arguments: [String]
    public var environment: [String: String]
    public var workingDirectory: String?

    public enum Error: Swift.Error, Equatable, CustomStringConvertible {
        case noCommand
        case notFound(String)
        case noSuitablePHP(minimum: String?, tried: [String])

        public var description: String {
            switch self {
            case .noCommand:
                return "The manifest names no command to run."
            case let .notFound(command):
                return "\(command) cannot be found."
            case let .noSuitablePHP(minimum, tried):
                let what = minimum.map { "PHP \($0) or later" } ?? "PHP"
                let looked = tried.isEmpty ? "No php was found." : "Found: \(tried.joined(separator: ", "))."
                return "This application needs \(what). \(looked) Install it, for example with Homebrew (brew install php), then open the application again."
            }
        }
    }

    /// Where `php` may be: `launch.php.path`, else every `php` on PATH, then the usual
    /// installation directories, since an application opened from the Finder gets a short PATH.
    public static func phpCandidates(manifest: Manifest, manifestDirectory: String, path: String?, home: String?, exists: (String) -> Bool) -> [String] {
        if let configured = manifest.launch.php?.path, !configured.isEmpty {
            return [resolve(configured, against: manifestDirectory)]
        }
        var directories = (path ?? "").split(separator: ":").map(String.init)
        directories += ["/opt/homebrew/bin", "/usr/local/bin", "/opt/local/bin"]
        if let home { directories.append(home + "/.local/bin") }
        var seen = Set<String>()
        return directories.compactMap { directory in
            let candidate = (directory as NSString).appendingPathComponent("php")
            guard exists(candidate), seen.insert(candidate).inserted else { return nil }
            return candidate
        }
    }

    /// The plan for a packaged application. `php` is the interpreter chosen for `system` mode.
    public static func make(manifest: Manifest, manifestDirectory: String, inherited: [String: String], address: String, token: String, php: String? = nil) throws -> LaunchPlan {
        let launch = manifest.launch
        var arguments = launch.args.map { resolveArgument($0, against: manifestDirectory) }
        let executable: String
        if launch.mode == "system" {
            guard let php else {
                throw Error.noSuitablePHP(minimum: launch.php?.minVersion, tried: [])
            }
            executable = php
            if let command = launch.command, !command.isEmpty {
                arguments.insert(resolveArgument(command, against: manifestDirectory), at: 0)
            }
        } else {
            guard let command = launch.command, !command.isEmpty else { throw Error.noCommand }
            executable = resolve(command, against: manifestDirectory)
        }
        var environment = EnvironmentFilter.filter(inherited, pass: launch.env?.pass ?? [], set: launch.env?.set ?? [:])
        environment["TUI_APP_ADDR"] = address
        environment["TUI_APP_TOKEN"] = token
        return LaunchPlan(executable: executable, arguments: arguments, environment: environment, workingDirectory: manifestDirectory)
    }

    /// `--dev -- <command> [args…]`: the command as given, found on PATH, with the host's whole
    /// environment, run from the current directory.
    public static func development(command: [String], inherited: [String: String], currentDirectory: String, address: String, token: String, exists: (String) -> Bool) throws -> LaunchPlan {
        guard let first = command.first else { throw Error.noCommand }
        guard let executable = which(first, path: inherited["PATH"], currentDirectory: currentDirectory, exists: exists) else {
            throw Error.notFound(first)
        }
        var environment = inherited
        environment["TUI_APP_ADDR"] = address
        environment["TUI_APP_TOKEN"] = token
        return LaunchPlan(executable: executable, arguments: Array(command.dropFirst()), environment: environment, workingDirectory: currentDirectory)
    }

    public static func which(_ command: String, path: String?, currentDirectory: String, exists: (String) -> Bool) -> String? {
        if command.contains("/") {
            let full = resolve(command, against: currentDirectory)
            return exists(full) ? full : nil
        }
        for directory in (path ?? "/usr/bin:/bin").split(separator: ":") {
            let candidate = (String(directory) as NSString).appendingPathComponent(command)
            if exists(candidate) { return candidate }
        }
        return nil
    }

    /// Arguments starting with ./ are relative to the manifest directory; others are kept.
    static func resolveArgument(_ argument: String, against directory: String) -> String {
        argument.hasPrefix("./") ? (directory as NSString).appendingPathComponent(String(argument.dropFirst(2))) : argument
    }

    static func resolve(_ path: String, against directory: String) -> String {
        let expanded = (path as NSString).expandingTildeInPath
        if expanded.hasPrefix("/") { return (expanded as NSString).standardizingPath }
        return ((directory as NSString).appendingPathComponent(expanded) as NSString).standardizingPath
    }
}
