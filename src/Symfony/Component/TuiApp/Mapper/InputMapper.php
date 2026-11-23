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
use Symfony\Component\Tui\Widget\InputWidget;

/**
 * Draws an InputWidget as a text field.
 *
 * @experimental
 */
final class InputMapper implements WidgetMapperInterface
{
    public const KIND = 'textfield';

    public function supports(AbstractWidget $widget): bool
    {
        return $widget instanceof InputWidget;
    }

    public function map(AbstractWidget $widget, MapContext $context): Node
    {
        \assert($widget instanceof InputWidget);

        return new Node(self::KIND, $context->getCommonProps($widget) + [
            'value' => $widget->getValue(),
            'prompt' => $widget->getPrompt(),
        ]);
    }

    public function handle(AbstractWidget $widget, HostEvent $event): void
    {
        \assert($widget instanceof InputWidget);

        TextControl::handle($widget, $event, $widget->getValue(...), $widget->setValue(...));
    }
}
