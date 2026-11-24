import UIKit

final class HostController: UIViewController {
  static let shared = HostController()
  var tree = NodeTree()
  var views = [Int: UIView]()
  var session: MobileSession?
  var font = UIFont.monospacedSystemFont(ofSize: 14, weight: .regular)
  lazy var cell = CellSize(
    width: ("M" as NSString).size(withAttributes: [.font: font]).width,
    height: max(34, ceil(font.lineHeight)))
  let content = UIView()
  let scroll = UIScrollView()
  var rootConstraints = [NSLayoutConstraint]()
  var applying = false
  var testedControls = false
  var testedInput = false
  var formRows = [String: ([String: JSON], UIStackView)]()
  var lastRects = [Int: [Int]]()
  var lastGrid = (columns: 0, rows: 0)

  override func viewDidLoad() {
    super.viewDidLoad()
    if let resources = Bundle.main.resourceURL,
      let data = try? Data(contentsOf: resources.appendingPathComponent("app.manifest.json")),
      let manifest = try? JSON.parse(data), let window = manifest["window"]?.objectValue
    {
      let settings = window["canvasFont"]?.objectValue ?? [:]
      let size = settings["size"]?.doubleValue ?? 13
      font =
        settings["family"]?.stringValue.flatMap { UIFont(name: $0, size: size) }
        ?? UIFont.monospacedSystemFont(ofSize: size, weight: .regular)
      if window["theme"]?.stringValue == "dark" { overrideUserInterfaceStyle = .dark }
      if window["theme"]?.stringValue == "light" { overrideUserInterfaceStyle = .light }
    }
    registerForTraitChanges([UITraitUserInterfaceStyle.self]) {
      (controller: HostController, previous: UITraitCollection) in
      controller.session?.send(.appearance(controller.appearance))
      for canvas in controller.views.values.compactMap({ $0 as? MobileCanvas }) {
        canvas.setNeedsDisplay()
      }
    }
    view.backgroundColor = .systemBackground
    scroll.keyboardDismissMode = .interactive
    scroll.translatesAutoresizingMaskIntoConstraints = false
    content.translatesAutoresizingMaskIntoConstraints = false
    view.addSubview(scroll)
    scroll.addSubview(content)
    NSLayoutConstraint.activate([
      scroll.leadingAnchor.constraint(equalTo: view.safeAreaLayoutGuide.leadingAnchor),
      scroll.trailingAnchor.constraint(equalTo: view.safeAreaLayoutGuide.trailingAnchor),
      scroll.topAnchor.constraint(equalTo: view.safeAreaLayoutGuide.topAnchor),
      scroll.bottomAnchor.constraint(equalTo: view.keyboardLayoutGuide.topAnchor),
      content.leadingAnchor.constraint(equalTo: scroll.contentLayoutGuide.leadingAnchor),
      content.trailingAnchor.constraint(equalTo: scroll.contentLayoutGuide.trailingAnchor),
      content.topAnchor.constraint(equalTo: scroll.contentLayoutGuide.topAnchor),
      content.bottomAnchor.constraint(equalTo: scroll.contentLayoutGuide.bottomAnchor),
      content.widthAnchor.constraint(equalTo: scroll.frameLayoutGuide.widthAnchor),
      content.heightAnchor.constraint(greaterThanOrEqualTo: scroll.frameLayoutGuide.heightAnchor),
    ])
    if ProcessInfo.processInfo.arguments.contains("--contract-test") {
      do {
        try contractTests()
        receipt("tests.txt", "HOST_TESTS=0\n")
        show("HOST_TESTS=0")
      } catch {
        receipt("tests.txt", String(describing: error))
        show(String(describing: error))
      }
    }
  }
  override func viewDidAppear(_ animated: Bool) {
    super.viewDidAppear(animated)
    if ProcessInfo.processInfo.arguments.contains("--controls-test") {
      if !testedControls {
        testedControls = true
        do {
          try controlsTests()
          receipt("controls-tests.txt", "CONTROLS_TESTS=0\n")
        } catch { receipt("controls-tests.txt", String(describing: error)) }
      }
      return
    }
    guard session == nil, !ProcessInfo.processInfo.arguments.contains("--contract-test") else {
      return
    }
    let grid = cell.grid(width: scroll.bounds.width, height: scroll.bounds.height)
    let hello = Hello(
      columns: grid.columns, rows: grid.rows, cellWidth: cell.width, cellHeight: cell.height,
      scale: Double(view.window?.screen.scale ?? traitCollection.displayScale),
      appearance: appearance,
      capabilities: [
        "appearance", "canvas-controls", "pointer", "touch", "clipboard", "password", "number",
        "slider",
        "listbox",
      ], os: "ios")
    let session = MobileSession(hello: hello)
    self.session = session
    session.receive = { [weak self] message in self?.receive(message) }
    session.failure = { [weak self] message in
      self?.receipt("host-error.txt", message)
      self?.show(message)
    }
    session.ended = { [weak self] in self?.receipt("host-ended.txt", "PHP session ended\n") }
    session.didExit = { status in if status == 0 { exit(0) } }
    do { try session.start() } catch {
      show(String(describing: error))
      receipt("host-error.txt", String(describing: error))
    }
  }
  var appearance: String { traitCollection.userInterfaceStyle == .dark ? "dark" : "light" }
  override func viewDidLayoutSubviews() {
    super.viewDidLayoutSubviews()
    let grid = cell.grid(width: scroll.bounds.width, height: scroll.bounds.height)
    if grid != lastGrid {
      lastGrid = grid
      session?.send(.resize(columns: grid.columns, rows: grid.rows))
    }
    reportRects()
  }
  func receipt(_ name: String, _ text: String) {
    guard
      let directory = FileManager.default.urls(for: .documentDirectory, in: .userDomainMask).first
    else { return }
    try? text.write(to: directory.appendingPathComponent(name), atomically: true, encoding: .utf8)
  }
  func show(_ text: String) {
    let label = UILabel()
    label.numberOfLines = 0
    label.text = text
    label.translatesAutoresizingMaskIntoConstraints = false
    for child in content.subviews { child.removeFromSuperview() }
    content.addSubview(label)
    NSLayoutConstraint.activate([
      label.leadingAnchor.constraint(equalTo: content.leadingAnchor, constant: 12),
      label.trailingAnchor.constraint(equalTo: content.trailingAnchor, constant: -12),
      label.topAnchor.constraint(equalTo: content.topAnchor, constant: 12),
    ])
  }
  func receive(_ message: Message) {
    switch message {
    case .patch(let ops):
      let changed = tree.apply(ops)
      for id in changed.created {
        views[id]?.removeFromSuperview()
        views.removeValue(forKey: id)
        for key in Array(formRows.keys) where key.hasPrefix("\(id):") {
          formRows.removeValue(forKey: key)
        }
      }
      render(changed: Set(changed.created).union(changed.updated).union(changed.restructured))
    case .focus(let node):
      if let node { _ = views[node]?.becomeFirstResponder() } else { view.endEditing(false) }
    case .clipboard(let text): UIPasteboard.general.string = text
    case .window(let title, _): if case .value(let value) = title { self.title = value }
    case .closeReply(let accept):
      if accept {
        session?.queue.async { self.session?.close() }
        view.endEditing(true)
        show("Application closed")
      }
    case .bye:
      session?.queue.async { self.session?.close() }
      receipt("host-ended.txt", "PHP session ended\n")
    default: break
    }
  }
  func enabled(_ id: Int) -> Bool {
    var next: Int? = id
    while let id = next {
      guard let node = tree[id], node.props["disabled"]?.boolValue != true,
        node.props["hidden"]?.boolValue != true
      else { return false }
      next = node.parent
    }
    return true
  }
  func event(_ id: Int, _ name: String, _ value: JSON? = nil) {
    guard !applying, enabled(id) else { return }
    let property = [
      "change": "value", "toggle": tree[id]?.kind == "checkbox" ? "checked" : "expanded",
      "tab": "active",
      "select": tree[id]?.kind == "list" && tree[id]?.props["multiple"]?.boolValue == true
        ? "checked" : "selected",
    ][name]
    if let property, let value { tree.apply([.update(id: id, props: [property: value])]) }
    session?.send(.event(node: id, name: name, value: value))
  }
  func stack() -> UIStackView {
    let stack = UIStackView()
    stack.axis = .vertical
    stack.alignment = .fill
    return stack
  }
  func button(_ title: String, _ action: @escaping () -> Void) -> ActionButton {
    let button = ActionButton()
    button.setTitle(title, for: .normal)
    button.setTitleColor(.systemBlue, for: .normal)
    button.action = action
    return button
  }
  func label(_ text: String) -> UILabel {
    let label = UILabel()
    label.text = text
    label.numberOfLines = 0
    label.font = .systemFont(ofSize: 14)
    return label
  }
  func make(_ node: Node) -> UIView {
    let id = node.id
    switch node.kind {
    case "label": return label("")
    case "textfield", "password":
      let field = MobileField()
      field.isSecureTextEntry = node.kind == "password"
      field.keyboardType = node.kind == "number" ? .numbersAndPunctuation : .default
      field.action = { [weak self] name, value in self?.event(id, name, value.map(JSON.string)) }
      return field
    case "number":
      let number = MobileNumber()
      number.action = { [weak self] name, value in self?.event(id, name, value.map(JSON.string)) }
      return number
    case "textarea":
      let editor = MobileEditor()
      editor.action = { [weak self] name, value in self?.event(id, name, value.map(JSON.string)) }
      return editor
    case "button": return button("") { [weak self] in self?.event(id, "press") }
    case "checkbox":
      let toggle = ActionSwitch()
      toggle.action = { [weak self] value in self?.event(id, "toggle", .bool(value)) }
      return toggle
    case "slider":
      let slider = ActionSlider()
      slider.action = { [weak self, weak slider] value in
        guard let self, let props = self.tree[id]?.props else { return }
        let min = props["min"]?.doubleValue ?? 0
        let max = props["max"]?.doubleValue ?? 100
        let step = props["step"]?.doubleValue ?? 1
        let value = Stepping.snap(value, min: min, max: max, step: step > 0 ? step : 1)
        slider?.value = Float(value)
        self.event(id, "change", .double(value))
      }
      return slider
    case "popup":
      let popup = button("") {}
      popup.showsMenuAsPrimaryAction = true
      return popup
    case "canvas":
      let canvas = MobileCanvas(cell: cell, font: font)
      canvas.pointer = { [weak self] pointer in
        guard let self, self.enabled(id) else { return }
        self.session?.send(.pointer(node: id, pointer))
      }
      canvas.onFocused = { [weak self] in self?.event(id, "focus") }
      canvas.pasteText = { [weak self] text in self?.session?.send(.paste(text: text)) }
      canvas.key = { [weak self] key, text in self?.session?.send(.key(key: key, text: text)) }
      return canvas
    default: return stack()
    }
  }
  func render(changed: Set<Int>) {
    applying = true
    for id in Array(views.keys) where tree[id] == nil {
      views[id]?.removeFromSuperview()
      views.removeValue(forKey: id)
      for key in Array(formRows.keys) where key.hasPrefix("\(id):") {
        formRows.removeValue(forKey: key)
      }
    }
    for id in tree.orderedIds {
      guard let node = tree[id] else { continue }
      let fresh = views[id] == nil
      let control = views[id] ?? make(node)
      control.tag = id
      views[id] = control
      if fresh || changed.contains(id) { update(node, control) }
      control.isHidden = node.props["hidden"]?.boolValue == true
      control.isUserInteractionEnabled = enabled(id)
      (control as? UIControl)?.isEnabled = enabled(id)
      if let text = node.props["label"]?.stringValue { control.accessibilityLabel = text }
      let style = node.props["style"]?.objectValue ?? [:]
      control.alpha = style["dim"]?.boolValue == true ? 0.6 : 1
      if let background = style["bg"] { control.backgroundColor = mobileColor(background, .clear) }

      control.setContentHuggingPriority(
        (node.props["grow"]?.doubleValue ?? 0) > 0 ? .defaultLow : .defaultHigh, for: .vertical)
    }
    for id in tree.orderedIds { if let node = tree[id] { structure(node) } }
    if let id = tree.root, let root = views[id], root.superview !== content {
      NSLayoutConstraint.deactivate(rootConstraints)
      root.removeFromSuperview()
      content.addSubview(root)
      root.translatesAutoresizingMaskIntoConstraints = false
      rootConstraints = [
        root.leadingAnchor.constraint(equalTo: content.leadingAnchor),
        root.trailingAnchor.constraint(equalTo: content.trailingAnchor),
        root.topAnchor.constraint(equalTo: content.topAnchor),
        root.bottomAnchor.constraint(equalTo: content.bottomAnchor),
      ]
      NSLayoutConstraint.activate(rootConstraints)
    }
    applying = false
    view.setNeedsLayout()
    if ProcessInfo.processInfo.arguments.contains("--html-test") {
      if !testedInput {
        let name = tree.orderedIds.compactMap { tree[$0] }.first {
          $0.kind == "textfield" && $0.props["label"]?.stringValue == "Name"
        }
        let submit = tree.orderedIds.compactMap { tree[$0] }.first {
          $0.kind == "button" && $0.props["text"]?.stringValue == "Greet"
        }
        if let name, let submit, let input = views[name.id] as? MobileField,
          let button = views[submit.id] as? ActionButton
        {
          testedInput = true
          input.text = "Ada"
          input.changed()
          button.sendActions(for: .touchUpInside)
        }
      }
      if tree.orderedIds.compactMap({ tree[$0] }).contains(where: {
        $0.kind == "canvas" && JSON.object($0.props).text.contains("Hello Ada!")
      }) {
        receipt("html-test.txt", "HTML_TEST=0\n")
      }
    }
    if ProcessInfo.processInfo.arguments.contains("--ui-test") {
      if !testedInput, let field = views[3] as? MobileField {
        testedInput = true
        field.text = "Ada"
        field.changed()
        _ = field.textFieldShouldReturn(field)
      }
      if (views[2] as? UILabel)?.text == "Hello Ada!" { receipt("ui-test.txt", "UI_TEST=0\n") }
    }
  }
  func update(_ node: Node, _ control: UIView) {
    let p = node.props
    let id = node.id
    if let field = control as? MobileField {
      field.update(p)
    } else if let number = control as? MobileNumber {
      number.update(p)
    } else if let editor = control as? MobileEditor {
      editor.update(p)
    } else if let text = control as? UILabel {
      if let runs = p["runs"]?.arrayValue {
        text.attributedText = mobileRuns(
          runs, font: .systemFont(ofSize: 14), base: p["style"]?.objectValue ?? [:])
      } else {
        text.attributedText = nil
        text.text = p["text"]?.stringValue ?? ""
        text.textColor = mobileColor(p["style"]?.objectValue?["fg"])
        let style = p["style"]?.objectValue ?? [:]
        var traits: UIFontDescriptor.SymbolicTraits = []
        if style["bold"]?.boolValue == true { traits.insert(.traitBold) }
        if style["italic"]?.boolValue == true { traits.insert(.traitItalic) }
        text.font = UIFont(
          descriptor: UIFont.systemFont(ofSize: 14).fontDescriptor.withSymbolicTraits(traits)
            ?? UIFont.systemFont(ofSize: 14).fontDescriptor, size: 14)
      }
      text.textAlignment =
        p["align"]?.stringValue == "center"
        ? .center : p["align"]?.stringValue == "right" ? .right : .left
    } else if let toggle = control as? ActionSwitch {
      toggle.isOn = p["checked"]?.boolValue ?? false
      toggle.accessibilityLabel = p["text"]?.stringValue
    } else if let slider = control as? ActionSlider {
      slider.minimumValue = Float(p["min"]?.doubleValue ?? 0)
      slider.maximumValue = Float(p["max"]?.doubleValue ?? 100)
      slider.value = Float(p["value"]?.doubleValue ?? 0)
    } else if let canvas = control as? MobileCanvas {
      canvas.props = p
      canvas.invalidateIntrinsicContentSize()
      canvas.setNeedsDisplay()
      canvas.setNeedsLayout()
      canvas.setNeedsDisplay()
    } else if let button = control as? ActionButton {
      if node.kind == "popup" {
        let choices = p["choices"]?.arrayValue ?? []
        let selected = p["selected"]?.intValue ?? 0
        button.setTitle(
          choices.indices.contains(selected) ? choices[selected].stringValue : "", for: .normal)
        button.menu = UIMenu(
          children: choices.enumerated().map { index, value in
            UIAction(title: value.stringValue ?? "", state: selected == index ? .on : .off) {
              [weak self] _ in
              self?.event(id, "select", .int(Int64(index)))
              if let node = self?.tree[id] { self?.update(node, button) }
            }
          })
      } else {
        button.setTitle(p["text"]?.stringValue ?? "", for: .normal)
      }
    } else if let container = control as? UIStackView {
      if node.kind == "stack" {
        container.axis = p["direction"]?.stringValue == "horizontal" ? .horizontal : .vertical
        container.spacing =
          (p["gap"]?.doubleValue ?? 0) * (container.axis == .horizontal ? cell.width : cell.height)
        let padding = (p["padding"]?.doubleValue ?? 0) * cell.width
        container.isLayoutMarginsRelativeArrangement = true
        container.layoutMargins = UIEdgeInsets(
          top: padding, left: padding, bottom: padding, right: padding)
        return
      }
      if node.kind != "form" {
        for child in container.arrangedSubviews where tree[child.tag]?.parent != id {
          container.removeArrangedSubview(child)
          child.removeFromSuperview()
        }
      }
      switch node.kind {
      case "tabs":
        let tabs = UISegmentedControl(
          items: (p["tabs"]?.arrayValue ?? []).map { $0.stringValue ?? "" })
        tabs.selectedSegmentIndex = p["active"]?.intValue ?? 0
        tabs.addAction(
          UIAction { [weak self, weak tabs] _ in
            guard let self, let tabs else { return }
            self.event(id, "tab", .int(Int64(tabs.selectedSegmentIndex)))
            if let node = self.tree[id] { self.structure(node) }
          }, for: .valueChanged)
        container.insertArrangedSubview(tabs, at: 0)
      case "disclosure":
        container.insertArrangedSubview(
          button(p["summary"]?.stringValue ?? "Details") { [weak self] in
            guard let self else { return }
            self.event(id, "toggle", .bool(self.tree[id]?.props["expanded"]?.boolValue != true))
            if let node = self.tree[id] { self.structure(node) }
          }, at: 0)
      case "list", "listbox":
        let multiple = p["multiple"]?.boolValue == true
        let items = p[node.kind == "list" ? "items" : "choices"]?.arrayValue ?? []
        for (index, item) in items.enumerated() {
          let title = item.objectValue?["label"]?.stringValue ?? item.stringValue ?? ""
          let key = node.kind == "list" && multiple ? "checked" : "selected"
          let selected = p[key]?.arrayValue?.compactMap(\.intValue) ?? [p[key]?.intValue ?? -1]
          let item = button((selected.contains(index) ? "✓ " : "") + title) {
            [weak self, weak container] in
            guard let self, let container else { return }
            if multiple || node.kind == "listbox" {
              var values =
                multiple ? self.tree[id]?.props[key]?.arrayValue?.compactMap(\.intValue) ?? [] : []
              if values.contains(index) {
                values.removeAll { $0 == index }
              } else {
                values.append(index)
              }
              self.event(id, "select", .array(values.map { .int(Int64($0)) }))
            } else {
              self.event(id, "select", .int(Int64(index)))
            }
            if let node = self.tree[id] { self.update(node, container) }
          }
          item.contentHorizontalAlignment = .leading
          item.titleLabel?.numberOfLines = 0
          if let description = itemDescription(
            item: p[node.kind == "list" ? "items" : "choices"]?.arrayValue?[index])
          {
            item.setTitle(
              (selected.contains(index) ? "✓ " : "") + title + "\n" + description, for: .normal)
          }
          container.addArrangedSubview(item)
        }
      case "form":
        var desired = [UIStackView]()
        var keys = Set<String>()
        for value in p["rows"]?.arrayValue ?? [] {
          guard let row = value.objectValue, let rowId = row["id"]?.stringValue else { continue }
          let key = "\(id):\(rowId)"
          keys.insert(key)
          let previous = formRows[key]
          let choices = row["choices"]?.arrayValue
          let sameKind = (previous?.0["choices"]?.arrayValue != nil) == (choices != nil)
          let line = (sameKind ? previous?.1 : nil) ?? stack()
          if line.arrangedSubviews.isEmpty {
            line.axis = .horizontal
            line.spacing = 8
            line.addArrangedSubview(label(""))
            if choices != nil {
              let popup = button("") {}
              popup.showsMenuAsPrimaryAction = true
              line.addArrangedSubview(popup)
            } else {
              let field = MobileField()
              field.action = { [weak self] name, value in
                if name == "submit", let value { self?.setting(id, rowId, value) }
              }
              field.onCommit = { [weak self] value in self?.setting(id, rowId, value) }
              line.addArrangedSubview(field)
            }
          }
          (line.arrangedSubviews[0] as? UILabel)?.text = row["label"]?.stringValue ?? ""
          if let choices, let popup = line.arrangedSubviews[1] as? ActionButton {
            popup.setTitle(row["value"]?.stringValue ?? "", for: .normal)
            popup.isEnabled = row["readOnly"]?.boolValue != true
            popup.menu = UIMenu(
              children: choices.compactMap(\.stringValue).map { value in
                UIAction(title: value) { [weak self, weak popup] _ in
                  popup?.setTitle(value, for: .normal)
                  self?.setting(id, rowId, value)
                }
              })
          } else if let field = line.arrangedSubviews[1] as? MobileField {
            if previous?.0["value"] != row["value"] {
              field.update(["value": row["value"] ?? .string("")])
            }
            field.isEnabled = row["readOnly"]?.boolValue != true
          }
          formRows[key] = (row, line)
          desired.append(line)
        }
        for child in container.arrangedSubviews where !desired.contains(where: { $0 === child }) {
          container.removeArrangedSubview(child)
          child.removeFromSuperview()
        }
        for (index, line) in desired.enumerated() {
          if line.superview !== container
            || container.arrangedSubviews.firstIndex(of: line) != index
          {
            if let old = line.superview as? UIStackView { old.removeArrangedSubview(line) }
            line.removeFromSuperview()
            container.insertArrangedSubview(line, at: min(index, container.arrangedSubviews.count))
          }
        }
        for key in Array(formRows.keys) where key.hasPrefix("\(id):") && !keys.contains(key) {
          formRows.removeValue(forKey: key)
        }
      case "progress":
        if let value = p["progress"]?.doubleValue {
          let progress = UIProgressView(progressViewStyle: .default)
          progress.progress = Float(value)
          container.addArrangedSubview(progress)
        } else {
          let indicator = UIActivityIndicatorView(style: .medium)
          indicator.startAnimating()
          container.addArrangedSubview(indicator)
        }
        container.addArrangedSubview(label(p["text"]?.stringValue ?? ""))
      case "spinner":
        if p["running"]?.boolValue != false {
          let indicator = UIActivityIndicatorView(style: .medium)
          indicator.startAnimating()
          container.addArrangedSubview(indicator)
        }
        container.addArrangedSubview(label(p["text"]?.stringValue ?? ""))
        if p["cancellable"]?.boolValue == true {
          container.addArrangedSubview(
            button("Cancel") { [weak self] in self?.event(id, "cancel") })
        }
      default: break
      }
    }
  }
  func itemDescription(item: JSON?) -> String? { item?.objectValue?["description"]?.stringValue }
  func setting(_ id: Int, _ rowId: String, _ value: String) {
    guard enabled(id), let rows = tree[id]?.props["rows"]?.arrayValue,
      let row = rows.first(where: { $0["id"]?.stringValue == rowId }),
      row["readOnly"]?.boolValue != true
    else { return }
    var changed = false
    let updated = rows.map { item -> JSON in
      guard var row = item.objectValue, row["id"]?.stringValue == rowId,
        row["value"]?.stringValue != value
      else { return item }
      row["value"] = .string(value)
      changed = true
      return .object(row)
    }
    guard changed else { return }
    tree.apply([.update(id: id, props: ["rows": .array(updated)])])
    if var cached = formRows["\(id):\(rowId)"] {
      cached.0["value"] = .string(value)
      formRows["\(id):\(rowId)"] = cached
    }
    event(id, "setting", .object(["id": .string(rowId), "value": .string(value)]))
  }
  func structure(_ node: Node) {
    guard let parent = views[node.id] else { return }
    if let canvas = parent as? MobileCanvas {
      canvas.placements.removeAll()
      for id in node.children {
        guard let node = tree[id], let child = views[id],
          let place = node.props["place"]?.arrayValue?.compactMap(\.intValue), place.count == 4
        else { continue }
        if child.superview !== canvas {
          child.removeFromSuperview()
          child.translatesAutoresizingMaskIntoConstraints = true
          canvas.addSubview(child)
        }
        canvas.placements[id] = (place, node.props["clip"]?.arrayValue?.compactMap(\.intValue))
      }
      canvas.setNeedsLayout()
      canvas.setNeedsDisplay()
    } else if let stack = parent as? UIStackView,
      ["stack", "tabs", "disclosure"].contains(node.kind)
    {
      let offset = node.kind == "stack" ? 0 : 1
      for (index, id) in node.children.enumerated() {
        guard let child = views[id] else { continue }
        let position = offset + index
        if child.superview !== stack || stack.arrangedSubviews.firstIndex(of: child) != position {
          if let old = child.superview as? UIStackView { old.removeArrangedSubview(child) }
          child.removeFromSuperview()
          stack.insertArrangedSubview(child, at: min(position, stack.arrangedSubviews.count))
        }
        child.isHidden =
          tree[id]?.props["hidden"]?.boolValue == true
          || (node.kind == "tabs" && index != (node.props["active"]?.intValue ?? 0))
          || (node.kind == "disclosure" && node.props["expanded"]?.boolValue != true)
      }
      if node.kind == "stack" {
        let grows = node.children.contains { (tree[$0]?.props["grow"]?.doubleValue ?? 0) > 0 }
        if grows {
          for spacer in stack.arrangedSubviews where spacer.tag == -1 {
            stack.removeArrangedSubview(spacer)
            spacer.removeFromSuperview()
          }
        } else if !stack.arrangedSubviews.contains(where: { $0.tag == -1 }) {
          let spacer = UIView()
          spacer.tag = -1
          spacer.setContentHuggingPriority(UILayoutPriority(1), for: .vertical)
          spacer.setContentHuggingPriority(UILayoutPriority(1), for: .horizontal)
          stack.addArrangedSubview(spacer)
        }
      }
    }
  }
  func reportRects() {
    guard session != nil else { return }
    var rects = [Int: [Int]]()
    for (id, control) in views where !control.isHidden {
      let frame = control.convert(control.bounds, to: content)
      rects[id] = cell.cells(x: frame.minX, y: frame.minY, width: frame.width, height: frame.height)
    }
    if rects != lastRects {
      lastRects = rects
      session?.send(.rects(rects))
    }
  }
  func controlsTests() throws {
    func check(_ value: Bool, _ text: String) throws {
      if !value { throw MobileSession.SessionError.invalid(text) }
    }
    let ops = try Message.decode(
      JSON.parse(
        "{\"type\":\"patch\",\"ops\":[{\"op\":\"create\",\"id\":1,\"kind\":\"stack\",\"parent\":null,\"index\":0,\"props\":{}},{\"op\":\"create\",\"id\":2,\"kind\":\"textfield\",\"parent\":1,\"index\":0,\"props\":{\"value\":\"\",\"maxLength\":1}},{\"op\":\"create\",\"id\":3,\"kind\":\"password\",\"parent\":1,\"index\":1,\"props\":{\"value\":\"\"}},{\"op\":\"create\",\"id\":4,\"kind\":\"number\",\"parent\":1,\"index\":2,\"props\":{\"value\":\"1\",\"min\":0,\"max\":10,\"step\":0.5}},{\"op\":\"create\",\"id\":5,\"kind\":\"checkbox\",\"parent\":1,\"index\":3,\"props\":{\"disabled\":true,\"checked\":true}}]}"
      ))
    receive(ops)
    view.layoutIfNeeded()
    guard let field = views[2] as? MobileField, let password = views[3] as? MobileField,
      let number = views[4] as? MobileNumber
    else { throw MobileSession.SessionError.invalid("Missing native controls") }
    try check(password.isSecureTextEntry, "Password is not secure")
    try check((views[5] as? UIControl)?.isEnabled == false, "Disabled control is interactive")
    number.stepper.value = 1
    number.stepper.sendActions(for: .valueChanged)
    try check(tree[4]?.props["value"]?.stringValue == "1.5", "Number step did not reach model")
    receive(
      .patch([
        .create(id: 3, kind: "label", parent: 1, index: 1, props: ["text": .string("Replacement")])
      ]))
    try check(views[3] is UILabel, "Recreated node retained its old native control")
    receive(
      .patch([
        .create(
          id: 6, kind: "form", parent: 1, index: 4,
          props: [
            "rows": .array([
              .object([
                "id": .string("locked"), "label": .string("Locked"), "value": .string("Original"),
                "readOnly": .bool(true),
              ])
            ])
          ])
      ]))
    let locked = formRows["6:locked"]!.1.arrangedSubviews[1] as! MobileField
    locked.text = "Changed"
    locked.finished()
    try check(
      tree[6]?.props["rows"]?.arrayValue?.first?["value"]?.stringValue == "Original",
      "Read-only setting accepted a pending edit")
    let canvas = MobileCanvas(cell: cell, font: font)
    var actions = [String]()
    canvas.pointer = { actions.append($0.action) }
    canvas.tapped(UITapGestureRecognizer())
    try check(actions == ["press", "release", "leave"], "Touch sequence differs: \(actions)")
    _ = field.becomeFirstResponder()
    field.setMarkedText("日本", selectedRange: NSRange(location: 2, length: 0))
    field.changed()
    try check(field.text == "日本", "Preedit was cut before commit")
    try check(tree[2]?.props["value"]?.stringValue == "", "Preedit reached model")
    field.unmarkText()
    try check(
      tree[2]?.props["value"]?.stringValue == "日",
      "Composition commit did not reach model within maxLength")
  }
  func contractTests() throws {
    guard mobileLimit(before: "😀", after: "😀é日", limit: 2) == "😀é",
      mobileLimit(before: "abc", after: "ab日c", limit: 3) == "abc",
      mobileLimit(before: "abcd", after: "abcde", limit: 2) == "abcd"
    else { throw MobileSession.SessionError.invalid("Unicode limits differ") }
    let directory = Bundle.main.resourceURL!.appendingPathComponent("fixtures")
    _ = try Manifest.parse(Data(contentsOf: directory.appendingPathComponent("manifest.json")))
    let frames =
      try JSON.parse(Data(contentsOf: directory.appendingPathComponent("frames.json")))["frames"]?
      .arrayValue ?? []
    for fixture in frames {
      guard let text = fixture["json"]?.stringValue, let hex = fixture["hex"]?.stringValue,
        let bytes = Hex.decode(hex)
      else { throw MobileSession.SessionError.invalid("Invalid frame fixture") }
      guard try Frame.encode(Data(text.utf8)) == bytes else {
        throw MobileSession.SessionError.invalid("Frame differs from fixture")
      }
      var decoder = Frame.Decoder()
      for byte in bytes { decoder.append(Data([byte])) }
      guard try decoder.next() == Data(text.utf8) else {
        throw MobileSession.SessionError.invalid("Fragmented frame differs")
      }
    }
    let entries =
      try JSON.parse(Data(contentsOf: directory.appendingPathComponent("messages.json"))).arrayValue
      ?? []
    var messages = [String: JSON]()
    for entry in entries {
      if let name = entry["name"]?.stringValue, let message = entry["message"] {
        messages[name] = message
        let decoded = try Message.decode(message)
        _ = try Message.decode(decoded.encode())
      }
    }
    let steps =
      try JSON.parse(Data(contentsOf: directory.appendingPathComponent("session.json")))["steps"]?
      .arrayValue ?? []
    var tree = NodeTree()
    func snapshot(_ id: Int) -> JSON {
      let node = tree[id]!
      return .object([
        "id": .int(Int64(id)), "kind": .string(node.kind), "props": .object(node.props),
        "children": .array(node.children.map(snapshot)),
      ])
    }
    for step in steps {
      guard let name = step["patch"]?.stringValue, let message = messages[name],
        case .patch(let ops) = try Message.decode(message)
      else { throw MobileSession.SessionError.invalid("Missing patch fixture") }
      tree.apply(ops)
      guard (tree.root.map(snapshot) ?? .null) == step["tree"] else {
        throw MobileSession.SessionError.invalid("Tree differs after \(name)")
      }
    }
  }
}
