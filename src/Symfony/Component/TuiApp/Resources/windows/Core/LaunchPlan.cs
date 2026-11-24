using System.Security.Cryptography;

namespace TuiAppHost.Core;

/// <summary>The process the host starts: what, with which arguments, where, and with which environment.</summary>
public sealed record LaunchPlan(string FileName, IReadOnlyList<string> Arguments, string WorkingDirectory, IReadOnlyDictionary<string, string> Environment)
{
    public const string AddressVariable = "TUI_APP_ADDR";
    public const string TokenVariable = "TUI_APP_TOKEN";

    public static string NewToken() => Convert.ToHexString(RandomNumberGenerator.GetBytes(16)).ToLowerInvariant();

    public static string Address(int port) => $"tcp://127.0.0.1:{port}";

    /// <summary>
    /// The plan for a manifest. For mode system, <paramref name="php"/> is the php that was found and checked;
    /// otherwise launch.command, relative to the manifest directory.
    /// </summary>
    public static LaunchPlan For(Manifest manifest, string manifestDirectory, string? php, IReadOnlyDictionary<string, string> inherited, string address, string token, Func<string, bool> fileExists)
    {
        var launch = manifest.Launch;
        string fileName;
        if (launch.Mode == "system")
        {
            fileName = php ?? throw new LaunchException("no php was found");
        }
        else
        {
            var command = launch.Command ?? throw new LaunchException($"launch.command is required in mode {launch.Mode}");
            fileName = WindowsExecutable(Resolve(command, manifestDirectory), fileExists);
        }

        var arguments = launch.Args.Select(arg => arg.StartsWith("./", StringComparison.Ordinal) || arg.StartsWith(".\\", StringComparison.Ordinal) ? Resolve(arg, manifestDirectory) : arg).ToList();
        return new LaunchPlan(fileName, arguments, manifestDirectory, EnvironmentFilter.Apply(inherited, launch.Pass, launch.Set, address, token));
    }

    /// <summary>
    /// The plan of `--dev -- command args…`: the command on PATH, run in the current directory with the
    /// whole environment, as on macOS and Linux (there is no manifest to say what to pass).
    /// </summary>
    public static LaunchPlan Development(string command, IReadOnlyList<string> args, string workingDirectory, IReadOnlyDictionary<string, string> inherited, string address, string token, Func<string, bool> fileExists)
    {
        var fileName = command.IndexOfAny(new[] { '/', '\\' }) >= 0
            ? WindowsExecutable(Resolve(command, workingDirectory), fileExists)
            : Which(command, Lookup(inherited, "PATH"), Lookup(inherited, "PATHEXT"), fileExists) ?? command;
        var environment = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
        foreach (var (name, value) in inherited)
        {
            environment[name] = value;
        }

        environment[AddressVariable] = address;
        environment[TokenVariable] = token;
        return new LaunchPlan(fileName, args.ToList(), workingDirectory, environment);
    }

    /// <summary>Where a bare program name is on PATH, trying each PATHEXT extension as Windows does.</summary>
    public static string? Which(string name, string? path, string? pathExt, Func<string, bool> fileExists)
    {
        var extensions = (pathExt ?? ".COM;.EXE;.BAT;.CMD").Split(';', StringSplitOptions.RemoveEmptyEntries);
        foreach (var directory in (path ?? "").Split(';', StringSplitOptions.RemoveEmptyEntries))
        {
            var candidate = Path.Combine(directory.Trim('"'), name);
            if (Path.HasExtension(name) && fileExists(candidate))
            {
                return candidate;
            }

            foreach (var extension in extensions)
            {
                if (fileExists(candidate + extension.ToLowerInvariant()) || fileExists(candidate + extension))
                {
                    return fileExists(candidate + extension.ToLowerInvariant()) ? candidate + extension.ToLowerInvariant() : candidate + extension;
                }
            }
        }

        return null;
    }

    /// <summary>The php candidates of mode system, in order: launch.php.path, else php on PATH.</summary>
    public static string? FindPhp(Manifest manifest, string manifestDirectory, IReadOnlyDictionary<string, string> inherited, Func<string, bool> fileExists)
    {
        if (manifest.Launch.PhpPath is { } path)
        {
            var resolved = WindowsExecutable(Resolve(path, manifestDirectory), fileExists);
            return fileExists(resolved) ? resolved : null;
        }

        return Which("php", Lookup(inherited, "PATH"), Lookup(inherited, "PATHEXT"), fileExists);
    }

    /// <summary>Whether `php -r 'echo PHP_VERSION;'` printed a version at least <paramref name="minimum"/>.</summary>
    public static bool Satisfies(string actual, string? minimum)
    {
        if (string.IsNullOrWhiteSpace(minimum))
        {
            return Parse(actual) is not null;
        }

        var have = Parse(actual);
        var need = Parse(minimum);
        return have is not null && need is not null && have >= need;
    }

    private static Version? Parse(string version)
    {
        var match = System.Text.RegularExpressions.Regex.Match(version.Trim(), @"^(\d+)(?:\.(\d+))?(?:\.(\d+))?");
        if (!match.Success)
        {
            return null;
        }

        int Part(int i) => match.Groups[i].Success ? int.Parse(match.Groups[i].Value, System.Globalization.CultureInfo.InvariantCulture) : 0;
        return new Version(Part(1), Part(2), Part(3));
    }

    private static string Resolve(string path, string directory) =>
        Path.IsPathRooted(path) || path.Length > 1 && path[1] == ':' ? path : Path.GetFullPath(Path.Combine(directory, path));

    /// <summary>runtime/php is runtime/php.exe on Windows.</summary>
    private static string WindowsExecutable(string path, Func<string, bool> fileExists) =>
        !fileExists(path) && fileExists(path + ".exe") ? path + ".exe" : path;

    private static string? Lookup(IReadOnlyDictionary<string, string> environment, string name) =>
        environment.FirstOrDefault(e => string.Equals(e.Key, name, StringComparison.OrdinalIgnoreCase)).Value;
}

/// <summary>The application's environment: the allowlist, launch.env.pass, then launch.env.set and the transport.</summary>
public static class EnvironmentFilter
{
    public static readonly IReadOnlyList<string> Allowed = new[]
    {
        "PATH", "HOME", "USER", "LANG", "TMPDIR", "TEMP", "TMP", "SystemRoot", "APPDATA", "LOCALAPPDATA", "DISPLAY", "WAYLAND_DISPLAY",
    };

    public static readonly IReadOnlyList<string> AllowedPrefixes = new[] { "LC_", "XDG_" };

    /// <summary>Names compare without case, as Windows does: Path is PATH.</summary>
    public static bool Keeps(string name, IEnumerable<string> pass) =>
        Allowed.Contains(name, StringComparer.OrdinalIgnoreCase)
        || AllowedPrefixes.Any(prefix => name.StartsWith(prefix, StringComparison.OrdinalIgnoreCase))
        || pass.Contains(name, StringComparer.OrdinalIgnoreCase);

    public static IReadOnlyDictionary<string, string> Apply(IReadOnlyDictionary<string, string> inherited, IEnumerable<string> pass, IReadOnlyDictionary<string, string> set, string address, string token)
    {
        var passList = pass.ToList();
        var result = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
        foreach (var (name, value) in inherited)
        {
            if (Keeps(name, passList))
            {
                result[name] = value;
            }
        }

        foreach (var (name, value) in set)
        {
            result[name] = value;
        }

        result[LaunchPlan.AddressVariable] = address;
        result[LaunchPlan.TokenVariable] = token;
        return result;
    }
}

public sealed class LaunchException(string message) : Exception(message);
