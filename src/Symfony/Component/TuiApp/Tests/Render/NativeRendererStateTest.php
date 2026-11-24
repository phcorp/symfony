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
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\TuiApp\Application;
use Symfony\Component\TuiApp\Render\NativeRenderer;
use Symfony\Component\TuiApp\Tests\Fixtures\HostStub;

class NativeRendererStateTest extends TestCase
{
    private HostStub $host;
    private Tui $tui;

    protected function setUp(): void
    {
        $this->host = new HostStub();
        $this->tui = (new Application(connection: $this->host->connection))->getTui();
        $this->tui->start();
    }

    protected function tearDown(): void
    {
        $this->tui->stop();
    }

    public function testWhatIsKeptOfRemovedNodesIsForgotten()
    {
        for ($i = 0; $i < 200; ++$i) {
            $this->tui->add($widget = new CanvasWidget());
            $this->frame();
            $this->host->send(['type' => 'rects', 'rects' => [(string) $this->host->find('canvas') => [0, 0, 12, 1]]]);
            $this->tui->remove($widget);
            $this->frame();
        }

        $this->assertLessThanOrEqual(1, \count($this->property('rects')));
        $this->assertSame([], $this->property('canvasColumns'));
    }

    public function testRemovedWidgetsHaveNoNodeId()
    {
        $this->tui->add($widget = new CanvasWidget());
        $this->frame();
        $renderer = $this->tui->getRenderer();
        $this->assertNotNull($renderer->getNodeId($widget));

        $this->tui->remove($widget);
        $this->frame();
        $this->assertNull($renderer->getNodeId($widget));

        $this->tui->add($widget);
        $this->frame();
        $this->assertNotNull($renderer->getNodeId($widget));
    }

    public function testAnotherHostReceivesACompleteTree()
    {
        $renderer = new NativeRenderer();
        $root = new \Symfony\Component\Tui\Widget\ContainerWidget();
        $root->add(new CanvasWidget());
        $first = new HostStub();
        $writer = $renderer->createFrameWriter(new \Symfony\Component\TuiApp\Terminal\HostTerminal($first->connection));
        $writer->writeFrame($renderer->renderFrame($root, 60, 20));
        $first->receive();
        $this->assertNotNull($first->root);

        $second = new HostStub();
        $writer = $renderer->createFrameWriter(new \Symfony\Component\TuiApp\Terminal\HostTerminal($second->connection));
        $writer->writeFrame($renderer->renderFrame($root, 40, 12));
        $second->receive();

        $this->assertNotNull($second->root);
        $this->assertCount(2, $second->nodes);
        $renderer->detach();
    }

    public function testRectsThatAreNotAList()
    {
        $this->tui->add(new CanvasWidget());
        $this->frame();

        foreach ([['rects' => 'x'], ['rects' => [5 => 'x']], ['rects' => null], []] as $message) {
            $this->host->send(['type' => 'rects'] + $message);
        }
        $this->frame();

        $this->assertNotNull($this->host->find('canvas'));
    }

    private function frame(): void
    {
        $this->tui->requestRender();
        $this->tui->processRender();
        $this->host->receive();
    }

    private function property(string $name): mixed
    {
        $renderer = $this->tui->getRenderer();
        \assert($renderer instanceof NativeRenderer);

        return (new \ReflectionProperty(NativeRenderer::class, $name))->getValue($renderer);
    }
}

class CanvasWidget extends AbstractWidget
{
    public function render(RenderContext $context): array
    {
        return [str_repeat('x', $context->getColumns())];
    }
}
