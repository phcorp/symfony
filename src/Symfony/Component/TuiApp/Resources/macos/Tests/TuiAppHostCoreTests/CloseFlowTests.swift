import XCTest
@testable import TuiAppHostCore

final class CloseFlowTests: XCTestCase {
    private func connected() -> CloseFlow {
        var flow = CloseFlow()
        flow.connected()
        return flow
    }

    func testBeforeTheApplicationConnectsTheWindowClosesWithoutAsking() {
        var flow = CloseFlow()
        XCTAssertEqual(flow.closeRequested(), .closeNow)
    }

    func testOnceConnectedTheApplicationIsAskedOnlyOnce() {
        var flow = connected()
        XCTAssertEqual(flow.closeRequested(), .askApplication)
        XCTAssertEqual(flow.closeRequested(), .wait)
    }

    func testAcceptWaitsForTheExit() {
        var flow = connected()
        _ = flow.closeRequested()
        XCTAssertEqual(flow.closeReply(accept: true), .waitForExit)
        XCTAssertEqual(flow.bye(), .waitForExit, "bye re-arms the 15 s exit timer")
        XCTAssertTrue(flow.isClosing)
        XCTAssertEqual(flow.exited(status: 0), .finished)
        XCTAssertEqual(flow.closeRequested(), .closeNow)
    }

    func testARefusalKeepsTheWindowAndTheNextCloseAsksAgain() {
        var flow = connected()
        _ = flow.closeRequested()
        XCTAssertEqual(flow.closeReply(accept: false), .stopWaiting)
        XCTAssertEqual(flow.closeRequested(), .askApplication)
    }

    func testSilenceOffersAForceQuitAndKeepWaitingLeavesTheWindow() {
        var flow = connected()
        _ = flow.closeRequested()
        XCTAssertEqual(flow.timedOut(), .offerForceQuit)
        XCTAssertEqual(flow.keepWaiting(), .stopWaiting)
        XCTAssertEqual(flow.timedOut(), .wait, "nothing is pending any more")
        XCTAssertEqual(flow.closeRequested(), .askApplication)
    }

    func testAShutdownThatHangsStillLeavesAWayOut() {
        var flow = connected()
        XCTAssertEqual(flow.bye(), .waitForExit)
        XCTAssertEqual(flow.closeRequested(), .wait)
        XCTAssertEqual(flow.timedOut(), .offerForceQuit)
        XCTAssertEqual(flow.keepWaiting(), .waitForExit)
        XCTAssertEqual(flow.timedOut(), .offerForceQuit, "keeping waiting is not the last word")
    }

    func testAForceQuitIsNotReportedAsACrash() {
        var silent = connected()
        _ = silent.closeRequested()
        XCTAssertEqual(silent.timedOut(), .offerForceQuit)
        silent.forceQuit()
        XCTAssertEqual(silent.closeRequested(), .wait, "the kill is under way")
        XCTAssertEqual(silent.exited(status: 9), .finished, "killed on the user's word, not a crash")

        var hung = connected()
        _ = hung.bye()
        XCTAssertEqual(hung.timedOut(), .offerForceQuit)
        hung.forceQuit()
        XCTAssertEqual(hung.exited(status: 9), .finished)

        var gone = connected()
        XCTAssertEqual(gone.exited(status: 0), .finished)
        gone.forceQuit()
        XCTAssertTrue(gone.hasExited, "a force quit after the exit changes nothing")
    }

    func testExits() {
        var early = CloseFlow()
        XCTAssertEqual(early.exited(status: 255), .failedBeforeConnecting)
        var crashed = connected()
        XCTAssertEqual(crashed.exited(status: 1), .failed)
        var quiet = connected()
        XCTAssertEqual(quiet.exited(status: 0), .finished)
        var said = connected()
        _ = said.bye()
        XCTAssertEqual(said.exited(status: 3), .finished, "bye says the application meant to stop")
    }
}
