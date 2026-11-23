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
 * Dispatched when the user chooses an item of the application menus.
 *
 * @experimental
 */
final class MenuSelectEvent extends Event
{
    public function __construct(
        private readonly string $id,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }
}
