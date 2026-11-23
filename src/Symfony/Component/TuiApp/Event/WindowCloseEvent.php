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
 * Dispatched when the user closes the window or quits. A listener that
 * calls keepOpen() cancels it, for example to ask to save first; otherwise
 * the TUI stops.
 *
 * @experimental
 */
final class WindowCloseEvent extends Event
{
    private bool $keepOpen = false;

    public function keepOpen(): void
    {
        $this->keepOpen = true;
        $this->stopPropagation();
    }

    public function isKeptOpen(): bool
    {
        return $this->keepOpen;
    }
}
