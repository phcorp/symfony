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
use Revolt\EventLoop;
use Symfony\Component\Tui\Event\ChangeEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Event\SettingChangeEvent;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Event\TabChangeEvent;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\Direction;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\ProgramStatus\ProgramState;
use Symfony\Component\Tui\Terminal\ProgramStatus\ProgramStatus;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\CollapsibleWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\InputWidget;
use Symfony\Component\Tui\Widget\KeybindingsTrait;
use Symfony\Component\Tui\Widget\ProgressBarWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\SettingItem;
use Symfony\Component\Tui\Widget\SettingsListWidget;
use Symfony\Component\Tui\Widget\TabsWidget;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\TuiApp\Application;
use Symfony\Component\TuiApp\Event\AppearanceChangeEvent;
use Symfony\Component\TuiApp\Event\MenuSelectEvent;
use Symfony\Component\TuiApp\Event\OpenEvent;
use Symfony\Component\TuiApp\Event\WindowCloseEvent;
use Symfony\Component\TuiApp\Exception\LogicException;
use Symfony\Component\TuiApp\Mapper\HostEvent;
use Symfony\Component\TuiApp\Mapper\MapContext;
use Symfony\Component\TuiApp\Mapper\Node;
use Symfony\Component\TuiApp\Mapper\WidgetMapperInterface;
use Symfony\Component\TuiApp\Render\NativeRenderer;
use Symfony\Component\TuiApp\Tests\Fixtures\HostStub;
use Symfony\Component\TuiApp\Tests\Fixtures\WrappingRenderer;

class NativeRendererTest extends TestCase
{
    private HostStub $host;
    private Application $app;
    private Tui $tui;

    protected function setUp(): void
    {
        $this->host = new HostStub();
        $this->app = new Application(new StyleSheet(['.title' => new Style(bold: true, color: 'green')]), connection: $this->host->connection);
        $this->tui = $this->app->getTui();
    }

    public function testDrawsWidgetsWithNativeControls()
    {
        $title = (new TextWidget('What is your name?'))->addStyleClass('title');
        $name = new InputWidget();
        $name->setPrompt('Name: ');
        $this->tui->add($title)->add($name);
        $this->tui->setFocus($name);

        $this->frame();

        $root = $this->host->nodes[$this->host->root];
        $this->assertSame('stack', $root['kind']);
        $this->assertSame(1, $root['props']['grow']);
        [$labelId, $fieldId] = $root['children'];
        $this->assertSame('label', $this->host->nodes[$labelId]['kind']);
        $this->assertSame('What is your name?', $this->host->nodes[$labelId]['props']['text']);
        $this->assertEquals(['bold' => true, 'fg' => '#00cd00'], $this->host->nodes[$labelId]['props']['style']);
        $this->assertSame('textfield', $this->host->nodes[$fieldId]['kind']);
        $this->assertEquals(['value' => '', 'prompt' => 'Name: ', 'focusable' => true], array_intersect_key($this->host->nodes[$fieldId]['props'], ['value' => 1, 'prompt' => 1, 'focusable' => 1]));
        $this->assertSame($fieldId, $this->host->focus);
    }

    public function testOnlyChangesAreSentAgain()
    {
        $text = new TextWidget('One');
        $this->tui->add($text)->add(new TextWidget('Two'));
        $this->frame();

        $this->assertSame([], $this->frame(), 'Nothing changed');

        $text->setText('Uno');
        $patches = $this->frame();
        $this->assertSame([['op' => 'update', 'id' => $this->renderer()->getNodeId($text), 'props' => ['text' => 'Uno']]], $this->ops($patches));
    }

    public function testTypingAndSubmittingInATextField()
    {
        $label = new TextWidget('?');
        $name = new InputWidget();
        $changes = [];
        $name->onChange(static function (ChangeEvent $event) use (&$changes): void {
            $changes[] = $event->getValue();
        });
        $name->onSubmit(static function (SubmitEvent $event) use ($label): void {
            $label->setText('Hello '.$event->getValue().'!');
        });
        $this->tui->add($label)->add($name);
        $this->frame();
        $fieldId = $this->host->find('textfield');

        $this->host->send(['type' => 'event', 'node' => $fieldId, 'name' => 'change', 'value' => 'Ad']);
        $this->host->send(['type' => 'event', 'node' => $fieldId, 'name' => 'change', 'value' => 'Ada']);
        $this->assertSame([], $this->ops($this->frame()), 'The host already shows what was typed');

        $this->host->send(['type' => 'event', 'node' => $fieldId, 'name' => 'submit', 'value' => 'Ada']);
        $this->frame();

        $this->assertSame(['Ad', 'Ada'], $changes);
        $this->assertSame('Hello Ada!', $this->host->nodes[$this->host->find('label')]['props']['text']);
    }

    public function testListenersGetWhatTheTextFieldHolds()
    {
        $name = new InputWidget();
        $changes = [];
        $name->onChange(static function (ChangeEvent $event) use (&$changes): void {
            $changes[] = $event->getValue();
        });
        $this->tui->add($name);
        $this->frame();
        $fieldId = $this->host->find('textfield');

        $this->host->send(['type' => 'event', 'node' => $fieldId, 'name' => 'change', 'value' => "a\x07b"]);
        $this->assertSame([['op' => 'update', 'id' => $fieldId, 'props' => ['value' => $name->getValue()]]], $this->ops($this->frame()), 'The host shows what the field holds');
        $this->host->send(['type' => 'event', 'node' => $fieldId, 'name' => 'change', 'value' => "a\x07b"]);

        $this->assertSame('ab', $name->getValue());
        $this->assertSame([$name->getValue()], $changes, 'Nothing changed the second time');
    }

    public function testCancelingATextFieldPressesItsCancelKey()
    {
        $name = new InputWidget();
        $cancelled = false;
        $name->onCancel(static function () use (&$cancelled): void {
            $cancelled = true;
        });
        $this->tui->add($name);
        $this->frame();

        $this->host->send(['type' => 'event', 'node' => $this->host->find('textfield'), 'name' => 'cancel']);

        $this->assertTrue($cancelled);
    }

    public function testSelectingInAList()
    {
        $list = new SelectListWidget([['value' => 'lib', 'label' => 'Library', 'description' => 'A package'], ['value' => 'app', 'label' => 'Application']]);
        $selected = null;
        $list->onSelect(static function (SelectEvent $event) use (&$selected): void {
            $selected = $event->getValue();
        });
        $this->tui->add($list);
        $this->frame();
        $listId = $this->host->find('list');
        $this->assertSame([['label' => 'Library', 'description' => 'A package'], ['label' => 'Application', 'description' => null]], $this->host->nodes[$listId]['props']['items']);

        $this->host->send(['type' => 'event', 'node' => $listId, 'name' => 'select', 'value' => 1]);

        $this->assertSame('app', $selected);
    }

    public function testCheckingItemsOfAMultipleList()
    {
        $list = new SelectListWidget([['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B'], ['value' => 'c', 'label' => 'C', 'checked' => true]], multiselect: true);
        $this->tui->add($list);
        $this->frame();
        $listId = $this->host->find('list');
        $this->assertSame([2], $this->host->nodes[$listId]['props']['checked']);

        $this->host->send(['type' => 'event', 'node' => $listId, 'name' => 'select', 'value' => [0, 1]]);

        $this->assertSame(['a', 'b'], array_column($list->getSelectedItems(), 'value'));
        $this->assertSame([], array_values(array_filter($this->ops($this->frame()), static fn (array $op): bool => isset($op['props']['checked']))), 'The host already shows the checked items');
        $this->assertSame(0, $this->host->nodes[$listId]['props']['selected'], 'Checking items does not move the highlight');
    }

    public function testSwitchingTabs()
    {
        $tabs = new TabsWidget();
        $tabs->add((new TextWidget('General settings'))->setId('general')->setLabel('General'));
        $tabs->add((new TextWidget('Advanced settings'))->setId('advanced')->setLabel('Advanced'));
        $changed = null;
        $tabs->onTabChange(static function (TabChangeEvent $event) use (&$changed): void {
            $changed = $event->getId();
        });
        $this->tui->add($tabs);
        $this->frame();
        $tabsId = $this->host->find('tabs');
        $this->assertSame(['General', 'Advanced'], $this->host->nodes[$tabsId]['props']['tabs']);
        [$first, $second] = $this->host->nodes[$tabsId]['children'];
        $this->assertSame('General settings', $this->host->nodes[$first]['props']['text']);
        $this->assertSame('stack', $this->host->nodes[$second]['kind']);

        $this->host->send(['type' => 'event', 'node' => $tabsId, 'name' => 'tab', 'value' => 1]);
        $this->frame();

        $this->assertSame('advanced', $changed);
        [$first, $second] = $this->host->nodes[$tabsId]['children'];
        $this->assertSame('stack', $this->host->nodes[$first]['kind']);
        $this->assertSame('Advanced settings', $this->host->nodes[$second]['props']['text']);
        $this->assertSame(1, $this->host->nodes[$tabsId]['props']['active']);
        $this->assertSameTree();
    }

    public function testExpandingADisclosure()
    {
        $details = new CollapsibleWidget('Details', new TextWidget('More'), description: 'Click to see');
        $this->tui->add($details);
        $this->frame();
        $id = $this->host->find('disclosure');
        $this->assertSame([], $this->host->nodes[$id]['children']);

        $this->host->send(['type' => 'event', 'node' => $id, 'name' => 'toggle', 'value' => true]);
        $this->frame();

        $this->assertTrue($details->isExpanded());
        $this->assertSame('More', $this->host->nodes[$this->host->nodes[$id]['children'][0]]['props']['text']);
        $this->assertSameTree();
    }

    public function testChangingASetting()
    {
        $settings = new SettingsListWidget([new SettingItem('theme', 'Theme', 'dark', null, ['light', 'dark']), new SettingItem('name', 'Name', 'Ada')]);
        $changed = null;
        $settings->onChange(static function (SettingChangeEvent $event) use (&$changed): void {
            $changed = [$event->getId(), $event->getValue()];
        });
        $this->tui->add($settings);
        $this->frame();
        $id = $this->host->find('form');
        $this->assertSame([
            ['id' => 'theme', 'label' => 'Theme', 'value' => 'dark', 'description' => null, 'choices' => ['light', 'dark']],
            ['id' => 'name', 'label' => 'Name', 'value' => 'Ada', 'description' => null, 'choices' => null, 'readOnly' => true],
        ], $this->host->nodes[$id]['props']['rows']);

        $this->host->send(['type' => 'event', 'node' => $id, 'name' => 'setting', 'value' => ['id' => 'theme', 'value' => 'light']]);

        $this->assertSame(['theme', 'light'], $changed);
        $this->assertSame('light', $settings->getValue('theme'));
    }

    public function testProgress()
    {
        $bar = new ProgressBarWidget(4);
        $bar->setProgress(1);
        $indeterminate = new ProgressBarWidget();
        $this->tui->add($bar)->add($indeterminate);
        $this->frame();

        [$determinateId, $indeterminateId] = $this->host->nodes[$this->host->root]['children'];
        $this->assertSame(['progress' => 0.25, 'text' => '1/4'], array_intersect_key($this->host->nodes[$determinateId]['props'], ['progress' => 1, 'text' => 1]));
        $this->assertArrayNotHasKey('progress', $this->host->nodes[$indeterminateId]['props']);
    }

    public function testAWidgetWithoutANativeControlDrawsOnACanvas()
    {
        $art = new ArtWidget();
        $this->tui->add($art);
        $this->frame();
        $id = $this->host->find('canvas');

        $this->assertSame(60, $this->host->nodes[$id]['props']['columns']);
        $this->assertSame([[['t' => 'art', 'b' => true, 'fg' => '#cd0000'], ['t' => ' 60']]], $this->host->nodes[$id]['props']['lines']);
        $this->assertSame([0, 4], $this->host->nodes[$id]['props']['cursor'], 'After "art "');

        $this->host->send(['type' => 'rects', 'rects' => [(string) $id => [0, 0, 12, 1]]]);
        $this->frame();

        $this->assertSame(12, $this->host->nodes[$id]['props']['columns']);
        $this->assertSame(' 12', $this->host->nodes[$id]['props']['lines'][0][1]['t']);
        $this->assertEquals(new \Symfony\Component\Tui\Render\WidgetRect(0, 0, 12, 1), $this->tui->getWidgetRect($art));
    }

    public function testKeysGoToTheFocusedWidget()
    {
        $art = new ArtWidget();
        $this->tui->add($art);
        $this->tui->setFocus($art);
        $this->frame();

        $this->host->send(['type' => 'key', 'key' => 'ctrl+s', 'text' => null]);
        $this->host->send(['type' => 'key', 'key' => 'é', 'text' => 'é']);
        $this->host->send(['type' => 'paste', 'text' => 'pasted']);

        $this->assertSame(["\x1b[115;5u", 'é', "\x1b[200~pasted\x1b[201~"], $art->input);

        $this->host->send(['type' => 'key', 'key' => 'f13', 'text' => null]);
        $this->assertCount(3, $art->input, 'A key Tui has no id for is ignored');

        $this->host->send(['type' => 'paste', 'text' => "a\x1b[201~\x1b[115;5u"]);
        $this->assertSame("\x1b[200~a[201~[115;5u\x1b[201~", end($art->input), 'Pasted text cannot end the paste');
    }

    public function testFocusFollowsTheHost()
    {
        $first = new InputWidget();
        $second = new InputWidget();
        $this->tui->add($first)->add($second);
        $this->tui->setFocus($first);
        $this->frame();
        $secondId = $this->renderer()->getNodeId($second);

        $this->host->send(['type' => 'event', 'node' => $secondId, 'name' => 'focus']);
        $messages = $this->frame();

        $this->assertSame($second, $this->tui->getFocus());
        $this->assertSame([], array_values(array_filter($messages, static fn (array $message): bool => 'focus' === $message['type'])), 'The host already focused it');
    }

    public function testAResizeKeepsTheNativeControls()
    {
        $label = new TextWidget('What is your name?');
        $name = new InputWidget();
        $this->tui->add($label)->add($name);
        $this->tui->setFocus($name);
        $this->frame();
        $nodes = array_keys($this->host->nodes);

        // The window grows: unlike a terminal screen, the native controls are still right, and keep their state
        $this->host->send(['type' => 'resize', 'columns' => 100, 'rows' => 30]);

        $this->assertSame([], $this->ops($this->frame()));
        $this->assertSame($nodes, array_keys($this->host->nodes));

        $label->setText('Hello Ada!');
        $this->assertSame([['op' => 'update', 'id' => $this->renderer()->getNodeId($label), 'props' => ['text' => 'Hello Ada!']]], $this->ops($this->frame()));
    }

    public function testTheTreeIsDrawnAgainWhenTheTuiStartsAgain()
    {
        $this->tui->add(new TextWidget('a'));
        $this->frame();
        $this->tui->stop();
        $this->host->receive();
        $this->assertNull($this->host->root, 'Stopping removes the tree');

        $this->tui->start();
        $this->frame();

        $this->assertSame('a', $this->host->nodes[$this->host->nodes[$this->host->root]['children'][0]]['props']['text']);
        $this->tui->stop();
    }

    public function testRemovedAndMovedWidgets()
    {
        $a = new TextWidget('a');
        $b = new TextWidget('b');
        $box = (new ContainerWidget())->setStyle(new Style(direction: Direction::Horizontal));
        $box->add($a)->add($b);
        $c = new TextWidget('c');
        $this->tui->add($box)->add($c);
        $this->frame();
        $this->assertSame('horizontal', $this->host->nodes[$this->host->find('stack', static fn (array $props): bool => 'horizontal' === $props['direction'])]['props']['direction']);

        $box->remove($a);
        $this->tui->remove($c);
        $box->add($c);
        $this->tui->add($a);
        $this->frame();

        $this->assertSameTree();
        $root = $this->host->nodes[$this->host->root];
        $this->assertSame(['b', 'c'], array_map(fn (int $id): string => $this->host->nodes[$id]['props']['text'], $this->host->nodes[$root['children'][0]]['children']));
        $this->assertSame('a', $this->host->nodes[$root['children'][1]]['props']['text']);
    }

    public function testMappersOfTheApplicationAreAskedForEveryWidget()
    {
        $mapper = new class implements WidgetMapperInterface {
            public function supports(AbstractWidget $widget): bool
            {
                return $widget instanceof TextWidget && \in_array('link', $widget->getStyleClasses(), true);
            }

            public function map(AbstractWidget $widget, MapContext $context): Node
            {
                return new Node('progress', ['progress' => 1]);
            }

            public function handle(AbstractWidget $widget, HostEvent $event): void
            {
            }
        };
        $host = new HostStub();
        $app = new Application(mappers: [$mapper], connection: $host->connection);
        $app->getTui()->add(new TextWidget('plain'))->add((new TextWidget('link'))->addStyleClass('link'))->add(new TextWidget('plain'));
        $app->getTui()->start();
        $app->getTui()->processRender();
        $host->receive();

        $kinds = array_map(static fn (int $id): string => $host->nodes[$id]['kind'], $host->nodes[$host->root]['children']);
        $this->assertSame(['label', 'progress', 'label'], $kinds);
    }

    public function testMappersAreUsedWithARendererThatDrawsWithTheNativeOne()
    {
        $renderer = new WrappingRenderer(new Renderer());
        $host = new HostStub();
        $app = new Application(connection: $host->connection, renderer: $renderer);
        $app->getTui()->add(new TextWidget('Hello'))->add(new InputWidget());
        $app->getTui()->start();
        $app->getTui()->processRender();
        $host->receive();

        $this->assertSame('stack', $host->nodes[$host->root]['kind']);
        $this->assertSame(['label', 'textfield'], array_map(static fn (int $id): string => $host->nodes[$id]['kind'], $host->nodes[$host->root]['children']));
    }

    public function testMappersOfTheApplicationWin()
    {
        $mapper = new class implements WidgetMapperInterface {
            public ?HostEvent $handled = null;

            public function supports(AbstractWidget $widget): bool
            {
                return $widget instanceof TextWidget;
            }

            public function map(AbstractWidget $widget, MapContext $context): Node
            {
                return new Node('progress', ['progress' => 0.5]);
            }

            public function handle(AbstractWidget $widget, HostEvent $event): void
            {
                $this->handled = $event;
            }
        };
        $host = new HostStub();
        $app = new Application(mappers: [$mapper], connection: $host->connection);
        $app->getTui()->add(new TextWidget('Half'));
        $app->getTui()->start();
        $app->getTui()->processRender();
        $host->receive();
        $id = $host->find('progress');

        $host->send(['type' => 'event', 'node' => $id, 'name' => 'change', 'value' => 'x']);

        $this->assertSame(['change', 'x'], [$mapper->handled->name, $mapper->handled->value]);
    }

    public function testRunSaysHowTheApplicationEnded()
    {
        $this->tui->add(new TextWidget('a'));
        EventLoop::defer(fn () => $this->tui->stop());
        $this->app->run();
        $this->assertSame([['type' => 'bye', 'status' => 0]], array_values(array_filter($this->host->receive(), static fn (array $message): bool => 'bye' === $message['type'])));

        $host = new HostStub();
        $app = new Application(connection: $host->connection);
        $app->getTui()->add(new TextWidget('a'));
        EventLoop::defer(static fn () => throw new \RuntimeException('Crash'));
        try {
            $app->run();
            $this->fail('The crash goes on');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('Crash', $e->getMessage().($e->getPrevious()?->getMessage() ?? ''));
        } finally {
            $app->getTui()->stop();
        }
        $this->assertSame([['type' => 'bye', 'status' => 1]], array_values(array_filter($host->receive(), static fn (array $message): bool => 'bye' === $message['type'])));
    }

    public function testWindowClose()
    {
        $this->tui->addListener(static function (WindowCloseEvent $event): void {
            $event->keepOpen();
        });
        $this->frame();

        $this->host->send(['type' => 'close']);
        $this->host->receive();

        $this->assertSame(['type' => 'close-reply', 'accept' => false], end($this->host->received));
    }

    public function testWindowCloseStopsTheTui()
    {
        $this->frame();

        $this->host->send(['type' => 'close']);
        $this->host->receive();

        $this->assertContains(['type' => 'close-reply', 'accept' => true], $this->host->received);
        $this->assertNull($this->host->root, 'The tree is removed when the TUI stops');
    }

    public function testMenusAndOpen()
    {
        $events = [];
        $this->tui->addListener(static function (MenuSelectEvent $event) use (&$events): void {
            $events[] = 'menu:'.$event->getId();
        });
        $this->tui->addListener(static function (OpenEvent $event) use (&$events): void {
            $events[] = 'open:'.implode(',', $event->getPaths());
        });
        $this->app->setMenus([['title' => 'File', 'items' => [['id' => 'save', 'label' => 'Save', 'shortcut' => 'ctrl+s'], ['separator' => true]]]]);
        $this->frame();

        $this->host->send(['type' => 'menu-select', 'id' => 'save']);
        $this->host->send(['type' => 'open', 'paths' => ['/tmp/a.txt']]);

        $this->assertSame(['menu:save', 'open:/tmp/a.txt'], $events);
        $this->assertSame('menu', $this->host->received[0]['type']);
    }

    public function testWidgetsChangedByMessagesOfTheHostAreDrawn()
    {
        $text = new TextWidget('ready');
        $this->tui->add($text);
        $this->tui->addListener(static function (MenuSelectEvent $event) use ($text): void {
            $text->setText('menu: '.$event->getId());
        });
        $this->tui->addListener(static function (OpenEvent $event) use ($text): void {
            $text->setText('open: '.implode(',', $event->getPaths()));
        });
        $this->tui->addListener(static function (AppearanceChangeEvent $event) use ($text): void {
            $text->setText('dark: '.($event->isDark() ? 'yes' : 'no'));
        });
        $this->tui->addListener(static function (WindowCloseEvent $event) use ($text): void {
            $event->keepOpen();
            $text->setText('kept open');
        });
        $this->frame();
        $id = $this->renderer()->getNodeId($text);

        foreach ([
            [['type' => 'menu-select', 'id' => 'save'], 'menu: save'],
            [['type' => 'open', 'paths' => ['/tmp/a.txt']], 'open: /tmp/a.txt'],
            [['type' => 'appearance', 'value' => 'light'], 'dark: no'],
            [['type' => 'close'], 'kept open'],
        ] as [$message, $expected]) {
            $this->host->send($message);
            // What the ticker of a running TUI does: render if something asked for it
            $this->tui->processRender();
            $this->host->receive();

            $this->assertSame($expected, $this->host->nodes[$id]['props']['text'], \sprintf('After %s', $message['type']));
        }
    }

    public function testNotificationsProgressAndTheBell()
    {
        $this->frame();
        $terminal = $this->tui->getTerminal();

        $this->app->notify('Export finished', '3 files written');
        $terminal->setProgramStatus(new ProgramStatus(ProgramState::Working, progress: 50));
        $terminal->setProgramStatus(new ProgramStatus(ProgramState::Working));
        $terminal->setProgramStatus(new ProgramStatus(ProgramState::Blocked, progress: 25));
        $terminal->setProgramStatus(new ProgramStatus(ProgramState::Error));
        $terminal->clearProgramStatus();
        $terminal->bell();

        $this->assertSame([
            ['type' => 'notify', 'title' => 'Export finished', 'body' => '3 files written'],
            ['type' => 'window', 'progress' => ['state' => 'normal', 'value' => 0.5]],
            ['type' => 'window', 'progress' => ['state' => 'indeterminate', 'value' => null]],
            ['type' => 'window', 'progress' => ['state' => 'paused', 'value' => 0.25]],
            ['type' => 'window', 'progress' => ['state' => 'error', 'value' => null]],
            ['type' => 'window', 'progress' => null],
            ['type' => 'beep'],
        ], $this->host->receive());
    }

    public function testAppearance()
    {
        $events = [];
        $this->tui->addListener(static function (AppearanceChangeEvent $event) use (&$events): void {
            $events[] = $event->isDark();
        });
        $this->assertTrue($this->app->isDark(), 'hello said dark');

        $this->host->send(['type' => 'appearance', 'value' => 'light']);
        $this->assertFalse($this->app->isDark());
        $this->host->send(['type' => 'appearance', 'value' => 'dark']);
        $this->assertTrue($this->app->isDark());

        $this->assertSame([false, true], $events);
        $this->assertNull((new Application(connection: false))->isDark(), 'unknown in a terminal');
    }

    public function testResize()
    {
        $this->tui->add(new ArtWidget());
        $this->frame();

        $this->host->send(['type' => 'resize', 'columns' => 30, 'rows' => 10]);
        $this->frame();

        $this->assertSame(30, $this->tui->getTerminal()->getColumns());
        $this->assertSame(30, $this->host->nodes[$this->host->find('canvas')]['props']['columns']);
    }

    public function testTheNativeRendererNeedsAHost()
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('draws in the window of a TuiApp host');

        new Tui(terminal: new VirtualTerminal(), renderer: new NativeRenderer());
    }

    public function testRunsInTheTerminalWithoutAHost()
    {
        $app = new Application(connection: false);

        $this->assertFalse($app->isNative());
        $this->assertNotInstanceOf(NativeRenderer::class, $app->getTui()->getRenderer());
    }

    /**
     * Draws a frame and lets the host stub apply it.
     *
     * @return list<array<string, mixed>>
     */
    private function frame(): array
    {
        static $started = new \WeakMap();
        if (!isset($started[$this->tui])) {
            $started[$this->tui] = true;
            $this->tui->start();
        }
        // Like the ticker of a running TUI, which renders when the widget tree changed
        $this->tui->requestRender();
        $this->tui->processRender();
        $messages = $this->host->receive();
        $this->assertSameTree();

        return $messages;
    }

    private function renderer(): NativeRenderer
    {
        $renderer = $this->tui->getRenderer();
        \assert($renderer instanceof NativeRenderer);

        return $renderer;
    }

    /**
     * @param list<array<string, mixed>> $messages
     *
     * @return list<array<string, mixed>>
     */
    private function ops(array $messages): array
    {
        $ops = [];
        foreach ($messages as $message) {
            if ('patch' === $message['type']) {
                array_push($ops, ...$message['ops']);
            }
        }

        return $ops;
    }

    private function assertSameTree(): void
    {
        $this->assertSame(json_encode($this->renderer()->dumpTree()), json_encode($this->host->dump()), 'The host has the tree the renderer thinks it has');
    }
}

class ArtWidget extends AbstractWidget implements FocusableInterface
{
    use FocusableTrait;
    use KeybindingsTrait;

    /** @var list<string> */
    public array $input = [];

    public function handleInput(string $data): void
    {
        $this->input[] = $data;
    }

    public function render(RenderContext $context): array
    {
        return ["\x1b[1;31mart\x1b[0m \x1b_pi:c;block\x07".$context->getColumns()];
    }
}
