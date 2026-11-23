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
use Symfony\Component\Tui\Event\ChangeEvent;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\TuiApp\Application;
use Symfony\Component\TuiApp\Tests\Fixtures\HostStub;
use Symfony\Component\TuiHtml\Event\FormSubmitEvent;
use Symfony\Component\TuiHtml\Event\SelectionChangeEvent;
use Symfony\Component\TuiHtml\Html\HtmlBuilder;
use Symfony\Component\TuiHtml\Html\HtmlView;
use Symfony\Component\TuiHtml\Render\Renderer as HtmlRenderer;
use Symfony\Component\TuiHtml\Widget\DropdownWidget;
use Symfony\Component\TuiHtml\Widget\InputWidget;
use Symfony\Component\TuiHtml\Widget\LinkedTextWidget;
use Symfony\Component\TuiHtml\Widget\SelectListWidget;

/**
 * The form controls of HTML views as native controls over the canvas, for a host that places them.
 */
class HtmlControlsTest extends TestCase
{
    private const FORM = <<<'HTML'
        <style>
          form { display: grid; grid-template-columns: 8ch 1fr; gap: 0 1ch }
        </style>
        <form>
          <label for="name">Name</label> <input id="name" name="name" placeholder="Your name">
          <label for="secret">Secret</label> <input id="secret" name="secret" type="password">
          <label for="notes">Notes</label> <textarea id="notes" name="notes" rows="2"></textarea>
          <label for="news">News</label> <input id="news" name="news" type="checkbox">
          <label>Size</label> <span><input type="radio" id="small" name="size" value="s" checked> <input type="radio" id="large" name="size" value="l"></span>
          <label for="color">Color</label> <select id="color" name="color"><option>Red</option><option>Green</option><option>Blue</option></select>
          <label for="off">Off</label> <input id="off" name="off" disabled>
          <span></span> <button id="create">Create</button>
        </form>
        HTML;

    private const INPUTS = <<<'HTML'
        <form>
          <input id="count" name="count" type="number" min="0" max="10" step="0.5" value="1" aria-label="Count">
          <input id="secret" name="secret" type="password" maxlength="8" aria-label="Secret">
          <input id="level" name="level" type="range" step="5" aria-label="Level">
          <select id="size" name="size" size="3" aria-label="Size"><option value="s">S</option><option value="m" selected>M</option><option value="l">L</option></select>
          <select id="colors" name="colors[]" multiple aria-label="Colors"><option value="r" selected>Red</option><option value="g">Green</option><option value="b">Blue</option></select>
          <button id="go">Go</button>
        </form>
        HTML;

    private const FRUITS = '<select id="fruit" size="4" aria-label="Fruit"><option value="apple">Apple</option><option value="banana">Banana</option><option value="blueberry">Blueberry</option><option value="cherry">Cherry</option></select>';

    private HostStub $host;
    private HtmlRenderer $html;
    private Tui $tui;
    private HtmlView $view;

    protected function setUp(): void
    {
        $this->host = new HostStub(['capabilities' => ['canvas-controls']] + HostStub::HELLO);
        $this->html = new HtmlRenderer();
        $this->tui = (new Application(connection: $this->host->connection, renderer: $this->html))->getTui();
    }

    protected function tearDown(): void
    {
        $this->tui->stop();
    }

    public function testTheControlsArePlacedWhereTheFrameHasThem()
    {
        $view = $this->mount(self::FORM);
        $this->frame();

        $canvas = $this->host->nodes[$this->host->root];
        $this->assertSame('canvas', $canvas['kind']);
        $this->assertSame(['tab', 'shift+tab'], $canvas['props']['keys']);
        $this->assertSame(['textfield', 'textarea', 'checkbox', 'checkbox', 'checkbox', 'popup', 'button', 'textfield'], array_map(fn (int $id): string => $this->host->nodes[$id]['kind'], $canvas['children']), 'In the order of the focus, the disabled field last; the password stays in the canvas');

        foreach (['name', 'notes', 'create', 'off'] as $id) {
            $rect = $this->html->getWidgetRect($view->getById($id));
            $this->assertSame([$rect?->row, $rect?->col, $rect?->columns, $rect?->rows], $this->props($id)['place'], $id);
            $this->assertArrayNotHasKey('clip', $this->props($id));
        }
        // A checkbox takes the cells of its mark, not those of its box: a cell of the grid, the space before it
        foreach (['news' => '[ ]', 'small' => '(•)', 'large' => '( )'] as $id => $mark) {
            $rect = $this->html->getWidgetRect($view->getById($id));
            $place = $this->props($id)['place'];
            $this->assertSame([$rect?->row, 3, 1], [$place[0], $place[2], $place[3]], $id);
            $this->assertGreaterThanOrEqual($rect?->col, $place[1]);
            $this->assertSame($mark, mb_substr($this->text()[$place[0]], $place[1], 3), $id);
        }
        $this->assertGreaterThan(3, $this->html->getWidgetRect($view->getById('news'))?->columns);
        $field = $view->getById('color');
        $this->assertInstanceOf(DropdownWidget::class, $field);
        $rect = $this->html->getWidgetRect($field->getField());
        $this->assertSame([$rect?->row, $rect?->col, $rect?->columns, $rect?->rows], $this->props('color')['place'], 'A select is placed where its field is');

        $this->assertSame(['label' => 'Name', 'place' => $this->props('name')['place'], 'value' => ''], $this->props('name'));
        $this->assertSame(['choices' => ['Red', 'Green', 'Blue'], 'label' => 'Color', 'place' => $this->props('color')['place'], 'selected' => 0], $this->props('color'));
        $this->assertSame('Create', $this->props('create')['text']);
        $this->assertTrue($this->props('off')['disabled']);
        $this->assertTrue($this->props('small')['checked']);
        $this->assertFalse($this->props('large')['checked']);
        $this->assertSame($this->props('small')['group'], $this->props('large')['group'], 'The radios of a name are a group');
        $this->assertArrayNotHasKey('group', $this->props('news'));
        $this->assertSame($this->host->root, $this->host->focus, 'Nothing has the focus before the first Tab: the canvas has it');
    }

    public function testAHostThatCannotPlaceControlsGetsOneCanvas()
    {
        $this->host = new HostStub();
        $this->tui = (new Application(connection: $this->host->connection, renderer: new HtmlRenderer()))->getTui();
        $view = $this->mount(self::FORM);
        $this->frame();

        $this->assertSame([$this->host->root], array_keys($this->host->nodes));
        $this->assertSame(['tab', 'shift+tab'], $this->host->nodes[$this->host->root]['props']['keys']);

        $this->key('tab');
        $this->assertSame($view->getById('name'), $this->tui->getFocus(), 'Tab moves the focus, like in a browser');
        $this->key('tab');
        $this->assertSame($view->getById('secret'), $this->tui->getFocus());
        $this->key('shift+tab');
        $this->assertSame($view->getById('name'), $this->tui->getFocus());
        $this->frame();
        $this->assertSame($this->host->root, $this->host->focus, 'The canvas keeps the keys');

        // What an input method commits comes as typed keys
        foreach (['日', '本', '👍🏽', 'shift+é'] as $key) {
            $this->key($key, 'shift+é' === $key ? 'É' : $key);
        }
        $this->assertSame('日本👍🏽É', $view->getById('name')->getValue());
    }

    public function testANativeFieldIsFilledLikeTyping()
    {
        $view = $this->mount(self::FORM);
        $submitted = [];
        $view->querySelector('form')?->onSubmit(static function (FormSubmitEvent $event) use (&$submitted): void {
            $submitted[] = $event->getValues();
        });
        $changes = [];
        $view->getById('name')->on(ChangeEvent::class, static function (ChangeEvent $event) use (&$changes): void {
            $changes[] = $event->getValue();
        });
        $this->frame();
        $name = $this->node('name');

        $this->event($name, 'change', 'Ada');
        $this->assertSame('Ada', $view->getById('name')->getValue());
        $this->assertSame(['Ada'], $changes, 'Listeners get the change, like when typing');
        $this->frame();
        $this->assertSame([], $this->updatesOf($name), 'The field shows it already');
        $this->assertMatchesRegularExpression('/^Name {5}> Ada/m', implode("\n", $this->text()), 'The canvas under it too');

        $this->event($name, 'change', "Ada\x07 L.");
        $this->frame();
        $this->assertSame([['value' => 'Ada L.']], $this->updatesOf($name), 'The field gets back the value the widget keeps');

        $this->event($this->node('notes'), 'change', "line 1\nline 2");
        $this->assertSame("line 1\nline 2", $view->getById('notes')->getText());

        $this->event($name, 'submit', 'Ada');
        $this->assertSame([['name' => 'Ada', 'secret' => '', 'notes' => "line 1\nline 2", 'size' => 's', 'color' => 'Red']], $submitted, 'Enter submits the form, like an implicit submission');
    }

    public function testCheckboxesRadiosPopupsAndButtons()
    {
        $view = $this->mount(self::FORM);
        $submitted = [];
        $view->querySelector('form')?->onSubmit(static function (FormSubmitEvent $event) use (&$submitted): void {
            $submitted[] = $event->getValues();
        });
        $this->frame();

        $this->event($this->node('news'), 'toggle', true);
        $this->event($this->node('large'), 'toggle', true);
        $this->event($this->node('color'), 'select', 2);
        $this->frame();
        $this->assertFalse($this->props('small')['checked'], 'Checking a radio unchecks the others of its group');
        $this->assertTrue($this->props('large')['checked']);
        $this->assertSame(2, $this->props('color')['selected']);
        $this->assertStringContainsString('[ Blue ▾ ]', implode("\n", $this->text()));

        $this->event($this->node('small'), 'toggle', false);
        $this->frame();
        $this->assertFalse($this->props('small')['checked'], 'A radio is not unchecked by itself');

        $this->event($this->node('large'), 'toggle', false);
        $this->frame();
        $this->assertTrue($this->props('large')['checked'], 'The host gets back that it stays checked');

        $this->event($this->node('color'), 'select', 7);
        $this->event($this->node('create'), 'press');
        $this->assertSame([['name' => '', 'secret' => '', 'notes' => '', 'news' => 'on', 'size' => 'l', 'color' => 'Blue']], $submitted);

        $this->event($this->node('news'), 'toggle', false);
        $this->frame();
        $this->assertFalse($this->props('news')['checked']);

        $this->event($this->node('off'), 'change', 'no');
        $this->assertSame('', $view->getById('off')->getValue(), 'A disabled field takes no input');
    }

    public function testTabMovesTheFocusInTheOrderOfTheView()
    {
        $view = $this->mount(<<<'HTML'
            <form>
              <input id="first" name="first">
              <a href="#somewhere">A link</a>
              <select id="choice"><option>A</option><option>B</option></select>
              <button id="go" tabindex="1">Go</button>
            </form>
            HTML);
        $this->frame();
        $this->assertSame(['button', 'textfield', 'popup'], array_map(fn (int $id): string => $this->host->nodes[$id]['kind'], $this->host->nodes[$this->host->root]['children']), 'A positive tabindex comes first');

        $this->key('tab');
        $this->frame();
        $this->assertSame($this->node('go'), $this->host->focus);
        $this->key('tab');
        $this->frame();
        $this->assertSame($this->node('first'), $this->host->focus);
        $this->key('tab');
        $this->frame();
        $this->assertSame($this->host->root, $this->host->focus, 'A link is drawn in the canvas: the canvas takes the focus');
        $this->assertNotNull($this->tui->getFocus());
        $this->key('tab');
        $this->frame();
        $this->assertSame($this->node('choice'), $this->host->focus);
        $this->assertSame($view->getById('choice')->getField(), $this->tui->getFocus());
        $this->key('shift+tab');
        $this->frame();
        $this->assertSame($this->host->root, $this->host->focus);
    }

    public function testTheHostMovesTheFocus()
    {
        $view = $this->mount(self::FORM);
        $this->frame();

        $this->event($this->node('color'), 'focus');
        $this->assertSame($view->getById('color')->getField(), $this->tui->getFocus());
        $this->frame();
        $this->assertSame(['focus' => false], ['focus' => $this->hasFocusMessage()], 'The host has it already');

        $this->event($this->host->root, 'focus');
        $this->assertNull($this->tui->getFocus(), 'A click on the canvas takes the focus out of the control');
        $this->frame();
        $this->assertFalse($this->hasFocusMessage());
    }

    public function testControlsOutOfTheWindowAreHiddenAndCutOnesClipped()
    {
        $html = '<div id="top"><input id="above"></div>';
        for ($i = 1; $i <= 20; ++$i) {
            $html .= '<div>Line '.$i.'</div>';
        }
        $html .= '<textarea id="cut" rows="6"></textarea><div inert><input id="inert"></div>';
        $view = $this->mount($html);
        $this->frame();

        $this->assertSame(['hidden' => true, 'value' => ''], array_diff_key($this->props('above'), ['place' => true]), 'Above the window');
        $this->assertTrue($this->props('inert')['hidden'], 'An inert field takes no input');

        $inWindow = $this->tui->getWidgetRect($view->getById('cut'));
        $this->assertNotNull($inWindow);
        $this->assertSame([$inWindow->row, $inWindow->col, $inWindow->columns, $inWindow->rows], $this->props('cut')['place']);
        $this->tui->setScrollOffset(HostStub::HELLO['rows'] - $inWindow->row - $inWindow->rows + 2);
        $this->frame();
        $place = $this->props('cut')['place'];
        $this->assertGreaterThan(HostStub::HELLO['rows'], $place[0] + $place[3], 'The window shows its top only');
        $this->assertSame([$place[0], $place[1], $place[2], HostStub::HELLO['rows'] - $place[0]], $this->props('cut')['clip']);

        $this->tui->setScrollOffset(0);
        $this->frame();
        $this->assertArrayNotHasKey('clip', $this->props('cut'));
    }

    public function testAControlHalfScrolledOrCutByABoxIsPlacedWholeAndClipped()
    {
        $lines = implode('', array_map(static fn (int $i): string => "<div>Line {$i}</div>", range(1, 8)));
        $this->mount('<div id="box" style="overflow: auto; max-height: 6"><div>Top</div><textarea id="notes" rows="3"></textarea>'.$lines.'</div><div style="overflow: hidden; height: 4; border: 1px solid"><div>x</div><textarea id="cut" rows="3"></textarea></div><p>After</p>');
        $this->frame();
        $whole = $this->props('notes')['place'];
        $this->assertArrayNotHasKey('clip', $this->props('notes'));
        $this->view->getById('box')->scrollTo(2);
        $this->frame();
        $place = $this->props('notes')['place'];
        $this->assertSame([$whole[0] - 2, $whole[1], $whole[2], $whole[3]], $place, 'At its whole size, its top scrolled out');
        $this->assertSame([0, $place[1], $place[2], $place[3] - 1], $this->props('notes')['clip'], 'Cut to the rows the box shows');

        // The box of overflow: hidden shows 2 rows, x and the top of the text area, at its whole size
        $cut = $this->props('cut');
        $this->assertSame(5, $cut['place'][3], 'At its whole size, with its border');
        $this->assertSame([$cut['place'][0], $cut['place'][1], $cut['place'][2], 1], $cut['clip'] ?? null, 'Cut by the box of overflow: hidden');
    }

    public function testAHiddenControlWithTheFocusLeavesItToTheCanvas()
    {
        $html = '<input id="field">';
        for ($i = 1; $i <= 30; ++$i) {
            $html .= '<div>Line '.$i.'</div>';
        }
        $view = $this->mount($html);
        $this->tui->setFocus($view->getById('field'));
        $this->frame();

        $this->assertTrue($this->props('field')['hidden']);
        $this->assertSame($this->host->root, $this->host->focus, 'The canvas takes the keys of a field the window does not show');
    }

    public function testANativeFieldKeepsToItsMaxLength()
    {
        $view = $this->mount('<input id="code" maxlength="3"> <textarea id="memo" maxlength="5"></textarea> <input id="long" maxlength="2" value="abcd">');
        $changes = [];
        $view->getById('code')->on(ChangeEvent::class, static function (ChangeEvent $event) use (&$changes): void {
            $changes[] = $event->getValue();
        });
        $this->frame();
        $this->assertSame(3, $this->props('code')['maxLength'], 'Hosts may stop typing at it');
        $this->assertSame(5, $this->props('memo')['maxLength']);

        $this->event($this->node('code'), 'change', 'abcdef');
        $this->assertSame('abc', $view->getById('code')->getValue(), 'What is past it is cut, like a paste');
        $this->assertSame(['abc'], $changes);
        $this->frame();
        $this->assertSame(['abc'], array_column($this->updatesOf($this->node('code')), 'value'), 'The field gets back the value kept');

        $this->event($this->node('memo'), 'change', "ab\ncdefg");
        $this->assertSame("ab\ncd", $view->getById('memo')->getText());
        $this->frame();
        $this->assertSame(["ab\ncd"], array_column($this->updatesOf($this->node('memo')), 'value'));

        $this->event($this->node('long'), 'change', 'abc');
        $this->assertSame('abc', $view->getById('long')->getValue(), 'Like typing, an edit that does not make a value past it longer is kept');
    }

    public function testFieldsThatCompleteStayInTheCanvasAndTabCompletesThem()
    {
        $dir = sys_get_temp_dir().'/tui-app-'.bin2hex(random_bytes(4));
        mkdir($dir);
        touch($dir.'/report.txt');
        try {
            $view = $this->mount('<input id="name"> <input id="file" type="file"> <input id="fruit" list="fruits"><datalist id="fruits"><option>banana</option></datalist> <input id="word">');
            $word = $view->getById('word');
            $this->assertInstanceOf(InputWidget::class, $word);
            $word->setSuggestions(['kiwi']);
            $this->frame();
            $this->assertSame([$this->node('name')], $this->host->nodes[$this->host->root]['children'], 'A file field and fields with suggestions complete in the canvas');

            $file = $view->getById('file');
            $this->tui->setFocus($file);
            $this->typeText($dir.'/rep');
            $this->key('tab');
            $this->assertSame($file, $this->tui->getFocus(), 'Tab takes the completion');
            $this->assertSame($dir.'/report.txt', $file->getValue());
            $this->key('tab');
            $this->assertNotSame($file, $this->tui->getFocus(), 'Without one, Tab moves the focus');

            $this->tui->setFocus($word);
            $this->typeText('ki');
            $this->key('tab');
            $this->assertSame('kiwi', $word->getValue());
            $this->assertSame($word, $this->tui->getFocus());
        } finally {
            unlink($dir.'/report.txt');
            rmdir($dir);
        }
    }

    public function testImageButtonsAreLabeledWithText()
    {
        $svg = 'data:image/svg+xml,'.rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="8" height="8"><rect width="8" height="8"/></svg>');
        $this->mount('<input type="image" id="send" src="'.$svg.'" alt="Send"> <button id="save"><img src="'.$svg.'" alt=""> Save</button>');
        $this->frame();

        $this->assertSame('Send', $this->props('send')['text']);
        $this->assertSame('Save', $this->props('save')['text']);
    }

    public function testControlsCssDrawsStayInTheCanvas()
    {
        $this->mount(<<<'HTML'
            <style>.plain { appearance: none }</style>
            <input id="name">
            <button id="go" class="plain">Go</button>
            <select id="color" class="plain"><option>Red</option></select>
            <textarea id="notes" class="plain"></textarea>
            HTML);
        $this->frame();

        $this->assertSame([$this->node('name')], $this->host->nodes[$this->host->root]['children']);
    }

    public function testACheckboxInATallCellIsPlacedOnItsMark()
    {
        $view = $this->mount(<<<'HTML'
            <style>.row { display: grid; grid-template-columns: 20ch 6ch; align-items: stretch }</style>
            <div class="row"><textarea rows="4"></textarea><input type="checkbox" id="top"></div>
            <div class="row"><textarea rows="4"></textarea><input type="checkbox" id="padded" style="padding-top: 2em"></div>
            HTML);
        $this->frame();

        foreach (['top' => 0, 'padded' => 2] as $id => $line) {
            $rect = $this->html->getWidgetRect($view->getById($id));
            $this->assertGreaterThan(1, $rect?->rows, 'The cell is stretched');
            $this->assertSame([$rect?->row + $line, 3, 1], [$this->props($id)['place'][0], $this->props($id)['place'][2], $this->props($id)['place'][3]], $id);
            $this->assertSame('[ ]', mb_substr($this->text()[$this->props($id)['place'][0]], $this->props($id)['place'][1], 3), $id);
        }
    }

    public function testControlsInACenteredGridCellAreWhereTheyAreDrawn()
    {
        $this->mount(<<<'HTML'
            <style>.row { display: grid; grid-template-columns: 20ch 12ch 6ch; align-items: center }</style>
            <div class="row"><textarea rows="5"></textarea><button id="go">Go</button><input type="checkbox" id="ok"></div>
            HTML);
        $this->frame();

        $row = array_key_first(array_filter($this->text(), static fn (string $line): bool => str_contains($line, 'Go')));
        $this->assertGreaterThan(0, $row, 'The button is drawn below the top of its cell');
        $this->assertSame($row, $this->props('go')['place'][0], 'The native button is on its drawn row');
        $this->assertSame('[ ]', mb_substr($this->text()[$this->props('ok')['place'][0]], $this->props('ok')['place'][1], 3), 'The native checkbox is on its drawn mark');
    }

    public function testADisabledPopupTakesNoChoice()
    {
        $view = $this->mount('<select id="color"><option>Red</option><option>Green</option></select>');
        $this->frame();
        $select = $view->getById('color');
        $this->assertInstanceOf(DropdownWidget::class, $select);
        $select->setState(AbstractWidget::STATE_DISABLED);
        $this->frame();
        $this->assertTrue($this->props('color')['disabled']);

        $this->event($this->node('color'), 'select', 1);
        $this->assertSame(0, $select->getList()->getSelectedIndex());
    }

    public function testOptionalKindsAreDrawnOnlyByAHostThatListsThem()
    {
        $this->mount(self::INPUTS);
        $this->frame();

        $this->assertSame(['textfield', 'button'], $this->kinds(), 'The number field is a text field; the password, the slider and the lists stay in the canvas');
        $this->assertSame($this->node('count'), $this->host->nodes[$this->host->root]['children'][0]);
    }

    public function testAPasswordFieldIsASecureField()
    {
        $view = $this->mountWithKinds(self::INPUTS);
        $submitted = [];
        $view->querySelector('form')?->onSubmit(static function (FormSubmitEvent $event) use (&$submitted): void {
            $submitted[] = $event->getValues();
        });
        $this->frame();
        $this->assertSame(['label' => 'Secret', 'maxLength' => 8, 'place' => $this->props('secret')['place'], 'value' => ''], $this->props('secret'));

        $this->event($this->node('secret'), 'change', 's3cret-and-more');
        $this->assertSame('s3cret-a', $view->getById('secret')->getValue(), 'Cut to its maxlength');
        $this->frame();
        $this->assertSame([['value' => 's3cret-a']], $this->updatesOf($this->node('secret')));
        $this->assertStringNotContainsString('s3cret', implode("\n", $this->text()), 'The canvas under it masks it');

        $this->event($this->node('secret'), 'submit', 's3cret-a');
        $this->assertSame('s3cret-a', $submitted[0]['secret'] ?? null, 'Enter submits the form');
    }

    public function testANumberFieldHasItsRange()
    {
        $view = $this->mountWithKinds(self::INPUTS);
        $this->frame();
        $this->assertSame(['label' => 'Count', 'max' => 10.0, 'min' => 0.0, 'place' => $this->props('count')['place'], 'step' => 0.5, 'value' => '1'], $this->props('count'));

        $this->event($this->node('count'), 'change', '2.5');
        $this->assertSame('2.5', $view->getById('count')->getValue());
        $this->assertContains('valid', $view->getById('count')->getStateFlags());
        $this->event($this->node('count'), 'change', '2.5x');
        $this->assertSame('2.5x', $view->getById('count')->getValue(), 'What is typed is kept, invalid, like in a browser');
        $this->assertContains('invalid', $view->getById('count')->getStateFlags());
        $this->frame();
        $this->assertSame([], $this->updatesOf($this->node('count')), 'The field shows it already');
    }

    public function testASliderSnapsToItsStep()
    {
        $view = $this->mountWithKinds(self::INPUTS);
        $changes = [];
        $view->getById('level')->on(ChangeEvent::class, static function (ChangeEvent $event) use (&$changes): void {
            $changes[] = $event->getValue();
        });
        $this->frame();
        $this->assertSame(['label' => 'Level', 'max' => 100.0, 'min' => 0.0, 'place' => $this->props('level')['place'], 'step' => 5.0, 'value' => 50.0], $this->props('level'));

        $this->event($this->node('level'), 'change', 73);
        $this->assertSame(75.0, $view->getById('level')->getValue());
        $this->assertSame(['75'], $changes, 'Listeners get the change, like when moving it with the keys');
        $this->frame();
        $this->assertSame([['value' => 75.0]], $this->updatesOf($this->node('level')), 'The slider gets back the value on a step');

        $this->event($this->node('level'), 'change', 80.0);
        $this->frame();
        $this->assertSame([], $this->updatesOf($this->node('level')));
        $this->event($this->node('level'), 'change', 'high');
        $this->assertSame(80.0, $view->getById('level')->getValue(), 'Not a number');
    }

    public function testSizedAndMultipleSelectsAreLists()
    {
        $view = $this->mountWithKinds(self::INPUTS);
        $submitted = [];
        $view->querySelector('form')?->onSubmit(static function (FormSubmitEvent $event) use (&$submitted): void {
            $submitted[] = $event->getValues();
        });
        $moves = [];
        $view->getById('size')->on(SelectionChangeEvent::class, static function (SelectionChangeEvent $event) use (&$moves): void {
            $moves[] = $event->getItem()['value'];
        });
        $this->frame();
        $this->assertSame(['choices' => ['S', 'M', 'L'], 'label' => 'Size', 'place' => $this->props('size')['place'], 'selected' => [1]], $this->props('size'));
        $this->assertSame(3, $this->props('size')['place'][3], 'As tall as its size');
        $this->assertSame(['choices' => ['Red', 'Green', 'Blue'], 'label' => 'Colors', 'multiple' => true, 'place' => $this->props('colors')['place'], 'selected' => [0]], $this->props('colors'));

        $this->event($this->node('size'), 'select', [2]);
        $this->assertSame(['l'], $moves);
        $this->event($this->node('colors'), 'select', [1, 2]);
        $this->frame();
        $this->assertSame([], $this->updatesOf($this->node('size')), 'The lists show it already');
        $this->assertSame([], $this->updatesOf($this->node('colors')));

        $this->event($this->node('colors'), 'select', [1, 2, 7]);
        $this->frame();
        $this->assertSame([['selected' => [1, 2]]], $this->updatesOf($this->node('colors')), 'An index past the choices is dropped');
        $this->event($this->node('go'), 'press');
        $this->assertSame('l', $submitted[0]['size'] ?? null);
        $this->assertSame(['g', 'b'], $submitted[0]['colors'] ?? null, 'Both checked');
    }

    public function testSlidersAndListsCssDrawsStayInTheCanvas()
    {
        $this->mountWithKinds('<style>.plain { appearance: none }</style><input id="level" type="range" class="plain"> <select id="colors" multiple class="plain"><option>A</option></select> <input id="name">');
        $this->frame();

        $this->assertSame([$this->node('name')], $this->host->nodes[$this->host->root]['children']);
    }

    public function testAControlUnderWhatTheViewDrawsOverItIsHidden()
    {
        $view = $this->mount(<<<'HTML'
            <style>select { appearance: none }</style>
            <div><select id="color"><option>Red</option><option>Green</option><option>Blue</option></select></div>
            <div><input id="below"> <button id="beside">Go</button></div>
            <p>One</p><p>Two</p><p>Three</p>
            <input id="far">
            <dialog id="dialog"><input id="inside"></dialog>
            HTML);
        $this->frame();
        $this->assertArrayNotHasKey('hidden', $this->props('below'));

        $select = $view->getById('color');
        $this->assertInstanceOf(DropdownWidget::class, $select);
        $select->setOpen(true);
        $this->frame();
        $this->assertTrue($this->props('below')['hidden'], 'The list the view draws, over it');
        $this->assertArrayNotHasKey('hidden', $this->props('far'), 'Not under it');

        $select->setOpen(false);
        $this->frame();
        $this->assertArrayNotHasKey('hidden', $this->props('below'));

        $this->view->showModal($view->getById('dialog'));
        $this->frame();
        $covered = array_filter(['below', 'beside', 'far'], fn (string $id): bool => $this->props($id)['hidden'] ?? false);
        $this->assertNotSame([], $covered, 'The dialog over the page');
        $this->assertArrayNotHasKey('hidden', $this->props('inside'), 'What is in the dialog is over it');
    }

    public function testAControlIsHiddenOnlyUnderWhatIsShownOfABoxOverIt()
    {
        $view = $this->mount(<<<'HTML'
            <div style="position: relative; overflow: hidden; height: 2"><div id="pop" style="position: absolute; top: 0; left: 0; width: 12ch; height: 6; background: red">Pop</div></div>
            <input id="below">
            HTML);
        $this->frame();

        $pop = $this->html->getWidgetRect($view->getById('pop'));
        $below = $this->html->getWidgetRect($view->getById('below'));
        $this->assertNotNull($pop);
        $this->assertNotNull($below);
        $this->assertTrue($pop->row + $pop->rows > $below->row && $pop->col <= $below->col, 'Its box covers the field');
        $this->assertNotNull($pop->clip);
        $this->assertLessThanOrEqual($below->row, $pop->clip[2], 'What is shown of it does not');
        $this->assertArrayNotHasKey('hidden', $this->props('below'), 'The field shows where the box is cut');
    }

    public function testTheSuggestionsOfAFieldStayInTheCanvas()
    {
        $view = $this->mountWithKinds('<input id="fruit" list="fruits"><datalist id="fruits"><option>banana</option><option>blueberry</option></datalist> <input id="name">');
        $this->frame();
        $this->tui->setFocus($view->getById('fruit'));
        $this->typeText('b');
        $this->frame();

        $this->assertSame(['textfield'], $this->kinds(), 'The list of suggestions is a part of the field: a native list would take the focus from it');
    }

    public function testSelectsWithGroupsStayInTheCanvas()
    {
        $this->mountWithKinds('<select id="colors" size="4" aria-label="Colors"><optgroup label="Warm"><option>Red</option></optgroup><optgroup label="Cold"><option>Blue</option></optgroup></select> <select id="plain" size="2" aria-label="Plain"><option>A</option><option>B</option></select>');
        $this->frame();

        $this->assertSame(['listbox'], $this->kinds(), 'A native list has no headings: the one with groups stays drawn with them');
        $this->assertSame(['A', 'B'], $this->props('plain')['choices']);
    }

    public function testPopupsWithGroupsStayInTheCanvas()
    {
        $this->mountWithKinds('<select id="colors" aria-label="Colors"><optgroup label="Warm"><option>Red</option></optgroup><optgroup label="Cold"><option>Blue</option></optgroup></select> <select id="plain" aria-label="Plain"><option>A</option><option>B</option></select>');
        $this->frame();

        $this->assertSame(['popup'], $this->kinds(), 'A native pop-up has no headings: the one with groups stays drawn with them');
        $this->assertSame(['A', 'B'], $this->props('plain')['choices']);
    }

    public function testTabCompletesOnlyAtTheEndOfAField()
    {
        $view = $this->mount('<input id="word"> <input id="next">');
        $word = $view->getById('word');
        $this->assertInstanceOf(InputWidget::class, $word);
        $word->setSuggestions(['kiwi']);
        $this->frame();
        $this->tui->setFocus($word);
        $this->typeText('ki');
        $this->key('left');
        $this->key('tab');
        $this->assertSame('ki', $word->getValue(), 'Not at its end: the field does not take Tab');
        $this->assertSame($view->getById('next'), $this->tui->getFocus(), 'Tab moves the focus');

        $this->tui->setFocus($word);
        $this->key('end');
        $this->key('tab');
        $this->assertSame('kiwi', $word->getValue());
        $this->assertSame($word, $this->tui->getFocus());
    }

    public function testTabGoesThroughTheLinksOfAText()
    {
        $view = $this->mount('<input id="before"> <p id="links"><a href="#one">One</a> and <a href="#two">Two</a> and <a href="#three">Three</a></p> <input id="after">');
        $links = $view->getById('links');
        $this->assertInstanceOf(LinkedTextWidget::class, $links);
        $this->frame();
        $this->tui->setFocus($view->getById('before'));
        $this->frame();

        $this->key('tab');
        $this->assertSame([$links, '#one'], [$this->tui->getFocus(), $links->getCurrentHref()]);
        $this->key('tab');
        $this->assertSame([$links, '#two'], [$this->tui->getFocus(), $links->getCurrentHref()], 'Tab goes to its next link, like in a terminal');
        $this->key('tab');
        $this->assertSame('#three', $links->getCurrentHref());
        $this->key('tab');
        $this->assertSame($view->getById('after'), $this->tui->getFocus(), 'From its last link, to what follows');
        $this->key('shift+tab');
        $this->assertSame([$links, '#three'], [$this->tui->getFocus(), $links->getCurrentHref()], 'Shift+Tab into it: on its last link, like in a browser');
        $this->key('shift+tab');
        $this->assertSame([$links, '#two'], [$this->tui->getFocus(), $links->getCurrentHref()], 'Shift+Tab goes to its previous link');
        $this->key('shift+tab');
        $this->key('shift+tab');
        $this->assertSame($view->getById('before'), $this->tui->getFocus(), 'From its first link, to what is before');
    }

    public function testAListWithAFilterShowsTheItemsItShows()
    {
        $view = $this->mountWithKinds(self::FRUITS);
        $list = $view->getById('fruit');
        $this->assertInstanceOf(SelectListWidget::class, $list);
        $list->setFilter('c');
        $moves = [];
        $list->on(SelectionChangeEvent::class, static function (SelectionChangeEvent $event) use (&$moves): void {
            $moves[] = $event->getItem()['value'];
        });
        $this->frame();
        $this->assertSame(['Cherry'], $this->props('fruit')['choices']);
        $this->assertSame([0], $this->props('fruit')['selected']);

        $list->setFilter('b')->setSelectedIndex(1);
        $this->frame();
        $this->assertSame(['Banana', 'Blueberry'], $this->props('fruit')['choices']);
        $this->assertSame([1], $this->props('fruit')['selected']);
        $this->event($this->node('fruit'), 'select', [0]);
        $this->assertSame('banana', $list->getSelectedItem()['value'] ?? null);
        $this->assertSame(['banana'], $moves);

        $this->event($this->node('fruit'), 'select', [2]);
        $this->frame();
        $this->assertSame('banana', $list->getSelectedItem()['value'] ?? null, 'An item the list does not show is not chosen');
        $this->assertSame([[0]], array_column($this->updatesOf($this->node('fruit')), 'selected'), 'The list gets back the item it highlights');

        $list->setFilter('z');
        $this->frame();
        $this->assertSame([], $this->props('fruit')['choices']);
        $this->assertSame([], $this->props('fruit')['selected']);
    }

    public function testAMultipleListWithAFilterChecksTheItemsChosen()
    {
        $view = $this->mountWithKinds(str_replace('size="4"', 'multiple', self::FRUITS));
        $list = $view->getById('fruit');
        $this->assertInstanceOf(SelectListWidget::class, $list);
        $list->setFilter('b');
        $this->frame();
        $this->assertSame(['Banana', 'Blueberry'], $this->props('fruit')['choices']);

        $this->event($this->node('fruit'), 'select', [0, 7]);

        $this->assertSame(['banana'], array_column($list->getSelectedItems(), 'value'));
        $this->frame();
        $this->assertSame([0], $this->props('fruit')['selected']);
    }

    public function testAPopupWithAFilterShowsTheItemsItShows()
    {
        $view = $this->mount(str_replace(' size="4"', '', self::FRUITS));
        $select = $view->getById('fruit');
        $this->assertInstanceOf(DropdownWidget::class, $select);
        $select->getList()->setFilter('c');
        $this->frame();
        $this->assertSame(['Cherry'], $this->props('fruit')['choices']);
        $this->assertSame(0, $this->props('fruit')['selected']);

        $select->getList()->setFilter('b');
        $this->frame();
        $this->event($this->node('fruit'), 'select', 0);
        $this->assertSame('banana', $select->getList()->getSelectedItem()['value'] ?? null);

        $this->event($this->node('fruit'), 'select', 2);
        $this->assertSame('banana', $select->getList()->getSelectedItem()['value'] ?? null, 'An item the pop-up does not show is not chosen');
    }

    public function testAPasswordFieldWithSuggestionsStaysInTheCanvas()
    {
        $view = $this->mountWithKinds('<input id="secret" type="password" aria-label="Secret"> <input id="name">');
        $secret = $view->getById('secret');
        $this->assertInstanceOf(InputWidget::class, $secret);
        $secret->setSuggestions(['hunter2']);
        $this->frame();

        $this->assertSame(['textfield'], $this->kinds(), 'Tab completes it in the canvas');
    }

    private function mountWithKinds(string $html): HtmlView
    {
        $this->tui->stop();
        $this->host = new HostStub(['capabilities' => ['canvas-controls', 'password', 'number', 'slider', 'listbox']] + HostStub::HELLO);
        $this->tui = (new Application(connection: $this->host->connection, renderer: $this->html = new HtmlRenderer()))->getTui();

        return $this->mount($html);
    }

    /**
     * @return list<string>
     */
    private function kinds(): array
    {
        return array_map(fn (int $id): string => $this->host->nodes[$id]['kind'], $this->host->nodes[$this->host->root]['children']);
    }

    private function mount(string $html): HtmlView
    {
        return $this->view = (new HtmlBuilder())->build($html)->mount($this->tui);
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

    private function typeText(string $text): void
    {
        foreach (mb_str_split($text) as $char) {
            $this->key($char, $char);
        }
    }

    private function key(string $key, ?string $text = null): void
    {
        $this->host->send(['type' => 'key', 'key' => $key, 'text' => $text]);
    }

    private function event(int $node, string $name, mixed $value = null): void
    {
        $this->host->send(['type' => 'event', 'node' => $node, 'name' => $name] + (null === $value ? [] : ['value' => $value]));
    }

    /**
     * The node of the control of an element.
     */
    private function node(string $id): int
    {
        $node = $this->tui->getRenderer()->getNodeId($this->view->getById($id));
        $this->assertNotNull($node, $id);
        $this->assertContains($node, $this->host->nodes[$this->host->root]['children']);

        return $node;
    }

    /**
     * @return array<string, mixed>
     */
    private function props(string $id): array
    {
        $props = $this->host->nodes[$this->node($id)]['props'];
        ksort($props);

        return $props;
    }

    /**
     * The props the last frame updated on a node.
     *
     * @return list<array<string, mixed>>
     */
    private function updatesOf(int $node): array
    {
        $updates = [];
        foreach ($this->host->received as $message) {
            foreach ('patch' === $message['type'] ? $message['ops'] : [] as $op) {
                if ('update' === $op['op'] && $node === $op['id']) {
                    $updates[] = (array) $op['props'];
                }
            }
        }

        return $updates;
    }

    private function hasFocusMessage(): bool
    {
        return [] !== array_filter($this->host->received, static fn (array $message): bool => 'focus' === $message['type']);
    }

    /**
     * @return list<string>
     */
    private function text(): array
    {
        return array_map(static fn (array $runs): string => rtrim(implode('', array_column($runs, 't'))), $this->host->nodes[$this->host->root]['props']['lines']);
    }
}
