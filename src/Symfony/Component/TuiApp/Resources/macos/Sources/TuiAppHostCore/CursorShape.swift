import Foundation

/// The pointer a canvas shows for a CSS cursor keyword of the `cursor` message, as the shapes
/// AppKit has: the UI picks the NSCursor, and an older one for what came with macOS 15.
public enum CursorShape: Sendable, Equatable {
    case arrow
    /// `none`: no pointer.
    case hidden
    case pointingHand
    case iBeam
    case verticalIBeam
    case crosshair
    case openHand
    case closedHand
    case notAllowed
    case dragCopy
    case dragLink
    case contextualMenu
    /// macOS 15; an arrow before.
    case zoomIn
    case zoomOut
    /// A divider moved left and right, or up and down.
    case columnResize
    case rowResize
    /// An edge or a corner of a frame, moved either way.
    case frameResize(FrameEdge)

    public enum FrameEdge: Sendable, Hashable {
        case top, left, bottom, right, topLeft, topRight, bottomLeft, bottomRight

        /// Left or right: a left-right arrow before macOS 15; top or bottom, an up-down one;
        /// a corner, none (an arrow).
        public var isHorizontal: Bool { self == .left || self == .right }
        public var isVertical: Bool { self == .top || self == .bottom }
    }

    /// The closest shape for a keyword; an arrow for one AppKit has none for (`help`, `wait`,
    /// `progress`, `move`, `all-scroll`) or an unknown one.
    public init(keyword: String) {
        switch keyword {
        case "none": self = .hidden
        case "pointer": self = .pointingHand
        case "text": self = .iBeam
        case "vertical-text": self = .verticalIBeam
        case "crosshair", "cell": self = .crosshair
        case "grab": self = .openHand
        case "grabbing": self = .closedHand
        case "not-allowed", "no-drop": self = .notAllowed
        case "copy": self = .dragCopy
        case "alias": self = .dragLink
        case "context-menu": self = .contextualMenu
        case "zoom-in": self = .zoomIn
        case "zoom-out": self = .zoomOut
        case "col-resize": self = .columnResize
        case "row-resize": self = .rowResize
        case "n-resize": self = .frameResize(.top)
        case "s-resize": self = .frameResize(.bottom)
        case "e-resize": self = .frameResize(.right)
        case "w-resize": self = .frameResize(.left)
        case "ns-resize": self = .frameResize(.bottom)
        case "ew-resize": self = .frameResize(.right)
        case "ne-resize": self = .frameResize(.topRight)
        case "nw-resize": self = .frameResize(.topLeft)
        case "se-resize": self = .frameResize(.bottomRight)
        case "sw-resize": self = .frameResize(.bottomLeft)
        case "nesw-resize": self = .frameResize(.topRight)
        case "nwse-resize": self = .frameResize(.bottomRight)
        default: self = .arrow
        }
    }
}
