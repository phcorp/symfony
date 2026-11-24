using Xunit;

namespace TuiAppHost.Core.Tests;

public class LaunchTests
{
    private static Manifest FixtureManifest() => Manifest.Parse(Fixtures.Load("manifest").ToJsonString());

    [Fact]
    public void ParsesTheManifestFixture()
    {
        var manifest = FixtureManifest();
        Assert.Equal("Acme Notes", manifest.App.Name);
        Assert.Equal("com.acme.notes", manifest.App.Id);
        Assert.Equal("1.2.0", manifest.App.Version);
        Assert.Equal("Acme", manifest.App.Publisher);
        Assert.Equal("© 2026 Acme", manifest.App.Copyright);
        Assert.True(manifest.App.SingleInstance);
        Assert.Equal("productivity", manifest.App.Category);
        Assert.Equal(new[] { new FileAssociation("acme", "Acme note", null, "editor") }, manifest.App.FileAssociations);
        Assert.Equal(new[] { "acme" }, manifest.App.UrlSchemes);
        Assert.Equal(900, manifest.Window.Width);
        Assert.Equal(480, manifest.Window.MinWidth);
        Assert.True(manifest.Window.RememberGeometry);
        Assert.Equal("system", manifest.Window.Theme);
        Assert.Null(manifest.Window.FontFamily);
        Assert.Equal(13, manifest.Window.FontSize);
        Assert.Equal(new MenuSettings(true, true, true), manifest.Menu);
        Assert.Equal("embedded", manifest.Launch.Mode);
        Assert.Equal("runtime/php", manifest.Launch.Command);
        Assert.Equal(new[] { "-n", "-c", "./runtime/php.ini", "./app/app.phar" }, manifest.Launch.Args);
        Assert.Equal(new[] { "LANG" }, manifest.Launch.Pass);
        Assert.Equal("prod", manifest.Launch.Set["APP_ENV"]);
        Assert.Null(manifest.Logging.File);
        Assert.Equal("warning", manifest.Logging.Level);
    }

    [Theory]
    [InlineData("{}")]
    [InlineData("{\"manifest\":2}")]
    [InlineData("[]")]
    public void RejectsWhatIsNotAManifest(string json)
    {
        Assert.Throws<ManifestException>(() => Manifest.Parse(json));
    }

    [Fact]
    public void RejectsABadAppId()
    {
        var json = Fixtures.Load("manifest").ToJsonString().Replace("com.acme.notes", "notes");
        Assert.Throws<ManifestException>(() => Manifest.Parse(json));
    }

    [Fact]
    public void EmbeddedPlanResolvesAgainstTheManifestDirectory()
    {
        var dir = Path.Combine(Path.GetTempPath(), "acme");
        var exe = Path.GetFullPath(Path.Combine(dir, "runtime/php")) + ".exe";
        var plan = LaunchPlan.For(FixtureManifest(), dir, null, Inherited(), "tcp://127.0.0.1:5000", "ab", path => path == exe);
        Assert.Equal(exe, plan.FileName);
        Assert.Equal(new[] { "-n", "-c", Path.GetFullPath(Path.Combine(dir, "runtime/php.ini")), Path.GetFullPath(Path.Combine(dir, "app/app.phar")) }, plan.Arguments);
        Assert.Equal(dir, plan.WorkingDirectory);
        Assert.Equal("prod", plan.Environment["APP_ENV"]);
        Assert.Equal("tcp://127.0.0.1:5000", plan.Environment["TUI_APP_ADDR"]);
        Assert.Equal("ab", plan.Environment["TUI_APP_TOKEN"]);
    }

    [Fact]
    public void EnvironmentKeepsTheAllowlistAndPass()
    {
        var env = EnvironmentFilter.Apply(Inherited(), new[] { "LANG", "Keep_Me" }, new Dictionary<string, string> { ["APP_ENV"] = "prod", ["PATH"] = "x" }, "addr", "tok");
        Assert.Equal(
            new[] { "APP_ENV", "APPDATA", "keep_me", "LANG", "LC_ALL", "LOCALAPPDATA", "Path", "SystemRoot", "TEMP", "TMP", "TUI_APP_ADDR", "TUI_APP_TOKEN", "XDG_CONFIG_HOME" }.OrderBy(k => k, StringComparer.OrdinalIgnoreCase),
            env.Keys.OrderBy(k => k, StringComparer.OrdinalIgnoreCase));
        // set wins over the inherited value, whatever the case of its name.
        Assert.Equal("x", env["PATH"]);
        Assert.False(env.ContainsKey("SECRET"));
        Assert.False(env.ContainsKey("TUI_APP_TOKEN_OLD"));
    }

    [Fact]
    public void DevelopmentFindsTheCommandOnPath()
    {
        var plan = LaunchPlan.Development("php", new[] { @"tools\fake-app.php" }, @"C:\work", new Dictionary<string, string>
        {
            ["Path"] = @"C:\Windows;C:\php",
            ["PATHEXT"] = ".COM;.EXE",
        }, "addr", "tok", path => path == Path.Combine(@"C:\php", "php.exe"));
        Assert.Equal(Path.Combine(@"C:\php", "php.exe"), plan.FileName);
        Assert.Equal(new[] { @"tools\fake-app.php" }, plan.Arguments);
        Assert.Equal("tok", plan.Environment["TUI_APP_TOKEN"]);
    }

    [Fact]
    public void DevelopmentKeepsTheWholeEnvironment()
    {
        var plan = LaunchPlan.Development("php", new[] { @"tools\fake-app.php" }, @"C:\work", new Dictionary<string, string>
        {
            ["Path"] = @"C:\php",
            ["FAKE_APP_CANVAS"] = "1",
            ["TUI_APP_TOKEN"] = "inherited",
        }, "addr", "tok", path => path == Path.Combine(@"C:\php", "php.exe"));
        Assert.Equal("1", plan.Environment["FAKE_APP_CANVAS"]);
        Assert.Equal(@"C:\php", plan.Environment["PATH"]);
        Assert.Equal("tok", plan.Environment["TUI_APP_TOKEN"]);
        Assert.Equal("addr", plan.Environment["TUI_APP_ADDR"]);
    }

    [Theory]
    [InlineData("8.3.12", "8.2", true)]
    [InlineData("8.2.0", "8.2", true)]
    [InlineData("8.1.30", "8.2", false)]
    [InlineData("8.4.0-dev", "8.4.0", true)]
    [InlineData("garbage", "8.2", false)]
    [InlineData("8.0.0", null, true)]
    public void ChecksThePhpVersion(string actual, string? minimum, bool expected)
    {
        Assert.Equal(expected, LaunchPlan.Satisfies(actual, minimum));
    }

    [Fact]
    public void SystemModeUsesPhpPathElsePath()
    {
        var json = Fixtures.Load("manifest").ToJsonString()
            .Replace("\"mode\": \"embedded\"", "\"mode\": \"system\"").Replace("\"mode\":\"embedded\"", "\"mode\":\"system\"");
        var manifest = Manifest.Parse(json);
        Assert.Equal("system", manifest.Launch.Mode);
        var inherited = new Dictionary<string, string> { ["PATH"] = "/opt/php" };
        Assert.Equal(Path.Combine("/opt/php", "php.exe"), LaunchPlan.FindPhp(manifest, "/app", inherited, p => p == Path.Combine("/opt/php", "php.exe")));
        Assert.Null(LaunchPlan.FindPhp(manifest, "/app", inherited, _ => false));
        Assert.Throws<LaunchException>(() => LaunchPlan.For(manifest, "/app", null, inherited, "a", "t", _ => false));
    }

    [Fact]
    public void TokensAre32LowercaseHexDigits()
    {
        var token = LaunchPlan.NewToken();
        Assert.Matches("^[0-9a-f]{32}$", token);
        Assert.NotEqual(token, LaunchPlan.NewToken());
    }

    [Fact]
    public void ParsesTheCommandLine()
    {
        var options = HostOptions.Parse(new[] { "--self-test", "notes.acme", "--dev", "--", "php", "tools/fake-app.php", "--self-test" });
        Assert.True(options.SelfTest);
        Assert.Equal(new[] { "php", "tools/fake-app.php", "--self-test" }, options.DevCommand);
        Assert.Equal(new[] { "notes.acme" }, options.Paths);
        Assert.False(options.CanvasTest);

        var canvas = HostOptions.Parse(new[] { "--canvas-test", "--dev", "--", "php", "tools/fake-app.php" });
        Assert.True(canvas.CanvasTest);
        Assert.True(canvas.SelfTest, "the canvas test prints its failures like the self-test");

        var manifest = HostOptions.Parse(new[] { "--manifest", "app.manifest.json", "acme://open?id=42" });
        Assert.Equal("app.manifest.json", manifest.ManifestPath);
        Assert.Null(manifest.DevCommand);
        Assert.Equal(new[] { "acme://open?id=42" }, HostOptions.OpenPaths(manifest.Paths, "/home/ada"));
        Assert.Equal(new[] { Path.GetFullPath("/home/ada/draft.acme") }, HostOptions.OpenPaths(new[] { "draft.acme" }, "/home/ada"));
        Assert.False(HostOptions.IsUrl(@"C:\notes\draft.acme"));
        Assert.True(HostOptions.IsUrl("mailto:ada@example.com"));
    }

    [Fact]
    public void WhatFollowsTheEndOfTheOptionsIsAPath()
    {
        // A URL that broke out of the handler's quotes: "acme:x" --dev -- calc.exe
        var options = HostOptions.Parse(new[] { "--", "acme:x", "--dev", "--", "calc.exe", "--manifest", "evil.json" });
        Assert.Null(options.DevCommand);
        Assert.Null(options.ManifestPath);
        Assert.False(options.SelfTest);
        Assert.Equal(new[] { "acme:x", "--dev", "--", "calc.exe", "--manifest", "evil.json" }, options.Paths);
    }

    private static Dictionary<string, string> Inherited() => new()
    {
        ["Path"] = @"C:\Windows",
        ["SystemRoot"] = @"C:\Windows",
        ["APPDATA"] = "a",
        ["LOCALAPPDATA"] = "l",
        ["TEMP"] = "t",
        ["TMP"] = "t",
        ["LANG"] = "fr_FR.UTF-8",
        ["LC_ALL"] = "C",
        ["XDG_CONFIG_HOME"] = "x",
        ["keep_me"] = "1",
        ["SECRET"] = "s",
        ["TUI_APP_TOKEN_OLD"] = "old",
    };
}
