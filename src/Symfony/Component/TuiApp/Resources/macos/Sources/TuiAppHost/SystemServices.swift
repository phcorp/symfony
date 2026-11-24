import AppKit
import TuiAppHostCore
import UserNotifications

/// The progress bar drawn over the application icon in the Dock.
final class DockProgressView: NSView {
    var state = "normal"
    var value: Double?

    override func draw(_ dirtyRect: NSRect) {
        NSApp.applicationIconImage?.draw(in: bounds)
        let bar = NSRect(x: bounds.width * 0.1, y: bounds.height * 0.08, width: bounds.width * 0.8, height: bounds.height * 0.11)
        let radius = bar.height / 2
        NSColor.black.withAlphaComponent(0.55).setFill()
        NSBezierPath(roundedRect: bar, xRadius: radius, yRadius: radius).fill()
        let inner = bar.insetBy(dx: 2, dy: 2)
        let fill: NSColor
        switch state {
        case "error": fill = .systemRed
        case "paused": fill = .systemYellow
        case "indeterminate": fill = .systemGray
        default: fill = .controlAccentColor
        }
        fill.setFill()
        let fraction = state == "indeterminate" ? 1 : min(1, max(0, value ?? 0))
        guard fraction > 0 else { return }
        var filled = inner
        filled.size.width = max(inner.height, inner.width * fraction)
        NSBezierPath(roundedRect: filled, xRadius: inner.height / 2, yRadius: inner.height / 2).fill()
    }
}

@MainActor
final class DockProgress {
    private var view: DockProgressView?

    func show(_ progress: WindowProgress?) {
        let tile = NSApp.dockTile
        guard let progress else {
            if view != nil {
                view = nil
                tile.contentView = nil
                tile.display()
            }
            return
        }
        let view = self.view ?? DockProgressView(frame: NSRect(origin: .zero, size: tile.size))
        self.view = view
        view.state = progress.state
        view.value = progress.value.value
        tile.contentView = view
        tile.display()
    }
}

/// System notifications.
///
/// In a bundle, UNUserNotificationCenter: it asks the user once, and shows notifications even
/// while the host is the active application. macOS refuses it to an ad hoc signed bundle
/// ("Notifications are not allowed for this application"), and an unbundled binary (`swift
/// run`, `--dev`) has no notification identity: both fall back to `osascript -e 'display
/// notification'`, which macOS shows under Script Editor, when Script Editor may notify.
@MainActor
final class Notifier: NSObject, @preconcurrency UNUserNotificationCenterDelegate {
    private let log: AppLog
    private var authorized: Bool?
    /// Why UNUserNotificationCenter cannot be used, once it said so.
    private var refused: String?
    /// Authorization without asking the user: notifications go quietly to Notification Center.
    /// For the feature test, which nobody is there to answer.
    var provisional = false
    /// How each notification went, for the feature test.
    var onOutcome: ((String) -> Void)?

    init(log: AppLog) {
        self.log = log
        super.init()
        if Self.bundled {
            UNUserNotificationCenter.current().delegate = self
        }
    }

    static var bundled: Bool {
        Bundle.main.bundleIdentifier != nil && Bundle.main.bundleURL.pathExtension == "app"
    }

    func notify(title: String, body: String?) {
        guard Self.bundled, refused == nil else {
            return notifyWithScript(title: title, body: body, why: refused ?? "no bundle")
        }
        Task { @MainActor in
            let center = UNUserNotificationCenter.current()
            do {
                if self.authorized == nil {
                    var options: UNAuthorizationOptions = [.alert, .sound]
                    if self.provisional { options.insert(.provisional) }
                    do {
                        self.authorized = try await center.requestAuthorization(options: options)
                    } catch let error as UNError where error.code == .notificationsNotAllowed {
                        // Not the user's choice, which is a false answer: macOS does not let this
                        // bundle notify at all (ad hoc signed).
                        guard await center.notificationSettings().authorizationStatus != .denied else {
                            self.authorized = false
                            return self.report("not shown: notifications are turned off for this application")
                        }
                        self.refused = "UNUserNotificationCenter: \(error.localizedDescription)"
                        return self.notifyWithScript(title: title, body: body, why: self.refused!)
                    }
                }
                guard self.authorized == true else {
                    let status = await center.notificationSettings().authorizationStatus
                    return self.report("not shown: notifications are not allowed (authorization status \(status.rawValue))")
                }
                let content = UNMutableNotificationContent()
                content.title = title
                if let body { content.body = body }
                let id = UUID().uuidString
                try await center.add(UNNotificationRequest(identifier: id, content: content, trigger: nil))
                // Delivery takes a moment after add().
                var delivered = false
                for _ in 0..<20 where !delivered {
                    delivered = await center.deliveredNotifications().contains { $0.request.identifier == id }
                    if !delivered { try? await Task.sleep(nanoseconds: 100_000_000) }
                }
                self.report("posted to UNUserNotificationCenter (\(delivered ? "delivered" : "not in the delivered list"))")
            } catch {
                self.report("not shown: \(error.localizedDescription)")
            }
        }
    }

    private func notifyWithScript(title: String, body: String?, why: String) {
        // The texts are arguments, never script source.
        let process = Process()
        process.executableURL = URL(fileURLWithPath: "/usr/bin/osascript")
        process.arguments = ["-e", "on run argv", "-e", "display notification (item 2 of argv) with title (item 1 of argv)", "-e", "end run", title, body ?? ""]
        process.standardInput = FileHandle.nullDevice
        process.standardOutput = FileHandle.nullDevice
        process.standardError = FileHandle.nullDevice
        process.terminationHandler = { [weak self] process in
            let status = process.terminationStatus
            Task { @MainActor in
                self?.report(status == 0 ? "shown with osascript (\(why))" : "not shown: osascript exited with \(status)")
            }
        }
        do {
            try process.run()
        } catch {
            report("not shown: osascript: \(error.localizedDescription)")
        }
    }

    private func report(_ outcome: String) {
        log.host("notification \(outcome)")
        onOutcome?(outcome)
    }

    // Shown even while the host is the active application.
    func userNotificationCenter(_ center: UNUserNotificationCenter, willPresent notification: UNNotification) async -> UNNotificationPresentationOptions {
        [.banner, .list, .sound]
    }

    // A click on the notification brings the window forward.
    func userNotificationCenter(_ center: UNUserNotificationCenter, didReceive response: UNNotificationResponse) async {
        NSApp.activate(ignoringOtherApps: true)
    }
}
