<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Symfony\Component\Tui\Terminal\ProgramStatus\ProgramState;
use Symfony\Component\Tui\Terminal\ProgramStatus\ProgramStatus;
use Symfony\Component\Tui\Widget\InputWidget;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\TuiApp\Application;
use Symfony\Component\TuiApp\Event\AppearanceChangeEvent;
use Symfony\Component\TuiApp\Event\MenuSelectEvent;
use Symfony\Component\TuiApp\Event\OpenEvent;
use Symfony\Component\TuiApp\Event\WindowCloseEvent;

require $argv[1] ?? (getenv('TUI_APP_AUTOLOAD') ?: __DIR__.'/autoload.php');

$app = new Application();
$tui = $app->getTui();

// notify, progress and the bell go out before the first frame, which reads "ready".
$app->notify('Feature test', 'notify reached the host');
$tui->getTerminal()->setProgramStatus(new ProgramStatus(ProgramState::Working, progress: 50));
$tui->getTerminal()->bell();
$status = new TextWidget('ready');
$name = new InputWidget();
$tui->add($status)->add($name);
$tui->setFocus($name);

$app->setMenus([['title' => 'File', 'items' => [
    ['id' => 'file.save', 'label' => 'Save', 'shortcut' => 'ctrl+s'],
    ['separator' => true],
    ['id' => 'file.wrap', 'label' => 'Wrap lines', 'checked' => true],
]]]);

$tui->addListener(static function (OpenEvent $event) use ($status): void {
    $status->setText('open: '.implode(' | ', $event->getPaths()));
});
$tui->addListener(static function (AppearanceChangeEvent $event) use ($status): void {
    $status->setText('appearance: '.($event->isDark() ? 'dark' : 'light'));
});
$tui->addListener(static function (MenuSelectEvent $event) use ($status): void {
    $status->setText('menu: '.$event->getId());
});
$closes = 0;
$tui->addListener(static function (WindowCloseEvent $event) use ($status, &$closes): void {
    if (1 === ++$closes) {
        $event->keepOpen();
        $status->setText('close: kept open');

        return;
    }
    // Hung: the close is never answered, and SIGTERM does not stop it either.
    fwrite(\STDERR, "hanging\n");
    if (function_exists('pcntl_signal')) {
        pcntl_signal(\SIGTERM, \SIG_IGN);
    }
    while (true) {
        sleep(60);
    }
});

$app->run();
