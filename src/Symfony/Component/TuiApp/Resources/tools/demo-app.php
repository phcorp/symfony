<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Widget\CancellableLoaderWidget;
use Symfony\Component\Tui\Widget\CollapsibleWidget;
use Symfony\Component\Tui\Widget\EditorWidget;
use Symfony\Component\Tui\Widget\InputWidget;
use Symfony\Component\Tui\Widget\MarkdownWidget;
use Symfony\Component\Tui\Widget\ProgressBarWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\SettingItem;
use Symfony\Component\Tui\Widget\SettingsListWidget;
use Symfony\Component\Tui\Widget\TabsWidget;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\TuiApp\Application;
use Symfony\Component\TuiApp\Event\MenuSelectEvent;

require getenv('TUI_APP_AUTOLOAD') ?: __DIR__.'/autoload.php';

$app = new Application(new StyleSheet(['.title' => new Style(bold: true, color: 'green')]));
$tui = $app->getTui();

$title = (new TextWidget('What is your name?'))->addStyleClass('title');
$name = new InputWidget();
$name->onSubmit(static function (SubmitEvent $event) use ($title): void {
    $title->setText('Hello '.$event->getValue().'!');
});

$notes = new EditorWidget();
$notes->setText("line 1\nline 2");

$kind = new SelectListWidget([
    ['value' => 'library', 'label' => 'Library', 'description' => 'A reusable package'],
    ['value' => 'application', 'label' => 'Application'],
]);

$settings = new SettingsListWidget([
    new SettingItem('theme', 'Theme', 'dark', null, ['light', 'dark']),
    new SettingItem('name', 'Name', 'Ada', 'Your name'),
]);

$tabs = new TabsWidget();
$tabs->add((new TextWidget('General settings'))->setId('general')->setLabel('General'));
$tabs->add((new TextWidget('Advanced settings'))->setId('advanced')->setLabel('Advanced'));

$progress = new ProgressBarWidget(4);
$progress->setProgress(1);
$progress->setMessage('Copying 1 of 4');

$loader = new CancellableLoaderWidget('Loading…');
$loader->start();

$tui->add($title)
    ->add($name)
    ->add($notes)
    ->add($kind)
    ->add($settings)
    ->add($tabs)
    ->add(new CollapsibleWidget('Details', new TextWidget("plain \e[1mbold\e[0m \e[31;4mred\e[0m")))
    ->add($progress)
    ->add(new ProgressBarWidget())
    ->add($loader)
    ->add(new MarkdownWidget("# Title\n\n* *item*"));
$tui->setFocus($name);

$app->setMenus([['title' => 'File', 'items' => [['id' => 'hello', 'label' => 'Say hello', 'shortcut' => 'ctrl+h']]]]);
$tui->addListener(static function (MenuSelectEvent $event) use ($app): void {
    $app->notify('Menu', $event->getId());
});

$app->run();
