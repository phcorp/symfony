/// What closing the window does, which depends on how far the application has got.
///
/// 1. The close button and Quit send `close`. 2. `close-reply` accept: wait for `bye` and the
/// exit. 3. Refused: the window stays. 4. No answer within 4 s, or no exit within 15 s after
/// `bye`: offer to force quit. 5. An application that exits by itself takes the window with it.
public struct CloseFlow: Equatable, Sendable {
    public static let answerSeconds = 4.0
    public static let exitSeconds = 15.0

    public enum Step: Equatable, Sendable {
        /// Close the window now: the application never connected, or is gone.
        case closeNow
        /// Send `close` and wait `answerSeconds` for the reply.
        case askApplication
        /// Already asked or already closing: nothing to do.
        case wait
        /// The application refused: stop the timer, the window stays.
        case stopWaiting
        /// The application is ending: (re)arm the `exitSeconds` timer.
        case waitForExit
        /// It did not answer, or did not exit: ask the user whether to force quit.
        case offerForceQuit
    }

    private enum State: Equatable, Sendable {
        case starting, running, asking, closing, exited
    }

    private var state: State = .starting
    public private(set) var saidBye = false

    public init() {}

    public var isClosing: Bool { state == .closing }
    public var hasExited: Bool { state == .exited }

    /// The application authenticated: from now on closing asks it.
    public mutating func connected() {
        if state == .starting { state = .running }
    }

    public mutating func closeRequested() -> Step {
        switch state {
        case .starting, .exited:
            return .closeNow
        case .running:
            state = .asking
            return .askApplication
        case .asking, .closing:
            return .wait
        }
    }

    public mutating func closeReply(accept: Bool) -> Step {
        guard state == .asking || state == .running else { return .wait }
        if accept {
            state = .closing
            return .waitForExit
        }
        state = .running
        return .stopWaiting
    }

    public mutating func bye() -> Step {
        saidBye = true
        guard state != .exited else { return .wait }
        state = .closing
        return .waitForExit
    }

    public mutating func timedOut() -> Step {
        switch state {
        case .asking, .closing:
            return .offerForceQuit
        default:
            return .wait
        }
    }

    /// The user chose to keep waiting after a timeout.
    public mutating func keepWaiting() -> Step {
        switch state {
        case .asking:
            // The window stays; the next close asks again.
            state = .running
            return .stopWaiting
        case .closing:
            return .waitForExit
        default:
            return .wait
        }
    }

    /// The user chose to force quit: the host kills the process, whose exit then closes the
    /// window without reporting a failure.
    public mutating func forceQuit() {
        guard state != .exited else { return }
        state = .closing
    }

    /// The process ended. The window always closes; the result says whether something went
    /// wrong that the user should read about first.
    public mutating func exited(status: Int32) -> Exit {
        let before = state
        state = .exited
        if before == .starting {
            return .failedBeforeConnecting
        }
        if saidBye || status == 0 || before == .closing {
            return .finished
        }
        return .failed
    }

    public enum Exit: Equatable, Sendable {
        case finished
        /// Exited before `auth`: show its last stderr lines.
        case failedBeforeConnecting
        /// Exited with an error, without `bye`.
        case failed
    }
}
