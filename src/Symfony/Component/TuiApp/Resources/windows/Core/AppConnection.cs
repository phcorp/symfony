using System.Net;
using System.Net.Sockets;
using System.Security.Cryptography;
using System.Text;

namespace TuiAppHost.Core;

/// <summary>
/// The loopback socket the application connects to. The first frame of a connection must be auth with
/// the token and protocol 1, or that connection is closed and the next one awaited.
/// </summary>
public sealed class AppConnection : IDisposable
{
    public static readonly TimeSpan AcceptTime = TimeSpan.FromSeconds(10);

    private readonly TcpListener listener;
    private readonly object writing = new();
    private TcpClient? client;
    private NetworkStream? stream;

    private AppConnection(TcpListener listener)
    {
        this.listener = listener;
    }

    public int Port => ((IPEndPoint)listener.LocalEndpoint).Port;

    public string Address => LaunchPlan.Address(Port);

    public bool IsConnected => stream is not null;

    /// <summary>Connections refused so far, and why: for the log.</summary>
    public List<string> Refused { get; } = new();

    public static AppConnection Listen()
    {
        var listener = new TcpListener(IPAddress.Loopback, 0);
        listener.Start(4);
        return new AppConnection(listener);
    }

    public static bool IsValidAuth(Message message, string token) =>
        message is AuthMessage auth
        && auth.Protocol == 1
        && CryptographicOperations.FixedTimeEquals(Encoding.UTF8.GetBytes(auth.Token), Encoding.UTF8.GetBytes(token));

    /// <summary>How long a connection has to send its auth frame, and how large that frame may be.</summary>
    public static readonly TimeSpan AuthTime = TimeSpan.FromSeconds(5);
    public const int MaxAuthBytes = 4096;

    /// <summary>
    /// Waits for an authenticated connection; false when none came in time. Candidates are read at the
    /// same time, each for <see cref="AuthTime"/> at most: a connection that never speaks cannot keep
    /// the application's own out.
    /// </summary>
    public async Task<bool> AcceptAsync(string token, TimeSpan timeout, CancellationToken cancellation = default)
    {
        using var deadline = CancellationTokenSource.CreateLinkedTokenSource(cancellation);
        deadline.CancelAfter(timeout);
        var winner = new TaskCompletionSource<(TcpClient Client, NetworkStream Stream)>(TaskCreationOptions.RunContinuationsAsynchronously);
        var candidates = new List<Task>();
        var accepting = AcceptCandidatesAsync(token, winner, candidates, deadline.Token);
        var done = await Task.WhenAny(winner.Task, accepting, Task.Delay(Timeout.Infinite, deadline.Token)).ConfigureAwait(false);
        if (done == winner.Task)
        {
            (client, stream) = winner.Task.Result;
            listener.Stop();
            deadline.Cancel();
            await Task.WhenAll(candidates.Append(accepting).Select(t => t.ContinueWith(_ => { }, TaskScheduler.Default))).ConfigureAwait(false);
            return true;
        }

        deadline.Cancel();
        await Task.WhenAll(candidates.Append(accepting).Select(t => t.ContinueWith(_ => { }, TaskScheduler.Default))).ConfigureAwait(false);
        if (winner.Task.IsCompletedSuccessfully)
        {
            (client, stream) = winner.Task.Result;
            listener.Stop();
            return true;
        }

        return false;
    }

    private async Task AcceptCandidatesAsync(string token, TaskCompletionSource<(TcpClient, NetworkStream)> winner, List<Task> candidates, CancellationToken cancellation)
    {
        while (!winner.Task.IsCompleted)
        {
            TcpClient candidate;
            try
            {
                candidate = await listener.AcceptTcpClientAsync(cancellation).ConfigureAwait(false);
            }
            catch (Exception exception) when (exception is OperationCanceledException or ObjectDisposedException or SocketException)
            {
                return;
            }

            lock (candidates)
            {
                candidates.Add(CheckCandidateAsync(candidate, token, winner, cancellation));
            }
        }
    }

    private async Task CheckCandidateAsync(TcpClient candidate, string token, TaskCompletionSource<(TcpClient, NetworkStream)> winner, CancellationToken cancellation)
    {
        candidate.NoDelay = true;
        var candidateStream = candidate.GetStream();
        using var limit = CancellationTokenSource.CreateLinkedTokenSource(cancellation);
        limit.CancelAfter(AuthTime);
        string reason;
        try
        {
            var first = await FrameCodec.ReadAsync(candidateStream, limit.Token, MaxAuthBytes).ConfigureAwait(false);
            if (first is not null && IsValidAuth(MessageCodec.Parse(first), token) && winner.TrySetResult((candidate, candidateStream)))
            {
                return;
            }

            reason = first is null ? "closed before auth" : "first frame was not a valid auth";
        }
        catch (OperationCanceledException)
        {
            reason = cancellation.IsCancellationRequested ? "still waiting for auth when the wait ended" : $"no auth within {AuthTime.TotalSeconds} s";
        }
        catch (Exception exception) when (exception is FrameException or MessageException or System.Text.Json.JsonException or IOException or InvalidOperationException or ObjectDisposedException)
        {
            reason = exception.Message;
        }

        lock (Refused)
        {
            Refused.Add(reason);
        }

        candidate.Dispose();
    }

    public void Send(Message message)
    {
        var frame = MessageCodec.Frame(message);
        lock (writing)
        {
            if (stream is null)
            {
                return;
            }

            try
            {
                stream.Write(frame);
            }
            catch (IOException)
            {
                // The application is gone: its exit is handled where the process is watched.
            }
            catch (ObjectDisposedException)
            {
            }
        }
    }

    /// <summary>Reads messages until the application closes the connection. Each one goes to <paramref name="received"/> in order.</summary>
    public async Task ReadAsync(Action<Message> received, Action<string> malformed, CancellationToken cancellation = default)
    {
        var source = stream ?? throw new InvalidOperationException("not connected");
        while (true)
        {
            byte[]? payload;
            try
            {
                payload = await FrameCodec.ReadAsync(source, cancellation).ConfigureAwait(false);
            }
            catch (Exception exception) when (exception is IOException or ObjectDisposedException or FrameException or OperationCanceledException)
            {
                malformed(exception.Message);
                return;
            }

            if (payload is null)
            {
                return;
            }

            Message message;
            try
            {
                message = MessageCodec.Parse(payload);
            }
            catch (Exception exception) when (exception is MessageException or System.Text.Json.JsonException or InvalidOperationException or FormatException)
            {
                malformed(exception.Message);
                continue;
            }

            received(message);
        }
    }

    public void Dispose()
    {
        lock (writing)
        {
            stream?.Dispose();
            stream = null;
        }

        client?.Dispose();
        listener.Stop();
    }
}
