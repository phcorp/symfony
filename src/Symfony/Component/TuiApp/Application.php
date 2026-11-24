<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\TuiApp;

use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Event\RendererChangeEvent;
use Symfony\Component\Tui\Exception\InvalidArgumentException as TuiInvalidArgumentException;
use Symfony\Component\Tui\Input\KeyParser;
use Symfony\Component\Tui\Render\RendererInterface;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Tui;
use Symfony\Component\TuiApp\Event\AppearanceChangeEvent;
use Symfony\Component\TuiApp\Event\MenuSelectEvent;
use Symfony\Component\TuiApp\Event\OpenEvent;
use Symfony\Component\TuiApp\Event\WindowCloseEvent;
use Symfony\Component\TuiApp\Exception\InvalidArgumentException;
use Symfony\Component\TuiApp\Exception\LogicException;
use Symfony\Component\TuiApp\Input\PointerHandler;
use Symfony\Component\TuiApp\Mapper\MapperRegistry;
use Symfony\Component\TuiApp\Mapper\WidgetMapperInterface;
use Symfony\Component\TuiApp\Protocol\HostConnection;
use Symfony\Component\TuiApp\Render\NativeRenderer;
use Symfony\Component\TuiApp\Terminal\HostTerminal;
use Symfony\Component\TuiHtml\Render\Renderer as HtmlRenderer;
use Symfony\Component\TuiHtml\Style\ColorScheme;
use Symfony\Component\TuiHtml\Style\MediaContext;

/**
 * Runs a Tui in the window of a native host, with native controls, or in the
 * terminal when the process was not started by a host.
 *
 *     $app = new Application();
 *     $app->getTui()->add(new TextWidget('Hello'));
 *     $app->run();
 *
 * Another renderer, like the HTML renderer of TuiHtml, draws the TUI in a
 * terminal, and in the window of a host as one canvas, keeping its layout:
 *
 *     $app = new Application(renderer: new HtmlRenderer());
 *
 * @experimental
 */
final class Application
{
    private readonly Tui $tui;
    private readonly ?HostConnection $connection;
    private readonly MapperRegistry $mappers;
    private ?bool $dark = null;
    /** The HTML renderer that follows the appearance of the system, and the color scheme it was given */
    private ?HtmlRenderer $html = null;
    private ?ColorScheme $scheme = null;

    /**
     * @param iterable<WidgetMapperInterface> $mappers    Mappers for widgets of the application, or to draw built-in ones otherwise
     * @param HostConnection|false|null       $connection The connection to the host; null connects when started by a host, false never does
     * @param RendererInterface|null          $renderer   The renderer of the TUI, the native renderer of Tui by default; in a window, the HTML renderer of TuiHtml, or one that decorates it, draws the TUI as one canvas, and the mappers draw it with any other
     */
    public function __construct(
        ?StyleSheet $styleSheet = null,
        iterable $mappers = [],
        HostConnection|false|null $connection = null,
        ?RendererInterface $renderer = null,
    ) {
        if (null === $connection && HostConnection::isAvailable()) {
            $connection = HostConnection::fromEnvironment();
        }
        $this->connection = $connection ?: null;

        if (null === $this->connection) {
            $this->tui = new Tui($styleSheet, renderer: $renderer);

            return;
        }

        $terminal = new HostTerminal($this->connection, new EventDispatcher());
        $this->mappers = new MapperRegistry($mappers);
        $this->tui = new Tui($styleSheet, $terminal, renderer: $native = new NativeRenderer($renderer, $this->mappers));
        $this->dark = 'dark' === ($this->connection->getHello()['appearance'] ?? null);
        // A host that sends what the mouse does: PointerEvent, the context menus, and an HTML view tracks the pointer
        if ($this->connection->hasCapability('pointer')) {
            $pointer = new PointerHandler($this->tui, $this->connection);
            $this->connection->on('pointer', $pointer->handle(...));
            $this->connection->on('contextmenu-select', $pointer->select(...));
        }
        $this->useRenderer($native);
        // Only native controls draw in a window, before any other listener is told
        $this->tui->getEventDispatcher()->addListener(RendererChangeEvent::class, function (RendererChangeEvent $event): void {
            $renderer = $event->getRenderer();
            if (!$renderer instanceof NativeRenderer) {
                // Tui selected it already: the native renderer draws again, and no other listener is told
                $event->stopPropagation();
                $this->tui->setRenderer($event->getPreviousRenderer());

                throw new LogicException(\sprintf('Only a "%s" draws in a window: give the "%s" to "%s::setRenderer()" instead, which draws with it through native controls.', NativeRenderer::class, get_debug_type($renderer), self::class));
            }
            $previous = $event->getPreviousRenderer();
            if ($previous instanceof NativeRenderer && $previous !== $renderer) {
                $previous->detach();
            }
            $this->useRenderer($renderer);
        }, 2048);

        $this->connection->on('close', $this->onClose(...));
        $this->connection->on('disconnect', function (): void {
            $this->tui->stop();
        });
        $this->connection->on('menu-select', function (array $message): void {
            if (\is_string($message['id'] ?? null)) {
                $this->dispatch(new MenuSelectEvent($message['id']));
            }
        });
        $this->connection->on('open', function (array $message): void {
            $paths = array_values(array_filter((array) ($message['paths'] ?? []), \is_string(...)));
            if ($paths) {
                $this->dispatch(new OpenEvent($paths));
            }
        });
        $this->connection->on('appearance', function (array $message): void {
            $this->dark = 'dark' === ($message['value'] ?? null);
            $this->followAppearance();
            $this->dispatch(new AppearanceChangeEvent($this->dark));
        });
    }

    /**
     * Sets up the HTML renderer the native renderer draws the frames of, if any: its :hover and (hover: hover) apply
     * with a host that sends what the mouse does, and it follows the appearance of the system, unless the application
     * gave it a color scheme.
     */
    private function useRenderer(NativeRenderer $native): void
    {
        $this->html = null;
        if (null === $html = $native->getHtmlRenderer()) {
            return;
        }
        if ($this->connection?->hasCapability('touch')) {
            $html->setMediaContext($html->getMediaContext()->withTouch());
        } elseif ($this->connection?->hasCapability('pointer')) {
            $html->setMediaContext($html->getMediaContext()->withPointer());
        }
        if (MediaContext::fromEnvironment()->colorScheme === $html->getMediaContext()->colorScheme) {
            $this->html = $html;
            $this->scheme = $html->getMediaContext()->colorScheme;
            $this->followAppearance();
        }
    }

    /**
     * Gives the appearance of the system to an HTML renderer, for prefers-color-scheme, until the application gives it
     * a color scheme.
     */
    private function followAppearance(): void
    {
        if (null === $this->html) {
            return;
        }
        $context = $this->html->getMediaContext();
        if ($context->colorScheme !== $this->scheme) {
            $this->html = null;

            return;
        }
        $this->scheme = $this->dark ? ColorScheme::Dark : ColorScheme::Light;
        $this->html->setMediaContext($context->withColorScheme($this->scheme));
        $this->tui->requestRender();
    }

    public function getTui(): Tui
    {
        return $this->tui;
    }

    /**
     * Selects the renderer of the TUI, before it runs: in a window, the native controls draw with it, and with the
     * mappers of the application.
     */
    public function setRenderer(RendererInterface $renderer): static
    {
        if (null !== $this->connection && !$renderer instanceof NativeRenderer) {
            $renderer = new NativeRenderer($renderer, $this->mappers);
        }
        $this->tui->setRenderer($renderer);

        return $this;
    }

    /**
     * Whether the TUI draws in the window of a host, rather than in a terminal.
     */
    public function isNative(): bool
    {
        return null !== $this->connection;
    }

    /**
     * Whether the system appearance is dark, or null in a terminal.
     */
    public function isDark(): ?bool
    {
        return $this->dark;
    }

    /**
     * Sets the menus of the window: a list of menus, each with a title and
     * items. An item has an id, sent with MenuSelectEvent, a label, and
     * optionally a shortcut (a key id like "ctrl+s"), "enabled" and
     * "checked"; ['separator' => true] is a separator.
     *
     * In a terminal, menus are ignored.
     *
     * @param list<array<string, mixed>> $menus
     *
     * @return $this
     */
    public function setMenus(array $menus): static
    {
        foreach ($menus as $menu) {
            if (!\is_string($menu['title'] ?? null) || !\is_array($menu['items'] ?? null)) {
                throw new InvalidArgumentException('A menu needs a "title" and a list of "items".');
            }
            foreach ($menu['items'] as $item) {
                if (!($item['separator'] ?? false) && (!\is_string($item['id'] ?? null) || !\is_string($item['label'] ?? null))) {
                    throw new InvalidArgumentException(\sprintf('An item of the "%s" menu needs an "id" and a "label", or "separator".', $menu['title']));
                }
                if (null === $shortcut = $item['shortcut'] ?? null) {
                    continue;
                }
                if (!\is_string($shortcut)) {
                    throw new InvalidArgumentException(\sprintf('The shortcut of the "%s" item must be a key id or null, "%s" given.', $item['id'] ?? '', get_debug_type($shortcut)));
                }
                try {
                    (new KeyParser())->validateKeyId($shortcut);
                } catch (TuiInvalidArgumentException $e) {
                    throw new InvalidArgumentException(\sprintf('The shortcut "%s" of the "%s" item is not a key id: %s', $shortcut, $item['id'] ?? '', $e->getMessage()), 0, $e);
                }
            }
        }

        $this->connection?->send(['type' => 'menu', 'menus' => $menus]);

        return $this;
    }

    /**
     * Shows a system notification. In a terminal, it rings the bell.
     *
     * @return $this
     */
    public function notify(string $title, string $body = ''): static
    {
        if (null === $this->connection) {
            $this->tui->getTerminal()->bell();
        } else {
            $this->connection->send(['type' => 'notify', 'title' => $title, 'body' => $body]);
        }

        return $this;
    }

    /**
     * Runs the TUI until it stops, then tells the host.
     */
    public function run(): void
    {
        $status = 1;
        try {
            $this->tui->run();
            $status = 0;
        } finally {
            // A crash is not a clean exit: the host reports it instead of closing the window quietly
            if (null !== $this->connection && !$this->connection->isClosed()) {
                $this->connection->send(['type' => 'bye', 'status' => $status]);
                $this->connection->drain();
                $this->connection->close();
            }
        }
    }

    /**
     * Dispatches an event from the host. Unlike input, it does not make the
     * TUI render by itself: what the listeners changed is drawn on the next tick.
     *
     * @template T of object
     *
     * @param T $event
     *
     * @return T
     */
    private function dispatch(object $event): object
    {
        $event = $this->tui->getEventDispatcher()->dispatch($event);
        $this->tui->requestRender();

        return $event;
    }

    /**
     * @param array<string, mixed> $message
     */
    private function onClose(array $message): void
    {
        $event = $this->dispatch(new WindowCloseEvent());
        $accept = !$event->isKeptOpen();

        $this->connection?->send(['type' => 'close-reply', 'accept' => $accept]);
        if ($accept) {
            $this->tui->stop();
        }
    }
}
