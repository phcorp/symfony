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
use Symfony\Component\Tui\Widget\ProgressBarWidget;

/**
 * Draws a ProgressBarWidget as a native progress bar, indeterminate when it has no maximum.
 *
 * @experimental
 */
final class ProgressBarMapper implements WidgetMapperInterface
{
    public const KIND = 'progress';

    public function supports(AbstractWidget $widget): bool
    {
        return $widget instanceof ProgressBarWidget;
    }

    public function map(AbstractWidget $widget, MapContext $context): Node
    {
        \assert($widget instanceof ProgressBarWidget);
        $max = $widget->getMaxSteps();

        return new Node(self::KIND, $context->getCommonProps($widget) + [
            'progress' => $max > 0 ? round($widget->getProgressPercent(), 4) : null,
            'text' => $widget->getMessage() ?? ($max > 0 ? \sprintf('%d/%d', $widget->getProgress(), $max) : null),
        ]);
    }

    public function handle(AbstractWidget $widget, HostEvent $event): void
    {
    }
}
