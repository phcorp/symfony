import Foundation

/// The values of `number` and `slider`, as HTML steps them: by `step` from `min` (0 without one),
/// within `min` and `max`, written with as many decimals as the step has, trailing zeros dropped.
public enum Stepping {
    /// The props of a node: min and max (nil: none), step (1 when absent, null or not positive).
    public static func range(_ props: [String: JSON]) -> (min: Double?, max: Double?, step: Double) {
        let step = props["step"]?.doubleValue ?? 1
        return (props["min"]?.doubleValue, props["max"]?.doubleValue, step > 0 ? step : 1)
    }

    /// Up (1) or Down (-1) in a number field showing `text`: a value off the steps goes to the
    /// nearest step that way, one on a step to the next; an empty or invalid value is 0. A value
    /// the bounds would move the other way, like Up from above max, is kept as it is.
    public static func step(_ text: String, by direction: Int, min: Double?, max: Double?, step: Double) -> String {
        let current = Double(text.trimmingCharacters(in: .whitespaces)) ?? 0
        let base = min ?? 0
        let steps = (current - base) / step
        let target = abs(steps - steps.rounded()) < 1e-9 ? steps.rounded() + Double(direction) : direction > 0 ? floor(steps) + 1 : ceil(steps) - 1
        var next = base + target * step
        if let min { next = Swift.max(min, next) }
        // Past max: the highest step at or below it, like HTML
        if let max { next = Swift.min(base + (((max - base) / step) + 1e-9).rounded(.down) * step, next) }
        // Like HTML's stepUp and stepDown: no change when the bounds would take the value the other way, like Up from
        // above max
        if direction > 0 ? next < current : next > current { return text }
        return format(next, step: step)
    }

    /// A slider's value: on a step from min, within min and max (the highest step below max).
    public static func snap(_ value: Double, min: Double, max: Double, step: Double) -> Double {
        let top = Swift.max(min, max)
        var snapped = Swift.max(min, Swift.min(top, value))
        snapped = min + ((snapped - min) / step).rounded() * step
        while snapped > top + 1e-9 { snapped -= step }
        return (snapped * 1e10).rounded() / 1e10
    }

    /// `value` with the decimals of `step`, trailing zeros dropped, -0 as 0.
    public static func format(_ value: Double, step: Double) -> String {
        let decimals = Self.decimals(step)
        var text = String(format: "%.\(decimals)f", value)
        if decimals > 0 {
            while text.hasSuffix("0") { text.removeLast() }
            if text.hasSuffix(".") { text.removeLast() }
        }
        return text == "-0" ? "0" : text
    }

    /// The decimals of a step as Swift writes it, shortest, also with an exponent: 0.5 has one, 1e-07 seven.
    static func decimals(_ step: Double) -> Int {
        guard step.isFinite, step.rounded() != step else { return 0 }
        let parts = String(describing: step).lowercased().split(separator: "e")
        let fraction = parts[0].split(separator: ".").dropFirst().first.map { $0.reversed().drop { $0 == "0" }.count } ?? 0
        let exponent = parts.count > 1 ? Int(parts[1]) ?? 0 : 0
        return Swift.min(20, Swift.max(0, fraction - exponent))
    }

    /// A slider's value as JSON: an integer when it is one.
    public static func json(_ value: Double) -> JSON {
        value.rounded() == value && abs(value) < 9e15 ? .int(Int64(value)) : .double(value)
    }
}
