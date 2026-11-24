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

/**
 * What a widget is drawn as in the native host: a kind of native control,
 * its props, and its children.
 *
 * A child is a widget, mapped in turn, or a node that stands for no widget,
 * like the empty pane of a tab that is not shown.
 *
 * @see Resources/contract/protocol.json for the kinds and their props
 *
 * @experimental
 */
final class Node
{
    /**
     * @param array<string, mixed>      $props
     * @param list<AbstractWidget|self> $children
     */
    public function __construct(
        public readonly string $kind,
        public readonly array $props = [],
        public readonly array $children = [],
    ) {
    }
}
