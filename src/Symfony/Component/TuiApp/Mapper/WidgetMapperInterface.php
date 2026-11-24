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
 * Draws a kind of widget with a native control, and applies to the widget
 * what the user does on that control.
 *
 * @experimental
 */
interface WidgetMapperInterface
{
    public function supports(AbstractWidget $widget): bool;

    /**
     * Called for every frame: the native renderer sends the host what changed since the last one.
     */
    public function map(AbstractWidget $widget, MapContext $context): Node;

    /**
     * Applies an event of the native control (change, submit, select...) to the widget.
     *
     * The widget should dispatch the events it dispatches when the same
     * happens from the keyboard, so that its listeners run.
     */
    public function handle(AbstractWidget $widget, HostEvent $event): void;
}
