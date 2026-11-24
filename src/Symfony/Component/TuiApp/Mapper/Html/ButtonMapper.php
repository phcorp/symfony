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

use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\TuiApp\Mapper\HostEvent;
use Symfony\Component\TuiApp\Mapper\MapContext;
use Symfony\Component\TuiApp\Mapper\Node;
use Symfony\Component\TuiApp\Mapper\WidgetMapperInterface;
use Symfony\Component\TuiHtml\Widget\ButtonWidget;
use Symfony\Component\TuiHtml\Widget\DropdownWidget;

/**
 * Draws a button of an HTML view as a native push button; the field of a
 * <select> is a part of its pop-up button, and one that CSS draws
 * (appearance: none, a border or a background of the page) stays drawn by the
 * HTML renderer.
 *
 * @internal
 */
final class ButtonMapper implements WidgetMapperInterface
{
    public const KIND = 'button';

    public function supports(AbstractWidget $widget): bool
    {
        return $widget instanceof ButtonWidget && $widget->hasNativeAppearance() && !$widget->getParent() instanceof DropdownWidget;
    }

    public function map(AbstractWidget $widget, MapContext $context): Node
    {
        \assert($widget instanceof ButtonWidget);

        // The images of an image button, or an icon, stand for characters of the private use plane 16 in its text,
        // which a native button cannot draw: its text without them, else its name (the alt of an image button)
        $text = AnsiUtils::stripAnsiCodes($widget->getText());
        $text = trim(preg_replace(['/[\x{100000}-\x{10FFFF}]/u', '/ {2,}/'], ['', ' '], $text) ?? $text);

        return new Node(self::KIND, [
            'text' => '' !== $text ? $text : $widget->getLabel() ?? '',
            'label' => $widget->getLabel(),
            'disabled' => $widget->hasState(AbstractWidget::STATE_DISABLED) ?: null,
        ]);
    }

    public function handle(AbstractWidget $widget, HostEvent $event): void
    {
        \assert($widget instanceof ButtonWidget);

        if ('press' === $event->name) {
            // Like Enter on it: a submit button submits its form, unless it is disabled
            $widget->activate();
        }
    }
}
