using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Linq;
using System.Text;
using TuiAppHost.Core;

namespace TuiAppHost;

/// <summary>The application's process: started from a launch plan, its output in the log, its last stderr lines kept.</summary>
public sealed class AppProcess : IDisposable
{
    private const int KeptLines = 30;
    private readonly Process process;
    private readonly Log log;
    private readonly Queue<string> lastErrors = new();

    private AppProcess(Process process, Log log)
    {
        this.process = process;
        this.log = log;
    }

    public event Action<int>? Exited;

    public int Id => process.Id;

    public bool HasExited
    {
        get
        {
            try
            {
                return process.HasExited;
            }
            catch (InvalidOperationException)
            {
                return true;
            }
        }
    }

    public string LastErrors
    {
        get
        {
            lock (lastErrors)
            {
                return string.Join(Environment.NewLine, lastErrors);
            }
        }
    }

    public static AppProcess Start(LaunchPlan plan, Log log)
    {
        var start = new ProcessStartInfo(plan.FileName)
        {
            UseShellExecute = false,
            CreateNoWindow = true,
            RedirectStandardInput = true,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            StandardOutputEncoding = Encoding.UTF8,
            StandardErrorEncoding = Encoding.UTF8,
            WorkingDirectory = plan.WorkingDirectory,
        };
        foreach (var argument in plan.Arguments)
        {
            start.ArgumentList.Add(argument);
        }

        start.Environment.Clear();
        foreach (var (name, value) in plan.Environment)
        {
            start.Environment[name] = value;
        }

        log.Write("info", $"starting {plan.FileName} {string.Join(" ", plan.Arguments)} in {plan.WorkingDirectory} with {string.Join(", ", plan.Environment.Keys.OrderBy(k => k))}");
        var process = new Process { StartInfo = start, EnableRaisingEvents = true };
        var app = new AppProcess(process, log);
        process.OutputDataReceived += (_, e) =>
        {
            if (e.Data is not null)
            {
                log.Write("out", e.Data);
            }
        };
        process.ErrorDataReceived += (_, e) =>
        {
            if (e.Data is null)
            {
                return;
            }

            log.Write("err", e.Data);
            lock (app.lastErrors)
            {
                app.lastErrors.Enqueue(e.Data);
                while (app.lastErrors.Count > KeptLines)
                {
                    app.lastErrors.Dequeue();
                }
            }
        };
        process.Exited += (_, _) =>
        {
            // The output readers finish first: the last stderr lines are in.
            process.WaitForExit();
            var code = process.ExitCode;
            log.Write("info", $"the application exited with {code}");
            app.Exited?.Invoke(code);
        };
        process.Start();
        // Nothing reads the application's stdin: it ends at once, as for a GUI launch.
        process.StandardInput.Close();
        process.BeginOutputReadLine();
        process.BeginErrorReadLine();
        return app;
    }

    /// <summary>`php -r 'echo PHP_VERSION;'`, or null when it does not run.</summary>
    public static string? PhpVersion(string php)
    {
        try
        {
            var start = new ProcessStartInfo(php) { UseShellExecute = false, CreateNoWindow = true, RedirectStandardOutput = true, RedirectStandardError = true };
            start.ArgumentList.Add("-r");
            start.ArgumentList.Add("echo PHP_VERSION;");
            using var process = Process.Start(start)!;
            var output = process.StandardOutput.ReadToEndAsync();
            if (!process.WaitForExit(10_000))
            {
                process.Kill(true);
                return null;
            }

            return process.ExitCode == 0 ? output.Result.Trim() : null;
        }
        catch (Exception exception) when (exception is System.ComponentModel.Win32Exception or InvalidOperationException or IOException)
        {
            return null;
        }
    }

    public void Kill()
    {
        try
        {
            if (!process.HasExited)
            {
                log.Write("warning", "killing the application");
                process.Kill(entireProcessTree: true);
            }
        }
        catch (Exception exception) when (exception is InvalidOperationException or System.ComponentModel.Win32Exception)
        {
        }
    }

    public void Dispose() => process.Dispose();
}

/// <summary>The application's output and the host's own lines: logging.file, else %LOCALAPPDATA%\&lt;id&gt;\logs\app.log.</summary>
public sealed class Log
{
    private static readonly string[] Levels = { "debug", "info", "warning", "error" };
    private readonly object writing = new();
    private readonly int threshold;
    private readonly bool echo;

    public Log(string path, string level, bool echo)
    {
        Path = path;
        threshold = Math.Max(0, Array.IndexOf(Levels, level));
        this.echo = echo;
        try
        {
            Directory.CreateDirectory(System.IO.Path.GetDirectoryName(path)!);
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
        {
        }
    }

    public string Path { get; }

    public static string For(Manifest manifest, string manifestDirectory) =>
        manifest.Logging.File is { } file
            ? System.IO.Path.GetFullPath(System.IO.Path.Combine(manifestDirectory, file))
            : System.IO.Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), manifest.App.Id, "logs", "app.log");

    /// <param name="level">debug, info, warning, error; out and err are the application's own lines, always kept</param>
    public void Write(string level, string line)
    {
        var index = Array.IndexOf(Levels, level);
        if (index >= 0 && index < threshold)
        {
            return;
        }

        var text = $"{DateTime.Now:yyyy-MM-dd HH:mm:ss.fff} [{level}] {line}";
        lock (writing)
        {
            try
            {
                File.AppendAllText(Path, text + Environment.NewLine);
            }
            catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
            {
            }

            if (echo)
            {
                Console.Out.WriteLine(text);
                Console.Out.Flush();
            }
        }
    }
}
