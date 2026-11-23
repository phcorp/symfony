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
use Symfony\Component\TuiApp\Event\ContextMenuEvent;
use Symfony\Component\TuiApp\Event\ContextMenuItem;
use Symfony\Component\TuiApp\Event\PointerEvent;
use Symfony\Component\TuiApp\Tests\Fixtures\HostStub;
use Symfony\Component\TuiHtml\Event\LinkEvent;
use Symfony\Component\TuiHtml\Event\PressEvent;
use Symfony\Component\TuiHtml\Html\HtmlBuilder;
use Symfony\Component\TuiHtml\Html\HtmlView;
use Symfony\Component\TuiHtml\Internal\ContainerState;
use Symfony\Component\TuiHtml\Render\Renderer as HtmlRenderer;
use Symfony\Component\TuiHtml\Widget\CheckboxWidget;
use Symfony\Component\TuiHtml\Widget\ContainerWidget;
use Symfony\Component\TuiHtml\Widget\LinkedTextWidget;

/**
 * What the mouse does on the canvas of an HTML view, from a host with the pointer capability.
 */
class PointerTest extends TestCase
{
    private HostStub $host;
    private HtmlRenderer $html;
    private Tui $tui;
    private HtmlView $view;

    protected function setUp(): void
    {
        $this->start(['pointer']);
    }

    protected function tearDown(): void
    {
        $this->tui->stop();
    }

    public function testTheViewKnowsThePointerIsTracked()
    {
        $this->assertTrue($this->html->getMediaContext()->pointer);
        $this->tui->stop();
        $this->start([]);
        $this->assertFalse($this->html->getMediaContext()->pointer, 'Not with a host that does not send it');
    }

    public function testAClickOnALinkDrawnInTheCanvasFollowsIt()
    {
        $this->mount('<p id="p">Read <a href="https://example.com/docs">the docs</a> now</p>');
        $followed = [];
        $this->tui->addListener(static function (LinkEvent $event) use (&$followed): void {
            $followed[] = $event->getHref();
        });
        $this->frame();
        [$row, $col] = $this->find('the docs');

        $this->click($row, $col + 2);
        $this->assertSame(['https://example.com/docs'], $followed);
        $text = $this->view->getById('p');
        $this->assertInstanceOf(LinkedTextWidget::class, $text);
        $this->assertSame($text, $this->tui->getFocus(), 'The link takes the focus, like in a browser');

        $this->click($row, 0);
        $this->assertSame(['https://example.com/docs'], $followed, 'Not on the link');
    }

    public function testAButtonAndACheckboxDrawnInTheCanvasAreClicked()
    {
        $this->mount('<button id="go">Go</button> <label><input type="checkbox" id="box"> Box</label>');
        $pressed = 0;
        $this->tui->addListener(static function (PressEvent $event) use (&$pressed): void {
            ++$pressed;
        });
        $this->frame();
        $this->assertSame('canvas', $this->host->nodes[$this->host->root]['kind']);
        $this->assertSame([], $this->host->nodes[$this->host->root]['children'], 'A host without canvas-controls: all in the canvas');

        [$row, $col] = $this->find('Go');
        $this->click($row, $col);
        $this->assertSame(1, $pressed);
        $this->assertSame($this->view->getById('go'), $this->tui->getFocus());

        $box = $this->view->getById('box');
        $this->assertInstanceOf(CheckboxWidget::class, $box);
        [$row, $col] = $this->find('Box');
        $this->click($row, $col);
        $this->assertTrue($box->isChecked(), 'A click on its label toggles it');
        $this->frame();
        $this->assertStringContainsString('[x]', $this->text()[$row] ?? '');
    }

    public function testAPressAndAReleaseElsewhereIsNoClick()
    {
        $this->mount('<button id="go">Go</button><p>Text</p>');
        $pressed = 0;
        $this->tui->addListener(static function (PressEvent $event) use (&$pressed): void {
            ++$pressed;
        });
        $this->frame();
        [$row, $col] = $this->find('Go');
        $this->pointer('press', $row, $col, 'left');
        $this->assertTrue($this->view->getById('go')->hasState('active'), ':active while pressed');
        $this->pointer('move', $row + 1, $col, 'left');
        $this->pointer('release', $row + 1, $col, 'left');
        $this->assertSame(0, $pressed, 'Released off the button: no click');
        $this->assertFalse($this->view->getById('go')->hasState('active'));

        $this->pointer('press', $row, $col, 'right');
        $this->pointer('release', $row, $col, 'right');
        $this->assertSame(0, $pressed, 'Another button does not click');
    }

    public function testASliderDrawnInTheCanvasFollowsThePointerWhileTheButtonIsHeld()
    {
        $this->mount('<p>Level</p><input id="level" type="range" min="0" max="10" value="5">');
        $level = $this->view->getById('level');
        $this->frame();
        [$row] = $this->find('●');
        $start = min(array_filter([mb_strpos($this->text()[$row], '━'), mb_strpos($this->text()[$row], '●')], \is_int(...)));
        $end = mb_strpos($this->text()[$row], ' 5');

        $this->pointer('press', $row, $start, 'left');
        $this->assertSame(0.0, $level->getValue(), 'The thumb goes where it is pressed');
        $this->assertSame($level, $this->tui->getFocus());
        $this->pointer('move', $row + 1, $end - 1, 'left');
        $this->assertSame(10.0, $level->getValue(), 'And follows the pointer, whatever the row');
        $this->pointer('release', $row + 1, $end - 1, 'left');
        $this->pointer('move', $row, $start, 'left');
        $this->assertSame(10.0, $level->getValue(), 'Not once released');
        $this->frame();
        $this->assertStringContainsString('10', $this->text()[$row]);

        $this->pointer('press', $row, $start, 'right');
        $this->pointer('move', $row, $start, 'right');
        $this->assertSame(10.0, $level->getValue(), 'Nor with another button');
    }

    public function testALeaveEndsTheDragOfASlider()
    {
        $this->mount('<p>Level</p><input id="level" type="range" min="0" max="10" value="5">');
        $level = $this->view->getById('level');
        $this->frame();
        [$row] = $this->find('●');
        $start = min(array_filter([mb_strpos($this->text()[$row], '━'), mb_strpos($this->text()[$row], '●')], \is_int(...)));

        $this->pointer('press', $row, $start + 2, 'left');
        $this->assertTrue($level->hasState('active'));
        $value = $level->getValue();
        // Released over a control placed on the canvas: the host sends leave, not release
        $this->pointer('leave', $row, $start + 2);
        $this->assertFalse($level->hasState('active'), 'Not pressed anymore');
        $this->pointer('move', $row, $start, 'left');
        $this->assertSame($value, $level->getValue(), 'A drag coming from elsewhere does not move it');
    }

    public function testASliderDraggedKeepsTheHoverAndTheCursor()
    {
        $this->mount('<input id="level" type="range" min="0" max="10" value="5"><p id="p"><a id="link" href="https://example.com">Link</a></p>');
        $level = $this->view->getById('level');
        $this->frame();
        [$row] = $this->find('●');
        [$linkRow, $linkCol] = $this->find('Link');
        $this->pointer('move', $row, 1);
        $this->pointer('press', $row, 1, 'left');
        $this->host->receive();

        $this->pointer('move', $linkRow, $linkCol + 1, 'left');
        $this->assertTrue($level->hasState('hover'), 'The slider stays hovered');
        $this->assertFalse($this->view->getById('p')->hasState('hover'), 'Not what is under the pointer');
        $this->assertSame([], array_values(array_filter($this->host->receive(), static fn (array $m): bool => 'cursor' === $m['type'])), 'And keeps its cursor');
    }

    public function testTheHostShowsTheCursorOfWhatIsUnderThePointer()
    {
        $this->mount('<p>Read <a href="https://example.com">the docs</a></p><p style="cursor: help">Help</p><input aria-label="Name">');
        $this->frame();
        [$row, $col] = $this->find('the docs');
        $cursors = function (string $action, int $row, int $col): array {
            $this->host->receive();
            $this->pointer($action, $row, $col);

            return array_values(array_map(static fn (array $m): array => [$m['node'], $m['shape']], array_filter($this->host->receive(), static fn (array $m): bool => 'cursor' === $m['type'])));
        };
        $root = $this->host->root;

        $this->assertSame([[$root, 'pointer']], $cursors('move', $row, $col + 1), 'A link');
        $this->assertSame([], $cursors('move', $row, $col + 2), 'Sent only when it changes');
        $this->assertSame([[$root, 'default']], $cursors('move', $row, 1));
        [$row] = $this->find('Help');
        $this->assertSame([[$root, 'help']], $cursors('move', $row, 1), 'The cursor of CSS');
        [$row] = $this->find('>');
        $this->assertSame([[$root, 'text']], $cursors('move', $row, 3), 'A text field drawn in the canvas');
        $this->assertSame([], $cursors('leave', $row, 3), 'The host goes back to its own pointer by itself');
        $this->assertSame([[$root, 'text']], $cursors('move', $row, 3), 'And is told again once the pointer is back');
    }

    public function testTheCursorFollowsWhatAScrollBringsUnderThePointer()
    {
        $this->mount('<p>Top</p><div id="box" style="overflow: auto; max-height: 3"><div>Line 1</div><div>Line 2</div><div><a href="https://example.com">Link</a></div><div>Line 4</div><div>Line 5</div></div>');
        $this->frame();
        [$row, $col] = $this->find('Line 1');
        $this->pointer('move', $row, $col + 1);
        $this->host->receive();

        $this->pointer('wheel', $row, $col + 1, rows: 2);
        $messages = $this->host->receive();
        $this->assertContains(['type' => 'cursor', 'node' => $this->host->root, 'shape' => 'pointer'], $messages, 'The cursor of the link the scroll brought there, the pointer still');
    }

    public function testAPointerEventComesFirstAndStopsTheClick()
    {
        $this->mount('<button id="go">Go</button>');
        $pressed = 0;
        $this->tui->addListener(static function (PressEvent $event) use (&$pressed): void {
            ++$pressed;
        });
        $events = [];
        $stop = false;
        $this->tui->addListener(static function (PointerEvent $event) use (&$events, &$stop): void {
            $events[] = [$event->getAction(), $event->getButton(), $event->getWidget()?->getId()];
            if ($stop) {
                $event->stopPropagation();
            }
        });
        $this->frame();
        [$row, $col] = $this->find('Go');

        $this->click($row, $col);
        $this->assertSame([['press', 'left', 'go'], ['release', 'left', 'go']], $events, 'With the widget of the view under the pointer');
        $this->assertSame(1, $pressed);
        $stop = true;
        $this->click($row, $col);
        $this->assertSame(1, $pressed, 'Stopped: the view does not take it');
    }

    public function testTheRightButtonOpensTheMenuOfALink()
    {
        $this->tui->stop();
        $this->start(['pointer', 'contextmenu', 'clipboard']);
        $this->mount('<p>Read <a href="https://example.com/docs">the docs</a> now</p>');
        $followed = [];
        $this->tui->addListener(static function (LinkEvent $event) use (&$followed): void {
            $followed[] = $event->getHref();
        });
        $this->frame();
        [$row, $col] = $this->find('the docs');

        $this->pointer('press', $row, $col + 1, 'right');
        $menus = $this->sent('contextmenu');
        $this->assertSame([['type' => 'contextmenu', 'node' => $this->host->root, 'row' => $row, 'column' => $col + 1, 'items' => [['id' => '1:1', 'label' => 'Open Link'], ['id' => '1:2', 'label' => 'Copy Link Address']]]], $menus);
        $this->assertSame([], $followed, 'Not followed by the press');
        $this->host->send(['type' => 'contextmenu-select', 'id' => '1:2']);
        $this->assertSame([['type' => 'clipboard', 'text' => 'https://example.com/docs']], $this->sent('clipboard'));
        $this->host->send(['type' => 'contextmenu-select', 'id' => '1:1']);
        $this->assertSame([], $followed, 'Only the item of the menu shown last, once');

        $this->pointer('press', $row, $col + 1, 'right');
        $this->host->send(['type' => 'contextmenu-select', 'id' => '1:1']);
        $this->assertSame([], $followed, 'An item of another menu, which the host still showed');
        $this->host->send(['type' => 'contextmenu-select', 'id' => '2:1']);
        $this->assertSame(['https://example.com/docs'], $followed);
        $this->host->receive();
        $this->pointer('press', $row, 0, 'right');
        $this->assertSame([], $this->sent('contextmenu'), 'Nothing to show off the link');
    }

    public function testTheRightButtonOnAFieldFocusesItAndOffersPaste()
    {
        $this->tui->stop();
        $this->start(['pointer', 'contextmenu']);
        $this->mount('<p>Text</p><input id="name" aria-label="Name"> <input id="off" aria-label="Off" disabled>');
        $this->frame();
        [$row] = $this->find('>');

        $this->pointer('press', $row, 3, 'right');
        $this->assertSame($this->view->getById('name'), $this->tui->getFocus(), 'Focused, like a browser does');
        $this->assertSame([['id' => '1:1', 'label' => 'Paste', 'role' => 'paste']], $this->sent('contextmenu')[0]['items'] ?? null);
        $this->host->send(['type' => 'paste', 'text' => 'Ada']);
        $this->assertSame('Ada', $this->view->getById('name')->getValue(), 'The host pastes its clipboard');
    }

    public function testAListenerChangesTheItemsOfTheMenu()
    {
        $this->tui->stop();
        $this->start(['pointer', 'contextmenu', 'clipboard']);
        $this->mount('<p id="text">Text and <a href="https://example.com">a link</a></p>');
        $chosen = [];
        $clear = false;
        $this->tui->addListener(static function (ContextMenuEvent $event) use (&$chosen, &$clear): void {
            if ($clear) {
                $event->setItems([]);

                return;
            }
            $event->addItem(ContextMenuItem::separator());
            $event->addItem(new ContextMenuItem('Inspect '.$event->getWidget()?->getId(), static function () use (&$chosen): void {
                $chosen[] = 'inspect';
            }));
            $event->addItem(new ContextMenuItem('Undo', static function () use (&$chosen): void {
                $chosen[] = 'undo';
            }, enabled: false));
        });
        $this->frame();
        [$row] = $this->find('Text');

        $this->pointer('press', $row, 1, 'right');
        $this->assertSame([['separator' => true], ['id' => '1:1', 'label' => 'Inspect text'], ['id' => '1:2', 'label' => 'Undo', 'enabled' => false]], $this->sent('contextmenu')[0]['items'] ?? null);
        $this->host->send(['type' => 'contextmenu-select', 'id' => '1:2']);
        $this->assertSame([], $chosen, 'A disabled item does nothing');
        $this->pointer('press', $row, 1, 'right');
        $this->host->send(['type' => 'contextmenu-select', 'id' => '2:1']);
        $this->assertSame(['inspect'], $chosen);

        $clear = true;
        $this->host->receive();
        $this->pointer('press', $row, 1, 'right');
        $this->assertSame([], $this->sent('contextmenu'), 'No items, no menu');
    }

    public function testOpenLinkFollowsTheLinkRightClickedWhateverIsDrawnThereSince()
    {
        $this->tui->stop();
        $this->start(['pointer', 'contextmenu']);
        $this->mount('<div id="top"></div><p>Read <a href="https://example.com/docs">the docs</a> now</p>');
        $followed = [];
        $this->tui->addListener(static function (LinkEvent $event) use (&$followed): void {
            $followed[] = $event->getHref();
        });
        $pressed = 0;
        $this->tui->addListener(static function (PressEvent $event) use (&$pressed): void {
            ++$pressed;
        });
        $this->frame();
        [$row, $col] = $this->find('the docs');
        $this->pointer('press', $row, $col + 1, 'right');
        $this->assertSame('Open Link', $this->sent('contextmenu')[0]['items'][0]['label'] ?? null);

        // While the menu is open, the page changes: a button is drawn where the link was
        $this->view->getById('top')->add((new HtmlBuilder())->build('<p><button>Pressed</button> instead</p>')->getRoot());
        $this->frame();
        $this->host->send(['type' => 'contextmenu-select', 'id' => '1:1']);
        $this->assertSame(['https://example.com/docs'], $followed);
        $this->assertSame(0, $pressed);
    }

    public function testADisabledFieldHasTheItemsOfTheListenersWithoutPaste()
    {
        $this->tui->stop();
        $this->start(['pointer', 'contextmenu']);
        $this->mount('<input id="name" aria-label="Name"> <input id="off" aria-label="Off" disabled>');
        $this->tui->addListener(static function (ContextMenuEvent $event): void {
            $event->addItem(new ContextMenuItem('Inspect'));
        });
        $this->frame();
        $name = $this->view->getById('name');
        $this->tui->setFocus($name);
        $off = $this->view->getById('off');
        $rect = $this->tui->getWidgetRect($off);

        $this->pointer('press', $rect->row, $rect->col + 2, 'right');
        $this->assertSame([['id' => '1:1', 'label' => 'Inspect']], $this->sent('contextmenu')[0]['items'] ?? null);
        $this->assertSame($name, $this->tui->getFocus(), 'A disabled field does not take the focus');
    }

    public function testAFieldTakesTheFocusOnlyWithAMenu()
    {
        $this->tui->stop();
        $this->start(['pointer', 'contextmenu']);
        $this->mount('<input id="name" aria-label="Name"> <input id="other" aria-label="Other">');
        $this->tui->addListener(static function (ContextMenuEvent $event): void {
            $event->setItems([]);
        });
        $this->frame();
        $name = $this->view->getById('name');
        $this->tui->setFocus($name);
        $rect = $this->tui->getWidgetRect($this->view->getById('other'));

        $this->pointer('press', $rect->row, $rect->col + 2, 'right');
        $this->assertSame([], $this->sent('contextmenu'));
        $this->assertSame($name, $this->tui->getFocus(), 'No menu: the focus stays');
    }

    public function testTheListenersSeeTheFieldFocused()
    {
        $this->tui->stop();
        $this->start(['pointer', 'contextmenu']);
        $this->mount('<input id="name" aria-label="Name"> <input id="other" aria-label="Other">');
        $focused = null;
        $this->tui->addListener(function (ContextMenuEvent $event) use (&$focused): void {
            $focused = $this->tui->getFocus();
        });
        $this->frame();
        $this->tui->setFocus($this->view->getById('name'));
        $other = $this->view->getById('other');
        $rect = $this->tui->getWidgetRect($other);

        $this->pointer('press', $rect->row, $rect->col + 2, 'right');
        $this->assertSame($other, $focused, 'Like in a browser, where the press focuses before the contextmenu event');
        $this->assertSame($other, $this->tui->getFocus());
    }

    public function testTheItemsOfAMenuGoWithTheNextRightPress()
    {
        $this->tui->stop();
        $this->start(['pointer', 'contextmenu']);
        $this->mount('<p>Read <a href="https://example.com/docs">the docs</a> now</p>');
        $followed = [];
        $this->tui->addListener(static function (LinkEvent $event) use (&$followed): void {
            $followed[] = $event->getHref();
        });
        $stop = false;
        $this->tui->addListener(static function (PointerEvent $event) use (&$stop): void {
            if ($stop) {
                $event->stopPropagation();
            }
        });
        $this->frame();
        [$row, $col] = $this->find('the docs');
        $this->pointer('press', $row, $col + 1, 'right');
        $this->assertCount(1, $this->sent('contextmenu'));

        $this->pointer('press', $row, 0, 'right');
        $this->host->send(['type' => 'contextmenu-select', 'id' => '2:1']);
        $this->assertSame([], $followed, 'A press that shows no menu');
        $this->pointer('press', $row, $col + 1, 'right');
        $stop = true;
        $this->pointer('press', $row, $col + 1, 'right');
        $this->host->send(['type' => 'contextmenu-select', 'id' => '3:1']);
        $this->assertSame([], $followed, 'A press a listener stops');
    }

    public function testNoMenuForAHostThatShowsNone()
    {
        $this->mount('<p><a href="https://example.com">a link</a></p>');
        $this->frame();
        [$row, $col] = $this->find('a link');
        $this->pointer('press', $row, $col, 'right');
        $this->assertSame([], $this->sent('contextmenu'));
    }

    public function testAnApplicationWithoutHtmlGetsPointerEvents()
    {
        $this->tui->stop();
        $this->host = new HostStub(['capabilities' => ['pointer', 'contextmenu']] + HostStub::HELLO);
        $this->tui = (new Application(connection: $this->host->connection))->getTui();
        $widget = new PointerTestWidget();
        $this->tui->add($widget);
        $events = [];
        $this->tui->addListener(static function (PointerEvent $event) use (&$events): void {
            $events[] = [$event->getAction(), $event->getRow(), $event->getColumn(), $event->getWidget(), $event->getScrollRows()];
        });
        $menus = 0;
        $this->tui->addListener(static function (ContextMenuEvent $event) use (&$menus, $widget): void {
            ++$menus;
            $event->addItem(new ContextMenuItem('Clear'));
            \assert($event->getWidget() === $widget);
        });
        $this->frame();
        $node = $this->host->find('canvas');
        $this->assertNotNull($node);

        $this->pointer('press', 1, 2, 'left', node: $node);
        $this->pointer('wheel', 1, 2, rows: 3, node: $node);
        $this->assertSame([['press', 1, 2, $widget, 0], ['wheel', 1, 2, $widget, 3]], $events, 'The cell in the canvas, and its widget');
        $this->pointer('press', 0, 0, 'right', node: $node);
        $this->assertSame(1, $menus);
        $this->assertSame('Clear', $this->sent('contextmenu')[0]['items'][0]['label'] ?? null);
    }

    public function testMovingThePointerHovers()
    {
        $this->mount('<button id="go">Go</button><p id="text">Text</p>');
        $this->frame();
        [$row, $col] = $this->find('Go');
        $this->pointer('move', $row, $col);
        $this->assertTrue($this->view->getById('go')->hasState('hover'));
        $this->pointer('move', $row + 1, $col);
        $this->assertFalse($this->view->getById('go')->hasState('hover'));
        $this->assertTrue($this->view->getById('text')->hasState('hover'));
        $this->pointer('leave', $row + 1, 50);
        $this->assertFalse($this->view->getById('text')->hasState('hover'), 'Nothing is hovered once the pointer left the canvas');
    }

    public function testTheWheelScrollsTheContainerUnderThePointer()
    {
        $lines = implode('', array_map(static fn (int $i): string => "<div>Line {$i}</div>", range(1, 10)));
        $this->mount('<p>Top</p><div id="box" style="overflow: auto; max-height: 3">'.$lines.'</div>');
        $this->frame();
        $box = $this->view->getById('box');
        $this->assertInstanceOf(ContainerWidget::class, $box);
        [$row, $col] = $this->find('Line 1');

        $this->pointer('wheel', $row, $col, rows: 2);
        $this->assertSame(2, ContainerState::of($box)->getScrollTop());
        $this->frame();
        $this->assertStringStartsWith('Line 3 ', $this->text()[$row]);
        $this->pointer('wheel', $row, $col, rows: -1);
        $this->assertSame(1, ContainerState::of($box)->getScrollTop());
        $this->pointer('wheel', 0, 0, rows: 3);
        $this->assertSame(1, ContainerState::of($box)->getScrollTop(), 'Not over the box');
    }

    public function testTheWheelScrollsATallFrameAndClicksLandWhereItIsShown()
    {
        $html = '<p><a href="#top-link">Top link</a></p>';
        for ($i = 1; $i <= 30; ++$i) {
            $html .= "<div>Line {$i}</div>";
        }
        $this->mount($html.'<p><a href="https://example.com/end">End link</a></p>');
        $followed = [];
        $this->tui->addListener(static function (LinkEvent $event) use (&$followed): void {
            $followed[] = $event->getHref();
        });
        $this->frame();
        $this->assertNull($this->find('Top link', false), 'The window shows the bottom of the frame');
        [$row, $col] = $this->find('End link');
        $this->click($row, $col);
        $this->assertSame(['https://example.com/end'], $followed, 'Where the window shows it, not where the frame has it');

        $this->pointer('wheel', 5, 5, rows: -100);
        $this->frame();
        $this->assertNotNull($this->find('Top link', false), 'Nothing to scroll there: the window scrolls up the frame, like a terminal');
        $this->pointer('wheel', 5, 5, rows: 2);
        $this->frame();
        $this->assertNull($this->find('Top link', false), 'And back down, as far as there is frame');
        $this->pointer('wheel', 5, 5, rows: -2);
        $this->frame();
        $this->assertNotNull($this->find('Top link', false));
    }

    public function testThePointerOfAnotherNodeOrWithoutAViewIsIgnored()
    {
        $this->mount('<button id="go">Go</button>');
        $pressed = 0;
        $this->tui->addListener(static function (PressEvent $event) use (&$pressed): void {
            ++$pressed;
        });
        $this->frame();
        [$row, $col] = $this->find('Go');
        $this->pointer('press', $row, $col, 'left', node: 999);
        $this->pointer('release', $row, $col, 'left', node: 999);
        $this->host->send(['type' => 'pointer', 'node' => $this->host->root, 'action' => 'release', 'row' => 'x', 'column' => null, 'button' => 'left', 'modifiers' => []]);
        $this->assertSame(0, $pressed);
    }

    /**
     * The messages of a type the application sent since the last call.
     *
     * @return list<array<string, mixed>>
     */
    private function sent(string $type): array
    {
        return array_values(array_filter($this->host->receive(), static fn (array $m): bool => $type === $m['type']));
    }

    private function start(array $capabilities): void
    {
        $this->host = new HostStub(['capabilities' => $capabilities] + HostStub::HELLO);
        $this->html = new HtmlRenderer();
        $this->tui = (new Application(connection: $this->host->connection, renderer: $this->html))->getTui();
    }

    private function mount(string $html): void
    {
        $this->view = (new HtmlBuilder())->build($html)->mount($this->tui);
    }

    private function frame(): void
    {
        static $started = new \WeakMap();
        if (!isset($started[$this->tui])) {
            $started[$this->tui] = true;
            $this->tui->start();
        }
        $this->host->received = [];
        $this->tui->requestRender();
        $this->tui->processRender();
        $this->host->receive();
    }

    private function pointer(string $action, int $row, int $column, ?string $button = null, int $rows = 0, ?int $node = null): void
    {
        $this->host->send(['type' => 'pointer', 'node' => $node ?? $this->host->root, 'action' => $action, 'row' => $row, 'column' => $column, 'button' => $button, 'modifiers' => []] + ('wheel' === $action ? ['rows' => $rows, 'columns' => 0] : []));
    }

    private function click(int $row, int $column): void
    {
        $this->pointer('press', $row, $column, 'left');
        $this->pointer('release', $row, $column, 'left');
    }

    /**
     * The cell of a text in the canvas.
     *
     * @return array{int, int}|null
     */
    private function find(string $needle, bool $required = true): ?array
    {
        foreach ($this->text() as $row => $line) {
            if (false !== $col = mb_strpos($line, $needle)) {
                return [$row, $col];
            }
        }
        if ($required) {
            $this->fail(\sprintf('"%s" is not in the canvas: %s', $needle, implode("\n", $this->text())));
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function text(): array
    {
        return array_map(static fn (array $runs): string => rtrim(implode('', array_column($runs, 't'))), $this->host->nodes[$this->host->root]['props']['lines']);
    }
}

class PointerTestWidget extends AbstractWidget
{
    public function render(RenderContext $context): array
    {
        return ['Drawn', 'by hand'];
    }
}
