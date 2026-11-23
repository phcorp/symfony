import AppKit

/// PNGs of what the host drew, for the tests' captures.
///
/// A window is taken as the window server shows it (`screencapture -l`): drawing a view to a bitmap
/// (`cacheDisplay`) leaves out what AppKit draws in layers of its own, like the selected tab of an
/// NSTabView, a white block there. A view that is not a whole window, or a window the screen cannot
/// be read for (no screen recording permission), is drawn to a bitmap.
@MainActor
enum WindowCapture {
    /// Writes the PNG; returns how it was taken, or nil when it could not be.
    static func write(_ view: NSView, to path: String) -> String? {
        if let window = view.window, view === window.contentView || view === window.contentView?.superview, window.isVisible, window.windowNumber > 0 {
            window.displayIfNeeded()
            CATransaction.flush()
            let process = Process()
            process.executableURL = URL(fileURLWithPath: "/usr/sbin/screencapture")
            // -x: no sound, -o: no shadow, -l: that window only
            process.arguments = ["-x", "-o", "-l", String(window.windowNumber), path]
            process.standardOutput = FileHandle.nullDevice
            process.standardError = FileHandle.nullDevice
            if (try? process.run()) != nil {
                process.waitUntilExit()
                let size = (try? FileManager.default.attributesOfItem(atPath: path)[.size] as? Int) ?? 0
                if process.terminationStatus == 0, size > 0 { return "window" }
            }
        }
        guard let bitmap = view.bitmapImageRepForCachingDisplay(in: view.bounds) else { return nil }
        view.cacheDisplay(in: view.bounds, to: bitmap)
        guard let data = bitmap.representation(using: .png, properties: [:]), FileManager.default.createFile(atPath: path, contents: data) else { return nil }
        return "view"
    }
}
