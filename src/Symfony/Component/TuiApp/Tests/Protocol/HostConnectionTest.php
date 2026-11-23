<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\TuiApp\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use Symfony\Component\TuiApp\Exception\InvalidArgumentException;
use Symfony\Component\TuiApp\Exception\ProtocolException;
use Symfony\Component\TuiApp\Protocol\FrameCodec;
use Symfony\Component\TuiApp\Protocol\HostConnection;
use Symfony\Component\TuiApp\Tests\Fixtures\HostStub;

class HostConnectionTest extends TestCase
{
    private const TOKEN = '0123456789abcdef0123456789abcdef';

    /** @var array<string, string|false> */
    private array $env = [];

    protected function setUp(): void
    {
        foreach ([HostConnection::ADDRESS_ENV, HostConnection::TOKEN_ENV] as $name) {
            $this->env[$name] = getenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->env as $name => $value) {
            putenv(false === $value ? $name : $name.'='.$value);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    public function testTheEnvironmentIsClearedOnceRead()
    {
        $this->setEnv('tcp://192.0.2.1:1234', self::TOKEN);

        try {
            HostConnection::fromEnvironment();
            $this->fail('The address is not a loopback one.');
        } catch (InvalidArgumentException) {
        }

        foreach ([HostConnection::ADDRESS_ENV, HostConnection::TOKEN_ENV] as $name) {
            $this->assertFalse(getenv($name), $name);
            $this->assertArrayNotHasKey($name, $_ENV, $name);
            $this->assertArrayNotHasKey($name, $_SERVER, $name);
        }
        $this->assertFalse(HostConnection::isAvailable());
        $child = proc_open([\PHP_BINARY, '-r', 'echo json_encode(getenv("'.HostConnection::TOKEN_ENV.'"));'], [1 => ['pipe', 'w']], $pipes);
        $this->assertSame('false', stream_get_contents($pipes[1]), 'A child process does not get the token.');
        proc_close($child);
    }

    public function testListenersGetEveryMessageOfAReadWhenOneThrows()
    {
        [$connection, $host] = $this->pair();
        $got = [];
        $connection->on('beep', static function (array $message) use (&$got): void {
            $got[] = $message['n'];
            if (1 === $message['n']) {
                throw new \RuntimeException('Listener failed.');
            }
        });
        fwrite($host, FrameCodec::encode(['type' => 'beep', 'n' => 1]).FrameCodec::encode(['type' => 'beep', 'n' => 2]));

        try {
            $connection->read();
            $this->fail('The exception of the listener is thrown.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Listener failed.', $e->getMessage());
        }

        $this->assertSame([1, 2], $got);
    }

    public function testAListenerOfAnyCallableIsRemoved()
    {
        [$connection, $host] = $this->pair();
        $listener = new class {
            public array $got = [];

            public function onBeep(array $message): void
            {
                $this->got[] = $message['n'];
            }
        };
        $added = $connection->on('beep', [$listener, 'onBeep']);
        fwrite($host, FrameCodec::encode(['type' => 'beep', 'n' => 1]));
        $connection->read();

        $connection->off('beep', $added);
        fwrite($host, FrameCodec::encode(['type' => 'beep', 'n' => 2]));
        $connection->read();

        $this->assertSame([1], $listener->got);
    }

    public function testTheWriteBufferIsBounded()
    {
        $host = new HostStub();
        $disconnected = false;
        $host->connection->on('disconnect', static function () use (&$disconnected): void {
            $disconnected = true;
        });
        $text = str_repeat('x', 10_000);

        try {
            for ($i = 0; $i < 2000; ++$i) {
                $host->connection->send(['type' => 'notify', 'title' => 't', 'body' => $text]);
            }
            $this->fail('A host that never reads gets disconnected.');
        } catch (ProtocolException $e) {
            $this->assertStringContainsString('does not read', $e->getMessage());
        }

        $this->assertTrue($host->connection->isClosed());
        $this->assertTrue($disconnected);
        $this->assertLessThan(1700, $i, 'The buffer holds about 16 MiB.');
    }

    public function testClosingReleasesBufferedOutput()
    {
        $host = new HostStub();
        $host->connection->send(['type' => 'notify', 'title' => 't', 'body' => str_repeat('x', 4 * 1024 * 1024)]);
        $buffer = new \ReflectionProperty(HostConnection::class, 'writeBuffer');
        $this->assertGreaterThan(0, \strlen($buffer->getValue($host->connection)));

        $host->connection->close();
        $this->assertSame(0, \strlen($buffer->getValue($host->connection)));
        $host->connection->close();
        $this->assertTrue($host->connection->isClosed());
    }

    public function testAFrameAsLongAsAllowedIsBuffered()
    {
        $host = new HostStub();
        $host->connection->send(['type' => 'notify', 'title' => 't', 'body' => str_repeat('x', FrameCodec::MAX_FRAME_BYTES - 100)]);

        $this->assertFalse($host->connection->isClosed());
    }

    public function testFrameLengthsAboveTheSignedRange()
    {
        $this->expectException(ProtocolException::class);

        (new FrameCodec())->decode("\x80\x00\x00\x00{}");
    }

    public function testAMalformedFirstFrameClosesTheSocket()
    {
        [$address, $server, $pipes] = $this->startHost(pack('N', 2).'{x');

        try {
            HostConnection::connect($address, self::TOKEN, 5);
            $this->fail('The first frame is not JSON.');
        } catch (ProtocolException) {
        }

        $this->assertSame('closed', trim(stream_get_contents($pipes[1])), 'The host sees the connection closed.');
        proc_close($server);
    }

    public function testCapabilities()
    {
        $host = new HostStub(['capabilities' => ['pointer', 42, 'menu']] + HostStub::HELLO);

        $this->assertTrue($host->connection->hasCapability('pointer'));
        $this->assertTrue($host->connection->hasCapability('menu'));
        $this->assertFalse($host->connection->hasCapability('canvas-controls'));
        $this->assertSame(['pointer', 'menu'], $host->connection->getCapabilities());
        $this->assertFalse((new HostStub(['capabilities' => 'pointer'] + HostStub::HELLO))->connection->hasCapability('pointer'));
    }

    private function setEnv(string $address, string $token): void
    {
        putenv(HostConnection::ADDRESS_ENV.'='.$address);
        putenv(HostConnection::TOKEN_ENV.'='.$token);
        $_ENV[HostConnection::ADDRESS_ENV] = $_SERVER[HostConnection::ADDRESS_ENV] = $address;
        $_ENV[HostConnection::TOKEN_ENV] = $_SERVER[HostConnection::TOKEN_ENV] = $token;
    }

    /**
     * @return array{HostConnection, resource}
     */
    private function pair(): array
    {
        [$app, $host] = stream_socket_pair('\\' === \DIRECTORY_SEPARATOR ? \STREAM_PF_INET : \STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);

        return [HostConnection::fromStream($app, HostStub::HELLO), $host];
    }

    /**
     * Starts a host in another process, which answers the auth message with the given bytes, then tells whether the
     * application closed the connection.
     *
     * @return array{string, resource, array<int, resource>}
     */
    private function startHost(string $answer): array
    {
        $code = <<<'PHP'
            $server = stream_socket_server('tcp://127.0.0.1:0');
            echo 'tcp://'.stream_socket_get_name($server, false), "\n";
            $client = stream_socket_accept($server, 10);
            fread($client, 65536);
            fwrite($client, hex2bin($argv[1]));
            stream_set_timeout($client, 3);
            $bytes = fread($client, 1);
            echo '' === $bytes && !stream_get_meta_data($client)['timed_out'] ? 'closed' : 'open', "\n";
            PHP;
        $process = proc_open([\PHP_BINARY, '-r', $code, bin2hex($answer)], [1 => ['pipe', 'w']], $pipes);

        return [trim(fgets($pipes[1])), $process, $pipes];
    }
}
