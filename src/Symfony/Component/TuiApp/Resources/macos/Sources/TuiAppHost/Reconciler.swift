import AppKit
import TuiAppHostCore

/// Keeps one native view per node, in step with the node tree.
@MainActor
final class Reconciler {
    private(set) var views: [Int: NodeView] = [:]
    private weak var host: NodeHost?

    init(host: NodeHost) {
        self.host = host
    }

    static func makeView(id: Int, kind: String, host: NodeHost) -> NodeView {
        switch kind {
        case "stack": return StackNodeView(id: id, kind: kind, host: host)
        case "label": return LabelNodeView(id: id, kind: kind, host: host)
        case "textfield", "password": return TextFieldNodeView(id: id, kind: kind, host: host)
        case "number": return NumberNodeView(id: id, kind: kind, host: host)
        case "slider": return SliderNodeView(id: id, kind: kind, host: host)
        case "listbox": return ListboxNodeView(id: id, kind: kind, host: host)
        case "textarea": return TextAreaNodeView(id: id, kind: kind, host: host)
        case "list": return ListNodeView(id: id, kind: kind, host: host)
        case "form": return FormNodeView(id: id, kind: kind, host: host)
        case "tabs": return TabsNodeView(id: id, kind: kind, host: host)
        case "disclosure": return DisclosureNodeView(id: id, kind: kind, host: host)
        case "progress": return ProgressNodeView(id: id, kind: kind, host: host)
        case "spinner": return SpinnerNodeView(id: id, kind: kind, host: host)
        case "canvas": return CanvasNodeView(id: id, kind: kind, host: host)
        case "button": return ButtonNodeView(id: id, kind: kind, host: host)
        case "checkbox": return CheckboxNodeView(id: id, kind: kind, host: host)
        case "popup": return PopupNodeView(id: id, kind: kind, host: host)
        default: return UnknownNodeView(id: id, kind: kind, host: host)
        }
    }

    /// Builds or updates the views a patch touched. Returns the root view.
    func apply(_ result: PatchResult, tree: NodeTree) -> NodeView? {
        guard let host else { return nil }
        for id in result.removed {
            if let view = views.removeValue(forKey: id) {
                view.removeFromSuperview()
            }
        }
        for id in result.created {
            guard let node = tree[id] else { continue }
            if let old = views[id] {
                old.removeFromSuperview()
            }
            let view = Self.makeView(id: id, kind: node.kind, host: host)
            views[id] = view
        }
        // Props first, so containers know their kind-specific settings before their children.
        for id in result.created + result.updated.sorted() {
            if let node = tree[id], let view = views[id] {
                view.update(props: node.props)
            }
        }
        var restructured = result.restructured
        // A new container gets its children even when none was added by this patch.
        restructured.formUnion(result.created.filter { !(tree[$0]?.children.isEmpty ?? true) })
        for id in restructured {
            guard let node = tree[id], let view = views[id] else { continue }
            view.setChildren(node.children.compactMap { views[$0] })
        }
        return tree.root.flatMap { views[$0] }
    }

    func view(for responder: NSResponder?) -> NodeView? {
        var view = responder as? NSView
        if let editor = responder as? NSTextView, editor.isFieldEditor, let field = editor.delegate as? NSView {
            view = field
        }
        while let current = view {
            if let node = current as? NodeView { return node }
            view = current.superview
        }
        return nil
    }
}
