import AppKit
import TuiAppHostCore

/// The menu bar: the application menu (About and Quit as the manifest says), the application's
/// own menus, then Edit and Window.
@MainActor
final class MainMenu: NSObject {
    private let bar = NSMenu()
    private var shown: [NSMenuItem] = []
    private var choose: ((String) -> Void)?

    func install(name: String, about: Bool, quit: Bool, target: AppDelegate) {
        var appItems: [NSMenuItem] = []
        if about {
            appItems += [Self.item("About \(name)", #selector(AppDelegate.showAbout(_:)), "", target), .separator()]
        }
        appItems += [
            NSMenuItem(title: "Hide \(name)", action: #selector(NSApplication.hide(_:)), keyEquivalent: "h"),
            {
                let item = NSMenuItem(title: "Hide Others", action: #selector(NSApplication.hideOtherApplications(_:)), keyEquivalent: "h")
                item.keyEquivalentModifierMask = [.command, .option]
                return item
            }(),
            NSMenuItem(title: "Show All", action: #selector(NSApplication.unhideAllApplications(_:)), keyEquivalent: ""),
        ]
        if quit {
            appItems += [.separator(), Self.item("Quit \(name)", #selector(AppDelegate.quit(_:)), "q", target)]
        }
        bar.addItem(Self.submenu(name, appItems))

        let redo = NSMenuItem(title: "Redo", action: Selector(("redo:")), keyEquivalent: "z")
        redo.keyEquivalentModifierMask = [.command, .shift]
        bar.addItem(Self.submenu("Edit", [
            NSMenuItem(title: "Undo", action: Selector(("undo:")), keyEquivalent: "z"),
            redo,
            .separator(),
            NSMenuItem(title: "Cut", action: #selector(NSText.cut(_:)), keyEquivalent: "x"),
            NSMenuItem(title: "Copy", action: #selector(NSText.copy(_:)), keyEquivalent: "c"),
            NSMenuItem(title: "Paste", action: #selector(NSText.paste(_:)), keyEquivalent: "v"),
            NSMenuItem(title: "Select All", action: #selector(NSText.selectAll(_:)), keyEquivalent: "a"),
        ]))
        let window = Self.submenu("Window", [
            NSMenuItem(title: "Minimize", action: #selector(NSWindow.performMiniaturize(_:)), keyEquivalent: "m"),
            NSMenuItem(title: "Zoom", action: #selector(NSWindow.performZoom(_:)), keyEquivalent: ""),
            NSMenuItem(title: "Close Window", action: #selector(NSWindow.performClose(_:)), keyEquivalent: "w"),
            .separator(),
            NSMenuItem(title: "Bring All to Front", action: #selector(NSApplication.arrangeInFront(_:)), keyEquivalent: ""),
        ])
        bar.addItem(window)
        NSApp.mainMenu = bar
        NSApp.windowsMenu = window.submenu
    }

    /// The application's menus, after the application menu. Items with a shortcut bind it, so
    /// the key goes out as `menu-select`, not as `key`.
    func show(_ menus: [Menu], choose: @escaping (String) -> Void) {
        self.choose = choose
        shown.forEach(bar.removeItem)
        shown = menus.enumerated().map { index, menu in
            let holder = NSMenuItem(title: menu.title, action: nil, keyEquivalent: "")
            let submenu = NSMenu(title: menu.title)
            submenu.autoenablesItems = false
            for entry in menu.items {
                submenu.addItem(entry.separator == true ? .separator() : makeItem(entry))
            }
            holder.submenu = submenu
            bar.insertItem(holder, at: index + 1)
            return holder
        }
    }

    private func makeItem(_ entry: MenuItem) -> NSMenuItem {
        let item = NSMenuItem(title: entry.label ?? entry.id ?? "", action: #selector(chosen(_:)), keyEquivalent: "")
        item.target = self
        item.representedObject = entry.id
        item.isEnabled = entry.enabled != false && entry.id != nil
        item.state = entry.checked.value == true ? .on : .off
        if let shortcut = entry.shortcut.value, let equivalent = KeyId.keyEquivalent(shortcut) {
            item.keyEquivalent = equivalent.key
            var mask = NSEvent.ModifierFlags()
            if equivalent.modifiers.contains(.control) { mask.insert(.control) }
            if equivalent.modifiers.contains(.shift) { mask.insert(.shift) }
            if equivalent.modifiers.contains(.option) { mask.insert(.option) }
            if equivalent.modifiers.contains(.command) { mask.insert(.command) }
            item.keyEquivalentModifierMask = mask
        }
        return item
    }

    @objc private func chosen(_ sender: NSMenuItem) {
        guard let id = sender.representedObject as? String else { return }
        choose?(id)
    }

    private static func item(_ title: String, _ action: Selector, _ key: String, _ target: AnyObject) -> NSMenuItem {
        let item = NSMenuItem(title: title, action: action, keyEquivalent: key)
        item.target = target
        return item
    }

    private static func submenu(_ title: String, _ items: [NSMenuItem]) -> NSMenuItem {
        let holder = NSMenuItem(title: title, action: nil, keyEquivalent: "")
        let menu = NSMenu(title: title)
        items.forEach(menu.addItem)
        holder.submenu = menu
        return holder
    }
}
