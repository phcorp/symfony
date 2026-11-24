import Foundation

/// `maxLength` of `textfield` and `textarea`: the most characters the value may have, counted in
/// Unicode code points like PHP's mb_strlen, so that the host stops where the application cuts.
public enum MaxLength {
    /// The limit in `props`; nil when absent, null or negative (no limit).
    public static func limit(_ props: [String: JSON]) -> Int? {
        guard let limit = props["maxLength"]?.intValue, limit >= 0 else { return nil }
        return limit
    }

    /// The part of `replacement` an edit of `current` may insert at `range` (UTF-16, as AppKit gives
    /// it): all of it under the limit, else its first code points up to the limit. A value already
    /// past the limit may be edited as long as it gets no longer, as the application allows.
    ///
    /// `marked` is the marked text of an input method, still composed: not counted, as it is not the
    /// value yet; an edit that replaces all of it, its commit, is counted instead.
    public static func allowed(current: String, range: NSRange, replacement: String, limit: Int?, marked: NSRange? = nil) -> String {
        if let marked, marked.location != NSNotFound, marked.length > 0, NSIntersectionRange(range, marked).length == marked.length {
            let unmarked = (current as NSString).replacingCharacters(in: marked, with: "")
            return allowed(current: unmarked, range: NSRange(location: range.location, length: range.length - marked.length), replacement: replacement, limit: limit)
        }
        guard let limit else { return replacement }
        let text = current as NSString
        let safe = NSIntersectionRange(range, NSRange(location: 0, length: text.length))
        let length = current.unicodeScalars.count
        let removed = text.substring(with: safe).unicodeScalars.count
        let room = max(0, max(limit, length) - (length - removed))
        let scalars = replacement.unicodeScalars
        guard scalars.count > room else { return replacement }
        return String(String.UnicodeScalarView(scalars.prefix(room)))
    }
}
