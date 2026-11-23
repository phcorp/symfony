<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\TuiApp\Tests\Render;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Render\RendererDecoratorInterface;
use Symfony\Component\Tui\Render\RendererInterface;
use Symfony\Component\Tui\Tui;
use Symfony\Component\TuiApp\Application;
use Symfony\Component\TuiApp\Render\NativeRenderer;
use Symfony\Component\TuiApp\Tests\Fixtures\HostStub;
use Symfony\Component\TuiApp\Tests\Fixtures\WrappingRenderer;
use Symfony\Component\TuiHtml\Event\FormSubmitEvent;
use Symfony\Component\TuiHtml\Html\HtmlBuilder;
use Symfony\Component\TuiHtml\Html\HtmlView;
use Symfony\Component\TuiHtml\Render\Renderer as HtmlRenderer;
use Symfony\Component\TuiHtml\Style\ColorScheme;
use Symfony\Component\TuiHtml\Style\MediaContext;

/**
 * HTML views in the window of a host: the HTML renderer draws the whole tree as one canvas.
 */
class HtmlViewTest extends TestCase
{
    private const FORM = <<<'HTML'
        <style>
          form { display: grid; grid-template-columns: 8ch 1fr; gap: 0 1ch }
          h1 { color: #00ff00 }
        </style>
        <h1>New project</h1>
        <form>
          <label for="name">Name</label> <input id="name" name="name" required>
          <label for="hidden">Hidden</label> <input id="hidden" name="hidden" inert>
          <label for="email">Email</label> <input id="email" name="email">
          <span></span> <button>Create</button>
        </form>
        HTML;

    private HostStub $host;
    private HtmlRenderer $html;
    private Tui $tui;

    protected function setUp(): void
    {
        $this->host = new HostStub();
        $this->html = new HtmlRenderer();
        $this->tui = (new Application(connection: $this->host->connection, renderer: $this->html))->getTui();
    }

    protected function tearDown(): void
    {
        $this->tui->stop();
    }

    public function testTheNativeRendererDecoratesTheHtmlRenderer()
    {
        $renderer = $this->tui->getRenderer();
        $this->assertInstanceOf(NativeRenderer::class, $renderer);
        $this->assertSame($this->html, $renderer->getDecoratedRenderer());
    }

    public function testAnHtmlRendererBehindADecoratorDrawsOneCanvas()
    {
        $decorator = new class(new HtmlRenderer()) extends WrappingRenderer implements RendererDecoratorInterface {
            public function getDecoratedRenderer(): RendererInterface
            {
                return $this->inner;
            }
        };
        $host = new HostStub();
        $tui = (new Application(connection: $host->connection, renderer: $decorator))->getTui();
        (new HtmlBuilder())->build('<p>Hello</p><input>')->mount($tui);
        $tui->start();
        $tui->processRender();
        $host->receive();
        $tui->stop();

        $this->assertSame([$host->root], array_keys($host->nodes));
        $this->assertSame('canvas', $host->nodes[$host->root]['kind']);
    }

    public function testInATerminalTheTuiDrawsWithTheRendererGiven()
    {
        $renderer = new HtmlRenderer();

        $this->assertSame($renderer, (new Application(connection: false, renderer: $renderer))->getTui()->getRenderer());
    }

    public function testTouchHostsUseCoarsePointerMediaQueries()
    {
        $renderer = new HtmlRenderer();
        $host = new HostStub(['capabilities' => ['pointer', 'touch']] + HostStub::HELLO);
        new Application(null, [], $host->connection, $renderer);

        $context = $renderer->getMediaContext();
        $this->assertTrue(\Symfony\Component\TuiHtml\Css\MediaQuery::parse('(pointer: coarse) and (hover: none)')->matches($context));
        $this->assertFalse(\Symfony\Component\TuiHtml\Css\MediaQuery::parse('(hover: hover)')->matches($context));
    }

    public function testTheViewFollowsTheAppearanceOfTheSystem()
    {
        $this->mount(<<<'HTML'
            <style>
              h1 { color: #0000ff }
              @media (prefers-color-scheme: light) { h1 { color: #ff0000 } }
            </style>
            <h1>Title</h1>
            HTML);
        $this->frame();
        $this->assertSame('#0000ff', $this->colorOf('Title'), 'The host said dark');

        $this->host->send(['type' => 'appearance', 'value' => 'light']);
        $this->frame();
        $this->assertSame('#ff0000', $this->colorOf('Title'));

        $this->host->send(['type' => 'appearance', 'value' => 'dark']);
        $this->frame();
        $this->assertSame('#0000ff', $this->colorOf('Title'));
    }

    public function testAColorSchemeTheApplicationSetsIsKept()
    {
        $scheme = ColorScheme::Light === MediaContext::fromEnvironment()->colorScheme ? ColorScheme::Dark : ColorScheme::Light;
        $html = new HtmlRenderer();
        $html->setMediaContext($html->getMediaContext()->withColorScheme($scheme));
        $host = new HostStub(['appearance' => ColorScheme::Light === $scheme ? 'dark' : 'light'] + HostStub::HELLO);
        new Application(connection: $host->connection, renderer: $html);
        $this->assertSame($scheme, $html->getMediaContext()->colorScheme, 'Set before the application');
        $host->send(['type' => 'appearance', 'value' => ColorScheme::Light === $scheme ? 'dark' : 'light']);
        $this->assertSame($scheme, $html->getMediaContext()->colorScheme);

        $html = new HtmlRenderer();
        $host = new HostStub();
        new Application(connection: $host->connection, renderer: $html);
        $html->setMediaContext($html->getMediaContext()->withColorScheme(ColorScheme::Light));
        $host->send(['type' => 'appearance', 'value' => 'dark']);
        $this->assertSame(ColorScheme::Light, $html->getMediaContext()->colorScheme, 'Set after the application');
    }

    public function testTheScrollOffsetDrawsTheCanvasAgain()
    {
        $html = '';
        for ($i = 1; $i <= 30; ++$i) {
            $html .= '<div>Line '.$i.'</div>';
        }
        $this->mount($html);
        $this->frame();

        $this->tui->setScrollOffset(5);
        $this->tui->processRender();
        $this->host->receive();
        $text = $this->text();
        $this->assertSame('Line 25', rtrim(end($text)));
    }

    public function testAFormIsDrawnFilledAndSubmittedInOneCanvas()
    {
        $view = $this->mount(self::FORM);
        $submitted = [];
        $view->querySelector('form')?->onSubmit(static function (FormSubmitEvent $event) use (&$submitted): void {
            $submitted[] = $event->getValues();
        });
        $this->frame();

        $this->assertSame([$this->host->root], array_keys($this->host->nodes), 'The whole tree is one node');
        $canvas = $this->host->nodes[$this->host->root];
        $this->assertSame('canvas', $canvas['kind']);
        $this->assertTrue($canvas['props']['focusable']);
        $this->assertSame(1, $canvas['props']['grow']);
        $this->assertSame(HostStub::HELLO['columns'], $canvas['props']['columns']);
        $this->assertSame($this->host->root, $this->host->focus, 'The canvas takes the keys, like a terminal');

        $text = $this->text();
        $this->assertContains('New project', $text);
        $this->assertMatchesRegularExpression('/^Name {5}>/m', implode("\n", $text), 'The grid of the style sheet places the field after its label');
        $heading = array_values(array_filter($this->runs(), static fn (array $run): bool => str_contains((string) $run['t'], 'New project')));
        $this->assertSame('#00ff00', $heading[0]['fg'] ?? null, 'The styles of the style sheet are kept');

        $this->key('f6');
        $this->assertSame($view->getById('name'), $this->tui->getFocus());
        $this->key('f6');
        $this->assertSame($view->getById('email'), $this->tui->getFocus(), 'The HTML renderer decides the focus: an inert field is skipped');
        $this->key('shift+f6');
        foreach (['shift+a' => 'A', 'd' => 'd', 'a' => 'a'] as $key => $typed) {
            $this->key($key, $typed);
        }
        $this->frame();

        $this->assertMatchesRegularExpression('/^Name {5}> Ada/m', implode("\n", $this->text()));
        $row = array_search(true, array_map(static fn (string $line): bool => str_starts_with($line, 'Name'), $this->text()), true);
        $this->assertSame([$row, 14], $this->host->nodes[$this->host->root]['props']['cursor'], 'The cursor is after what was typed');

        $this->key('enter');
        $this->assertSame([['name' => 'Ada', 'hidden' => '', 'email' => '']], $submitted);
    }

    public function testTheCanvasIsDrawnAgainForTheSizeOfTheWindow()
    {
        $this->mount('<p>'.str_repeat('word ', 30).'</p>');
        $this->frame();
        $this->assertSame(['', ...array_fill(0, 2, rtrim(str_repeat('word ', 12))), rtrim(str_repeat('word ', 6)), ''], $this->text());

        $this->host->send(['type' => 'resize', 'columns' => 30, 'rows' => 10]);
        $this->frame();

        $this->assertSame(30, $this->host->nodes[$this->host->root]['props']['columns']);
        $this->assertSame(['', ...array_fill(0, 5, rtrim(str_repeat('word ', 6))), ''], $this->text(), 'The text wraps again');
        $this->assertSame([$this->host->root], array_keys($this->host->nodes), 'The same canvas');
    }

    public function testTheCanvasIsDrawnForTheSizeTheHostGivesIt()
    {
        $view = $this->mount('<p id="text">'.str_repeat('word ', 30).'</p>');
        $this->frame();
        $root = $this->host->root;

        // The host draws the canvas with a margin of 1 column on each side and 1 row below
        $this->host->send(['type' => 'rects', 'rects' => [$root => [0, 1, 58, 19]]]);
        $this->frame();
        $this->assertSame(58, $this->host->nodes[$root]['props']['columns']);
        $this->assertSame(['', ...array_fill(0, 2, rtrim(str_repeat('word ', 11))), rtrim(str_repeat('word ', 8)), ''], $this->text());
        $inFrame = $this->html->getWidgetRect($view->getById('text'));
        $inWindow = $this->tui->getWidgetRect($view->getById('text'));
        $this->assertSame([$inFrame?->row, 1 + $inFrame?->col], [$inWindow?->row, $inWindow?->col], 'Widgets are placed where the canvas is');

        // Like the macOS host, the rects of the new layout come before the new size of the window
        $this->host->send(['type' => 'rects', 'rects' => [$root => [0, 1, 30, 9]]]);
        $this->host->send(['type' => 'resize', 'columns' => 32, 'rows' => 10]);
        $this->frame();
        $this->assertSame(30, $this->host->nodes[$root]['props']['columns']);
        $this->assertCount(7, $this->text(), 'The text wraps for the canvas');
    }

    public function testRestartDoesNotReuseTheRemovedCanvasSize()
    {
        $this->mount('<p>Hello</p>');
        $this->frame();
        $this->host->send(['type' => 'rects', 'rects' => [$this->host->root => [0, 1, 30, 9]]]);
        $this->frame();
        $this->assertSame(30, $this->host->nodes[$this->host->root]['props']['columns']);

        $this->tui->stop();
        $this->host->receive();
        $this->host->send(['type' => 'resize', 'columns' => 40, 'rows' => 12]);
        $this->tui->start();
        $this->frame();

        $this->assertSame(40, $this->host->nodes[$this->host->root]['props']['columns']);
    }

    public function testATallFrameShowsItsBottomLikeATerminal()
    {
        $html = '';
        for ($i = 1; $i <= 30; ++$i) {
            $html .= '<div id="line'.$i.'">Line '.$i.'</div>';
        }
        $view = $this->mount($html);
        $this->frame();

        $text = $this->text();
        $this->assertCount(HostStub::HELLO['rows'], $text);
        $this->assertSame('Line 30', rtrim(end($text)));
        $this->assertSame(HostStub::HELLO['rows'] - 1, $this->tui->getWidgetRect($view->getById('line30'))?->row, 'Widgets are where the window shows them');

        $this->tui->setScrollOffset(5);
        $this->frame();
        $text = $this->text();
        $this->assertSame('Line 25', rtrim(end($text)), 'The scroll offset moves the window up');
    }

    private function mount(string $html): HtmlView
    {
        return (new HtmlBuilder())->build($html)->mount($this->tui);
    }

    private function frame(): void
    {
        static $started = new \WeakMap();
        if (!isset($started[$this->tui])) {
            $started[$this->tui] = true;
            $this->tui->start();
        }
        $this->tui->requestRender();
        $this->tui->processRender();
        $this->host->receive();
    }

    private function key(string $key, ?string $text = null): void
    {
        $this->host->send(['type' => 'key', 'key' => $key, 'text' => $text]);
    }

    /**
     * @return list<string>
     */
    private function text(): array
    {
        return array_map(static fn (array $runs): string => rtrim(implode('', array_column($runs, 't'))), $this->host->nodes[$this->host->root]['props']['lines']);
    }

    private function colorOf(string $text): ?string
    {
        $runs = array_values(array_filter($this->runs(), static fn (array $run): bool => str_contains((string) $run['t'], $text)));

        return isset($runs[0]['fg']) ? (string) $runs[0]['fg'] : null;
    }

    /**
     * @return list<array<string, string|bool>>
     */
    private function runs(): array
    {
        return array_merge(...$this->host->nodes[$this->host->root]['props']['lines']);
    }
}
