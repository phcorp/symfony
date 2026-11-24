using System.Buffers.Binary;
using System.Text;

namespace TuiAppHost.Core;

/// <summary>A frame is a u32 big-endian byte length, then that many bytes of UTF-8 JSON.</summary>
public static class FrameCodec
{
    public const int MaxFrameBytes = 16 * 1024 * 1024;

    public static byte[] Encode(string json) => Encode(Encoding.UTF8.GetBytes(json));

    public static byte[] Encode(ReadOnlySpan<byte> payload)
    {
        if (payload.Length > MaxFrameBytes)
        {
            throw new FrameException($"a frame of {payload.Length} bytes is over the {MaxFrameBytes} byte limit");
        }

        var frame = new byte[4 + payload.Length];
        BinaryPrimitives.WriteUInt32BigEndian(frame, (uint)payload.Length);
        payload.CopyTo(frame.AsSpan(4));
        return frame;
    }

    /// <summary>The next frame's payload, or null when the stream ends cleanly between frames.</summary>
    public static async Task<byte[]?> ReadAsync(Stream stream, CancellationToken cancellation = default, int maxBytes = MaxFrameBytes)
    {
        var header = new byte[4];
        var got = await FillAsync(stream, header, cancellation).ConfigureAwait(false);
        if (got == 0)
        {
            return null;
        }

        if (got < 4)
        {
            throw new FrameException("the stream ended inside a frame header");
        }

        var length = BinaryPrimitives.ReadUInt32BigEndian(header);
        if (length > maxBytes)
        {
            throw new FrameException($"a frame of {length} bytes is over the {maxBytes} byte limit");
        }

        var payload = new byte[length];
        if (await FillAsync(stream, payload, cancellation).ConfigureAwait(false) < length)
        {
            throw new FrameException("the stream ended inside a frame");
        }

        return payload;
    }

    private static async Task<int> FillAsync(Stream stream, byte[] buffer, CancellationToken cancellation)
    {
        var total = 0;
        while (total < buffer.Length)
        {
            var read = await stream.ReadAsync(buffer.AsMemory(total), cancellation).ConfigureAwait(false);
            if (read == 0)
            {
                break;
            }

            total += read;
        }

        return total;
    }
}

/// <summary>Splits bytes that arrive in any chunks into frames.</summary>
public sealed class FrameDecoder
{
    private readonly List<byte> buffer = new();

    public void Push(ReadOnlySpan<byte> bytes)
    {
        foreach (var value in bytes)
        {
            buffer.Add(value);
        }
    }

    public bool TryRead(out byte[] payload)
    {
        payload = Array.Empty<byte>();
        if (buffer.Count < 4)
        {
            return false;
        }

        var length = (uint)(buffer[0] << 24 | buffer[1] << 16 | buffer[2] << 8 | buffer[3]);
        if (length > FrameCodec.MaxFrameBytes)
        {
            throw new FrameException($"a frame of {length} bytes is over the {FrameCodec.MaxFrameBytes} byte limit");
        }

        if (buffer.Count < 4 + length)
        {
            return false;
        }

        payload = buffer.GetRange(4, (int)length).ToArray();
        buffer.RemoveRange(0, 4 + (int)length);
        return true;
    }
}

public sealed class FrameException(string message) : Exception(message);
