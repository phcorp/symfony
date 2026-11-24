<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Symfony\Component\TuiApp\Application;
use Symfony\Component\TuiHtml\Html\HtmlBuilder;
use Symfony\Component\TuiHtml\Render\Renderer as HtmlRenderer;
use Symfony\Component\TuiHtml\Widget\DropdownWidget;

require $argv[1] ?? (getenv('TUI_APP_AUTOLOAD') ?: __DIR__.'/autoload.php');

$html = <<<'HTML'
    <style>
      form { display: grid; grid-template-columns: 10ch 1fr; gap: 0 1ch }
      .plain { appearance: none }
    </style>
    <h1>Native controls</h1>
    <form>
      <label for="name">Name</label> <input id="name" name="name" placeholder="Your name">
      <label for="secret">Password</label> <input id="secret" name="secret" type="password" maxlength="12">
      <label for="count">Count</label> <input id="count" name="count" type="number" min="0" max="10" step="0.5" value="1">
      <label for="level">Level</label> <input id="level" name="level" type="range" step="5">
      <label for="size">Size</label> <select id="size" name="size" size="3"><option>Small</option><option selected>Medium</option><option>Large</option></select>
      <label for="colors">Colors</label> <select id="colors" name="colors[]" multiple size="3"><option selected>Red</option><option>Green</option><option selected>Blue</option></select>
      <label for="fruit">Fruit</label> <select id="fruit" name="fruit"><option>Apple</option><option>Pear</option></select>
      <label for="plain">CSS select</label> <select id="plain" name="plain" class="plain"><option>One</option><option>Two</option><option>Three</option><option>Four</option></select>
      <label for="lang">Completes</label> <input id="lang" name="lang" list="langs"><datalist id="langs"><option>PHP</option><option>Python</option></datalist>
      <label for="news">News</label> <span><input id="news" name="news" type="checkbox" checked> by mail</span>
      <label for="notes">Notes</label> <textarea id="notes" name="notes" rows="2"></textarea>
      <span></span> <span><button id="save">Save</button> <button id="open" type="button">Open dialog</button></span>
    </form>
    <p>Links: <a href="#one">one</a>, <a href="#two">two</a> and <a href="#three">three</a>.</p>
    <dialog id="dialog"><p>A dialog over the form</p><input id="inside" value="In the dialog"> <button id="close" type="button">Close</button></dialog>
    HTML;

$app = new Application(renderer: new HtmlRenderer());
$view = (new HtmlBuilder())->build($html)->mount($app->getTui());
$dialog = $view->getById('dialog');
$view->getById('open')->on(Symfony\Component\TuiHtml\Event\PressEvent::class, static fn () => $view->showModal($dialog));
$view->getById('close')->on(Symfony\Component\TuiHtml\Event\PressEvent::class, static fn () => $view->close($dialog));

match (getenv('HTML_DEMO')) {
    'dropdown' => ($plain = $view->getById('plain')) instanceof DropdownWidget ? $plain->setOpen(true) : null,
    'dialog' => $view->showModal($dialog),
    default => null,
};

$app->run();
