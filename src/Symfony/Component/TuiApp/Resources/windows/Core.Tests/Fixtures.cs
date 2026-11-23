using System.Text.Json.Nodes;

namespace TuiAppHost.Core.Tests;

internal static class Fixtures
{
    public static JsonNode Load(string name) =>
        JsonNode.Parse(File.ReadAllText(Path.Combine(AppContext.BaseDirectory, "fixtures", name + ".json")))!;

    /// <summary>A file of the contract copied next to the tests, like protocol.json.</summary>
    public static JsonNode LoadFile(string name) =>
        JsonNode.Parse(File.ReadAllText(Path.Combine(AppContext.BaseDirectory, name)))!;

    public static IReadOnlyDictionary<string, JsonObject> Messages { get; } = ((JsonArray)Load("messages"))
        .Select(entry => (JsonObject)entry!)
        .ToDictionary(entry => entry["name"]!.GetValue<string>(), entry => (JsonObject)entry["message"]!);

    public static IEnumerable<object[]> MessageNames() => Messages.Keys.Select(name => new object[] { name });

    /// <summary>JSON trees compared as parsed: objects without key order, numbers by value, null distinct from absent.</summary>
    public static bool JsonEquals(JsonNode? a, JsonNode? b, out string where, string path = "$")
    {
        where = path;
        switch (a, b)
        {
            case (null, null):
                return true;
            case (JsonObject x, JsonObject y):
                if (!x.Select(p => p.Key).OrderBy(k => k, StringComparer.Ordinal).SequenceEqual(y.Select(p => p.Key).OrderBy(k => k, StringComparer.Ordinal)))
                {
                    where = path + " keys {" + string.Join(",", x.Select(p => p.Key)) + "} vs {" + string.Join(",", y.Select(p => p.Key)) + "}";
                    return false;
                }

                foreach (var (key, value) in x)
                {
                    if (!JsonEquals(value, y[key], out where, path + "." + key))
                    {
                        return false;
                    }
                }

                return true;
            case (JsonArray x, JsonArray y):
                if (x.Count != y.Count)
                {
                    where = path + " length";
                    return false;
                }

                for (var i = 0; i < x.Count; i++)
                {
                    if (!JsonEquals(x[i], y[i], out where, $"{path}[{i}]"))
                    {
                        return false;
                    }
                }

                return true;
            case (JsonValue x, JsonValue y):
                var kx = x.GetValueKind();
                var ky = y.GetValueKind();
                if (kx != ky)
                {
                    where = $"{path}: {kx} vs {ky}";
                    return false;
                }

                var equal = kx == System.Text.Json.JsonValueKind.Number
                    ? decimal.Parse(x.ToJsonString(), System.Globalization.NumberStyles.Float, System.Globalization.CultureInfo.InvariantCulture)
                        == decimal.Parse(y.ToJsonString(), System.Globalization.NumberStyles.Float, System.Globalization.CultureInfo.InvariantCulture)
                    : x.ToJsonString() == y.ToJsonString();
                where = $"{path}: {x.ToJsonString()} vs {y.ToJsonString()}";
                return equal;
            default:
                where = $"{path}: {a?.ToJsonString() ?? "null"} vs {b?.ToJsonString() ?? "null"}";
                return false;
        }
    }

    public static void AssertJsonEqual(JsonNode? expected, JsonNode? actual)
    {
        Xunit.Assert.True(JsonEquals(expected, actual, out var where), "JSON differs at " + where);
    }
}
