using System.Globalization;

namespace TuiAppHost.Core;

/// <summary>
/// maxLength of a textfield or textarea: the most code points its value may have, as the application
/// counts them (mb_strlen), where a TextBox counts UTF-16 units. Typing and pastes stop at it; a value the
/// application sets is shown as it is.
/// </summary>
public static class TextLimit
{
    /// <summary>The node's limit: none when maxLength is missing, null, or not a count.</summary>
    public static int? Of(Node node) => node.Int("maxLength") is { } max && max >= 0 ? max : null;

    public static int CodePoints(string text)
    {
        var count = 0;
        for (var i = 0; i < text.Length; i++)
        {
            if (char.IsHighSurrogate(text[i]) && i + 1 < text.Length && char.IsLowSurrogate(text[i + 1]))
            {
                i++;
            }

            count++;
        }

        return count;
    }

    /// <summary>
    /// What of <paramref name="inserted"/> fits when it replaces <paramref name="selectionLength"/> UTF-16 units
    /// of <paramref name="text"/> at <paramref name="selectionStart"/>: all of it without a limit, else its
    /// first code points, never half a surrogate pair. A value already past the limit may be edited as long
    /// as it gets no longer.
    /// </summary>
    public static string Fit(string text, int selectionStart, int selectionLength, string inserted, int? max)
    {
        if (max is not { } limit)
        {
            return inserted;
        }

        var start = Math.Clamp(selectionStart, 0, text.Length);
        var length = Math.Clamp(selectionLength, 0, text.Length - start);
        var kept = CodePoints(text) - CodePoints(text.Substring(start, length));
        return Take(inserted, Math.Max(0, Math.Max(limit, CodePoints(text)) - kept));
    }

    /// <summary>
    /// What an edit that made <paramref name="after"/> of <paramref name="before"/> did, found by what they have in
    /// common at each end: it replaced <c>Removed</c> UTF-16 units at <c>Start</c> with <c>Inserted</c>. Never
    /// half a surrogate pair.
    /// </summary>
    public static (int Start, int Removed, string Inserted) Splice(string before, string after)
    {
        var start = 0;
        var shortest = Math.Min(before.Length, after.Length);
        while (start < shortest && before[start] == after[start])
        {
            start++;
        }

        if (start > 0 && char.IsHighSurrogate(before[start - 1]))
        {
            start--;
        }

        var end = 0;
        while (end < shortest - start && before[before.Length - 1 - end] == after[after.Length - 1 - end])
        {
            end++;
        }

        if (end > 0 && char.IsLowSurrogate(after[after.Length - end]))
        {
            end--;
        }

        return (start, before.Length - start - end, after.Substring(start, after.Length - start - end));
    }

    /// <summary>
    /// What an edit that made <paramref name="after"/> of <paramref name="before"/> did, when what it inserted ends
    /// at <paramref name="end"/> in <paramref name="after"/> (the caret after an input method's commit): what
    /// follows <paramref name="end"/> is what followed the edit. <paramref name="inserted"/>, when known (the
    /// commit's text), says where it starts; else it starts where the two texts stop having the same start.
    /// Repeated text (xy made xyxy) is no longer ambiguous then. As <see cref="Splice(string, string)"/> when
    /// <paramref name="end"/> does not fit the two texts.
    /// </summary>
    public static (int Start, int Removed, string Inserted) Splice(string before, string after, int end, string? inserted = null)
    {
        var tail = after.Length - end;
        if (end < 0 || tail < 0 || tail > before.Length || string.CompareOrdinal(before, before.Length - tail, after, end, tail) != 0
            || (end > 0 && end < after.Length && char.IsLowSurrogate(after[end]) && char.IsHighSurrogate(after[end - 1])))
        {
            return Splice(before, after);
        }

        var beforeEnd = before.Length - tail;
        if (inserted is { Length: > 0 } && inserted.Length <= end && end - inserted.Length <= beforeEnd
            && string.CompareOrdinal(after, end - inserted.Length, inserted, 0, inserted.Length) == 0
            && string.CompareOrdinal(before, 0, after, 0, end - inserted.Length) == 0)
        {
            var at = end - inserted.Length;
            return (at, beforeEnd - at, inserted);
        }

        var start = 0;
        var shortest = Math.Min(beforeEnd, end);
        while (start < shortest && before[start] == after[start])
        {
            start++;
        }

        if (start > 0 && char.IsHighSurrogate(after[start - 1]))
        {
            start--;
        }

        return (start, beforeEnd - start, after[start..end]);
    }

    /// <summary>
    /// The text an edit may leave, when the control made it already (an input method's commit, a PasswordBox,
    /// which says nothing of its selection): <paramref name="after"/> when what it inserted fits, else
    /// <paramref name="before"/> with only the part that fits inserted. <paramref name="caret"/> is after it.
    /// The edit is guessed from what the texts have in common: see the overload that knows where it ends.
    /// </summary>
    public static string Keep(string before, string after, int? max, out int caret) => Keep(before, Splice(before, after), max, out caret);

    /// <summary>
    /// As <see cref="Keep(string, string, int?, out int)"/>, for an edit whose inserted text ends at
    /// <paramref name="end"/> in <paramref name="after"/> (and is <paramref name="inserted"/>, when known).
    /// </summary>
    public static string Keep(string before, string after, int end, string? inserted, int? max, out int caret) =>
        Keep(before, Splice(before, after, end, inserted), max, out caret);

    private static string Keep(string before, (int Start, int Removed, string Inserted) edit, int? max, out int caret)
    {
        var (start, removed, inserted) = edit;
        var fits = Fit(before, start, removed, inserted, max);
        caret = start + fits.Length;
        return before[..start] + fits + before[(start + removed)..];
    }

    /// <summary>The first <paramref name="count"/> code points of a text.</summary>
    public static string Take(string text, int count)
    {
        var end = 0;
        for (var taken = 0; taken < count && end < text.Length; taken++)
        {
            end += char.IsHighSurrogate(text[end]) && end + 1 < text.Length && char.IsLowSurrogate(text[end + 1]) ? 2 : 1;
        }

        return text[..end];
    }
}
