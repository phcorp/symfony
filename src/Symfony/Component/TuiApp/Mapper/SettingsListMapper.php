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

use Symfony\Component\Tui\Event\SettingChangeEvent;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\SettingItem;
use Symfony\Component\Tui\Widget\SettingsListWidget;

/**
 * Draws a SettingsListWidget as a form: a label and a choice or a text field per setting.
 *
 * @experimental
 */
final class SettingsListMapper implements WidgetMapperInterface
{
    public const KIND = 'form';

    public function supports(AbstractWidget $widget): bool
    {
        return $widget instanceof SettingsListWidget;
    }

    public function map(AbstractWidget $widget, MapContext $context): Node
    {
        \assert($widget instanceof SettingsListWidget);

        return new Node(self::KIND, $context->getCommonProps($widget) + [
            'rows' => array_map(static fn (SettingItem $item): array => [
                'id' => $item->getId(),
                'label' => $item->getLabel(),
                'value' => $item->getCurrentValue(),
                'description' => $item->getDescription(),
                'choices' => $item->hasValues() ? $item->getValues() : null,
            ] + ($item->hasValues() ? [] : ['readOnly' => true]), $widget->getItems()),
        ]);
    }

    public function handle(AbstractWidget $widget, HostEvent $event): void
    {
        \assert($widget instanceof SettingsListWidget);

        if ('cancel' === $event->name) {
            $event->press($widget, 'select_cancel');

            return;
        }

        $id = $event->value['id'] ?? null;
        $value = $event->value['value'] ?? null;
        if ('setting' !== $event->name || !\is_string($id) || !\is_string($value)) {
            return;
        }

        // Like Enter on it, which goes through its choices: a setting without choices is changed by the application
        foreach ($widget->getItems() as $item) {
            if ($item->getId() === $id && $item->getCurrentValue() !== $value && \in_array($value, $item->getValues(), true)) {
                $widget->updateValue($id, $value);
                $widget->dispatch(new SettingChangeEvent($widget, $id, $value));
            }
        }
    }
}
