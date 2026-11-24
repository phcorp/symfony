<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\TuiApp\Render;

use Symfony\Component\TuiApp\Mapper\HostEvent;

/**
 * The properties a native control already shows after a host event.
 *
 * @internal
 */
final class HostControlState
{
    /**
     * The props the control of a node shows after the user acted on it.
     *
     * @param array<string, mixed> $props
     *
     * @return array<string, mixed>
     */
    public static function acknowledge(string $kind, array $props, HostEvent $event): array
    {
        $value = $event->value;
        $isIndexList = \is_array($value) && array_is_list($value) && array_all($value, static fn (mixed $index): bool => \is_int($index));

        return match ([$kind, $event->name]) {
            ['textfield', 'change'], ['textfield', 'submit'], ['textarea', 'change'], ['textarea', 'submit'],
            ['password', 'change'], ['password', 'submit'], ['number', 'change'], ['number', 'submit'] => \is_string($value) ? ['value' => $value] : [],
            ['slider', 'change'] => \is_int($value) || \is_float($value) ? ['value' => $value] : [],
            ['list', 'select'] => match (true) {
                \is_int($value) => ['selected' => $value],
                $isIndexList => ['checked' => $value],
                default => [],
            },
            ['popup', 'select'] => \is_int($value) ? ['selected' => $value] : [],
            ['listbox', 'select'] => $isIndexList ? ['selected' => $value] : [],
            ['tabs', 'tab'] => \is_int($value) ? ['active' => $value] : [],
            ['disclosure', 'toggle'] => \is_bool($value) ? ['expanded' => $value] : [],
            ['checkbox', 'toggle'] => \is_bool($value) ? ['checked' => $value] : [],
            ['form', 'setting'] => \is_array($value) && \is_string($value['id'] ?? null) && \is_string($value['value'] ?? null) ? ['rows' => array_map(
                static fn (mixed $row): mixed => \is_array($row) && ($row['id'] ?? null) === $value['id'] ? array_replace($row, ['value' => $value['value']]) : $row,
                $props['rows'] ?? [],
            )] : [],
            default => [],
        };
    }
}
