<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\TuiApp\Terminal;

use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Tui\Terminal\ProgramStatus\ProgramState;
use Symfony\Component\Tui\Terminal\ProgramStatus\ProgramStatus;
use Symfony\Component\Tui\Terminal\TerminalInterface;
use Symfony\Component\TuiApp\Exception\InvalidArgumentException;
use Symfony\Component\TuiApp\Input\KeyEncoder;
use Symfony\Component\TuiApp\Protocol\HostConnection;

/**
 * The terminal of a Tui drawn by a native host: input comes from the host's
 * window, and the size is the window's, in cells of its canvas font.
 *
 * Nothing is written as text: the native renderer sends the widget tree to
 * the host, so write() ignores the escape sequences Tui sends to a terminal.
 *
 * @experimental
 */
final class HostTerminal implements TerminalInterface
{
    private int $columns;
    private int $rows;
    private ?\Closure $onInput = null;
    private ?\Closure $onResize = null;
    private bool $listening = false;
    private ?string $title = null;
    /** @var list<string|null> */
    private array $titles = [];
    /** @var array<string, ProgramStatus> */
    private array $statuses = [];

    public function __construct(
        private readonly HostConnection $connection,
        private readonly EventDispatcherInterface $eventDispatcher = new EventDispatcher(),
    ) {
        $hello = $connection->getHello();
        $this->columns = max(1, (int) ($hello['columns'] ?? 80));
        $this->rows = max(1, (int) ($hello['rows'] ?? 24));

        $connection->on('resize', function (array $message): void {
            $this->columns = max(1, (int) ($message['columns'] ?? $this->columns));
            $this->rows = max(1, (int) ($message['rows'] ?? $this->rows));
            if (null !== $this->onResize) {
                ($this->onResize)();
            }
        });
        $connection->on('key', function (array $message): void {
            if (null === $this->onInput || !\is_string($message['key'] ?? null)) {
                return;
            }

            try {
                $data = KeyEncoder::encode($message['key'], \is_string($message['text'] ?? null) ? $message['text'] : null);
            } catch (InvalidArgumentException) {
                // A key Tui has no id for, from a newer host
                return;
            }
            ($this->onInput)($data);
        });
        $connection->on('paste', function (array $message): void {
            if (null !== $this->onInput && \is_string($message['text'] ?? null)) {
                // Pasted text comes from the clipboard: an escape byte could end the paste and type keys
                ($this->onInput)("\x1b[200~".str_replace("\x1b", '', $message['text'])."\x1b[201~");
            }
        });
    }

    public function getConnection(): HostConnection
    {
        return $this->connection;
    }

    public function getEventDispatcher(): EventDispatcherInterface
    {
        return $this->eventDispatcher;
    }

    public function start(callable $onInput, callable $onResize, callable $onKittyProtocolActivated): void
    {
        $this->onInput = $onInput(...);
        $this->onResize = $onResize(...);
        // Every key the host sends is encoded with the Kitty protocol
        $onKittyProtocolActivated();

        if (!$this->listening) {
            $this->listening = true;
            $this->connection->listen();
        }
    }

    public function stop(): void
    {
        $this->onInput = null;
        $this->onResize = null;
    }

    public function write(string $data): void
    {
        // Tui writes escape sequences for a text terminal, which a host does not need
    }

    public function getColumns(): int
    {
        return $this->columns;
    }

    public function getRows(): int
    {
        return $this->rows;
    }

    public function isKittyProtocolActive(): bool
    {
        return true;
    }

    public function moveBy(int $lines): void
    {
    }

    public function hideCursor(): void
    {
    }

    public function showCursor(): void
    {
    }

    public function clearLine(): void
    {
    }

    public function clearFromCursor(): void
    {
    }

    public function clearScreen(): void
    {
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
        $this->connection->send(['type' => 'window', 'title' => $title]);
    }

    public function pushTitle(): void
    {
        $this->titles[] = $this->title;
    }

    public function popTitle(): void
    {
        if (!$this->titles) {
            return;
        }

        // Null: the title of the host, before the application set one
        $title = array_pop($this->titles);
        if ($title !== $this->title) {
            $this->title = $title;
            $this->connection->send(['type' => 'window', 'title' => $title]);
        }
    }

    public function bell(): void
    {
        $this->connection->send(['type' => 'beep']);
    }

    public function setProgramStatus(ProgramStatus $status): void
    {
        $this->statuses[$status->id ?? ''] = $status;
        $this->sendProgress();
    }

    public function clearProgramStatus(?string $id = null): void
    {
        if (null === $id) {
            $this->statuses = [];
        } else {
            unset($this->statuses[$id]);
        }
        $this->sendProgress();
    }

    public function isVirtual(): bool
    {
        // Like a virtual terminal, nothing scrolls out of a window
        return true;
    }

    /**
     * Sends the progress of the last status that has one to the dock or the taskbar.
     */
    private function sendProgress(): void
    {
        $progress = null;
        foreach ($this->statuses as $status) {
            $progress = match ($status->state) {
                ProgramState::Working => null === $status->progress ? ['state' => 'indeterminate', 'value' => null] : ['state' => 'normal', 'value' => $status->progress / 100],
                ProgramState::Blocked => ['state' => 'paused', 'value' => null === $status->progress ? null : $status->progress / 100],
                ProgramState::Error => ['state' => 'error', 'value' => null],
                ProgramState::Idle, ProgramState::Done => $progress,
            };
        }

        $this->connection->send(['type' => 'window', 'progress' => $progress]);
    }
}
