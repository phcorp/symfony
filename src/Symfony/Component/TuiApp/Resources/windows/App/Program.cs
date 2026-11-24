using System;
using System.Collections.Generic;
using System.IO;
using System.IO.Pipes;
using System.Linq;
using System.Security.AccessControl;
using System.Security.Cryptography;
using System.Security.Principal;
using System.Text.Json;
using System.Threading;
using System.Threading.Tasks;
using System.Windows;
using TuiAppHost.Core;

namespace TuiAppHost;

public static class Program
{
    [STAThread]
    public static int Main(string[] args)
    {
        HostOptions options;
        try
        {
            options = HostOptions.Parse(args);
        }
        catch (ArgumentException exception)
        {
            return Error(null, exception.Message, selfTest: args.Contains("--self-test"));
        }

        if (options.SelfTest || options.FeatureTest)
        {
            Native.ConsoleForOutput();
        }

        Manifest manifest;
        string manifestDirectory;
        if (options.DevCommand is { } dev)
        {
            manifest = Manifest.Development(dev[0], dev.Skip(1).ToList());
            manifestDirectory = Environment.CurrentDirectory;
        }
        else
        {
            var path = options.ManifestPath ?? Path.Combine(AppContext.BaseDirectory, Manifest.FileName);
            try
            {
                manifest = Manifest.Parse(File.ReadAllText(path));
                manifestDirectory = Path.GetDirectoryName(Path.GetFullPath(path))!;
            }
            catch (Exception exception) when (exception is IOException or UnauthorizedAccessException or ManifestException or JsonException or InvalidOperationException)
            {
                return Error(null, $"The application's manifest {path} cannot be read: {exception.Message}", options.SelfTest);
            }
        }

        using var instance = manifest.App.SingleInstance && !options.SelfTest ? SingleInstance.Claim(manifest.App.Id) : null;
        if (instance is { IsFirst: false })
        {
            // A second launch hands its paths to the first and leaves.
            instance.HandOver(HostOptions.OpenPaths(options.Paths, Environment.CurrentDirectory));
            return 0;
        }

        var log = new Log(Log.For(manifest, manifestDirectory), manifest.Logging.Level, echo: false);
        var application = new Application { ShutdownMode = ShutdownMode.OnMainWindowClose };
        var exitCode = 0;
        application.DispatcherUnhandledException += (_, e) =>
        {
            log.Write("error", "unhandled: " + e.Exception);
            if (options.FeatureTest)
            {
                Console.Out.WriteLine("feature-test: FAILED: unhandled " + e.Exception);
                Console.Out.Flush();
                e.Handled = true;
                application.Shutdown(1);
            }
            else if (options.SelfTest)
            {
                Console.Out.WriteLine("self-test: FAIL: unhandled " + e.Exception);
                Console.Out.Flush();
                e.Handled = true;
                application.Shutdown(1);
            }
        };
        application.Exit += (_, e) => exitCode = e.ApplicationExitCode;
        application.Startup += (_, _) =>
        {
            var window = new HostWindow(manifest, manifestDirectory, options, log);
            application.MainWindow = window;
            if (options.CanvasTest)
            {
                new CanvasTest(window, application).Start();
            }
            else if (options.FeatureTest)
            {
                new FeatureTest(window, application, manifest, options).Start();
            }
            else if (options.SelfTest)
            {
                new SelfTest(window, application).Start();
            }

            instance?.Listen(paths => application.Dispatcher.BeginInvoke(() =>
            {
                window.Open(paths);
                if (window.WindowState == WindowState.Minimized)
                {
                    window.WindowState = WindowState.Normal;
                }

                window.Activate();
            }));
            window.Show();
        };
        application.Run();
        return exitCode;
    }

    private static int Error(string? title, string message, bool selfTest)
    {
        if (selfTest)
        {
            Native.ConsoleForOutput();
            Console.Out.WriteLine("self-test: FAIL: " + message);
        }
        else
        {
            MessageBox.Show(message, title ?? "TuiApp", MessageBoxButton.OK, MessageBoxImage.Error);
        }

        return 1;
    }
}

/// <summary>
/// One instance per user and application: a named mutex, and a named pipe to hand paths over. Pipe
/// names are seen by every user of the computer, so the pipe's is random, and the first instance keeps
/// it in the user's local application data, which other users cannot read: nobody else can create the
/// pipe first and receive the paths and URLs (which may hold tokens) meant for this user. Pipe names can
/// still be listed, so the client also refuses a pipe that another user owns.
/// </summary>
public sealed class SingleInstance : IDisposable
{
    private readonly Mutex mutex;
    private readonly string nameFile;

    private SingleInstance(Mutex mutex, bool isFirst, string nameFile)
    {
        this.mutex = mutex;
        IsFirst = isFirst;
        this.nameFile = nameFile;
    }

    public bool IsFirst { get; }

    public static SingleInstance Claim(string appId)
    {
        var user = WindowsIdentity.GetCurrent().User!.Value;
        var mutex = new Mutex(true, $@"Local\tuiapp-{appId}-{user}", out var first);
        var folder = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), appId);
        return new SingleInstance(mutex, first, Path.Combine(folder, "instance-pipe"));
    }

    public void HandOver(IReadOnlyList<string> paths)
    {
        try
        {
            // The first instance may still be writing the name.
            string? pipeName = null;
            for (var attempt = 0; attempt < 30 && pipeName is null; attempt++)
            {
                try
                {
                    pipeName = File.ReadAllText(nameFile).Trim();
                }
                catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
                {
                    Thread.Sleep(100);
                }
            }

            if (string.IsNullOrEmpty(pipeName))
            {
                return;
            }

            // CurrentUserOnly: .NET checks that the pipe's owner is this user. Pipe names can be listed by
            // anyone, so a pipe another user made under this name in a gap between two server
            // instances is refused rather than handed the paths.
            using var pipe = new NamedPipeClientStream(".", pipeName, PipeDirection.Out, PipeOptions.CurrentUserOnly);
            pipe.Connect(3000);
            using var writer = new StreamWriter(pipe);
            writer.WriteLine(JsonSerializer.Serialize(paths));
        }
        catch (Exception exception) when (exception is TimeoutException or IOException or UnauthorizedAccessException)
        {
            // The first instance is closing: this launch is lost, as a double click on a closing app is.
        }
    }

    public void Listen(Action<IReadOnlyList<string>> received)
    {
        var security = new PipeSecurity();
        security.AddAccessRule(new PipeAccessRule(WindowsIdentity.GetCurrent().User!, PipeAccessRights.FullControl, AccessControlType.Allow));
        var pipeName = "tuiapp-" + Convert.ToHexString(RandomNumberGenerator.GetBytes(16)).ToLowerInvariant();
        Directory.CreateDirectory(Path.GetDirectoryName(nameFile)!);
        File.WriteAllText(nameFile, pipeName);
        Task.Run(() =>
        {
            while (true)
            {
                try
                {
                    using var pipe = NamedPipeServerStreamAcl.Create(pipeName, PipeDirection.In, 1, PipeTransmissionMode.Byte, PipeOptions.FirstPipeInstance, 4096, 4096, security);
                    pipe.WaitForConnection();
                    using var reader = new StreamReader(pipe);
                    var paths = JsonSerializer.Deserialize<List<string>>(reader.ReadLine() ?? "[]") ?? new List<string>();
                    received(paths);
                }
                catch (Exception exception) when (exception is IOException or JsonException or UnauthorizedAccessException)
                {
                    Thread.Sleep(100);
                }
            }
        });
    }

    public void Dispose()
    {
        if (IsFirst)
        {
            mutex.ReleaseMutex();
        }

        mutex.Dispose();
    }
}
