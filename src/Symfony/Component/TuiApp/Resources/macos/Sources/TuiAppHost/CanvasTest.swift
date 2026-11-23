import AppKit
import TuiAppHostCore

/// `--canvas-test`: controls over a canvas, Tab handed to the application, input methods and rects
/// after a resize, against `FAKE_APP_CANVAS=1 php tools/fake-app.php`. See HOSTS.md. Each stage
/// acts through AppKit as a user, or the input method, would, and waits for label node 2 to show
/// what the application got. Any failure, or 60 s (as on Linux), exits 1.
///
/// `TUI_APP_CANVAS_CAPTURE=<dir>` writes canvas.png, the window after the input method stage.
@MainActor
final class CanvasTest {
    private weak var controller: HostWindowController?
    private var stage = "waiting for the first patch"
    private var done = false
    private let begin = Date()
    private var deadline: Timer?
    private let capture = ProcessInfo.processInfo.environment["TUI_APP_CANVAS_CAPTURE"].flatMap { $0.isEmpty ? nil : $0 }
    private var keysSent: [String] = []
    private var pointersSent: [Pointer] = []
    /// `contextmenu-select` and `paste` sent, as "select <id>" and "paste <text>".
    private var menuSent: [String] = []
    /// The user's pasteboard, put back once the test is over.
    private var savedPasteboard: [NSPasteboardItem]?

    init(controller: HostWindowController) {
        self.controller = controller
        say("starting")
        deadline = Timer.scheduledTimer(withTimeInterval: 60, repeats: false) { [weak self] _ in
            MainActor.assumeIsolated {
                guard let self else { return }
                self.fail("no result after 60 s, while \(self.stage)")
            }
        }
    }

    private func say(_ text: String) {
        let elapsed = String(format: "%.2f", Date().timeIntervalSince(begin))
        FileHandle.standardOutput.write(Data("canvas-test [\(elapsed)s]: \(text)\n".utf8))
    }

    // MARK: Hooks

    func sent(_ message: Message) {
        if case let .key(key, _) = message { keysSent.append(key) }
        if case let .pointer(_, pointer) = message { pointersSent.append(pointer) }
        if case let .contextMenuSelect(id) = message { menuSent.append("select \(id)") }
        if case let .paste(text) = message { menuSent.append("paste \(text)") }
    }

    func firstPatchDrawn() {
        guard stage == "waiting for the first patch" else { return }
        stage = "waiting for the controls"
        waitForLabel("canvas ready") { [weak self] in self?.checkPlacement() }
    }

    // MARK: Stages

    private var canvas: CanvasNodeView? { controller?.reconciler.views[21] as? CanvasNodeView }

    private func view<T: NodeView>(_ id: Int, _ type: T.Type) -> T? {
        controller?.reconciler.views[id] as? T
    }

    /// Every control is above the surface, at its place, and node 35 shows its first row only.
    private func checkPlacement() {
        stage = "checking the placement of the controls"
        guard let controller, let canvas else { return fail("node 21 is not a canvas") }
        let cell = controller.cell
        let kinds: [Int: NodeView.Type] = [30: TextFieldNodeView.self, 31: CheckboxNodeView.self, 32: CheckboxNodeView.self, 33: PopupNodeView.self, 34: ButtonNodeView.self, 35: TextAreaNodeView.self]
        let surfaceIndex = canvas.subviews.firstIndex { $0 === canvas.surface } ?? Int.max
        for id in kinds.keys.sorted() {
            guard let view = controller.reconciler.views[id], type(of: view) == kinds[id], let node = controller.tree[id] else {
                return fail("node \(id) has no \(kinds[id].map { String(describing: $0) } ?? "?")")
            }
            guard let clip = view.superview as? ClipView, clip.superview === canvas, let index = canvas.subviews.firstIndex(where: { $0 === clip }), index > surfaceIndex else {
                return fail("node \(id) is not over the canvas surface")
            }
            guard view.placed, !view.isHiddenOrHasHiddenAncestor, view.nativeView.window != nil else {
                return fail("node \(id) is not shown as a placed control")
            }
            let place = node.props["place"]?.arrayValue?.compactMap(\.intValue) ?? []
            let expected = NSRect(x: CGFloat(place[1]) * cell.width, y: CGFloat(place[0]) * cell.height, width: CGFloat(place[2]) * cell.width, height: CGFloat(place[3]) * cell.height)
            let frame = view.convert(view.bounds, to: canvas.surface)
            guard abs(frame.minX - expected.minX) <= 1, abs(frame.minY - expected.minY) <= 1, abs(frame.width - expected.width) <= 1, abs(frame.height - expected.height) <= 1 else {
                return fail("node \(id) is at \(frame), not at \(expected) (place \(place))")
            }
            let shown = clip.layer?.masksToBounds == true ? clip.frame.height : frame.height
            say("node \(id): \(String(describing: type(of: view.nativeView))) at place \(place), \(Int(frame.width))×\(Int(frame.height)) pt\(id == 35 ? ", \(Int(shown)) pt shown" : "")")
        }
        guard let clip = view(35, TextAreaNodeView.self)?.superview, clip.layer?.masksToBounds == true, abs(clip.frame.height - CGFloat(cell.height)) <= 1 else {
            return fail("node 35 is not cut to its first row")
        }
        say("node 35 is cut to its first row")
        keyViewLoop()
    }

    /// Tab goes through the canvas and its controls in the order of the nodes, not where they are: the
    /// test moves button 34 above the others and clips popup 33 to nothing, as a patch would, walks the
    /// loop with Tab from the canvas, then puts them back.
    private func keyViewLoop() {
        stage = "walking the key-view loop of canvas 21"
        guard let controller, let canvas, let window = controller.window else { return }
        controller.applyForTest([.update(id: 34, props: ["place": .array([.int(1), .int(0), .int(8), .int(1)])]), .update(id: 33, props: ["clip": .array([.int(5), .int(10), .int(0), .int(1)])])])
        let children = (30...39).compactMap { controller.reconciler.views[$0] }
        let expected = children.filter { $0.id != 33 && $0.focusTarget?.canBecomeKeyView == true }.map(\.id)
        guard window.makeFirstResponder(canvas.surface) else { return fail("the canvas does not take the focus") }
        var walked: [Int] = []
        for _ in 0...expected.count {
            // What Tab does: from the field, not its field editor, when one edits it
            let editor = window.firstResponder as? NSTextView
            guard let from = editor?.isFieldEditor == true ? editor?.delegate as? NSView : window.firstResponder as? NSView else { break }
            window.selectKeyView(following: from)
            guard let id = controller.reconciler.view(for: window.firstResponder)?.id, (30...39).contains(id) else { break }
            walked.append(id)
        }
        let left = controller.reconciler.view(for: window.firstResponder)?.id
        controller.applyForTest([.update(id: 34, props: ["place": .array([.int(7), .int(10), .int(8), .int(1)])]), .update(id: 33, props: ["clip": .null])])
        guard walked == expected, left != 21 else {
            return fail("Tab from the canvas went through nodes \(walked), then \(left.map(String.init) ?? "nothing"), not \(expected) in order, then out of the canvas")
        }
        say("Tab from the canvas went through nodes \(walked) in node order, skipping 33 clipped to nothing, then out of the canvas to node \(left.map(String.init) ?? "none")")
        typeIntoField()
    }

    /// Every control placed on canvas 21 (30 to 39) fits the cells of its `place`, to within half a
    /// point: the node view, its native control and every view in it (a stepper's arrows, a scroll
    /// view's scrollers), down to the clip view of a scroll view, whose content scrolls.
    private func overflowing() -> String? {
        stage = "checking that the controls fit their places"
        guard let controller, let canvas else { return "no canvas" }
        let cell = controller.cell
        var out: [String] = []
        for id in 30...39 {
            guard let view = controller.reconciler.views[id], let place = controller.tree[id]?.props["place"]?.arrayValue?.compactMap(\.intValue), place.count == 4 else {
                out.append("node \(id): missing")
                continue
            }
            let rect = NSRect(x: CGFloat(place[1]) * cell.width, y: CGFloat(place[0]) * cell.height, width: CGFloat(place[2]) * cell.width, height: CGFloat(place[3]) * cell.height)
            func check(_ v: NSView, _ path: String) {
                guard !v.isHidden else { return }
                let frame = v.convert(v.bounds, to: canvas.surface)
                if frame.minX < rect.minX - 0.5 || frame.minY < rect.minY - 0.5 || frame.maxX > rect.maxX + 0.5 || frame.maxY > rect.maxY + 0.5 {
                    out.append("node \(id) \(path) at \(frame) is out of its place \(rect) (\(place))")
                }
                if v is NSClipView { return }
                for sub in v.subviews { check(sub, path + "/" + String(describing: type(of: sub))) }
            }
            check(view, String(describing: type(of: view)))
        }
        if !out.isEmpty { return "controls out of their places:\n" + out.joined(separator: "\n") }
        say("nodes 30 to 39 and every view in them fit their places to within half a point")
        return nil
    }

    /// Through the toolkit, as a user would.
    private func typeIntoField() {
        stage = "typing into node 30"
        guard let field = view(30, TextFieldNodeView.self)?.field, let window = field.window, window.makeFirstResponder(field), let editor = field.currentEditor() as? NSTextView else {
            return fail("the text field of node 30 does not take the focus")
        }
        editor.selectAll(nil)
        editor.insertText("Ada", replacementRange: editor.selectedRange())
        waitForLabel("event 30 change \"Ada\"") { [weak self] in
            guard let self, let box = self.view(31, CheckboxNodeView.self)?.box else { return }
            self.stage = "clicking node 31"
            box.performClick(nil)
            self.waitForLabel("event 31 toggle false") {
                guard let radio = self.view(32, CheckboxNodeView.self)?.box else { return }
                self.stage = "clicking node 32"
                radio.performClick(nil)
                self.waitForLabel("event 32 toggle true") {
                    guard let popup = self.view(33, PopupNodeView.self)?.popup else { return }
                    self.stage = "picking the third choice of node 33"
                    popup.menu?.performActionForItem(at: 2)
                    self.waitForLabel("event 33 select 2") {
                        guard let button = self.view(34, ButtonNodeView.self)?.button else { return }
                        self.stage = "clicking node 34"
                        button.performClick(nil)
                        self.waitForLabel("event 34 press") { self.maxLength() }
                    }
                }
            }
        }
    }

    /// `maxLength`: a paste is cut and typing stops at it. Node 30 takes 40 characters (the fixture),
    /// node 35 takes 8 (the fake app).
    private func maxLength() {
        stage = "pasting past the maxLength of node 30"
        guard let field = view(30, TextFieldNodeView.self)?.field, let window = field.window, window.makeFirstResponder(field), let editor = field.currentEditor() as? NSTextView else {
            return fail("the text field of node 30 does not take the focus")
        }
        let digits = String(repeating: "0123456789", count: 4)
        let kept = "Ada" + digits.prefix(37)
        editor.setSelectedRange(NSRange(location: (editor.string as NSString).length, length: 0))
        // What paste: inserts, as one edit
        editor.insertText(digits, replacementRange: editor.selectedRange())
        waitForLabel("event 30 change \"\(kept)\"") { [weak self] in
            guard let self else { return }
            self.stage = "typing past the maxLength of node 30"
            self.press("z", into: window)
            self.after(0.3) {
                guard field.stringValue == kept, self.labelText() == "event 30 change \"\(kept)\"" else {
                    return self.fail("typing z at the maxLength of node 30 made it \"\(field.stringValue)\"; node 2 reads \"\(self.labelText() ?? "")\"")
                }
                self.say("node 30 kept 40 characters of a paste, and refused a typed z")
                guard let area = self.view(35, TextAreaNodeView.self)?.text, window.makeFirstResponder(area) else {
                    return self.fail("the text view of node 35 does not take the focus")
                }
                self.stage = "typing past the maxLength of node 35"
                area.setSelectedRange(NSRange(location: (area.string as NSString).length, length: 0))
                for character in "abcd" { self.press(character, into: window) }
                self.waitForLabel("event 35 change \"Notesabc\"") {
                    self.after(0.3) {
                        guard area.string == "Notesabc", self.labelText() == "event 35 change \"Notesabc\"" else {
                            return self.fail("typing abcd into node 35 (Notes, maxLength 8) made it \"\(area.string)\"; node 2 reads \"\(self.labelText() ?? "")\"")
                        }
                        self.say("node 35 took abc of a typed abcd: 8 characters")
                        self.overLimit()
                    }
                }
            }
        }
    }

    /// A value past `maxLength`: the application lowered node 30's to 30 (it has 40 characters). A
    /// selection may be replaced as long as the value gets no longer, and a key at the end is refused.
    /// Then an input method composes with room for one character: the marked text is kept whole, and
    /// sent to no one, and its commit is cut to what fits.
    private func overLimit() {
        stage = "waiting for the maxLength of node 30 to go down to 30"
        waitFor("node 30 to have maxLength 30", seconds: 5, { [weak self] in self?.controller?.tree[30]?.props["maxLength"]?.intValue == 30 }) { [weak self] in
            guard let self else { return }
            guard let field = self.view(30, TextFieldNodeView.self)?.field, let window = field.window, window.makeFirstResponder(field), let editor = field.currentEditor() as? NSTextView else {
                return self.fail("the text field of node 30 does not take the focus")
            }
            let digits = String(repeating: "0123456789", count: 4)
            let kept = "Ada" + digits.prefix(32)
            let end = { (editor.string as NSString).length }
            self.stage = "pasting XYZ over the last 5 of the 40 characters of node 30"
            editor.setSelectedRange(NSRange(location: end() - 5, length: 5))
            editor.insertText("XYZ", replacementRange: editor.selectedRange())
            self.waitForLabel("event 30 change \"\(kept)XYZ\"") {
                self.stage = "pasting LMNOP over the last 2 of the 38 characters of node 30"
                editor.setSelectedRange(NSRange(location: end() - 2, length: 2))
                editor.insertText("LMNOP", replacementRange: editor.selectedRange())
                self.waitForLabel("event 30 change \"\(kept)XLM\"") {
                    self.stage = "typing past the maxLength of node 30, its value past it"
                    editor.setSelectedRange(NSRange(location: end(), length: 0))
                    self.press("z", into: window)
                    self.after(0.3) {
                        guard field.stringValue == kept + "XLM", self.labelText() == "event 30 change \"\(kept)XLM\"" else {
                            return self.fail("typing z at the end of node 30 (38 characters, maxLength 30) made it \"\(field.stringValue)\"; node 2 reads \"\(self.labelText() ?? "")\"")
                        }
                        self.say("node 30, past its maxLength: XYZ replaced 5 characters, LM of LMNOP 2, a typed z nothing")
                        self.stage = "deleting the last 9 characters of node 30"
                        editor.setSelectedRange(NSRange(location: end() - 9, length: 9))
                        editor.deleteBackward(nil)
                        let room = "Ada" + digits.prefix(26)
                        self.waitForLabel("event 30 change \"\(room)\"") { self.composeAtLimit(editor, room) }
                    }
                }
            }
        }
    }

    /// Node 30 has 29 characters of 30: the input method's marked text is not cut, nor committed early.
    private func composeAtLimit(_ editor: NSTextView, _ value: String) {
        stage = "composing in node 30 with room for one character"
        editor.setSelectedRange(NSRange(location: (editor.string as NSString).length, length: 0))
        editor.setMarkedText("にほん", selectedRange: NSRange(location: 3, length: 0), replacementRange: NSRange(location: NSNotFound, length: 0))
        after(0.3) { [weak self] in
            guard let self else { return }
            guard editor.hasMarkedText(), editor.markedRange().length == 3, editor.string == value + "にほん" else {
                return self.fail("the marked text にほん in node 30 is \(editor.hasMarkedText() ? "\(editor.markedRange().length) characters" : "gone"); it has \"\(editor.string)\"")
            }
            guard self.labelText() == "event 30 change \"\(value)\"" else {
                return self.fail("composing in node 30 sent something: node 2 reads \"\(self.labelText() ?? "")\"")
            }
            self.say("node 30 shows the marked text にほん whole past its maxLength, and sent nothing")
            // Tab, which the canvas takes (`keys`), is the input method's while it composes
            let sent = self.keysSent.count
            guard let window = editor.window, let tab = NSEvent.keyEvent(with: .keyDown, location: .zero, modifierFlags: [], timestamp: ProcessInfo.processInfo.systemUptime, windowNumber: window.windowNumber,
                                                                       context: nil, characters: "\t", charactersIgnoringModifiers: "\t", isARepeat: false, keyCode: 48) else { return self.fail("no Tab key event") }
            guard self.controller?.takeCanvasKey(tab) == false, self.keysSent.count == sent else {
                return self.fail("Tab while composing in node 30 went to the application as \(self.keysSent[sent...])")
            }
            self.say("Tab while composing in node 30 is left to the input method")
            self.stage = "committing 日本 in node 30 with room for one character"
            editor.insertText("日本", replacementRange: NSRange(location: NSNotFound, length: 0))
            self.waitForLabel("event 30 change \"\(value)日\"") {
                self.after(0.3) {
                    guard !editor.hasMarkedText(), editor.string == value + "日", self.labelText() == "event 30 change \"\(value)日\"" else {
                        return self.fail("after committing 日本, node 30 has \"\(editor.string)\"\(editor.hasMarkedText() ? ", marked text left" : ""); node 2 reads \"\(self.labelText() ?? "")\"")
                    }
                    self.say("node 30 took 日 of the committed 日本: 30 characters")
                    self.nativeInputs()
                }
            }
        }
    }

    /// The password field 36, number field 37, slider 38 and list box 39 (patch-native-inputs), each
    /// through AppKit as a user would. The password is never printed.
    private func nativeInputs() {
        stage = "checking the native inputs"
        guard let controller, let canvas, let window = controller.window else { return }
        let kinds: [Int: (NodeView.Type, AnyClass)] = [36: (TextFieldNodeView.self, NSSecureTextField.self), 37: (NumberNodeView.self, NSTextField.self), 38: (SliderNodeView.self, NSSlider.self), 39: (ListboxNodeView.self, NSTableView.self)]
        for id in kinds.keys.sorted() {
            guard let view = controller.reconciler.views[id], let (viewType, nativeType) = kinds[id], type(of: view) == viewType, view.nativeView.isKind(of: nativeType),
                  let clip = view.superview as? ClipView, clip.superview === canvas, view.placed, !view.isHiddenOrHasHiddenAncestor else {
                return fail("node \(id) is not a placed \(kinds[id].map { String(describing: $0.1) } ?? "?") over the canvas")
            }
            say("node \(id): \(String(describing: type(of: view.nativeView))) at place \(controller.tree[id]?.props["place"]?.arrayValue?.compactMap(\.intValue) ?? [])")
        }
        if let why = overflowing() { return fail(why) }
        guard let secret = view(36, TextFieldNodeView.self)?.field, window.makeFirstResponder(secret), let editor = secret.currentEditor() as? NSTextView else {
            return fail("the password field 36 does not take the focus")
        }
        stage = "typing a password into node 36"
        editor.insertText("s3cret", replacementRange: editor.selectedRange())
        waitForLabel("event 36 change (6 characters)") { [weak self] in
            guard let self else { return }
            self.stage = "pasting past the maxLength of node 36"
            editor.setSelectedRange(NSRange(location: (editor.string as NSString).length, length: 0))
            editor.insertText("0123456789", replacementRange: editor.selectedRange())
            self.waitForLabel("event 36 change (8 characters)") {
                guard (secret.stringValue as NSString).length == 8 else { return self.fail("node 36 has \((secret.stringValue as NSString).length) characters, not 8") }
                self.say("node 36 is a secure field, cut to its maxLength 8")
                self.numberField()
            }
        }
    }

    private func numberField() {
        stage = "stepping node 37 with Up and Down"
        guard let window = controller?.window, let number = view(37, NumberNodeView.self), window.makeFirstResponder(number.field) else {
            return fail("the number field 37 does not take the focus")
        }
        press(keyCode: 126, into: window)
        waitForLabel("event 37 change \"1.5\"") { [weak self] in
            guard let self else { return }
            self.press(keyCode: 125, into: window)
            self.press(keyCode: 125, into: window)
            self.waitForLabel("event 37 change \"0.5\"") {
                self.stage = "clicking the stepper of node 37"
                // Through the window, as the mouse would: the press and the release at the centre of each
                // arrow, the top and the bottom quarter of the stepper's frame, where it is seen
                let stepper = number.stepper
                guard let controller = self.controller, let canvas = self.canvas, let content = window.contentView, let superview = stepper.superview else { return self.fail("node 37 has no stepper in the window") }
                // Placed under the rows the canvas draws, it is not in the window: moved onto row 0 as a
                // patch would, then put back
                let place = controller.tree[37]?.props["place"] ?? .null
                controller.applyForTest([.update(id: 37, props: ["place": .array([.int(0), .int(50), .int(12), .int(1)])])])
                canvas.scrollToVisible(canvas.bounds)
                // Where its arrows are drawn (the view AppKit draws them in, through the scaled bounds)
                // fills its frame, so that the points clicked are on the arrows seen
                let frame = superview.convert(stepper.frame, to: nil)
                guard let arrows = stepper.subviews.first(where: { !$0.isHidden }) else { return self.fail("the stepper of node 37 draws nothing") }
                let drawn = arrows.convert(arrows.bounds, to: nil)
                guard abs(drawn.minX - frame.minX) <= 0.5, abs(drawn.maxX - frame.maxX) <= 0.5, abs(drawn.minY - frame.minY) <= 0.5, abs(drawn.maxY - frame.maxY) <= 0.5 else {
                    return self.fail("the arrows of node 37 are drawn at \(drawn), not over its frame \(frame)")
                }
                let up = NSPoint(x: drawn.midX, y: drawn.maxY - drawn.height / 4)
                let down = NSPoint(x: drawn.midX, y: drawn.minY + drawn.height / 4)
                @MainActor func click(_ at: NSPoint, _ name: String) -> String? {
                    guard let hit = content.hitTest(content.superview?.convert(at, from: nil) ?? at), hit === stepper || hit.isDescendant(of: stepper) else {
                        return "the \(name) arrow of node 37 at \(at) hit-tests to \(content.hitTest(content.superview?.convert(at, from: nil) ?? at).map { "\(type(of: $0))" } ?? "nothing"), not its stepper"
                    }
                    guard let press = NSEvent.mouseEvent(with: .leftMouseDown, location: at, modifierFlags: [], timestamp: ProcessInfo.processInfo.systemUptime, windowNumber: window.windowNumber,
                                                         context: nil, eventNumber: 0, clickCount: 1, pressure: 1),
                          let release = NSEvent.mouseEvent(with: .leftMouseUp, location: at, modifierFlags: [], timestamp: ProcessInfo.processInfo.systemUptime, windowNumber: window.windowNumber,
                                                           context: nil, eventNumber: 0, clickCount: 1, pressure: 0) else { return "no event" }
                    // The stepper tracks the mouse until the release, which waits in the queue
                    NSApp.postEvent(release, atStart: false)
                    window.sendEvent(press)
                    self.say("the \(name) arrow of node 37 at \(at) hit-tests to its stepper (\(type(of: hit))), clicked through the window")
                    return nil
                }
                if let why = click(up, "up") { return self.fail(why) }
                self.waitForLabel("event 37 change \"1\"") {
                if let why = click(down, "down") { return self.fail(why) }
                self.waitForLabel("event 37 change \"0.5\"") {
                if let why = click(up, "up") { return self.fail(why) }
                self.waitForLabel("event 37 change \"1\"") {
                    controller.applyForTest([.update(id: 37, props: ["place": place])])
                    self.stage = "typing an invalid number into node 37"
                    guard let editor = number.field.currentEditor() as? NSTextView else { return self.fail("node 37 has no field editor") }
                    editor.setSelectedRange(NSRange(location: (editor.string as NSString).length, length: 0))
                    editor.insertText("x", replacementRange: editor.selectedRange())
                    self.waitForLabel("event 37 change \"1x\"") {
                        self.say("node 37 stepped 1.5, 1, 0.5 with Up and Down, 1, 0.5, 1 with its stepper's arrows, and sent 1x as typed")
                        self.slider()
                    }
                }
                }
                }
            }
        }
    }

    private func slider() {
        stage = "moving the slider 38 with Right"
        guard let window = controller?.window, let slider = view(38, SliderNodeView.self)?.slider, window.makeFirstResponder(slider) else {
            return fail("the slider 38 does not take the focus")
        }
        press(keyCode: 124, into: window)
        waitForLabel("event 38 change 55") { [weak self] in
            guard let self else { return }
            self.stage = "dragging the slider 38 to 73"
            slider.doubleValue = 73
            slider.sendAction(slider.action, to: slider.target)
            self.waitForLabel("event 38 change 75") {
                self.say("node 38 went to 55 with Right, and to 75, its step, when dragged to 73")
                self.listbox()
            }
        }
    }

    private func listbox() {
        stage = "selecting rows 1 and 2 of the list box 39"
        guard let window = controller?.window, let table = view(39, ListboxNodeView.self)?.table, window.makeFirstResponder(table) else {
            return fail("the list box 39 does not take the focus")
        }
        guard table.allowsMultipleSelection, table.numberOfRows == 4, table.selectedRowIndexes == IndexSet([0]) else {
            return fail("node 39 has \(table.numberOfRows) rows, \(table.selectedRowIndexes.map { $0 }) selected")
        }
        // What Cmd-clicking rows 1 and 2 after clicking row 1 does
        table.selectRowIndexes(IndexSet([1, 2]), byExtendingSelection: false)
        waitForLabel("event 39 select [1,2]") { [weak self] in
            guard let self else { return }
            self.stage = "moving down in the list box 39"
            self.press(keyCode: 125, into: window)
            self.waitForLabel("event 39 select [3]") {
                self.say("node 39 sent [1,2], then [3] for Down")
                if self.capture != nil {
                    self.save(window.contentView, to: "inputs.png")
                }
                self.tab()
            }
        }
    }

    /// A key press of a key with no text (arrows), through the window as from the keyboard.
    private func press(keyCode: UInt16, into window: NSWindow) {
        let arrows: [UInt16: Int] = [123: NSLeftArrowFunctionKey, 124: NSRightArrowFunctionKey, 125: NSDownArrowFunctionKey, 126: NSUpArrowFunctionKey]
        let text = arrows[keyCode].flatMap { UnicodeScalar($0) }.map { String(Character($0)) } ?? ""
        guard let event = NSEvent.keyEvent(with: .keyDown, location: .zero, modifierFlags: [.numericPad, .function], timestamp: ProcessInfo.processInfo.systemUptime, windowNumber: window.windowNumber,
                                           context: nil, characters: text, charactersIgnoringModifiers: text, isARepeat: false, keyCode: keyCode) else {
            return fail("no key event for key code \(keyCode)")
        }
        window.sendEvent(event)
    }

    /// A key press of a letter, through the window as from the keyboard.
    private func press(_ character: Character, into window: NSWindow) {
        let codes: [Character: UInt16] = ["a": 0, "b": 11, "c": 8, "d": 2, "z": 6]
        let text = String(character)
        guard let event = NSEvent.keyEvent(with: .keyDown, location: .zero, modifierFlags: [], timestamp: ProcessInfo.processInfo.systemUptime, windowNumber: window.windowNumber,
                                           context: nil, characters: text, charactersIgnoringModifiers: text, isARepeat: false, keyCode: codes[character] ?? 0) else {
            return fail("no key event for \(text)")
        }
        window.sendEvent(event)
    }

    private func after(_ seconds: Double, _ then: @escaping @MainActor () -> Void) {
        DispatchQueue.main.asyncAfter(deadline: .now() + seconds) { [weak self] in
            MainActor.assumeIsolated {
                guard let self, !self.done else { return }
                then()
            }
        }
    }

    /// Tab in the text field goes to the application, which moves the focus.
    private func tab() {
        stage = "pressing Tab in node 30"
        guard let controller, let field = view(30, TextFieldNodeView.self)?.field, let window = controller.window, window.makeFirstResponder(field),
              let event = NSEvent.keyEvent(with: .keyDown, location: .zero, modifierFlags: [], timestamp: ProcessInfo.processInfo.systemUptime,
                                           windowNumber: window.windowNumber, context: nil, characters: "\t", charactersIgnoringModifiers: "\t", isARepeat: false, keyCode: 48) else {
            return fail("cannot focus node 30")
        }
        let responder = window.firstResponder
        window.sendEvent(event)
        guard window.firstResponder === responder else {
            return fail("Tab moved the native focus to \(String(describing: window.firstResponder))")
        }
        say("Tab was not used by the field: the focus stayed")
        waitForLabel("key tab") { [weak self] in
            guard let self, let button = self.view(34, ButtonNodeView.self)?.button else { return }
            self.stage = "waiting for node 34 to take the focus"
            self.waitFor("node 34 to have the focus", seconds: 5, { window.firstResponder === button }) {
                self.say("the application focused node 34: its button has the focus")
                self.inputMethod()
            }
        }
    }

    /// What the input method server calls on the canvas, then a plain key through it.
    private func inputMethod() {
        stage = "composing in the canvas"
        guard let controller, let canvas, let window = controller.window, window.makeFirstResponder(canvas.surface) else {
            return fail("the canvas does not take the focus")
        }
        let surface = canvas.surface
        let before = keysSent.count
        surface.setMarkedText("´", selectedRange: NSRange(location: 1, length: 0), replacementRange: NSRange(location: NSNotFound, length: 0))
        guard surface.hasMarkedText(), surface.marked == "´", keysSent.count == before else {
            return fail("the marked text is \"\(surface.marked)\", \(keysSent.count - before) keys sent")
        }
        say("\"´\" is marked in the canvas, nothing sent")
        surface.insertText("é", replacementRange: NSRange(location: NSNotFound, length: 0))
        surface.setMarkedText("にほん", selectedRange: NSRange(location: 3, length: 0), replacementRange: NSRange(location: NSNotFound, length: 0))
        if capture != nil {
            canvas.scrollToVisible(canvas.bounds)
            save(window.contentView, to: "canvas-marked.png")
        }
        surface.insertText("日本", replacementRange: NSRange(location: NSNotFound, length: 0))
        guard !surface.hasMarkedText() else { return fail("the marked text stayed after the commit") }
        waitForLabel("typed é日本") { [weak self] in
            guard let self, let event = NSEvent.keyEvent(with: .keyDown, location: .zero, modifierFlags: [], timestamp: ProcessInfo.processInfo.systemUptime,
                                                         windowNumber: window.windowNumber, context: nil, characters: "a", charactersIgnoringModifiers: "a", isARepeat: false, keyCode: 0) else { return }
            self.stage = "typing a plain key in the canvas"
            let count = self.keysSent.count
            window.sendEvent(event)
            self.waitForLabel("typed é日本a") {
                guard self.keysSent.count == count + 1, self.keysSent.last == "a" else {
                    return self.fail("a plain key sent \(self.keysSent[count...])")
                }
                self.say("a plain key went through the input method as one key: a")
                if self.capture != nil {
                    canvas.scrollToVisible(canvas.bounds)
                    self.save(window.contentView, to: "canvas.png")
                }
                self.pointer()
            }
        }
    }

    /// What the mouse does on the canvas, to the view under the mouse as the window hands it over
    /// (not through NSWindow.sendEvent, which takes the first click of a window that is not key to
    /// make it key): a click, a drag, the wheel and a move are sent as `pointer`; a click on a
    /// control placed over it is the control's.
    private func pointer() {
        stage = "clicking in the canvas"
        guard let controller, let canvas, let window = controller.window, let content = window.contentView, let frame = content.superview else { return }
        let surface = canvas.surface
        let cell = controller.cell
        canvas.scrollToVisible(canvas.bounds)
        func point(_ row: Int, _ column: Int) -> NSPoint {
            surface.convert(NSPoint(x: (Double(column) + 0.5) * cell.width, y: (Double(row) + 0.5) * cell.height), to: nil)
        }
        var pressed: NSView?
        /// Hands a mouse event to the view under it, the one pressed for a drag or a release.
        @discardableResult
        func mouse(_ type: NSEvent.EventType, _ at: NSPoint) -> NSView? {
            guard let event = NSEvent.mouseEvent(with: type, location: at, modifierFlags: [], timestamp: ProcessInfo.processInfo.systemUptime, windowNumber: window.windowNumber,
                                                 context: nil, eventNumber: 0, clickCount: 1, pressure: type == .leftMouseUp ? 0 : 1),
                  let target = type == .leftMouseDown || type == .mouseMoved ? content.hitTest(frame.convert(at, from: nil)) : pressed else { return nil }
            switch type {
            case .leftMouseDown:
                pressed = target
                target.mouseDown(with: event)
            case .leftMouseDragged: target.mouseDragged(with: event)
            case .leftMouseUp: target.mouseUp(with: event)
            default: target.mouseMoved(with: event)
            }
            return target
        }
        let before = pointersSent.count
        guard mouse(.leftMouseDown, point(0, 1)) === surface else { return fail("cell (0, 1) of node 21 is not its surface under the mouse") }
        mouse(.leftMouseUp, point(0, 1))
        waitForLabel("pointer release 0 1 left") { [weak self] in
            guard let self else { return }
            let sent = self.pointersSent[before...].map { "\($0.action) \($0.row) \($0.column) \($0.button ?? "-")" }
            guard sent == ["press 0 1 left", "release 0 1 left"] else { return self.fail("a click sent \(sent)") }
            guard window.firstResponder === surface else { return self.fail("the click did not focus the canvas") }
            self.say("a click on cell (0, 1) sent press and release there, and focused the canvas")
            self.stage = "dragging in the canvas"
            let before = self.pointersSent.count
            mouse(.leftMouseDown, point(0, 1))
            mouse(.leftMouseDragged, point(1, 3))
            mouse(.leftMouseDragged, point(1, 3))
            mouse(.leftMouseUp, point(1, 3))
            self.waitForLabel("pointer release 1 3 left") {
                let sent = self.pointersSent[before...].map { "\($0.action) \($0.row) \($0.column) \($0.button ?? "-")" }
                guard sent == ["press 0 1 left", "move 1 3 left", "release 1 3 left"] else { return self.fail("a drag sent \(sent)") }
                self.say("a drag sent one move per cell: \(sent)")
                self.stage = "turning the wheel over the canvas"
                guard let cg = CGEvent(scrollWheelEvent2Source: nil, units: .line, wheelCount: 2, wheel1: -3, wheel2: 0, wheel3: 0), let wheel = NSEvent(cgEvent: cg) else {
                    return self.fail("no wheel event")
                }
                surface.scrollWheel(with: wheel)
                // At the mouse, wherever it is: the cell it falls in
                let at = surface.convert(wheel.locationInWindow, from: nil)
                let under = Pointer.cell(x: Double(at.x), y: Double(at.y), cellWidth: cell.width, cellHeight: cell.height)
                self.waitForLabel("pointer wheel \(under.row) \(under.column) 3 0") {
                    self.say("3 lines of the wheel down: 3 rows")
                    self.stage = "moving the mouse over the canvas"
                    guard mouse(.mouseMoved, point(2, 4)) === surface else { return self.fail("cell (2, 4) of node 21 is not its surface under the mouse") }
                    self.waitForLabel("pointer move 2 4") {
                        self.say("a move with no button held, sent once for its cell")
                        // Text field 30, over the rows the canvas draws (the controls under its last row are not over it)
                        guard let place = controller.tree[30]?.props["place"]?.arrayValue?.compactMap(\.intValue), place.count == 4,
                              let field = self.view(30, TextFieldNodeView.self)?.field else { return self.fail("no text field 30") }
                        let at = point(place[0], place[1] + place[2] / 2)
                        if let why = self.cursor(surface, inside: point(2, 4), overField: at, mouse: { mouse($0, $1) }) { return self.fail(why) }
                        self.overControl(surface, inside: point(2, 3), field: (place[0], place[1] + place[2] / 2), point: point, mouse: { mouse($0, $1) }) {
                        self.controlClick(surface, at: point(1, 2)) {
                        self.contextMenu(surface) {
                        self.swallowedRelease(surface, point: point) {
                        self.stage = "clicking a control over the canvas"
                        let before = self.pointersSent.count
                        // The field tracks the mouse until it is released: the release waits in the queue
                        if let up = NSEvent.mouseEvent(with: .leftMouseUp, location: at, modifierFlags: [], timestamp: ProcessInfo.processInfo.systemUptime, windowNumber: window.windowNumber,
                                                       context: nil, eventNumber: 0, clickCount: 1, pressure: 0) {
                            NSApp.postEvent(up, atStart: false)
                        }
                        guard let target = mouse(.leftMouseDown, at), target.isDescendant(of: field) else {
                            return self.fail("text field 30 is not under the mouse at its place \(place)")
                        }
                        self.waitFor("text field 30 to have the focus", seconds: 5, { field.currentEditor() != nil }) {
                            guard self.pointersSent.count == before else { return self.fail("a click on text field 30 sent \(self.pointersSent[before...])") }
                            self.say("a click on text field 30 focused it and sent no pointer")
                            self.realInput { self.resize() }
                        }
                        }
                        }
                        }
                        }
                    }
                }
            }
        }
    }

    /// `cursor` messages, handed to the host as if the application sent them (the fake application
    /// sends none), with the mouse at `inside`, on the surface of canvas 21. What is checked is the
    /// NSCursor the surface set and the application's current one (NSCursor.current); what the
    /// window server draws on the screen, and the cursor rects and cursorUpdate AppKit drives from
    /// the real mouse, are not seen. Over a control (`overField`, text field 30), AppKit still hands
    /// the moves to the surface, which owns the tracking area; the I-beam the field sets with its
    /// cursor rect then is not seen either.
    private func cursor(_ surface: CanvasSurface, inside: NSPoint, overField: NSPoint, mouse: (NSEvent.EventType, NSPoint) -> NSView?) -> String? {
        stage = "showing the cursor of canvas 21"
        guard let controller, let window = controller.window else { return "no window" }
        func send(_ node: Int, _ shape: String) { controller.receiveForTest(.cursor(node: node, shape: shape)) }
        func event(_ type: NSEvent.EventType, _ at: NSPoint) -> NSEvent? {
            switch type {
            case .mouseEntered, .mouseExited:
                return NSEvent.enterExitEvent(with: type, location: at, modifierFlags: [], timestamp: ProcessInfo.processInfo.systemUptime, windowNumber: window.windowNumber,
                                              context: nil, eventNumber: 0, trackingNumber: 0, userData: nil)
            default:
                return NSEvent.mouseEvent(with: type, location: at, modifierFlags: [], timestamp: ProcessInfo.processInfo.systemUptime, windowNumber: window.windowNumber,
                                          context: nil, eventNumber: 0, clickCount: 0, pressure: 0)
            }
        }
        /// nil when the surface shows `expected` and it is the current cursor; with nil, when the
        /// surface shows none and the arrow is back.
        func check(_ expected: NSCursor?, _ what: String) -> String? {
            if let expected {
                guard surface.shownCursor === expected, NSCursor.current === expected else {
                    return "\(what): the surface shows \(surface.shownCursor.map { "\($0)" } ?? "nothing") and the current cursor is \(NSCursor.current), not \(expected)"
                }
            } else {
                guard surface.shownCursor == nil, NSCursor.current === NSCursor.arrow else {
                    return "\(what): the surface still shows \(surface.shownCursor.map { "\($0)" } ?? "nothing"), the current cursor is \(NSCursor.current), not the arrow"
                }
            }
            say(what)
            return nil
        }

        guard surface.pointerOver else { return "the mouse moved to (2, 4) is not over the surface" }
        send(21, "pointer")
        if let why = check(.pointingHand, "cursor pointer: the pointing hand") { return why }
        send(2, "text")
        send(999, "text")
        guard surface.cursorShape == .pointingHand else { return "a cursor for label 2 or for no node changed canvas 21 to \(surface.cursorShape)" }
        if let why = check(.pointingHand, "cursor for label 2 and node 999: ignored") { return why }
        send(21, "none")
        if let why = check(CanvasSurface.blankCursor, "cursor none: a cursor with nothing drawn") { return why }
        send(21, "col-resize")
        if #available(macOS 15, *) {
            if let why = check(.columnResize, "cursor col-resize: the column resize cursor of macOS 15") { return why }
        }
        send(21, "help")
        if let why = check(.arrow, "cursor help: the arrow, AppKit has none") { return why }
        send(21, "text")
        if let why = check(.iBeam, "cursor text: the I-beam") { return why }

        guard let overField = event(.mouseMoved, overField) else { return "no event" }
        surface.mouseMoved(with: overField)
        guard !surface.pointerOver else { return "the surface takes the mouse over text field 30 as its own" }
        if let why = check(nil, "over text field 30: the surface lets go of its cursor") { return why }
        guard mouse(.mouseMoved, inside) === surface else { return "(2, 4) is not the surface" }
        if let why = check(.iBeam, "back on the surface: the I-beam again") { return why }

        guard let exited = event(.mouseExited, inside) else { return "no event" }
        surface.mouseExited(with: exited)
        if let why = check(nil, "out of canvas 21: the arrow") { return why }
        send(21, "grab")
        if let why = check(nil, "cursor grab while out: kept, not shown") { return why }
        guard let entered = event(.mouseEntered, inside) else { return "no event" }
        surface.mouseEntered(with: entered)
        if let why = check(.openHand, "back into canvas 21 with no new cursor: the open hand it kept") { return why }
        send(21, "default")
        if let why = check(.arrow, "cursor default: the arrow") { return why }

        // Nothing says the mouse left: the window no longer the key one, the surface hidden
        send(21, "pointer")
        if let why = check(.pointingHand, "cursor pointer again") { return why }
        NotificationCenter.default.post(name: NSWindow.didResignKeyNotification, object: window)
        if let why = check(nil, "the window no longer the key one: the surface lets go of its cursor") { return why }
        send(21, "crosshair")
        if let why = check(nil, "cursor crosshair then: kept, not shown") { return why }
        guard mouse(.mouseMoved, inside) === surface else { return "(2, 4) is not the surface" }
        if let why = check(.crosshair, "the mouse moving on the surface again: the crosshair") { return why }
        surface.isHidden = true
        let hidden = check(nil, "the surface hidden: it lets go of its cursor")
        surface.isHidden = false
        if let hidden { return hidden }
        guard mouse(.mouseMoved, inside) === surface else { return "(2, 4) is not the surface once shown again" }
        send(21, "default")
        return check(.arrow, "cursor default: the arrow")
    }

    /// The mouse over text field 30, placed on canvas 21: the tracking area of the surface still
    /// hands it the moves, which are the field's, so moving onto it sends `leave` once and no
    /// move; the wheel there goes to the field and up its superviews, not to the surface; a drag
    /// from the surface onto it goes on (no `leave`) and its release there leaves the canvas;
    /// back on the surface, the moves are sent again.
    private func overControl(_ surface: CanvasSurface, inside: NSPoint, field: (row: Int, column: Int), point: (Int, Int) -> NSPoint,
                             mouse: @escaping (NSEvent.EventType, NSPoint) -> NSView?, then: @escaping @MainActor () -> Void) {
        stage = "moving the mouse onto text field 30"
        guard let controller, let window = controller.window, let content = window.contentView else { return }
        let over = point(field.row, field.column)
        let besides = point(field.row, field.column + 1)
        func moved(_ at: NSPoint) {
            if let event = NSEvent.mouseEvent(with: .mouseMoved, location: at, modifierFlags: [], timestamp: ProcessInfo.processInfo.systemUptime, windowNumber: window.windowNumber,
                                              context: nil, eventNumber: 0, clickCount: 0, pressure: 0) {
                surface.mouseMoved(with: event)
            }
        }
        let before = pointersSent.count
        guard mouse(.mouseMoved, point(2, 5)) === surface else { return fail("(2, 5) is not the surface") }
        moved(over)
        moved(besides)
        guard let cg = CGEvent(scrollWheelEvent2Source: nil, units: .line, wheelCount: 2, wheel1: -3, wheel2: 0, wheel3: 0), let wheel = NSEvent(cgEvent: cg),
              let target = content.hitTest(content.superview?.convert(over, from: nil) ?? over), target !== surface else {
            return fail("no wheel event, or text field 30 is not under the mouse")
        }
        target.scrollWheel(with: wheel)
        _ = mouse(.leftMouseDown, point(2, 4))
        _ = mouse(.leftMouseDragged, over)
        _ = mouse(.leftMouseUp, over)
        guard mouse(.mouseMoved, inside) === surface else { return fail("(2, 3) is not the surface") }
        let cell = "\(field.row) \(field.column)"
        waitForLabel("pointer move 2 3") { [weak self] in
            guard let self else { return }
            let sent = self.pointersSent[before...].map { "\($0.action) \($0.row) \($0.column)\($0.button.map { " \($0)" } ?? "")" }
            let expected = ["move 2 5", "leave 2 5", "press 2 4 left", "move \(cell) left", "release \(cell) left", "leave \(cell)", "move 2 3"]
            guard sent == expected else { return self.fail("over text field 30 the canvas sent \(sent), not \(expected)") }
            self.say("onto text field 30: leave once, no move nor wheel; a drag onto it: no leave until the release; back: \(sent)")
            then()
        }
    }

    /// Control-click on the surface, the gesture of context menus on macOS: sent as the press and
    /// the release of the right button, with no ctrl.
    private func controlClick(_ surface: CanvasSurface, at: NSPoint, then: @escaping @MainActor () -> Void) {
        stage = "control-clicking the canvas"
        guard let window = controller?.window, let content = window.contentView,
              content.hitTest(content.superview?.convert(at, from: nil) ?? at) === surface else { return fail("(1, 2) is not the surface") }
        let before = pointersSent.count
        for type in [NSEvent.EventType.leftMouseDown, .leftMouseUp] {
            guard let event = NSEvent.mouseEvent(with: type, location: at, modifierFlags: .control, timestamp: ProcessInfo.processInfo.systemUptime, windowNumber: window.windowNumber,
                                                 context: nil, eventNumber: 0, clickCount: 1, pressure: type == .leftMouseUp ? 0 : 1) else { return fail("no event") }
            type == .leftMouseDown ? surface.mouseDown(with: event) : surface.mouseUp(with: event)
        }
        waitForLabel("pointer release 1 2 right") { [weak self] in
            guard let self else { return }
            let sent = self.pointersSent[before...].map { "\($0.action) \($0.row) \($0.column) \($0.button ?? "-") \($0.modifiers)" }
            let expected = ["press 1 2 right []", "release 1 2 right []"]
            guard sent == expected else { return self.fail("a Control-click sent \(sent), not \(expected)") }
            self.say("a Control-click on (1, 2) sent \(sent)")
            then()
        }
    }

    /// `contextmenu` and `clipboard`, handed to the host as if the application sent them (the fake
    /// application sends none), after the Control-click at (1, 2): the menu is popped up for real,
    /// its items checked and chosen with performActionForItem while it tracks, then cancelled.
    /// The user's pasteboard is saved first and put back after (also on a failure).
    private func contextMenu(_ surface: CanvasSurface, then: @escaping @MainActor () -> Void) {
        stage = "showing a context menu over canvas 21"
        guard let controller else { return }
        let items: [ContextMenuItem] = [
            ContextMenuItem(id: "1", label: "Open Link"), ContextMenuItem(id: "1:2", label: "Copy Link Address"), .separator,
            ContextMenuItem(id: "3", label: "Paste", role: "paste"), ContextMenuItem(id: "4", label: "Nothing to undo", enabled: false),
        ]
        func show(_ node: Int = 21, _ items: [ContextMenuItem] = items) {
            controller.receiveForTest(.contextMenu(node: node, row: 1, column: 2, items: items))
        }
        let pasteboard = NSPasteboard.general
        savePasteboard()
        // The menus close with the mouse where it was pressed, on (1, 2)
        let cell = controller.cell
        surface.mouseLocationForTest = surface.convert(NSPoint(x: 2.5 * cell.width, y: 1.5 * cell.height), to: nil)
        pasteboard.clearContents()
        pasteboard.setString("pasted from the test", forType: .string)

        /// Shows the menu, does `act` with it open, cancels it, and checks what was sent once it closed.
        func round(_ what: String, _ act: @escaping @MainActor (NSMenu) -> Void, expect: [String], then: @escaping @MainActor () -> Void) {
            let before = menuSent.count
            show()
            whenMenuOpen(surface, what) { [weak self] menu in
                act(menu)
                menu.cancelTracking()
                self?.waitFor("the menu of \(what) to close", seconds: 5, { surface.contextMenu == nil }) {
                    guard let self else { return }
                    let sent = Array(self.menuSent[before...])
                    guard sent == expect else { return self.fail("\(what) sent \(sent), not \(expect)") }
                    self.say("\(what): \(sent.isEmpty ? "nothing sent" : "\(sent)")")
                    then()
                }
            }
        }

        let before = menuSent.count
        show()
        // While it is shown, another menu, and one for label 2: ignored
        show(21, [ContextMenuItem(id: "9", label: "Another")])
        show(2)
        whenMenuOpen(surface, "the first menu") { [weak self] menu in
            guard let self else { return }
            let seen = menu.items.map { $0.isSeparatorItem ? "-" : "\($0.title)\($0.isEnabled ? "" : " (disabled)")" }
            let expected = ["Open Link", "Copy Link Address", "-", "Paste", "Nothing to undo (disabled)"]
            guard seen == expected else {
                menu.cancelTracking()
                return self.fail("the context menu shows \(seen), not \(expected)")
            }
            self.say("the context menu shows \(seen)")
            menu.performActionForItem(at: 1)
            menu.cancelTracking()
            self.waitFor("the first menu to close", seconds: 5, { surface.contextMenu == nil }) {
                let sent = Array(self.menuSent[before...])
                guard sent == ["select 1:2"] else { return self.fail("Copy Link Address sent \(sent), not [\"select 1:2\"]") }
                self.say("Copy Link Address chosen: \(sent)")
                round("Paste with text on the pasteboard", { $0.performActionForItem(at: 3) }, expect: ["paste pasted from the test"]) {
                    pasteboard.clearContents()
                    round("Paste with no text on the pasteboard", { $0.performActionForItem(at: 3) }, expect: []) {
                        pasteboard.clearContents()
                        pasteboard.setString("", forType: .string)
                        round("Paste with an empty string on the pasteboard", { $0.performActionForItem(at: 3) }, expect: []) {
                        round("the menu dismissed", { _ in }, expect: []) {
                            controller.receiveForTest(.clipboard(text: "https://example.com/docs"))
                            guard pasteboard.string(forType: .string) == "https://example.com/docs" else {
                                return self.fail("clipboard put \(pasteboard.string(forType: .string) ?? "nothing") on the pasteboard")
                            }
                            self.say("clipboard: the text is on the pasteboard")
                            // Paste of the Edit menu in the canvas, with an empty string: disabled, and nothing sent
                            pasteboard.clearContents()
                            pasteboard.setString("", forType: .string)
                            let pasted = self.menuSent.count
                            let item = NSMenuItem(title: "Paste", action: #selector(NSText.paste(_:)), keyEquivalent: "v")
                            let enabled = surface.validateMenuItem(item)
                            surface.paste(nil)
                            guard !enabled, self.menuSent.count == pasted else {
                                return self.fail("Paste in the canvas with an empty string is \(enabled ? "enabled" : "disabled") and sent \(Array(self.menuSent[pasted...]))")
                            }
                            self.say("Paste in the canvas with an empty string: disabled, nothing sent")
                            self.restorePasteboard()
                            self.say("the pasteboard of the user is put back")
                            // A menu closed with the pointer off the canvas: leave, at the cell last sent
                            let left = self.pointersSent.count
                            surface.mouseLocationForTest = NSPoint(x: -50, y: -50)
                            show()
                            self.whenMenuOpen(surface, "the menu closed off the canvas") { menu in
                            menu.cancelTracking()
                            self.waitFor("the menu closed off the canvas to close", seconds: 5, { surface.contextMenu == nil }) {
                            surface.mouseLocationForTest = nil
                            let sent = self.pointersSent[left...].map { "\($0.action) \($0.row) \($0.column)" }
                            guard sent == ["leave 1 2"] else { return self.fail("a menu closed off the canvas sent \(sent), not [\"leave 1 2\"]") }
                            self.say("a menu closed with the pointer off the canvas: \(sent)")
                            // Once the pointer left the canvas, a menu is ignored
                            if let exited = NSEvent.enterExitEvent(with: .mouseExited, location: .zero, modifierFlags: [], timestamp: 0, windowNumber: controller.window.windowNumber,
                                                                   context: nil, eventNumber: 0, trackingNumber: 0, userData: nil) {
                                surface.mouseExited(with: exited)
                            }
                            show()
                            guard surface.contextMenu == nil else { return self.fail("a menu after the pointer left the canvas is shown") }
                            self.say("a menu after the pointer left the canvas: ignored")
                            then()
                            }
                            }
                        }
                        }
                    }
                }
            }
        }
    }

    /// A context menu that opens while the right button (or the left one of a Control-click) is
    /// still held: its tracking takes the release, so the host sends it when the menu opens, and
    /// `leave` when it closes with the pointer off the canvas. Where the mouse is when it closes
    /// is given to the surface (mouseLocationForTest), as the test does not move the real one.
    private func swallowedRelease(_ surface: CanvasSurface, point: @escaping (Int, Int) -> NSPoint, then: @escaping @MainActor () -> Void) {
        stage = "a context menu opened with the button held"
        guard let controller, let window = controller.window else { return }
        func event(_ type: NSEvent.EventType, _ at: NSPoint, _ flags: NSEvent.ModifierFlags) -> NSEvent? {
            NSEvent.mouseEvent(with: type, location: at, modifierFlags: flags, timestamp: ProcessInfo.processInfo.systemUptime, windowNumber: window.windowNumber,
                               context: nil, eventNumber: 0, clickCount: 1, pressure: 1)
        }
        func describe(_ from: Int) -> [String] {
            pointersSent[from...].map { "\($0.action) \($0.row) \($0.column) \($0.button ?? "-") \($0.modifiers)" }
        }
        /// Presses, shows the menu, cancels it once open, and hands what was sent to `check`.
        func held(_ what: String, press: () -> Void, beforeOpen: () -> Void = {}, mouseAt: NSPoint, check: @escaping @MainActor (Int) -> String?, then: @escaping @MainActor () -> Void) {
            let before = pointersSent.count
            surface.mouseLocationForTest = mouseAt
            press()
            controller.receiveForTest(.contextMenu(node: 21, row: 1, column: 2, items: [ContextMenuItem(id: "1", label: "Open Link")]))
            beforeOpen()
            whenMenuOpen(surface, what) { [weak self] menu in
                menu.cancelTracking()
                self?.waitFor("the menu of \(what) to close", seconds: 5, { surface.contextMenu == nil }) {
                    guard let self else { return }
                    surface.mouseLocationForTest = nil
                    if let why = check(before) { return self.fail(why) }
                    then()
                }
            }
        }
        held("a Control-click held", press: {
            if let down = event(.leftMouseDown, point(1, 2), .control) { surface.mouseDown(with: down) }
        }, mouseAt: point(1, 2), check: { [weak self] before in
            guard let self else { return nil }
            let sent = describe(before)
            let expected = ["press 1 2 right []", "release 1 2 right []"]
            guard sent == expected, !surface.controlClick else {
                return "a Control-click held while its menu opened sent \(sent), not \(expected)\(surface.controlClick ? ", and the left button is still taken as the right one" : "")"
            }
            // A move with Control held after it: ctrl is sent again, as no button is held
            let next = self.pointersSent.count
            if let move = event(.mouseMoved, point(1, 3), .control) { surface.mouseMoved(with: move) }
            guard describe(next) == ["move 1 3 - [\"ctrl\"]"] else { return "a move with Control after the menu sent \(describe(next))" }
            self.say("a Control-click held while its menu opened: \(sent), then a move with Control sends ctrl")
            return nil
        }) {
            held("a Control-click held, its cell left before the menu opened", press: {
                if let down = event(.leftMouseDown, point(1, 2), .control) { surface.mouseDown(with: down) }
            }, beforeOpen: {
                // The pointer leaves after the menu was asked for, before it opens: no cell to send the release at
                if let exited = NSEvent.enterExitEvent(with: .mouseExited, location: .zero, modifierFlags: [], timestamp: 0, windowNumber: window.windowNumber,
                                                       context: nil, eventNumber: 0, trackingNumber: 0, userData: nil) {
                    surface.mouseExited(with: exited)
                }
            }, mouseAt: point(1, 2), check: { [weak self] before in
                guard !surface.controlClick else { return "the left button is still taken as the right one after a menu that opened with no cell sent" }
                self?.say("a Control-click whose cell was gone when its menu opened: \(describe(before)), the left button is the left one again")
                return nil
            }) {
            held("a right press held, the menu closed off the canvas", press: {
                if let down = event(.rightMouseDown, point(1, 2), []) { surface.rightMouseDown(with: down) }
            }, mouseAt: NSPoint(x: -50, y: -50), check: { [weak self] before in
                let sent = describe(before)
                let expected = ["press 1 2 right []", "release 1 2 right []", "leave 1 2 - []"]
                guard sent == expected else { return "a right press held while its menu opened, closed off the canvas, sent \(sent), not \(expected)" }
                self?.say("a right press held while its menu opened, closed off the canvas: \(sent)")
                return nil
            }, then: then)
            }
        }
    }

    // MARK: Real input

    /// Where the mouse was before real input moved it, put back at the end (also on a failure).
    private var savedMouse: CGPoint?

    /// `TUI_APP_CANVAS_REAL_INPUT=1`: events posted at the HID level (CGEvent, which needs the
    /// Accessibility permission), as a user's mouse and keyboard, through the window server: the
    /// cursor shown over canvas 21 (NSCursor.currentSystem), a real right-click opening a context
    /// menu, a real click on an item, Escape closing one. Before each click, the window under the
    /// point must be the host's: nothing is clicked elsewhere. The mouse is put back after.
    private func realInput(then: @escaping @MainActor () -> Void) {
        guard ProcessInfo.processInfo.environment["TUI_APP_CANVAS_REAL_INPUT"].map({ !$0.isEmpty }) == true else { return then() }
        stage = "real input"
        guard CGPreflightPostEventAccess() else { return fail("posting events needs the Accessibility permission, which this process does not have") }
        guard let controller else { return fail("real input: the host's window controller is gone") }
        guard let canvas else { return fail("real input: node 21 is not a canvas") }
        guard let window = controller.window else { return fail("real input: the host has no window") }
        guard let label = controller.reconciler.views[2] else { return fail("real input: there is no label node 2 to move the mouse onto") }
        savedMouse = CGEvent(source: nil)?.location
        NSApp.activate(ignoringOtherApps: true)
        window.makeKeyAndOrderFront(nil)
        canvas.scrollToVisible(canvas.bounds)
        let surface = canvas.surface
        let cell = controller.cell
        func screen(_ view: NSView, _ point: NSPoint) -> CGPoint {
            let onScreen = window.convertPoint(toScreen: view.convert(point, to: nil))
            return CGPoint(x: onScreen.x, y: (NSScreen.screens.first?.frame.maxY ?? 0) - onScreen.y)
        }
        let inside = screen(surface, NSPoint(x: 2.5 * cell.width, y: 1.5 * cell.height))
        let outside = screen(label, NSPoint(x: label.bounds.midX, y: label.bounds.midY))
        func sent(_ from: Int) -> [String] { pointersSent[from...].map { "\($0.action) \($0.row) \($0.column) \($0.button ?? "-")" } }
        // Moves reach the surface's tracking area only in the key window of the active application
        poll("the host to be active, its window key", seconds: 3, { NSApp.isActive && window.isKeyWindow }) { [weak self] in
            guard let self else { return }
            guard self.ownsScreenPoint(inside), self.ownsScreenPoint(outside) else {
                return self.fail("another window is over the host's at \(inside) or \(outside): no real input there")
            }
            self.stage = "real input: the cursor over canvas 21"
            let moved = self.pointersSent.count
            self.post(.mouseMoved, at: CGPoint(x: inside.x + 1, y: inside.y))
            self.post(.mouseMoved, at: inside)
            self.poll("a real move to (1, 2)", seconds: 3, { sent(moved).last == "move 1 2 -" }) {
                controller.receiveForTest(.cursor(node: 21, shape: "pointer"))
                self.poll("the pointing hand on screen", seconds: 3, { self.systemCursor(is: .pointingHand) }) {
                    self.say("a real move to (1, 2), then cursor pointer: the pointing hand on screen (NSCursor.currentSystem)")
                    let left = self.pointersSent.count
                    self.post(.mouseMoved, at: outside)
                    // Label 2 is selectable: its own I-beam
                    self.poll("the I-beam of label 2 on screen", seconds: 3, { self.systemCursor(is: .iBeam) && sent(left).contains("leave 1 2 -") }) {
                        self.say("a real move onto label 2: leave sent, its own I-beam on screen")
                        self.post(.mouseMoved, at: inside)
                        self.poll("the pointing hand again", seconds: 3, { self.systemCursor(is: .pointingHand) }) {
                            self.say("a real move back to (1, 2): the pointing hand again, with no new cursor message")
                            controller.receiveForTest(.cursor(node: 21, shape: "default"))
                            self.realMenu(surface, inside: inside, outside: outside, sent: sent, then: then)
                        }
                    }
                }
            }
        }
    }

    /// A real right-click at (1, 2), the menu the application would answer with, a real click on
    /// its second item; then again, and Escape.
    private func realMenu(_ surface: CanvasSurface, inside: CGPoint, outside: CGPoint, sent: @escaping (Int) -> [String], then: @escaping @MainActor () -> Void) {
        guard let controller else { return }
        let items = [ContextMenuItem(id: "1", label: "Open Link"), ContextMenuItem(id: "2", label: "Copy Link Address")]
        func open(_ what: String, _ act: @escaping @MainActor (CGRect) -> Void) {
            stage = "real input: \(what)"
            guard ownsScreenPoint(inside) else { return fail("another window is over the host's at \(inside)") }
            let before = pointersSent.count
            post(.rightMouseDown, at: inside, button: .right)
            post(.rightMouseUp, at: inside, button: .right)
            poll("the real right-click at (1, 2)", seconds: 3, { sent(before).contains("release 1 2 right") }) {
                self.say("a real right-click at (1, 2): \(sent(before))")
                controller.receiveForTest(.contextMenu(node: 21, row: 1, column: 2, items: items))
                self.whenMenuOpen(surface, what) { _ in
                    guard let frame = self.menuWindowFrame() else {
                        surface.contextMenu?.menu.cancelTracking()
                        return self.fail("the context menu is open but no window of the host shows it")
                    }
                    act(frame)
                }
            }
        }
        let chosen = menuSent.count
        open("clicking an item of a context menu") { frame in
            // Two items of one height: the second one's middle is three quarters down
            let item = CGPoint(x: frame.midX, y: frame.minY + frame.height * 0.75)
            guard self.ownsScreenPoint(item) else {
                surface.contextMenu?.menu.cancelTracking()
                return self.fail("the menu's window is not under \(item)")
            }
            self.post(.mouseMoved, at: item)
            self.post(.leftMouseDown, at: item)
            self.post(.leftMouseUp, at: item)
            self.poll("the menu to close after the click", seconds: 3, { surface.contextMenu == nil }) {
                guard Array(self.menuSent[chosen...]) == ["select 2"] else { return self.fail("a real click on Copy Link Address sent \(Array(self.menuSent[chosen...]))") }
                self.say("a real click on Copy Link Address, in the menu at \(frame): [\"select 2\"]")
                let keys = self.keysSent.count
                let escaped = self.menuSent.count
                open("Escape in a context menu") { _ in
                    guard NSApp.isActive else {
                        surface.contextMenu?.menu.cancelTracking()
                        return self.fail("the host is no longer the active application: no key posted")
                    }
                    self.postKey(53)
                    self.poll("Escape to close the menu", seconds: 3, { surface.contextMenu == nil }) {
                        guard self.menuSent.count == escaped, self.keysSent.count == keys else {
                            return self.fail("Escape in the menu sent \(Array(self.menuSent[escaped...])) and keys \(Array(self.keysSent[keys...]))")
                        }
                        self.say("a real Escape closed the menu, nothing sent")
                        let left = self.pointersSent.count
                        open("a click outside a context menu, on label 2") { _ in
                            guard self.ownsScreenPoint(outside) else {
                                surface.contextMenu?.menu.cancelTracking()
                                return self.fail("another window is over label 2 at \(outside)")
                            }
                            self.post(.mouseMoved, at: outside)
                            self.post(.leftMouseDown, at: outside)
                            self.poll("the click outside to close the menu", seconds: 3, { surface.contextMenu == nil }) {
                                self.post(.leftMouseUp, at: outside)
                                let after = sent(left).filter { !$0.hasPrefix("press") && !$0.hasPrefix("release") }
                                guard after == ["leave 1 2 -"] else { return self.fail("a click on label 2 closing the menu sent \(sent(left)), no leave") }
                                self.say("a real click on label 2 closed the menu, with the button still down: \(sent(left))")
                                self.restoreMouse()
                                self.say("the mouse is put back")
                                then()
                            }
                        }
                    }
                }
            }
        }
    }

    private func post(_ type: CGEventType, at point: CGPoint, button: CGMouseButton = .left) {
        CGEvent(mouseEventSource: nil, mouseType: type, mouseCursorPosition: point, mouseButton: button)?.post(tap: .cghidEventTap)
    }

    private func postKey(_ code: CGKeyCode) {
        for down in [true, false] {
            CGEvent(keyboardEventSource: nil, virtualKey: code, keyDown: down)?.post(tap: .cghidEventTap)
        }
    }

    /// The frontmost window on screen at a point (global, y down) is one of the host's. Overlays
    /// above the normal level that cover a whole screen (the Dock's) let clicks through: skipped.
    private func ownsScreenPoint(_ point: CGPoint) -> Bool {
        guard let windows = CGWindowListCopyWindowInfo([.optionOnScreenOnly], kCGNullWindowID) as? [[String: Any]] else { return false }
        let screens = NSScreen.screens.map(\.frame.size)
        for info in windows {
            guard let bounds = info[kCGWindowBounds as String], let rect = CGRect(dictionaryRepresentation: bounds as! CFDictionary), rect.contains(point),
                  (info[kCGWindowAlpha as String] as? Double ?? 1) > 0 else { continue }
            if (info[kCGWindowLayer as String] as? Int ?? 0) > 0, (info[kCGWindowOwnerPID as String] as? Int32) != getpid(), screens.contains(rect.size) { continue }
            return (info[kCGWindowOwnerPID as String] as? Int32) == getpid()
        }
        return false
    }

    /// The frame (global, y down) of the host's window above the normal level: the open menu.
    private func menuWindowFrame() -> CGRect? {
        guard let windows = CGWindowListCopyWindowInfo([.optionOnScreenOnly], kCGNullWindowID) as? [[String: Any]] else { return nil }
        for info in windows where (info[kCGWindowOwnerPID as String] as? Int32) == getpid() && (info[kCGWindowLayer as String] as? Int ?? 0) > 0 {
            if let bounds = info[kCGWindowBounds as String], let rect = CGRect(dictionaryRepresentation: bounds as! CFDictionary) { return rect }
        }
        return nil
    }

    private func systemCursor(is cursor: NSCursor) -> Bool {
        guard let current = NSCursor.currentSystem else { return false }
        // Another object for the same shape: told apart by its hot spot and size
        return current.hotSpot == cursor.hotSpot && current.image.size == cursor.image.size
    }

    private func restoreMouse() {
        guard let saved = savedMouse else { return }
        savedMouse = nil
        CGWarpMouseCursorPosition(saved)
    }

    /// Polls in the common modes, which run while a menu tracks.
    private func poll(_ what: String, seconds: Double, _ condition: @escaping @MainActor () -> Bool, then: @escaping @MainActor () -> Void) {
        let until = Date().addingTimeInterval(seconds)
        final class Once: @unchecked Sendable { var over = false }
        let once = Once()
        let timer = Timer(timeInterval: 0.05, repeats: true) { [weak self] timer in
            // A then that runs a nested run loop (a menu's tracking) lets this timer fire again
            guard !once.over else { return timer.invalidate() }
            let over = MainActor.assumeIsolated { () -> Bool in
                guard let self, !self.done else { return true }
                if condition() {
                    once.over = true
                    then()
                    return true
                }
                if Date() > until {
                    once.over = true
                    self.fail("waited \(Int(seconds)) s for \(what)")
                    return true
                }
                return false
            }
            if over { timer.invalidate() }
        }
        RunLoop.main.add(timer, forMode: .common)
    }

    /// Polls in the common modes, which run while a menu tracks.
    private func whenMenuOpen(_ surface: CanvasSurface, _ what: String, then: @escaping @MainActor (NSMenu) -> Void) {
        let until = Date().addingTimeInterval(5)
        final class Once: @unchecked Sendable { var over = false }
        let once = Once()
        let timer = Timer(timeInterval: 0.05, repeats: true) { [weak self] timer in
            guard !once.over else { return timer.invalidate() }
            let over = MainActor.assumeIsolated { () -> Bool in
                if let menu = surface.contextMenu, menu.isOpen {
                    once.over = true
                    then(menu.menu)
                    return true
                } else if Date() > until {
                    self?.fail("waited 5 s for the context menu of \(what) to open")
                    return true
                }
                return false
            }
            if over { timer.invalidate() }
        }
        RunLoop.main.add(timer, forMode: .common)
    }

    private func savePasteboard() {
        savedPasteboard = (NSPasteboard.general.pasteboardItems ?? []).map { item in
            let copy = NSPasteboardItem()
            for type in item.types {
                if let data = item.data(forType: type) { copy.setData(data, forType: type) }
            }
            return copy
        }
    }

    private func restorePasteboard() {
        guard let saved = savedPasteboard else { return }
        savedPasteboard = nil
        NSPasteboard.general.clearContents()
        if !saved.isEmpty { NSPasteboard.general.writeObjects(saved) }
    }

    /// Ten cells wider: the rects of the new layout reach the application.
    private func resize() {
        stage = "resizing the window"
        guard let controller, let canvas, let window = controller.window, let content = window.contentView else { return }
        let cell = controller.cell
        let columns = { canvas.convert(canvas.bounds, to: nil).width / CGFloat(cell.width) }
        let before = Int(ceil(columns() - 0.001))
        window.setContentSize(NSSize(width: content.frame.width + CGFloat(cell.width) * 10, height: content.frame.height))
        let after = Int(ceil(columns() - 0.001))
        guard after == before + 10 else { return fail("node 21 went from \(before) to \(after) columns") }
        waitForLabel("rects \(after)") { [weak self] in
            guard let self else { return }
            self.stage = "closing"
            self.say("sending close")
            controller.requestClose()
        }
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
            fail("the application exited with status \(status) while \(stage)\(saidBye ? " (after bye)" : " (no bye)")" + (stderr.isEmpty ? "" : "\nlast stderr lines:\n" + stderr))
        }
    }

    // MARK: Helpers

    private func labelText() -> String? {
        (controller?.reconciler.views[2] as? LabelNodeView)?.field.stringValue
    }

    private func waitForLabel(_ text: String, then: @escaping @MainActor () -> Void) {
        waitFor("node 2 to read \"\(text)\"", seconds: 5, { [weak self] in self?.labelText() == text }) { [weak self] in
            self?.say("node 2 reads \"\(text)\"")
            then()
        }
    }

    private func waitFor(_ what: String, seconds: Double, _ condition: @escaping @MainActor () -> Bool, then: @escaping @MainActor () -> Void) {
        let until = Date().addingTimeInterval(seconds)
        func poll() {
            guard !done else { return }
            if condition() { return then() }
            guard Date() < until else {
                return fail("waited \(Int(seconds)) s for \(what); node 2 reads \"\(labelText() ?? "(no label)")\"")
            }
            DispatchQueue.main.asyncAfter(deadline: .now() + 0.05) {
                MainActor.assumeIsolated { poll() }
            }
        }
        poll()
    }

    private func save(_ view: NSView?, to name: String) {
        guard let capture, let view else { return }
        if let how = WindowCapture.write(view, to: (capture as NSString).appendingPathComponent(name)) {
            say("drew \(name) (\(Int(view.bounds.width))×\(Int(view.bounds.height)) pt, \(how))")
        }
    }

    private func pass() {
        restorePasteboard()
        restoreMouse()
        done = true
        say("ok")
        exit(0)
    }

    func fail(_ why: String) {
        guard !done else { return }
        restorePasteboard()
        restoreMouse()
        done = true
        FileHandle.standardError.write(Data("canvas-test: FAILED: \(why)\n".utf8))
        controller?.killApplication()
        exit(1)
    }
}
