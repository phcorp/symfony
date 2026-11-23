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
 * Dispatched when the system switches between light and dark appearance.
 *
 * @experimental
 */
final class AppearanceChangeEvent extends Event
{
    public function __construct(
        private readonly bool $dark,
    ) {
    }

    public function isDark(): bool
    {
        return $this->dark;
    }
}
