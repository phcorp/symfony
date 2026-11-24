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
use Symfony\Component\TuiHtml\Event\LinkEvent;
use Symfony\Component\TuiHtml\Html\HtmlBuilder;
use Symfony\Component\TuiHtml\Render\Renderer as HtmlRenderer;

require $argv[1] ?? (getenv('LINK_APP_AUTOLOAD') ?: exit("usage: php link-app.php <autoload.php>\n"));

const LINK = 'https://example.com/docs';
$log = getenv('LINK_APP_LOG') ?: 'php://stderr';
$words = implode(' ', array_fill(0, 400, 'link'));
$html = '<p><a href="'.LINK.'">'.$words.'</a></p>';

$app = new Application(renderer: new HtmlRenderer());
(new HtmlBuilder())->build($html)->mount($app->getTui());
$app->getTui()->getEventDispatcher()->addListener(LinkEvent::class, static function (LinkEvent $event) use ($log): void {
    file_put_contents($log, 'followed '.$event->getHref()."\n", \FILE_APPEND);
});
$app->run();
