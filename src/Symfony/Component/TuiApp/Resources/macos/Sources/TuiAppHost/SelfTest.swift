import AppKit
import TuiAppHostCore

/// `--self-test`: types "Ada" into textfield node 3 as a user would, waits for label node 2 to
/// read "Hello Ada!", checks every node has its native control, closes, and exits 0 once the
/// application said bye and exited. Any failure, or 20 s, exits 1 with the reason.
@MainActor
final class SelfTest {
    private weak var controller: HostWindowController?
    private var stage = "waiting for the first patch"
    private var started = false
    private var done = false
    private var deadline: Timer?
    private let begin = Date()

    init(controller: HostWindowController) {
        self.controller = controller
        say("starting")
        deadline = Timer.scheduledTimer(withTimeInterval: 20, repeats: false) { [weak self] _ in
            MainActor.assumeIsolated {
                guard let self else { return }
                self.fail("no result after 20 s, while \(self.stage)")
            }
        }
    }

    private func say(_ text: String) {
        let elapsed = String(format: "%.2f", Date().timeIntervalSince(begin))
        FileHandle.standardOutput.write(Data("self-test [\(elapsed)s]: \(text)\n".utf8))
    }

    func firstPatchDrawn() {
        guard !started, let controller else { return }
        started = true
        say("first patch drawn")
        guard let view = controller.reconciler.views[3] as? TextFieldNodeView else {
            return fail("node 3 is not a native text field (\(controller.reconciler.views[3].map { String(describing: type(of: $0)) } ?? "missing"))")
        }
        stage = "typing into node 3"
        let field = view.field
        guard let window = field.window, window.makeFirstResponder(field), let editor = field.currentEditor() as? NSTextView else {
            return fail("the text field of node 3 does not take the focus")
        }
        // Through the field editor, as typing does: the change handler runs, then Enter submits.
        editor.selectAll(nil)
        editor.insertText("Ada", replacementRange: editor.selectedRange())
        guard field.stringValue == "Ada" else {
            return fail("typing left \"\(field.stringValue)\" in node 3")
        }
        editor.doCommand(by: #selector(NSResponder.insertNewline(_:)))
        say("typed \"Ada\" into node 3 and pressed Enter")
        stage = "waiting for node 2 to read \"Hello Ada!\""
        poll(until: Date().addingTimeInterval(5))
    }

    private func labelText() -> String? {
        (controller?.reconciler.views[2] as? LabelNodeView)?.field.stringValue
    }

    private func poll(until: Date) {
        if labelText() == "Hello Ada!" {
            say("node 2 reads \"Hello Ada!\"")
            return checkControls()
        }
        guard Date() < until else {
            return fail("node 2 reads \"\(labelText() ?? "(no label)")\" after 5 s, not \"Hello Ada!\"")
        }
        DispatchQueue.main.asyncAfter(deadline: .now() + 0.05) { [weak self] in
            MainActor.assumeIsolated { self?.poll(until: until) }
        }
    }

    /// The AppKit class each kind must show as.
    private static func expected(_ kind: String) -> AnyClass {
        switch kind {
        case "stack": return StackNodeView.self
        case "label", "textfield", "number": return NSTextField.self
        case "password": return NSSecureTextField.self
        case "textarea": return NSTextView.self
        case "list", "listbox": return NSTableView.self
        case "slider": return NSSlider.self
        case "form": return NSGridView.self
        case "tabs": return NSTabView.self
        case "disclosure": return NSButton.self
        case "progress", "spinner": return NSProgressIndicator.self
        case "canvas": return CanvasSurface.self
        default: return UnknownNodeView.self
        }
    }

    private func checkControls() {
        guard let controller else { return }
        stage = "checking native controls"
        let ids = controller.tree.orderedIds
        var missing = [String]()
        var kinds = Set<String>()
        for id in ids {
            guard let node = controller.tree[id] else { continue }
            kinds.insert(node.kind)
            guard let view = controller.reconciler.views[id] else {
                missing.append("\(id) (\(node.kind)): no view")
                continue
            }
            if !view.nativeView.isKind(of: Self.expected(node.kind)) {
                missing.append("\(id) (\(node.kind)): \(type(of: view.nativeView)), not \(Self.expected(node.kind))")
            }
        }
        guard missing.isEmpty else {
            return fail("missing native controls: " + missing.joined(separator: "; "))
        }
        say("\(ids.count) nodes, each with its native control (\(kinds.sorted().joined(separator: ", ")))")
        stage = "closing"
        say("sending close")
        controller.requestClose()
    }

    func closeRefused() {
        guard !done, stage == "closing" else { return }
        say("the application refused to close; asking again")
        controller?.requestClose()
    }

    func processExited(status: Int32, saidBye: Bool, exit: CloseFlow.Exit, stderr: String) {
        guard !done else { return }
        if stage == "closing", saidBye, status == 0 {
            say("the application said bye and exited with status 0")
            pass()
        } else {
            let tail = stderr.isEmpty ? "" : "\nlast stderr lines:\n" + stderr
            fail("the application exited with status \(status) while \(stage)\(saidBye ? " (after bye)" : " (no bye)")" + tail)
        }
    }

    private func pass() {
        done = true
        say("ok")
        exit(0)
    }

    func fail(_ why: String) {
        guard !done else { return }
        done = true
        FileHandle.standardError.write(Data("self-test: FAILED: \(why)\n".utf8))
        controller?.killApplication()
        exit(1)
    }
}
