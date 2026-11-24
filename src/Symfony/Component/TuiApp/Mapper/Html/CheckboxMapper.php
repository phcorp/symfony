<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\TuiApp\Mapper\Html;

use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\TuiApp\Mapper\HostEvent;
use Symfony\Component\TuiApp\Mapper\MapContext;
use Symfony\Component\TuiApp\Mapper\Node;
use Symfony\Component\TuiApp\Mapper\WidgetMapperInterface;
use Symfony\Component\TuiHtml\Widget\CheckboxWidget;
use Symfony\Component\TuiHtml\Widget\RadioWidget;

/**
 * Draws a checkbox or a radio button of an HTML view as a native one; one that
 * CSS draws (appearance: none) stays drawn by the HTML renderer.
 *
 * @internal
 */
final class CheckboxMapper implements WidgetMapperInterface
{
    public const KIND = 'checkbox';

    public function supports(AbstractWidget $widget): bool
    {
        return $widget instanceof CheckboxWidget && !$widget->isDrawnByCss();
    }

    public function map(AbstractWidget $widget, MapContext $context): Node
    {
        \assert($widget instanceof CheckboxWidget);
        // The radios of a name are a group; one without a name is a group of its own
        $group = $widget instanceof RadioWidget ? 'radio-'.spl_object_id($widget->getGroup() ?? $widget) : null;

        return new Node(self::KIND, [
            'checked' => $widget->isChecked(),
            'group' => $group,
            'label' => $widget->getLabel(),
            'disabled' => $widget->hasState(AbstractWidget::STATE_DISABLED) ?: null,
        ]);
    }

    public function handle(AbstractWidget $widget, HostEvent $event): void
    {
        \assert($widget instanceof CheckboxWidget);

        if ('toggle' !== $event->name || !\is_bool($event->value)) {
            return;
        }
        // Like Space: a checked radio stays checked
        if ($event->value !== $widget->isChecked()) {
            $widget->toggle();
        }
    }
}
