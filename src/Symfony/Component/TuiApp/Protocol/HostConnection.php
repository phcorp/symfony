<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\TuiApp\Protocol;

use Revolt\EventLoop;
use Symfony\Component\TuiApp\Exception\InvalidArgumentException;
use Symfony\Component\TuiApp\Exception\ProtocolException;

/**
 * The connection of the application to its native host.
 *
 * The host listens on a loopback port and starts the application with the
 * TUI_APP_ADDR and TUI_APP_TOKEN environment variables. The application
 * connects, authenticates with the token and receives the hello message,
 * then messages flow both ways, read from the event loop. The variables are
 * removed from the environment once read, so that child processes do not
 * get the token.
 *
 * @experimental
 */
final class HostConnection
{
    public const PROTOCOL = 1;
    public const ADDRESS_ENV = 'TUI_APP_ADDR';
    public const TOKEN_ENV = 'TUI_APP_TOKEN';
    /** The most bytes waiting for a host that does not read them */
    public const MAX_WRITE_BUFFER_BYTES = 16 * 1024 * 1024;

    /** @var resource|null */
    private $stream;
    private FrameCodec $codec;
    private string $writeBuffer = '';
    private ?string $readWatcher = null;
    private ?string $writeWatcher = null;
    /** @var array<string, list<\Closure(array<string, mixed>): void>> */
    private array $listeners = [];
    /** @var list<array<string, mixed>> */
    private array $early = [];

    /**
     * @param resource             $stream
     * @param array<string, mixed> $hello
     */
    private function __construct($stream, FrameCodec $codec, private readonly array $hello)
    {
        $this->stream = $stream;
        $this->codec = $codec;
    }

    /**
     * Whether the process was started by a host.
     */
    public static function isAvailable(): bool
    {
        return false !== getenv(self::ADDRESS_ENV) && false !== getenv(self::TOKEN_ENV);
    }

    public static function fromEnvironment(float $timeout = 10.0): self
    {
        $address = getenv(self::ADDRESS_ENV);
        $token = getenv(self::TOKEN_ENV);
        foreach ([self::ADDRESS_ENV, self::TOKEN_ENV] as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
        if (false === $address || false === $token) {
            throw new InvalidArgumentException(\sprintf('The "%s" and "%s" environment variables are not set: the application was not started by a TuiApp host.', self::ADDRESS_ENV, self::TOKEN_ENV));
        }

        return self::connect($address, $token, $timeout);
    }

    /**
     * Connects to a host and authenticates.
     *
     * @param string $address tcp://127.0.0.1:<port> or tcp://[::1]:<port>
     */
    public static function connect(string $address, string $token, float $timeout = 10.0): self
    {
        if (!preg_match('{^tcp://(127\.0\.0\.1|\[::1\]):\d{1,5}$}D', $address)) {
            throw new InvalidArgumentException(\sprintf('The host address "%s" is not a loopback TCP address like "tcp://127.0.0.1:1234".', $address));
        }
        if (!preg_match('/^[0-9a-f]{32,}$/D', $token)) {
            throw new InvalidArgumentException('The host token must be at least 32 lowercase hexadecimal characters.');
        }

        $stream = @stream_socket_client($address, $errno, $error, $timeout);
        if (false === $stream) {
            throw new ProtocolException(\sprintf('Cannot connect to the host at "%s": %s.', $address, $error ?: 'unknown error'));
        }

        $codec = new FrameCodec();
        self::writeAll($stream, FrameCodec::encode(['type' => 'auth', 'token' => $token, 'protocol' => self::PROTOCOL]));

        $deadline = microtime(true) + $timeout;
        $messages = [];
        while (!$messages) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                fclose($stream);

                throw new ProtocolException(\sprintf('The host did not answer within %s seconds.', $timeout));
            }

            $read = [$stream];
            $write = $except = null;
            // 0 when nothing came within the time left, false when interrupted by a signal
            if (!@stream_select($read, $write, $except, (int) $remaining, (int) (fmod($remaining, 1) * 1e6))) {
                continue;
            }

            $bytes = fread($stream, 65536);
            if ('' === $bytes || false === $bytes) {
                fclose($stream);

                throw new ProtocolException('The host closed the connection: it did not accept the token.');
            }
            $messages = $codec->decode($bytes);
        }

        $hello = array_shift($messages);
        if ('hello' !== $hello['type'] || self::PROTOCOL !== ($hello['protocol'] ?? null)) {
            fclose($stream);

            throw new ProtocolException(\sprintf('The host answered "%s" with protocol %s, expected "hello" with protocol %d.', $hello['type'], json_encode($hello['protocol'] ?? null), self::PROTOCOL));
        }

        stream_set_blocking($stream, false);
        $connection = new self($stream, $codec, $hello);
        $connection->early = $messages;

        return $connection;
    }

    /**
     * Creates a connection over a stream that is already authenticated, for tests.
     *
     * @param resource             $stream
     * @param array<string, mixed> $hello
     *
     * @internal
     */
    public static function fromStream($stream, array $hello): self
    {
        stream_set_blocking($stream, false);

        return new self($stream, new FrameCodec(), $hello);
    }

    /**
     * @return array<string, mixed>
     */
    public function getHello(): array
    {
        return $this->hello;
    }

    /**
     * Whether the host does something beyond the core protocol, as listed in the capabilities of its hello.
     */
    public function hasCapability(string $capability): bool
    {
        return \in_array($capability, $this->getCapabilities(), true);
    }

    /**
     * What the host does beyond the core protocol, from its hello.
     *
     * @return list<string>
     */
    public function getCapabilities(): array
    {
        $capabilities = $this->hello['capabilities'] ?? [];

        return \is_array($capabilities) ? array_values(array_filter($capabilities, \is_string(...))) : [];
    }

    /**
     * Listens to a message type, or to every message with "*".
     *
     * The "disconnect" type is dispatched when the host closes the connection.
     *
     * @param callable(array<string, mixed>): void $listener
     *
     * @return \Closure The listener, to give to off()
     */
    public function on(string $type, callable $listener): \Closure
    {
        return $this->listeners[$type][] = \Closure::fromCallable($listener);
    }

    /**
     * Removes a listener added with on(), as it returned it.
     */
    public function off(string $type, \Closure $listener): void
    {
        $this->listeners[$type] = array_values(array_filter($this->listeners[$type] ?? [], static fn (\Closure $added): bool => $added !== $listener));
        if (!$this->listeners[$type]) {
            unset($this->listeners[$type]);
        }
    }

    /**
     * Starts reading messages from the event loop.
     */
    public function listen(): void
    {
        if (null !== $this->readWatcher || null === $this->stream) {
            return;
        }

        $this->readWatcher = EventLoop::onReadable($this->stream, function (): void {
            $this->read();
        });

        $early = $this->early;
        $this->early = [];
        $this->dispatchAll($early);
    }

    /**
     * Reads what is available and dispatches the messages it completes.
     */
    public function read(): void
    {
        if (null === $this->stream) {
            return;
        }

        $bytes = @fread($this->stream, 65536);
        if ('' === $bytes || false === $bytes) {
            if (feof($this->stream) || false === $bytes) {
                $this->close();
                $this->dispatch(['type' => 'disconnect']);
            }

            return;
        }

        $this->dispatchAll($this->codec->decode($bytes));
    }

    /**
     * @param array<string, mixed> $message
     */
    public function send(array $message): void
    {
        if (null === $this->stream) {
            return;
        }

        $frame = FrameCodec::encode($message);
        if ('' !== $this->writeBuffer && \strlen($this->writeBuffer) + \strlen($frame) > self::MAX_WRITE_BUFFER_BYTES) {
            $this->close();
            $this->dispatch(['type' => 'disconnect']);

            throw new ProtocolException(\sprintf('The host does not read what the application sends: more than %d bytes are waiting.', self::MAX_WRITE_BUFFER_BYTES));
        }

        $this->writeBuffer .= $frame;
        $this->flush();
    }

    /**
     * Writes what is buffered, waiting for the host to read it, up to the timeout.
     */
    public function drain(float $timeout = 2.0): void
    {
        $deadline = microtime(true) + $timeout;
        while (null !== $this->stream && '' !== $this->writeBuffer && microtime(true) < $deadline) {
            $read = $except = null;
            $write = [$this->stream];
            @stream_select($read, $write, $except, 0, 50000);
            $this->flush();
        }
    }

    public function close(): void
    {
        $this->writeBuffer = '';
        $this->early = [];
        $this->codec = new FrameCodec();

        if (null !== $this->readWatcher) {
            EventLoop::cancel($this->readWatcher);
            $this->readWatcher = null;
        }
        if (null !== $this->writeWatcher) {
            EventLoop::cancel($this->writeWatcher);
            $this->writeWatcher = null;
        }
        if (null !== $this->stream) {
            @fclose($this->stream);
            $this->stream = null;
        }
    }

    public function isClosed(): bool
    {
        return null === $this->stream;
    }

    private function flush(): void
    {
        if (null === $this->stream) {
            return;
        }

        while ('' !== $this->writeBuffer) {
            $written = @fwrite($this->stream, $this->writeBuffer);
            if (false === $written) {
                $this->close();
                $this->dispatch(['type' => 'disconnect']);

                return;
            }
            if (0 === $written) {
                break;
            }
            $this->writeBuffer = substr($this->writeBuffer, $written);
        }

        if ('' === $this->writeBuffer) {
            if (null !== $this->writeWatcher) {
                EventLoop::cancel($this->writeWatcher);
                $this->writeWatcher = null;
            }
        } elseif (null === $this->writeWatcher) {
            $this->writeWatcher = EventLoop::onWritable($this->stream, function (): void {
                $this->flush();
            });
        }
    }

    /**
     * Dispatches messages: one whose listener throws does not keep the others from theirs, then the first exception is
     * thrown again.
     *
     * @param list<array<string, mixed>> $messages
     */
    private function dispatchAll(array $messages): void
    {
        $exception = null;
        foreach ($messages as $message) {
            try {
                $this->dispatch($message);
            } catch (\Throwable $e) {
                $exception ??= $e;
            }
        }

        if (null !== $exception) {
            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $message
     */
    private function dispatch(array $message): void
    {
        foreach ([...$this->listeners[$message['type']] ?? [], ...$this->listeners['*'] ?? []] as $listener) {
            $listener($message);
        }
    }

    /**
     * @param resource $stream
     */
    private static function writeAll($stream, string $data): void
    {
        while ('' !== $data) {
            $written = @fwrite($stream, $data);
            if (false === $written || 0 === $written) {
                throw new ProtocolException('Cannot write to the host.');
            }
            $data = substr($data, $written);
        }
    }
}
