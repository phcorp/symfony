namespace TuiAppHost.Core;

public enum CloseStep
{
    /// <summary>Nothing to do.</summary>
    Wait,

    /// <summary>Close the window now (killing the process if it still runs).</summary>
    CloseNow,

    /// <summary>Send close and start the 4 s answer timer.</summary>
    AskApp,

    /// <summary>The application refused: stop the timer, keep the window.</summary>
    StopWaiting,

    /// <summary>Start (or restart) the 15 s exit timer.</summary>
    WaitForExit,

    /// <summary>Ask "&lt;name&gt; is not responding. Force quit?"</summary>
    OfferForceQuit,

    /// <summary>The process ended before auth: show its last stderr lines, then close.</summary>
    ShowStartFailure,
}

/// <summary>
/// What closing does, which depends on how far the application has got (HOSTS.md, Closing):
/// close → close-reply → bye → exit, with a 4 s answer timeout and a 15 s exit timeout.
/// </summary>
public sealed class CloseFlow
{
    public static readonly TimeSpan AnswerTime = TimeSpan.FromSeconds(4);
    public static readonly TimeSpan ExitTime = TimeSpan.FromSeconds(15);

    private enum State
    {
        Starting,
        Ready,
        Asking,
        Accepted,
        Bye,
        Exited,
    }

    private State state = State.Starting;

    public bool ByeReceived { get; private set; }

    public bool HasExited => state == State.Exited;

    /// <summary>The application authenticated and got hello.</summary>
    public void Connected()
    {
        if (state == State.Starting)
        {
            state = State.Ready;
        }
    }

    /// <summary>The close button, Quit, or Alt+F4.</summary>
    public CloseStep CloseRequested()
    {
        switch (state)
        {
            case State.Starting:
            case State.Exited:
                state = State.Exited;
                return CloseStep.CloseNow;
            case State.Ready:
                state = State.Asking;
                return CloseStep.AskApp;
            default:
                return CloseStep.Wait;
        }
    }

    public CloseStep Replied(bool accept)
    {
        if (state is State.Exited or State.Bye)
        {
            return CloseStep.Wait;
        }

        if (accept)
        {
            state = State.Accepted;
            return CloseStep.WaitForExit;
        }

        if (state == State.Asking)
        {
            state = State.Ready;
            return CloseStep.StopWaiting;
        }

        return CloseStep.Wait;
    }

    public CloseStep Bye()
    {
        ByeReceived = true;
        if (state == State.Exited)
        {
            return CloseStep.Wait;
        }

        state = State.Bye;
        return CloseStep.WaitForExit;
    }

    /// <summary>The process ended, by itself or after bye.</summary>
    public CloseStep Exited()
    {
        var before = state;
        state = State.Exited;
        return before switch
        {
            State.Starting => CloseStep.ShowStartFailure,
            State.Exited => CloseStep.Wait,
            _ => CloseStep.CloseNow,
        };
    }

    /// <summary>The answer timer or the exit timer ran out.</summary>
    public CloseStep TimedOut() => state is State.Asking or State.Accepted or State.Bye ? CloseStep.OfferForceQuit : CloseStep.Wait;

    /// <summary>The user answered no to force quit: keep waiting for an exit already promised, or keep the window.</summary>
    public CloseStep ForceQuitDeclined()
    {
        switch (state)
        {
            case State.Asking:
                state = State.Ready;
                return CloseStep.StopWaiting;
            case State.Accepted:
            case State.Bye:
                return CloseStep.WaitForExit;
            default:
                return CloseStep.Wait;
        }
    }

    /// <summary>The user answered yes: the host kills the process, then closes.</summary>
    public CloseStep ForceQuit()
    {
        state = State.Exited;
        return CloseStep.CloseNow;
    }
}
