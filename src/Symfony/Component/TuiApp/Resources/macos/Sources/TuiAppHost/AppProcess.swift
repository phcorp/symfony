import Foundation
import os
import TuiAppHostCore

/// Where the application's stdout and stderr go: a file, the host's stderr (`--dev`), or the
/// unified log.
final class AppLog: @unchecked Sendable {
    enum Sink {
        case file(FileHandle)
        case standardError
        case system(Logger)
    }

    private let sink: Sink
    private let lock = NSLock()
    private var tail: [String] = []
    private var partial = ""

    init(sink: Sink) {
        self.sink = sink
    }

    static func make(manifest: Manifest, manifestDirectory: String?, development: Bool) -> AppLog {
        if development {
            return AppLog(sink: .standardError)
        }
        if let file = manifest.logging?.file, !file.isEmpty {
            let expanded = (file as NSString).expandingTildeInPath
            let path = expanded.hasPrefix("/") ? expanded : ((manifestDirectory ?? FileManager.default.currentDirectoryPath) as NSString).appendingPathComponent(expanded)
            try? FileManager.default.createDirectory(atPath: (path as NSString).deletingLastPathComponent, withIntermediateDirectories: true)
            if !FileManager.default.fileExists(atPath: path) {
                FileManager.default.createFile(atPath: path, contents: nil)
            }
            if let handle = FileHandle(forWritingAtPath: path) {
                handle.seekToEndOfFile()
                return AppLog(sink: .file(handle))
            }
        }
        return AppLog(sink: .system(Logger(subsystem: manifest.app.id, category: "app")))
    }

    /// Output of the application. Stderr lines are also kept for an error dialog.
    func write(_ data: Data, stderr: Bool) {
        switch sink {
        case let .file(handle):
            lock.withLock { handle.write(data) }
        case .standardError:
            FileHandle.standardError.write(data)
        case let .system(logger):
            let text = String(decoding: data, as: UTF8.self)
            if stderr {
                logger.error("\(text, privacy: .public)")
            } else {
                logger.info("\(text, privacy: .public)")
            }
        }
        if stderr {
            lock.withLock {
                let text = partial + String(decoding: data, as: UTF8.self)
                var lines = text.components(separatedBy: "\n")
                partial = lines.removeLast()
                tail.append(contentsOf: lines)
                if tail.count > 40 { tail.removeFirst(tail.count - 40) }
            }
        }
    }

    /// A note from the host itself.
    func host(_ text: String) {
        write(Data("tuiapp-host: \(text)\n".utf8), stderr: false)
    }

    func lastLines(_ count: Int = 12) -> String {
        lock.withLock {
            (tail + (partial.isEmpty ? [] : [partial])).suffix(count).joined(separator: "\n")
        }
    }
}

/// The application process.
final class AppProcess: @unchecked Sendable {
    private let process = Process()
    private let log: AppLog

    init(plan: LaunchPlan, log: AppLog) {
        self.log = log
        process.executableURL = URL(fileURLWithPath: plan.executable)
        process.arguments = plan.arguments
        process.environment = plan.environment
        if let directory = plan.workingDirectory {
            process.currentDirectoryURL = URL(fileURLWithPath: directory)
        }
        process.standardInput = FileHandle.nullDevice
        let out = Pipe()
        let err = Pipe()
        process.standardOutput = out
        process.standardError = err
        out.fileHandleForReading.readabilityHandler = { [log] handle in
            let data = handle.availableData
            if data.isEmpty { handle.readabilityHandler = nil } else { log.write(data, stderr: false) }
        }
        err.fileHandleForReading.readabilityHandler = { [log] handle in
            let data = handle.availableData
            if data.isEmpty { handle.readabilityHandler = nil } else { log.write(data, stderr: true) }
        }
    }

    /// Starts it; `exited` runs on the main thread with the exit status.
    func start(exited: @escaping @MainActor @Sendable (Int32) -> Void) throws {
        process.terminationHandler = { process in
            let status = process.terminationStatus
            // Let the pipes drain what was written just before the exit.
            DispatchQueue.main.asyncAfter(deadline: .now() + 0.1) {
                MainActor.assumeIsolated { exited(status) }
            }
        }
        try process.run()
    }

    var isRunning: Bool { process.isRunning }
    var pid: Int32 { process.processIdentifier }

    /// SIGTERM, then SIGKILL after a second.
    func kill() {
        guard process.isRunning else { return }
        process.terminate()
        let pid = process.processIdentifier
        DispatchQueue.global().asyncAfter(deadline: .now() + 1) { [process] in
            if process.isRunning { Darwin.kill(pid, SIGKILL) }
        }
    }

    /// `php -r 'echo PHP_VERSION;'`, or nil when it does not run.
    static func phpVersion(_ php: String) -> String? {
        let process = Process()
        process.executableURL = URL(fileURLWithPath: php)
        process.arguments = ["-r", "echo PHP_VERSION;"]
        let out = Pipe()
        process.standardOutput = out
        process.standardError = FileHandle.nullDevice
        process.standardInput = FileHandle.nullDevice
        do {
            try process.run()
        } catch {
            return nil
        }
        let data = out.fileHandleForReading.readDataToEndOfFile()
        process.waitUntilExit()
        guard process.terminationStatus == 0 else { return nil }
        let version = String(decoding: data, as: UTF8.self).trimmingCharacters(in: .whitespacesAndNewlines)
        return version.isEmpty ? nil : version
    }
}
