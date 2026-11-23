import Foundation

/// What the mouse does on a canvas: the `pointer` message, with the `pointer` capability.
public struct Pointer: Sendable, Equatable {
    /// press, release, move, leave or wheel.
    public var action: String
    /// The cell under the pointer, from the canvas's top-left corner.
    public var row: Int
    public var column: Int
    /// left, middle or right; nil for leave, wheel and a move with no button held.
    public var button: String?
    /// Key ids of the modifiers held, in the order ctrl, shift, alt, super.
    public var modifiers: [String]
    /// wheel only: rows down and columns right to scroll by.
    public var rows: Int?
    public var columns: Int?

    public init(action: String, row: Int, column: Int, button: String? = nil, modifiers: [String] = [], rows: Int? = nil, columns: Int? = nil) {
        self.action = action
        self.row = row
        self.column = column
        self.button = button
        self.modifiers = modifiers
        self.rows = rows
        self.columns = columns
    }

    /// The modifier ids of a key-modifier set, in the protocol's order.
    public static func modifiers(_ modifiers: KeyModifiers) -> [String] {
        [(KeyModifiers.control, "ctrl"), (.shift, "shift"), (.option, "alt"), (.command, "super")].filter { modifiers.contains($0.0) }.map(\.1)
    }

    /// The cell a point falls in, the point in the canvas's coordinates, y down.
    public static func cell(x: Double, y: Double, cellWidth: Double, cellHeight: Double) -> (row: Int, column: Int) {
        (Int((y / cellHeight).rounded(.down)), Int((x / cellWidth).rounded(.down)))
    }
}

/// Adds up wheel deltas and hands them out in whole cells: a trackpad's deltas are points, a
/// mouse wheel's lines (one row each).
public struct WheelAccumulator: Sendable {
    private var rows = 0.0
    private var columns = 0.0

    public init() {}

    /// Deltas as the platform gives them, positive up and left (content moving down and right,
    /// as AppKit's `scrollingDeltaY`): the whole rows (down) and columns (right) to scroll by, if any.
    public mutating func add(deltaX: Double, deltaY: Double, precise: Bool, cellWidth: Double, cellHeight: Double) -> (rows: Int, columns: Int)? {
        rows -= precise ? deltaY / cellHeight : deltaY
        columns -= precise ? deltaX / cellWidth : deltaX
        let whole = (Int(rows.rounded(.towardZero)), Int(columns.rounded(.towardZero)))
        guard whole.0 != 0 || whole.1 != 0 else { return nil }
        rows -= Double(whole.0)
        columns -= Double(whole.1)
        return whole
    }

    /// A new gesture starts from nothing: what was left of the last one is dropped.
    public mutating func reset() {
        rows = 0
        columns = 0
    }
}
