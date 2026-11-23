<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\TuiApp\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Render\RendererDecoratorInterface;
use Symfony\Component\Tui\Render\RendererInterface;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\TuiApp\Application;
use Symfony\Component\TuiApp\Exception\InvalidArgumentException;
use Symfony\Component\TuiApp\Exception\LogicException;
use Symfony\Component\TuiApp\Mapper\HostEvent;
use Symfony\Component\TuiApp\Mapper\MapContext;
use Symfony\Component\TuiApp\Mapper\Node;
use Symfony\Component\TuiApp\Mapper\WidgetMapperInterface;
use Symfony\Component\TuiApp\Render\NativeRenderer;
use Symfony\Component\TuiApp\Tests\Fixtures\HostStub;
use Symfony\Component\TuiApp\Tests\Fixtures\WrappingRenderer;
use Symfony\Component\TuiHtml\Html\HtmlBuilder;
use Symfony\Component\TuiHtml\Render\Renderer as HtmlRenderer;
use Symfony\Component\TuiHtml\Style\ColorScheme;

class ApplicationTest extends TestCase
{
    public function testARendererSelectedInAWindowMustBeNative()
    {
        $host = new HostStub();
        $tui = (new Application(connection: $host->connection))->getTui();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(\sprintf('give the "%s" to "%s::setRenderer()"', Renderer::class, Application::class));

        $tui->setRenderer(new Renderer());
    }

    public function testAnHtmlRendererSelectedInAWindowDrawsOneCanvas()
    {
        $host = new HostStub(['capabilities' => ['pointer'], 'appearance' => 'light'] + HostStub::HELLO);
        $tui = (new Application(connection: $host->connection))->getTui();
        $html = new HtmlRenderer();

        $tui->setRenderer(new NativeRenderer($html));
        (new HtmlBuilder())->build('<p>Hello</p>')->mount($tui);

        $this->assertSame($html, $tui->getRenderer()->getHtmlRenderer());
        $this->assertSame(ColorScheme::Light, $html->getMediaContext()->colorScheme, 'It follows the appearance of the host.');
        $this->assertTrue($html->getMediaContext()->pointer, 'It follows the pointer of the host.');
        $this->frame($tui, $host);
        $this->assertSame('canvas', $host->nodes[$host->root]['kind']);
    }

    public function testANativeRendererReplacedNoLongerListensToTheHost()
    {
        $host = new HostStub();
        $tui = (new Application(connection: $host->connection))->getTui();
        $listeners = static fn (): array => array_map(\count(...), (new \ReflectionProperty($host->connection, 'listeners'))->getValue($host->connection));
        $before = $listeners();

        $tui->setRenderer($native = new NativeRenderer());

        $this->assertSame($native, $tui->getRenderer());
        $this->assertSame($before, $listeners());
    }

    public function testARendererRefusedKeepsTheNativeOne()
    {
        $host = new HostStub();
        $tui = (new Application(connection: $host->connection))->getTui();
        $native = $tui->getRenderer();
        $listeners = static fn (): array => array_map(\count(...), (new \ReflectionProperty($host->connection, 'listeners'))->getValue($host->connection));
        $before = $listeners();

        try {
            $tui->setRenderer(new Renderer());
            $this->fail('A renderer that is not native is refused.');
        } catch (LogicException) {
        }

        $this->assertSame($native, $tui->getRenderer());
        $this->assertSame($before, $listeners());
    }

    public function testARendererThatFailsToBeSelectedDoesNotListenToTheHost()
    {
        $host = new HostStub();
        $app = new Application(new StyleSheet(), connection: $host->connection);
        $keys = $app->getTui()->getTerminal()->getEventDispatcher();
        $listeners = static fn (): array => array_map(\count(...), (new \ReflectionProperty($host->connection, 'listeners'))->getValue($host->connection)) + ['keys' => \count($keys->getListeners(InputEvent::class))];
        $before = $listeners();

        try {
            $app->setRenderer(new class(new HtmlRenderer()) extends WrappingRenderer implements RendererDecoratorInterface {
                public function getDecoratedRenderer(): RendererInterface
                {
                    return $this->inner;
                }

                public function addStyleSheet(StyleSheet $styleSheet): void
                {
                    throw new \RuntimeException('No styles.');
                }
            });
            $this->fail('The renderer fails.');
        } catch (\RuntimeException $e) {
            $this->assertSame('No styles.', $e->getMessage());
        }

        $this->assertSame($before, $listeners());
    }

    public function testTheApplicationDrawsWithTheRendererItIsGivenAndItsMappers()
    {
        $host = new HostStub();
        $app = new Application(mappers: [new class implements WidgetMapperInterface {
            public function supports(AbstractWidget $widget): bool
            {
                return $widget instanceof TextWidget;
            }

            public function map(AbstractWidget $widget, MapContext $context): Node
            {
                return new Node('label', ['text' => 'Mapped']);
            }

            public function handle(AbstractWidget $widget, HostEvent $event): void
            {
            }
        }], connection: $host->connection);
        $app->getTui()->add(new TextWidget('Hello'));

        $app->setRenderer($renderer = new Renderer());

        $this->assertSame($renderer, $app->getTui()->getRenderer()->getDecoratedRenderer());
        $this->frame($app->getTui(), $host);
        $this->assertSame('Mapped', $host->nodes[$host->find('label')]['props']['text'] ?? null);
    }

    public function testTheApplicationGivesItsRendererToATerminal()
    {
        $app = new Application(connection: false);

        $app->setRenderer($renderer = new Renderer());

        $this->assertSame($renderer, $app->getTui()->getRenderer());
    }

    public static function provideMenus(): iterable
    {
        yield 'a shortcut' => [['id' => 'save', 'label' => 'Save', 'shortcut' => 'ctrl+s'], null];
        yield 'no shortcut' => [['id' => 'save', 'label' => 'Save', 'shortcut' => null], null];
        yield 'an unknown key' => [['id' => 'save', 'label' => 'Save', 'shortcut' => 'ctrl+nope'], 'The shortcut "ctrl+nope" of the "save" item'];
        yield 'an unknown modifier' => [['id' => 'save', 'label' => 'Save', 'shortcut' => 'cmd+s'], 'The shortcut "cmd+s" of the "save" item'];
        yield 'not a string' => [['id' => 'save', 'label' => 'Save', 'shortcut' => 1], 'The shortcut of the "save" item must be a key id'];
    }

    #[DataProvider('provideMenus')]
    public function testMenuShortcuts(array $item, ?string $error)
    {
        $host = new HostStub();
        $app = new Application(connection: $host->connection);
        if (null !== $error) {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage($error);
        }

        $app->setMenus([['title' => 'File', 'items' => [$item]]]);

        $this->assertSame('menu', $host->receive()[0]['type']);
    }

    private function frame(Tui $tui, HostStub $host): void
    {
        $tui->start();
        $tui->requestRender();
        $tui->processRender();
        $host->receive();
        $tui->stop();
    }
}
