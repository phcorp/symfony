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

use Symfony\Component\Tui\Exception\OutOfBoundsException;
use Symfony\Component\Tui\Render\LineBufferInterface;

/**
 * A frame of the native renderer: no lines of text, but the operations that
 * bring the host's node tree up to date, and the node to focus.
 *
 * @implements \IteratorAggregate<int, string>
 *
 * @internal
 */
final class PatchFrame implements LineBufferInterface, \IteratorAggregate
{
    /**
     * @param list<array<string, mixed>> $ops
     * @param int|false|null             $focus The node to focus, null for none, false to leave the focus as it is
     */
    public function __construct(
        public readonly array $ops,
        public readonly int|false|null $focus = false,
    ) {
    }

    public function count(): int
    {
        return 0;
    }

    public function getIterator(): \Iterator
    {
        return new \EmptyIterator();
    }

    public function getLine(int $index): string
    {
        throw new OutOfBoundsException(\sprintf('A patch frame has no line %d.', $index));
    }

    public function slice(int $offset, int $length): array
    {
        return [];
    }

    public function toArray(): array
    {
        return [];
    }

    public function getMaxVisibleWidth(): int
    {
        return 0;
    }
}
