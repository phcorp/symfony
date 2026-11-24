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
use Symfony\Component\Tui\Event\AbstractEvent;
use Symfony\Component\Tui\Event\ChangeEvent;
use Symfony\Component\Tui\Event\ExpandEvent;
use Symfony\Component\Tui\Event\MultiSelectEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Event\SelectionChangeEvent;
use Symfony\Component\Tui\Event\SelectionToggleEvent;
use Symfony\Component\Tui\Event\SettingChangeEvent;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Event\TabChangeEvent;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\CollapsibleWidget;
use Symfony\Component\Tui\Widget\EditorWidget;
use Symfony\Component\Tui\Widget\InputWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\SettingItem;
use Symfony\Component\Tui\Widget\SettingsListWidget;
use Symfony\Component\Tui\Widget\TabsWidget;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\TuiApp\Application;
use Symfony\Component\TuiApp\Mapper\HostEvent;
use Symfony\Component\TuiApp\Mapper\MapContext;
use Symfony\Component\TuiApp\Mapper\Node;
use Symfony\Component\TuiApp\Mapper\WidgetMapperInterface;
use Symfony\Component\TuiApp\Render\NativeRenderer;
use Symfony\Component\TuiApp\Tests\Fixtures\HostStub;

/**
 * What the host sends about its native controls, applied like the keyboard does, the host shown what the widget keeps.
 */
class NativeRendererEventsTest extends TestCase
{
    private HostStub $host;
    private Tui $tui;
    private bool $started = false;

    protected function setUp(): void
    {
        $this->host = new HostStub();
        $this->tui = (new Application(connection: $this->host->connection))->getTui();
    }

    protected function tearDown(): void
    {
        $this->tui->stop();
    }

    public function testATextFieldGetsBackWhatTheWidgetKeeps()
    {
        $field = new InputWidget();
        $field->setValue('a');
        $this->tui->add($field);
        $this->frame();
        $id = $this->host->find('textfield');

        $this->event($id, 'change', "a\x01");
        $this->assertSame('a', $field->getValue());
        $this->frame();

        $this->assertSame('a', $this->host->nodes[$id]['props']['value']);
        $this->assertSameTree();
    }

    public function testATextAreaGetsBackWhatTheWidgetKeeps()
    {
        $editor = new EditorWidget();
        $editor->setText('a');
        $this->tui->add($editor);
        $this->frame();
        $id = $this->host->find('textarea');

        $this->event($id, 'change', "a\x01");
        $this->assertSame('a', $editor->getText());
        $this->frame();

        $this->assertSame('a', $this->host->nodes[$id]['props']['value']);
        $this->assertSameTree();
    }

    public function testAListGetsBackAnIndexPastItsItems()
    {
        $list = new SelectListWidget([['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']]);
        $events = &$this->record($list);
        $this->tui->add($list);
        $this->frame();
        $id = $this->host->find('list');

        $this->event($id, 'select', 7);
        $this->event($id, 'select', -1);
        $this->frame();

        $this->assertSame([], $events, 'No item is chosen');
        $this->assertSame(0, $this->host->nodes[$id]['props']['selected']);
        $this->assertSameTree();
    }

    public function testATabListenerThatGoesBackIsShown()
    {
        $tabs = new TabsWidget();
        $tabs->add((new TextWidget('One'))->setId('one')->setLabel('One'));
        $tabs->add((new TextWidget('Two'))->setId('two')->setLabel('Two'));
        $tabs->onTabChange(static function (TabChangeEvent $event) use ($tabs): void {
            if ('two' === $event->getId()) {
                $tabs->setActiveTab(0);
            }
        });
        $this->tui->add($tabs);
        $this->frame();
        $id = $this->host->find('tabs');

        $this->event($id, 'tab', 1);
        $this->frame();

        $this->assertSame(0, $tabs->getActiveTabIndex());
        $this->assertSame(0, $this->host->nodes[$id]['props']['active']);
        $this->assertSameTree();
    }

    public function testADisclosureListenerThatCollapsesIsShown()
    {
        $details = new CollapsibleWidget('Details', new TextWidget('More'));
        $details->on(ExpandEvent::class, static fn () => $details->collapse());
        $this->tui->add($details);
        $this->frame();
        $id = $this->host->find('disclosure');

        $this->event($id, 'toggle', true);
        $this->frame();

        $this->assertFalse($details->isExpanded());
        $this->assertFalse($this->host->nodes[$id]['props']['expanded']);
        $this->assertSameTree();
    }

    public function testADisabledWidgetTakesNoEvent()
    {
        $field = new InputWidget();
        $field->setState(AbstractWidget::STATE_DISABLED);
        $events = &$this->record($field);
        $this->tui->add($field);
        $this->frame();
        $id = $this->host->find('textfield');

        $this->event($id, 'submit', 'hacked');
        $this->frame();

        $this->assertSame([], $events);
        $this->assertSame('', $field->getValue());
        $this->assertSame('', $this->host->nodes[$id]['props']['value'], 'The host gets back the value');
        $this->assertSameTree();
    }

    public function testChoosingInAListMovesToTheItemFirst()
    {
        $list = new SelectListWidget([['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B'], ['value' => 'c', 'label' => 'C']]);
        $events = &$this->record($list);
        $this->tui->add($list);
        $this->frame();
        $id = $this->host->find('list');

        $this->event($id, 'select', 2);
        $this->assertSame([[SelectionChangeEvent::class, 'c'], [SelectEvent::class, 'c']], $events, 'Like Down, Down and Enter');

        $events = [];
        $this->event($id, 'select', 2);
        $this->assertSame([[SelectEvent::class, 'c']], $events, 'Like Enter');
    }

    public function testCheckingItemsOfAMultipleListIsLikeSpaceAndEnterConfirms()
    {
        $list = new SelectListWidget([['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B'], ['value' => 'c', 'label' => 'C']], multiselect: true);
        $events = &$this->record($list);
        $this->tui->add($list);
        $this->tui->setFocus($list);
        $this->frame();
        $id = $this->host->find('list');

        $this->event($id, 'select', [0, 2, 9]);
        $this->assertSame([[SelectionToggleEvent::class, 'a'], [SelectionToggleEvent::class, 'c']], $events);

        $events = [];
        $this->host->send(['type' => 'key', 'key' => 'enter']);
        $this->assertSame([[MultiSelectEvent::class, 'a,c']], $events);
        $this->frame();
        $this->assertSame([0, 2], $this->host->nodes[$id]['props']['checked']);
        $this->assertSameTree();
    }

    public function testASettingWithoutChoicesIsReadOnly()
    {
        $this->tui->add(new SettingsListWidget([new SettingItem('theme', 'Theme', 'dark', null, ['light', 'dark']), new SettingItem('name', 'Name', 'Ada')]));
        $this->frame();
        $rows = $this->host->nodes[$this->host->find('form')]['props']['rows'];

        $this->assertArrayNotHasKey('readOnly', $rows[0]);
        $this->assertTrue($rows[1]['readOnly'] ?? null, 'Only the application changes it');
    }

    public function testASettingTakesOnlyItsChoices()
    {
        $settings = new SettingsListWidget([new SettingItem('theme', 'Theme', 'dark', null, ['light', 'dark']), new SettingItem('name', 'Name', 'Ada')]);
        $changes = [];
        $settings->onChange(static function (SettingChangeEvent $event) use (&$changes): void {
            $changes[] = [$event->getId(), $event->getValue()];
        });
        $this->tui->add($settings);
        $this->frame();
        $id = $this->host->find('form');
        $rows = $this->host->nodes[$id]['props']['rows'];

        $this->event($id, 'setting', ['id' => 'theme', 'value' => 'purple']);
        $this->event($id, 'setting', ['id' => 'name', 'value' => 'Eve']);
        $this->assertSame([], $changes);
        $this->assertSame('dark', $settings->getValue('theme'));
        $this->assertSame('Ada', $settings->getValue('name'));
        $ops = $this->frame();
        $this->assertSame([['op' => 'update', 'id' => $id, 'props' => ['rows' => $rows]]], $ops, 'The host gets back the values');

        $this->event($id, 'setting', ['id' => 'theme', 'value' => 'light']);
        $this->assertSame([['theme', 'light']], $changes);
    }

    public function testTheEventGoesToTheMapperThatDrewTheNode()
    {
        $mapper = new class implements WidgetMapperInterface {
            public bool $supports = true;
            public ?HostEvent $handled = null;

            public function supports(AbstractWidget $widget): bool
            {
                return $this->supports && $widget instanceof TextWidget;
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
        $this->host = new HostStub();
        $this->tui = (new Application(mappers: [$mapper], connection: $this->host->connection))->getTui();
        $this->tui->add(new TextWidget('Half'));
        $this->frame();
        $id = $this->host->find('progress');

        $mapper->supports = false;
        $this->event($id, 'change', 'x');

        $this->assertSame('x', $mapper->handled?->value);
    }

    /**
     * @return list<array<string, mixed>> The ops of the frame
     */
    private function frame(): array
    {
        if (!$this->started) {
            $this->started = true;
            $this->tui->start();
        }
        $this->tui->requestRender();
        $this->tui->processRender();
        $ops = [];
        foreach ($this->host->receive() as $message) {
            if ('patch' === $message['type']) {
                array_push($ops, ...array_map(static fn (array $op): array => array_map(static fn (mixed $value): mixed => \is_object($value) ? (array) $value : $value, $op), $message['ops']));
            }
        }

        return $ops;
    }

    private function event(int $node, string $name, mixed $value = null): void
    {
        $this->host->send(['type' => 'event', 'node' => $node, 'name' => $name] + (null === $value ? [] : ['value' => $value]));
    }

    /**
     * @return list<array{class-string, string}>
     */
    private function &record(AbstractWidget $widget): array
    {
        $events = [];
        $record = static function (AbstractEvent $event) use (&$events): void {
            $value = match (true) {
                $event instanceof MultiSelectEvent => implode(',', array_column($event->getItems(), 'value')),
                $event instanceof SelectionToggleEvent, $event instanceof SelectionChangeEvent, $event instanceof SelectEvent => $event->getItem()['value'] ?? $event->getValue(),
                $event instanceof ChangeEvent, $event instanceof SubmitEvent => $event->getValue(),
                default => '',
            };
            $events[] = [$event::class, $value];
        };
        foreach ([ChangeEvent::class, SubmitEvent::class, SelectEvent::class, SelectionChangeEvent::class, SelectionToggleEvent::class, MultiSelectEvent::class] as $class) {
            $widget->on($class, $record);
        }

        return $events;
    }

    private function assertSameTree(): void
    {
        $renderer = $this->tui->getRenderer();
        \assert($renderer instanceof NativeRenderer);
        $this->assertSame(json_encode($renderer->dumpTree()), json_encode($this->host->dump()), 'The host has the tree the renderer thinks it has');
    }
}
