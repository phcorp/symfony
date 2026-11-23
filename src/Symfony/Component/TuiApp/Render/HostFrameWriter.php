<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\TuiApp\Render;

use Symfony\Component\Tui\Render\FrameWriterInterface;
use Symfony\Component\Tui\Render\LineBufferInterface;
use Symfony\Component\TuiApp\Protocol\HostConnection;
use Symfony\Component\TuiApp\Terminal\HostTerminal;

/**
 * Sends the frames of the native renderer to the host.
 *
 * @internal
 */
final class HostFrameWriter implements FrameWriterInterface
{
    private bool $closed = false;

    public function __construct(
        private readonly HostConnection $connection,
        private readonly NativeRenderer $renderer,
        private readonly HostTerminal $terminal,
    ) {
    }

    public function writeFrame(LineBufferInterface $lines): void
    {
        $this->renderer->attach($this->terminal);
        if (!$lines instanceof PatchFrame) {
            return;
        }
        // Tui closes its writer when it stops, and writes with it again when it starts again
        $this->closed = false;

        if ($lines->ops) {
            $this->connection->send(['type' => 'patch', 'ops' => $lines->ops]);
        }
        if (false !== $lines->focus) {
            $this->connection->send(['type' => 'focus', 'node' => $lines->focus]);
        }
    }

    public function reset(): void
    {
        // Tui forces a full repaint on resize because a terminal screen cannot be trusted then; native
        // controls can, and drawing them again would lose what the user did in them (focus, selection,
        // scrolling): the next frame stays a diff
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        if (null !== $rootId = $this->renderer->forgetTree()) {
            $this->connection->send(['type' => 'patch', 'ops' => [['op' => 'remove', 'id' => $rootId]]]);
        }
    }

    public function setScrollOffset(int $offset): void
    {
        // Native controls scroll themselves; the canvas of a frame shows it like a terminal
        $this->renderer->setScrollOffset($offset);
    }

    public function getState(): array
    {
        return ['cursor_row' => 0, 'line_count' => 0];
    }

    public function onTerminalHeightChange(int $previousRows, int $rows): void
    {
    }
}
