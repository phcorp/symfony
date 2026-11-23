namespace TuiAppHost.Core;

/// <summary>
/// The host's command line: [--self-test | --canvas-test | --feature-test] [--manifest &lt;file&gt;] [paths or URLs…] [--dev -- command args…],
/// or [-- paths or URLs…], which is how file and URL handlers start it.
/// Paths and URLs go to the application as open. --canvas-test is a self-test too: failures are
/// printed, not shown in dialogs. --feature-test is not: it answers the dialogs, as a user would.
/// </summary>
public sealed record HostOptions(bool SelfTest, string? ManifestPath, IReadOnlyList<string>? DevCommand, IReadOnlyList<string> Paths, bool CanvasTest = false, bool FeatureTest = false)
{
    public static HostOptions Parse(IReadOnlyList<string> args)
    {
        var selfTest = false;
        var canvasTest = false;
        var featureTest = false;
        string? manifest = null;
        List<string>? dev = null;
        var paths = new List<string>();
        for (var i = 0; i < args.Count; i++)
        {
            switch (args[i])
            {
                case "--self-test":
                    selfTest = true;
                    break;
                case "--canvas-test":
                    selfTest = true;
                    canvasTest = true;
                    break;
                case "--feature-test":
                    featureTest = true;
                    break;
                case "--manifest" when i + 1 < args.Count:
                    manifest = args[++i];
                    break;
                case "--":
                    // The end of the options: what follows is a path or URL, even when it looks like
                    // an option. File and URL handlers register "<exe>" -- "%1" for this reason.
                    paths.AddRange(args.Skip(i + 1));
                    i = args.Count;
                    break;
                case "--dev":
                    var start = i + 1 < args.Count && args[i + 1] == "--" ? i + 2 : i + 1;
                    dev = args.Skip(start).ToList();
                    if (dev.Count == 0)
                    {
                        throw new ArgumentException("--dev needs a command: --dev -- php app.php");
                    }

                    i = args.Count;
                    break;
                default:
                    paths.Add(args[i]);
                    break;
            }
        }

        return new HostOptions(selfTest, manifest, dev, paths, canvasTest, featureTest);
    }

    /// <summary>
    /// Files made absolute against the directory the host was started in; URLs (scheme://…, or
    /// scheme:… with a scheme longer than a drive letter) as they are.
    /// </summary>
    public static IReadOnlyList<string> OpenPaths(IEnumerable<string> paths, string workingDirectory) =>
        paths.Select(path => IsUrl(path) ? path :Path.GetFullPath(Path.Combine(workingDirectory, path))).ToList();

    public static bool IsUrl(string value)
    {
        var colon = value.IndexOf(':');
        return colon > 1 && value[..colon].All(c => char.IsAsciiLetterOrDigit(c) || c is '+' or '-' or '.') && char.IsAsciiLetter(value[0]);
    }
}
