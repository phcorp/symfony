import UIKit

func mobileColor(_ value: JSON?, _ fallback: UIColor = .label) -> UIColor {
  guard let color = RGB(hex: value?.stringValue) else { return fallback }
  return UIColor(red: color.red, green: color.green, blue: color.blue, alpha: 1)
}
func mobileRuns(_ runs: [JSON], font: UIFont, base: [String: JSON] = [:]) -> NSAttributedString {
  let result = NSMutableAttributedString(string: "")
  for value in runs {
    guard let run = value.objectValue else { continue }
    var traits: UIFontDescriptor.SymbolicTraits = []
    if (run["b"]?.boolValue ?? base["bold"]?.boolValue) == true { traits.insert(.traitBold) }
    if (run["i"]?.boolValue ?? base["italic"]?.boolValue) == true { traits.insert(.traitItalic) }
    let selected =
      font.fontDescriptor.withSymbolicTraits(traits).map {
        UIFont(descriptor: $0, size: font.pointSize)
      } ?? font
    var attributes: [NSAttributedString.Key: Any] = [
      .font: selected, .foregroundColor: mobileColor(run["fg"] ?? base["fg"]),
    ]
    if run["bg"] != nil { attributes[.backgroundColor] = mobileColor(run["bg"], .clear) }
    if (run["u"]?.boolValue ?? base["underline"]?.boolValue) == true {
      attributes[.underlineStyle] = NSUnderlineStyle.single.rawValue
    }
    result.append(NSAttributedString(string: run["t"]?.stringValue ?? "", attributes: attributes))
  }
  return result
}

func mobileLimit(before: String, after: String, limit: Int?) -> String {
  guard let limit else { return after }
  let old = Array(before.unicodeScalars)
  let next = Array(after.unicodeScalars)
  guard next.count > max(limit, old.count) else { return after }
  var prefix = 0
  var suffix = 0
  while prefix < old.count && prefix < next.count && old[prefix] == next[prefix] { prefix += 1 }
  while suffix < old.count - prefix && suffix < next.count - prefix
    && old[old.count - 1 - suffix] == next[next.count - 1 - suffix]
  { suffix += 1 }
  let room = max(0, max(limit, old.count) - prefix - suffix)
  return String(String.UnicodeScalarView(next.prefix(prefix + room)))
    + String(String.UnicodeScalarView(next.suffix(suffix)))
}

final class ActionButton: UIButton {
  var action: (() -> Void)?
  init() {
    super.init(frame: .zero)
    addTarget(self, action: #selector(pressed), for: .touchUpInside)
  }
  required init?(coder: NSCoder) { fatalError() }
  override var intrinsicContentSize: CGSize {
    let size = super.intrinsicContentSize
    return CGSize(width: size.width, height: max(44, size.height))
  }
  @objc func pressed() { action?() }
}
final class ActionSwitch: UISwitch {
  var action: ((Bool) -> Void)?
  init() {
    super.init(frame: .zero)
    addTarget(self, action: #selector(changed), for: .valueChanged)
  }
  required init?(coder: NSCoder) { fatalError() }
  @objc func changed() { action?(isOn) }
}
final class ActionSlider: UISlider {
  var action: ((Double) -> Void)?
  init() {
    super.init(frame: .zero)
    addTarget(self, action: #selector(changed), for: .valueChanged)
  }
  required init?(coder: NSCoder) { fatalError() }
  @objc func changed() { action?(Double(value)) }
}
final class MobileField: UITextField, UITextFieldDelegate {
  var props = [String: JSON]()
  var action: ((String, String?) -> Void)?
  var committed = ""
  var onCommit: ((String) -> Void)?
  var programmatic = false
  var composing = false
  init() {
    super.init(frame: .zero)
    borderStyle = .roundedRect
    delegate = self
    returnKeyType = .done
    addTarget(self, action: #selector(changed), for: .editingChanged)
    addTarget(self, action: #selector(began), for: .editingDidBegin)
    addTarget(self, action: #selector(finished), for: .editingDidEnd)

  }
  required init?(coder: NSCoder) { fatalError() }
  override var intrinsicContentSize: CGSize {
    let size = super.intrinsicContentSize
    return CGSize(width: size.width, height: max(44, size.height))
  }
  func update(_ props: [String: JSON]) {
    self.props = props
    let value = props["value"]?.stringValue ?? ""
    if text != value {
      programmatic = true
      text = value
      programmatic = false
    }
    committed = value
    placeholder = props["prompt"]?.stringValue
    accessibilityLabel = props["label"]?.stringValue ?? placeholder
  }
  override func setMarkedText(_ markedText: String?, selectedRange: NSRange) {
    composing = true
    super.setMarkedText(markedText, selectedRange: selectedRange)
  }
  override func unmarkText() {
    super.unmarkText()
    composing = false
    changed()
  }
  @objc func began() { action?("focus", nil) }
  @objc func finished() {
    changed()
    onCommit?(text ?? "")
  }
  @objc func changed() {
    guard !programmatic, !composing, markedTextRange == nil else { return }
    let value = mobileLimit(before: committed, after: text ?? "", limit: MaxLength.limit(props))
    if text != value { text = value }
    guard value != committed else { return }
    committed = value
    action?("change", value)
  }
  func textFieldShouldReturn(_ textField: UITextField) -> Bool {
    changed()
    action?("submit", text ?? "")
    return true
  }
  func textField(
    _ textField: UITextField, shouldChangeCharactersIn range: NSRange,
    replacementString string: String
  ) -> Bool {
    if composing || markedTextRange != nil { return true }
    let allowed = MaxLength.allowed(
      current: text ?? "", range: range, replacement: string, limit: MaxLength.limit(props))
    guard allowed != string else { return true }
    text = ((text ?? "") as NSString).replacingCharacters(in: range, with: allowed)
    changed()
    return false
  }
}
final class MobileEditor: UITextView, UITextViewDelegate {
  var composing = false
  var props = [String: JSON]()
  var action: ((String, String?) -> Void)?
  var committed = ""
  init() {
    super.init(frame: .zero, textContainer: nil)
    delegate = self
    font = .systemFont(ofSize: 14)
    layer.borderWidth = 1
    layer.borderColor = UIColor.separator.cgColor
    layer.cornerRadius = 5
    let toolbar = UIToolbar()
    toolbar.sizeToFit()
    toolbar.items = [
      UIBarButtonItem(systemItem: .flexibleSpace),
      UIBarButtonItem(title: "Submit", style: .done, target: self, action: #selector(submit)),
    ]
    inputAccessoryView = toolbar
  }
  required init?(coder: NSCoder) { fatalError() }
  override var intrinsicContentSize: CGSize {
    CGSize(
      width: UIView.noIntrinsicMetric,
      height: max(
        44,
        (font?.lineHeight ?? 17) * CGFloat(props["minLines"]?.intValue ?? 3)
          + textContainerInset.top + textContainerInset.bottom))
  }
  func update(_ props: [String: JSON]) {
    self.props = props
    invalidateIntrinsicContentSize()
    let value = props["value"]?.stringValue ?? ""
    if text != value { text = value }
    committed = value
  }
  override func setMarkedText(_ markedText: String?, selectedRange: NSRange) {
    composing = true
    super.setMarkedText(markedText, selectedRange: selectedRange)
  }
  override func unmarkText() {
    super.unmarkText()
    composing = false
    textViewDidChange(self)
  }
  func textViewDidBeginEditing(_ textView: UITextView) { action?("focus", nil) }
  func textViewDidEndEditing(_ textView: UITextView) { textViewDidChange(self) }
  func textViewDidChange(_ textView: UITextView) {
    guard !composing, markedTextRange == nil, text != committed else { return }
    let value = mobileLimit(before: committed, after: text, limit: MaxLength.limit(props))
    if text != value { text = value }
    committed = value
    action?("change", value)
  }
  func textView(
    _ textView: UITextView, shouldChangeTextIn range: NSRange, replacementText string: String
  ) -> Bool {
    if composing || markedTextRange != nil { return true }
    let allowed = MaxLength.allowed(
      current: text, range: range, replacement: string, limit: MaxLength.limit(props))
    guard allowed != string else { return true }
    text = (text as NSString).replacingCharacters(in: range, with: allowed)
    textViewDidChange(self)
    return false
  }
  override var keyCommands: [UIKeyCommand]? {
    [
      UIKeyCommand(
        title: "Submit", action: #selector(submit), input: "\r", modifierFlags: [.command]),
      UIKeyCommand(
        title: "Submit", action: #selector(submit), input: "\r", modifierFlags: [.control]),
    ]
  }
  @objc func submit() {
    textViewDidChange(self)
    action?("submit", text)
  }
}
final class MobileNumber: UIStackView {
  let field = MobileField()
  let stepper = UIStepper()
  var props = [String: JSON]()
  var action: ((String, String?) -> Void)?
  var previous = 0.0
  init() {
    super.init(frame: .zero)
    axis = .horizontal
    spacing = 4
    field.keyboardType = .numbersAndPunctuation
    field.action = { [weak self] name, value in self?.action?(name, value) }
    addArrangedSubview(field)
    addArrangedSubview(stepper)
    stepper.minimumValue = -1_000_000
    stepper.maximumValue = 1_000_000
    stepper.addTarget(self, action: #selector(changed), for: .valueChanged)
  }
  required init(coder: NSCoder) { fatalError() }
  func update(_ props: [String: JSON]) {
    self.props = props
    field.update(props)
    previous = 0
    stepper.value = 0
  }
  @objc func changed() {
    let range = Stepping.range(props)
    let current = Double(field.text ?? "")
    let value = Stepping.step(
      current?.isFinite == true ? field.text ?? "" : "", by: stepper.value > previous ? 1 : -1,
      min: range.min, max: range.max, step: range.step)
    previous = stepper.value
    field.text = value
    field.changed()
  }
  override func becomeFirstResponder() -> Bool { field.becomeFirstResponder() }
}

final class CanvasInput: UITextView {
  var composing = false
  override func setMarkedText(_ markedText: String?, selectedRange: NSRange) {
    composing = true
    super.setMarkedText(markedText, selectedRange: selectedRange)
  }
  var backspace: (() -> Void)?
  var keyboard: ((UIKey) -> Bool)?
  var pasteText: ((String) -> Void)?
  override func paste(_ sender: Any?) {
    if let text = UIPasteboard.general.string { pasteText?(text) }
  }
  override func pressesBegan(_ presses: Set<UIPress>, with event: UIPressesEvent?) {
    let remaining = Set(
      presses.filter { press in
        guard markedTextRange == nil, let key = press.key else { return true }
        return keyboard?(key) != true
      })
    if !remaining.isEmpty { super.pressesBegan(remaining, with: event) }
  }
  override func unmarkText() {
    super.unmarkText()
    composing = false
    delegate?.textViewDidChange?(self)
  }
  override func deleteBackward() {
    if text.isEmpty { backspace?() } else { super.deleteBackward() }
  }
}
final class MobileCanvas: UIView, UITextViewDelegate, UIGestureRecognizerDelegate {
  var props = [String: JSON]()
  let cell: CellSize
  let font: UIFont
  var pointer: ((Pointer) -> Void)?
  var key: ((String, String?) -> Void)?
  var pasteText: ((String) -> Void)?
  var onFocused: (() -> Void)?
  var panScroll: Bool?
  var placements = [Int: ([Int], [Int]?)]()
  let input = CanvasInput()
  init(cell: CellSize, font: UIFont) {
    self.cell = cell
    self.font = font
    super.init(frame: .zero)
    backgroundColor = .systemBackground
    clipsToBounds = true
    input.delegate = self
    input.font = font
    input.textColor = .clear
    input.backgroundColor = .clear
    input.textContainerInset = .zero
    input.textContainer.lineFragmentPadding = 0
    input.autocorrectionType = .no
    input.isScrollEnabled = false
    input.isUserInteractionEnabled = false
    input.pasteText = { [weak self] text in self?.pasteText?(text) }
    input.backspace = { [weak self] in self?.key?("backspace", nil) }
    input.keyboard = { [weak self] key in
      guard let self else { return false }
      let code = key.keyCode.rawValue
      let names: [Int: String] = [
        40: "enter", 41: "escape", 42: "backspace", 43: "tab", 74: "home", 75: "page_up",
        76: "delete", 77: "end", 78: "page_down", 79: "right", 80: "left", 81: "down", 82: "up",
      ]
      var name = names[code]
      if (58...69).contains(code) { name = "f\(code-57)" }
      if (104...115).contains(code) { name = "f\(code-91)" }
      let flags = key.modifierFlags
      if name == nil && (!flags.intersection([.control, .alternate, .command]).isEmpty) {
        name = key.charactersIgnoringModifiers.lowercased()
      }
      guard let name else { return false }
      let modifiers =
        (flags.contains(.control) ? "ctrl+" : "") + (flags.contains(.shift) ? "shift+" : "")
        + (flags.contains(.alternate) ? "alt+" : "") + (flags.contains(.command) ? "super+" : "")
      self.key?(modifiers + name, nil)
      return true
    }
    addSubview(input)
    let tap = UITapGestureRecognizer(target: self, action: #selector(tapped(_:)))
    tap.delegate = self
    addGestureRecognizer(tap)
    let pan = UIPanGestureRecognizer(target: self, action: #selector(panned(_:)))
    pan.delegate = self
    addGestureRecognizer(pan)
    setContentHuggingPriority(.defaultLow, for: .vertical)
  }
  required init?(coder: NSCoder) { fatalError() }
  override var canBecomeFirstResponder: Bool { props["focusable"]?.boolValue == true }
  override func becomeFirstResponder() -> Bool {
    guard canBecomeFirstResponder else { return false }
    onFocused?()
    return input.becomeFirstResponder()
  }
  override var intrinsicContentSize: CGSize {
    CGSize(
      width: Double(props["columns"]?.intValue ?? 40) * cell.width,
      height: Double(max(1, props["lines"]?.arrayValue?.count ?? 1)) * cell.height)
  }
  override func layoutSubviews() {
    super.layoutSubviews()
    for view in subviews where view !== input {
      guard let (place, clip) = placements[view.tag], place.count == 4 else { continue }
      view.frame = CGRect(
        x: Double(place[1]) * cell.width, y: Double(place[0]) * cell.height,
        width: Double(place[2]) * cell.width, height: Double(place[3]) * cell.height)
      if let clip, clip.count == 4 {
        let mask = CAShapeLayer()
        mask.path =
          UIBezierPath(
            rect: CGRect(
              x: Double(clip[1] - place[1]) * cell.width,
              y: Double(clip[0] - place[0]) * cell.height, width: Double(clip[2]) * cell.width,
              height: Double(clip[3]) * cell.height)
          ).cgPath
        view.layer.mask = mask
      } else {
        view.layer.mask = nil
      }
    }
    let spoken = UIAccessibilityElement(accessibilityContainer: self)
    spoken.accessibilityLabel = (props["lines"]?.arrayValue ?? []).map { line in
      (line.arrayValue ?? []).compactMap { $0["t"]?.stringValue }.joined()
    }.joined(separator: "\n")
    spoken.accessibilityTraits = .staticText
    spoken.accessibilityFrameInContainerSpace = bounds
    accessibilityElements = [spoken] + subviews.filter { $0 !== input }
    let cursor = props["cursor"]?.arrayValue?.compactMap(\.intValue) ?? [0, 0]
    input.frame = CGRect(
      x: Double(cursor.count > 1 ? cursor[1] : 0) * cell.width,
      y: Double(cursor.first ?? 0) * cell.height, width: max(100, bounds.width / 2),
      height: cell.height * 2)
  }
  override func draw(_ rect: CGRect) {
    guard let lines = props["lines"]?.arrayValue else { return }
    for (row, line) in lines.enumerated() {
      mobileRuns(line.arrayValue ?? [], font: font).draw(
        at: CGPoint(x: 0, y: Double(row) * cell.height))
    }
    UIColor.systemBackground.setFill()
    for (_, placement) in placements {
      let (place, clip) = placement
      guard place.count == 4 else { continue }
      var area = CGRect(
        x: Double(place[1]) * cell.width, y: Double(place[0]) * cell.height,
        width: Double(place[2]) * cell.width, height: Double(place[3]) * cell.height)
      if let clip, clip.count == 4 {
        area = area.intersection(
          CGRect(
            x: Double(clip[1]) * cell.width, y: Double(clip[0]) * cell.height,
            width: Double(clip[2]) * cell.width, height: Double(clip[3]) * cell.height))
      }
      UIRectFill(area)
    }
    if let marked = input.markedTextRange, let text = input.text(in: marked), !text.isEmpty {
      NSAttributedString(
        string: text,
        attributes: [
          .font: font, .foregroundColor: UIColor.label,
          .underlineStyle: NSUnderlineStyle.single.rawValue,
        ]
      ).draw(at: input.frame.origin)
    }
  }
  func gestureRecognizer(_ gesture: UIGestureRecognizer, shouldReceive touch: UITouch) -> Bool {
    touch.view === self
  }
  @objc func tapped(_ gesture: UITapGestureRecognizer) {
    let point = gesture.location(in: self)
    if canBecomeFirstResponder { onFocused?() }
    pointer?(
      Pointer(
        action: "press", row: Int(floor(point.y / cell.height)),
        column: Int(floor(point.x / cell.width)), button: "left"))
    pointer?(
      Pointer(
        action: "release", row: Int(floor(point.y / cell.height)),
        column: Int(floor(point.x / cell.width)), button: "left"))
    pointer?(
      Pointer(
        action: "leave", row: Int(floor(point.y / cell.height)),
        column: Int(floor(point.x / cell.width))))
    if props["cursor"]?.arrayValue != nil { _ = becomeFirstResponder() }
  }
  @objc func panned(_ gesture: UIPanGestureRecognizer) {
    let point = gesture.location(in: self)
    let translation = gesture.translation(in: self)
    if gesture.state == .began {
      panScroll = abs(translation.y) >= abs(translation.x)
      if panScroll == false {
        if canBecomeFirstResponder { onFocused?() }
        pointer?(
          Pointer(
            action: "press", row: Int(floor((point.y - translation.y) / cell.height)),
            column: Int(floor((point.x - translation.x) / cell.width)), button: "left"))
      }
    }
    if panScroll == false {
      pointer?(
        Pointer(
          action: gesture.state == .ended
            ? "release" : gesture.state == .cancelled ? "leave" : "move",
          row: Int(floor(point.y / cell.height)), column: Int(floor(point.x / cell.width)),
          button: gesture.state == .cancelled ? nil : "left"))
    } else {
      let rows = Int((-translation.y / cell.height).rounded(.towardZero))
      let columns =
        gesture.numberOfTouches > 1 ? Int((-translation.x / cell.width).rounded(.towardZero)) : 0
      if rows != 0 || columns != 0 {
        pointer?(
          Pointer(
            action: "wheel", row: Int(floor(point.y / cell.height)),
            column: Int(floor(point.x / cell.width)), rows: rows, columns: columns))
        gesture.setTranslation(.zero, in: self)
      }
    }
    if gesture.state == .ended || (gesture.state == .cancelled && panScroll != false) {
      pointer?(
        Pointer(
          action: "leave", row: Int(floor(point.y / cell.height)),
          column: Int(floor(point.x / cell.width))))
    }
    if gesture.state == .ended || gesture.state == .cancelled { panScroll = nil }
  }
  func textView(
    _ textView: UITextView, shouldChangeTextIn range: NSRange, replacementText text: String
  ) -> Bool {
    if input.markedTextRange == nil && text == "\n" {
      key?("enter", nil)
      return false
    }
    return true
  }
  func textViewDidBeginEditing(_ textView: UITextView) { onFocused?() }
  func textViewDidChange(_ textView: UITextView) {
    guard !input.composing, input.markedTextRange == nil else {
      setNeedsDisplay()
      return
    }
    let text = input.text ?? ""
    input.text = ""
    for scalar in text.unicodeScalars where scalar.value >= 32 && scalar.value != 127 {
      let value = String(scalar)
      key?(value == " " ? "space" : value, value)
    }
    setNeedsDisplay()
  }
}
