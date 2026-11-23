import XCTest
@testable import TuiAppHostCore

/// Number fields and sliders step as HTML does, and as TuiHtml does in a terminal.
final class SteppingTests: XCTestCase {
    func testUpAndDownGoToTheNextStepWithinTheBounds() {
        XCTAssertEqual(Stepping.step("1", by: 1, min: 0, max: 10, step: 0.5), "1.5")
        XCTAssertEqual(Stepping.step("1.5", by: -1, min: 0, max: 10, step: 0.5), "1")
        XCTAssertEqual(Stepping.step("0", by: -1, min: 0, max: 10, step: 0.5), "0", "Within min")
        XCTAssertEqual(Stepping.step("10", by: 1, min: 0, max: 10, step: 0.5), "10", "Within max")
        XCTAssertEqual(Stepping.step("25", by: -1, min: 0, max: 10, step: 3), "9", "From far above max: the highest step below it, not max")
        XCTAssertEqual(Stepping.step("9", by: 1, min: 0, max: 10, step: 3), "9", "Up stays on the highest step")
        XCTAssertEqual(Stepping.step("12", by: 1, min: 0, max: 10, step: 3), "12", "Up from above max: no change, like HTML")
        XCTAssertEqual(Stepping.step("-3", by: -1, min: 0, max: 10, step: 3), "-3", "Down from below min: no change")
        XCTAssertEqual(Stepping.step("-3", by: 1, min: 0, max: 10, step: 3), "0", "Up from below min: min")
        XCTAssertEqual(Stepping.step("12", by: -1, min: 0, max: 10, step: 0.5), "10", "Max on a step")
        XCTAssertEqual(Stepping.step("", by: 1, min: nil, max: nil, step: 1), "1", "An empty value is 0")
        XCTAssertEqual(Stepping.step("abc", by: -1, min: nil, max: nil, step: 1), "-1", "So is an invalid one")
        XCTAssertEqual(Stepping.step("-0.5", by: 1, min: nil, max: nil, step: 0.5), "0", "Not -0")
    }

    func testAValueOffTheStepsGoesToTheNearestStepThatWay() {
        XCTAssertEqual(Stepping.step("1.2", by: 1, min: 0, max: nil, step: 0.5), "1.5")
        XCTAssertEqual(Stepping.step("1.2", by: -1, min: 0, max: nil, step: 0.5), "1")
        XCTAssertEqual(Stepping.step("4", by: 1, min: 1, max: nil, step: 2), "5", "From min")
    }

    func testAValueHasTheDecimalsOfTheStep() {
        XCTAssertEqual(Stepping.format(1e-7, step: 1e-7), "0.0000001", "A step Swift writes with an exponent")
        XCTAssertEqual(Stepping.format(3e-7, step: 1.5e-7), "0.0000003")
        XCTAssertEqual(Stepping.format(1234.1, step: 0.1), "1234.1")
        XCTAssertEqual(Stepping.format(2, step: 0.25), "2")
        XCTAssertEqual(Stepping.format(1e20, step: 1e20), "100000000000000000000")
        XCTAssertEqual(Stepping.step("0", by: 1, min: 0, max: nil, step: 1e-7), "0.0000001")
    }

    func testASliderSnapsToItsSteps() {
        XCTAssertEqual(Stepping.snap(73, min: 0, max: 100, step: 5), 75)
        XCTAssertEqual(Stepping.snap(9, min: 0, max: 9, step: 2), 8, "The highest step below max")
        XCTAssertEqual(Stepping.snap(-4, min: 0, max: 10, step: 1), 0)
        XCTAssertEqual(Stepping.snap(0.30000000000000004, min: 0, max: 1, step: 0.1), 0.3)
        XCTAssertEqual(Stepping.json(55), .int(55))
        XCTAssertEqual(Stepping.json(0.5), .double(0.5))
    }

    func testTheRangeComesFromTheProps() {
        let range = Stepping.range(["min": .int(0), "max": .double(10), "step": .double(0.5)])
        XCTAssertEqual(range.min, 0)
        XCTAssertEqual(range.max, 10)
        XCTAssertEqual(range.step, 0.5)
        XCTAssertEqual(Stepping.range(["step": .int(0)]).step, 1, "Not positive: 1")
        XCTAssertNil(Stepping.range([:]).min)
    }
}
