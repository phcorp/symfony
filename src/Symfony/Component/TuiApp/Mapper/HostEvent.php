<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\TuiApp\Mapper;

use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\TuiApp\Input\KeyEncoder;

/**
 * What the user did on a native control: change, submit, cancel, select,
 * toggle, tab or setting, with its value.
 *
 * @experimental
 */
final class HostEvent
{
    /** @var array<string, mixed> */
    private array $acknowledged = [];

    public function __construct(
        public readonly string $name,
        public readonly mixed $value = null,
    ) {
    }

    /**
     * Sends the widget the first key bound to an action, as if the user pressed it.
     *
     * @return bool Whether the action has a key
     */
    public function press(AbstractWidget $widget, string $action): bool
    {
        if (!method_exists($widget, 'getKeybindings') || !method_exists($widget, 'handleInput')) {
            return false;
        }

        $keyIds = $widget->getKeybindings()->getBindings($action);
        if (!$keyIds) {
            return false;
        }

        $widget->handleInput(KeyEncoder::encode($keyIds[0]));

        return true;
    }

    /**
     * Records props the native control already shows, so that the next frame
     * does not send them back while the user is typing, but sends what the
     * widget keeps when it is something else.
     *
     * The renderer records what the event says for the kinds of the protocol:
     * a mapper of another kind records what its control shows.
     *
     * @param array<string, mixed> $props
     */
    public function acknowledge(array $props): void
    {
        $this->acknowledged = $props + $this->acknowledged;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAcknowledged(): array
    {
        return $this->acknowledged;
    }
}
