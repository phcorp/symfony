import Foundation

/// The size of one canvas-font cell, in points.
public struct CellSize: Sendable, Equatable {
    public var width: Double
    public var height: Double

    public init(width: Double, height: Double) {
        self.width = max(width, 1)
        self.height = max(height, 1)
    }

    /// `[floor(y / h), floor(x / w), ceil(width / w), ceil(height / h)]`: row, column, columns, rows.
    public func cells(x: Double, y: Double, width: Double, height: Double) -> [Int] {
        // A frame that ends a hair past a cell boundary is a rounding error, not another cell.
        func up(_ v: Double) -> Int { Int((v - 1e-6).rounded(.up)) }
        return [Int((y / self.height).rounded(.down)), Int((x / self.width).rounded(.down)), max(0, up(width / self.width)), max(0, up(height / self.height))]
    }

    /// Columns and rows that fit in a content area, at least 1 of each.
    public func grid(width: Double, height: Double) -> (columns: Int, rows: Int) {
        (max(1, Int((width / self.width).rounded(.down))), max(1, Int((height / self.height).rounded(.down))))
    }
}

/// Boxes of cells, `[row, column, columns, rows]` like `place` and `clip`: those of the controls over a canvas, whose
/// cells the canvas leaves undrawn. A glyph drawn there, like `─` or `→`, reaches past its cell, into the cell before
/// the control, where the opaque box under the control does not hide it: small dots at the left edge of the control.
public struct CellBoxes: Sendable, Equatable {
    public var boxes: [[Int]]

    public init(_ boxes: [[Int]] = []) {
        self.boxes = boxes.filter { $0.count == 4 }
    }

    public func contains(row: Int, column: Int) -> Bool {
        boxes.contains { row >= $0[0] && row < $0[0] + $0[3] && column >= $0[1] && column < $0[1] + $0[2] }
    }
}

/// `#rrggbb` as components from 0 to 1.
public struct RGB: Sendable, Equatable {
    public var red: Double
    public var green: Double
    public var blue: Double

    public init?(hex: String?) {
        guard let hex, hex.count == 7, hex.hasPrefix("#"), let value = UInt32(hex.dropFirst(), radix: 16) else { return nil }
        red = Double(value >> 16 & 0xff) / 255
        green = Double(value >> 8 & 0xff) / 255
        blue = Double(value & 0xff) / 255
    }
}

/// Lets through at most one call per interval, and always the last one.
public struct Throttle: Sendable {
    public let interval: Double
    private var last: Double = -.infinity

    public init(interval: Double) {
        self.interval = interval
    }

    /// Now, or how long to wait before sending.
    public mutating func delay(at now: Double) -> Double {
        let wait = last + interval - now
        if wait <= 0 {
            last = now
            return 0
        }
        return wait
    }

    public mutating func sent(at now: Double) {
        last = now
    }
}
