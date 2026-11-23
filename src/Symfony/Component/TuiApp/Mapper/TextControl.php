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

use Symfony\Component\Tui\Event\ChangeEvent;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Applies the events of a native text control to its widget, like typing:
 * change and submit set the text, submit and cancel press their key.
 *
 * @internal
 */
final class TextControl
{
    /**
     * @param \Closure(): string      $getText
     * @param \Closure(string): mixed $setText
     * @param int|null                $maxLength Cuts the text like a paste does; an edit that does not make a text
     *                                           already past it longer is kept, like typing
     */
    public static function handle(AbstractWidget $widget, HostEvent $event, \Closure $getText, \Closure $setText, ?int $maxLength = null): void
    {
        if (\in_array($event->name, ['change', 'submit'], true) && \is_string($event->value)) {
            $previous = $getText();
            // The widget may normalize the text (line endings, control characters): listeners get what it keeps
            $setText($event->value);
            $text = $getText();
            if (null !== $maxLength && mb_strlen($text) > max($maxLength, mb_strlen($previous))) {
                $setText(mb_substr($text, 0, $maxLength));
                $text = $getText();
            }
            if ($text !== $previous) {
                $widget->dispatch(new ChangeEvent($widget, $text));
            }
        }

        match ($event->name) {
            'submit' => $event->press($widget, 'submit'),
            'cancel' => $event->press($widget, 'select_cancel'),
            default => null,
        };
    }
}
