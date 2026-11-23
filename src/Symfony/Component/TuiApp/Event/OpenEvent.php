<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\TuiApp\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched when the system asks the application to open files or URLs:
 * a file opened with the application, a link with one of its URL schemes,
 * or files dropped on its window.
 *
 * @experimental
 */
final class OpenEvent extends Event
{
    /**
     * @param list<string> $paths
     */
    public function __construct(
        private readonly array $paths,
    ) {
    }

    /**
     * @return list<string>
     */
    public function getPaths(): array
    {
        return $this->paths;
    }
}
