using System.Globalization;
using System.Text.Json.Nodes;

namespace TuiAppHost.Core;

/// <summary>
/// The values of number and slider, as HTML steps them: by step from min (0 without one), within min and max,
/// written with as many decimals as the step has, trailing zeros dropped. As the macOS host's Stepping.
/// </summary>
public static class Stepping
{
    /// <summary>The props of a node: min and max (null: none), step (1 when absent, null or not positive).</summary>
    public static (double? Min, double? Max, double Step) Range(Node node)
    {
        var step = node.Number("step") ?? 1;
        return (node.Number("min"), node.Number("max"), step > 0 && double.IsFinite(step) ? step : 1);
    }

    /// <summary>
    /// Up (1) or Down (-1) in a number field showing <paramref name="text"/>: a value off the steps goes to the
    /// nearest step that way, one on a step to the next; an empty or invalid value is 0. A value the bounds would
    /// move the other way, like Up from above max, is kept as it is.
    /// </summary>
    public static string Step(string text, int direction, double? min, double? max, double step)
    {
        var current = double.TryParse(text.Trim(), NumberStyles.Float, CultureInfo.InvariantCulture, out var parsed) && double.IsFinite(parsed) ? parsed : 0;
        var origin = min ?? 0;
        var steps = (current - origin) / step;
        var target = Math.Abs(steps - Math.Round(steps)) < 1e-9 ? Math.Round(steps) + direction : direction > 0 ? Math.Floor(steps) + 1 : Math.Ceiling(steps) - 1;
        var next = origin + target * step;
        if (min is { } low)
        {
            next = Math.Max(low, next);
        }

        // Past max: the highest step at or below it, like HTML
        if (max is { } high)
        {
            next = Math.Min(origin + Math.Floor(((high - origin) / step) + 1e-9) * step, next);
        }

        // Like HTML's stepUp and stepDown: no change when the bounds would take the value the other way, like Up from
        // above max
        if (direction > 0 ? next < current : next > current)
        {
            return text;
        }

        return Format(next, step);
    }

    /// <summary>A slider's value: on a step from min, within min and max (the highest step below max).</summary>
    public static double Snap(double value, double min, double max, double step)
    {
        var top = Math.Max(min, max);
        var snapped = Math.Max(min, Math.Min(top, double.IsFinite(value) ? value : min));
        snapped = min + Math.Round((snapped - min) / step, MidpointRounding.AwayFromZero) * step;
        while (snapped > top + 1e-9)
        {
            snapped -= step;
        }

        return Math.Round(snapped * 1e10) / 1e10;
    }

    /// <summary>A value with the decimals of the step, trailing zeros dropped, -0 as 0.</summary>
    public static string Format(double value, double step)
    {
        var written = step.ToString("0.###############", CultureInfo.InvariantCulture);
        var dot = written.IndexOf('.');
        var decimals = dot < 0 ? 0 : written.Length - dot - 1;
        var text = value.ToString("F" + decimals, CultureInfo.InvariantCulture);
        if (decimals > 0)
        {
            text = text.TrimEnd('0').TrimEnd('.');
        }

        return text == "-0" ? "0" : text;
    }

    /// <summary>A slider's value as JSON: an integer when it is one.</summary>
    public static JsonNode Json(double value) =>
        Math.Round(value) == value && Math.Abs(value) < 9e15 ? JsonValue.Create((long)value) : JsonValue.Create(value);
}
