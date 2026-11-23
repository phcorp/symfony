#!/usr/bin/env python3
import argparse
from pathlib import Path
import shutil
import json
import struct
import zlib

parser = argparse.ArgumentParser()
parser.add_argument('symfony', type=Path)
parser.add_argument('output', type=Path)
args = parser.parse_args()
source, output = args.symfony.resolve(), args.output.resolve()
output.mkdir(parents=True, exist_ok=True)
ignored_patterns = shutil.ignore_patterns('Tests', 'tests', '*.md', '.git*', '.build', 'build', 'obj', 'publish', 'out', '__pycache__')
def ignore(directory, names):
    excluded = set(ignored_patterns(directory, names))
    if 'Resources' in Path(directory).parts:
        excluded -= {'Tests', 'tests'}
        if 'windows' in Path(directory).parts:
            excluded.add('bin')
    return excluded

for component in ['Tui', 'TuiApp', 'TuiHtml', 'EventDispatcher', 'String']:
    shutil.copytree(source / 'src/Symfony/Component' / component, output / 'src/Symfony/Component' / component, dirs_exist_ok=True, ignore=ignore)
shutil.copytree(source / 'src/Symfony/Contracts', output / 'src/Symfony/Contracts', dirs_exist_ok=True, ignore=ignore)
for package in ['revolt/event-loop', 'psr/log', 'psr/event-dispatcher', 'symfony/polyfill-intl-normalizer', 'symfony/polyfill-intl-grapheme', 'symfony/polyfill-mbstring', 'symfony/polyfill-ctype']:
    shutil.copytree(source / 'vendor' / package, output / 'vendor' / package, dirs_exist_ok=True, ignore=ignore)
(output / 'vendor/autoload.php').write_text(r'''<?php
spl_autoload_register(static function (string $class): void {
    if ('Normalizer' === $class) { require __DIR__.'/symfony/polyfill-intl-normalizer/Resources/stubs/Normalizer.php'; return; }
    $map = [
        'Symfony\\Component\\' => __DIR__.'/../src/Symfony/Component/',
        'Symfony\\Contracts\\' => __DIR__.'/../src/Symfony/Contracts/',
        'Revolt\\' => __DIR__.'/revolt/event-loop/src/',
        'Psr\\Log\\' => __DIR__.'/psr/log/src/',
        'Psr\\EventDispatcher\\' => __DIR__.'/psr/event-dispatcher/src/',
        'Symfony\\Polyfill\\Intl\\Normalizer\\' => __DIR__.'/symfony/polyfill-intl-normalizer/',
        'Symfony\\Polyfill\\Intl\\Grapheme\\' => __DIR__.'/symfony/polyfill-intl-grapheme/',
    ];
    foreach ($map as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            $file = $directory.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
            if (is_file($file)) { require $file; }
            return;
        }
    }
});
require __DIR__.'/symfony/polyfill-intl-normalizer/bootstrap.php';
require __DIR__.'/symfony/polyfill-intl-grapheme/bootstrap.php';
require __DIR__.'/../src/Symfony/Contracts/Deprecation/function.php';
require __DIR__.'/../src/Symfony/Component/String/Resources/functions.php';
''')
(output / 'bin').mkdir(exist_ok=True)
(output / 'bin/app.php').write_text(r'''<?php
require __DIR__.'/../vendor/autoload.php';
use Symfony\Component\TuiApp\Application;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\Tui\Widget\InputWidget;
use Symfony\Component\Tui\Widget\EditorWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\CollapsibleWidget;
use Symfony\Component\Tui\Event\SubmitEvent;
if (['-d', 'precision=3'] !== array_slice($argv, 1)) { throw new RuntimeException('Application arguments were not preserved.'); }
if ('12' !== ini_get('precision') || 'Mobile é🙂' !== ini_get('user_agent') || function_exists('exec')) { throw new RuntimeException('Startup INI settings were not applied.'); }
$app = new Application();
$title = new TextWidget('What is your name?');
$name = new InputWidget();
$name->onSubmit(static function (SubmitEvent $event) use ($title): void {
    $title->setText('Hello '.$event->getValue().'!');
    file_put_contents(sys_get_temp_dir().'/native-submitted.txt', $event->getValue());
});
$app->getTui()->add($title)->add($name)->add(new EditorWidget())->add(new SelectListWidget([['value' => 'small', 'label' => 'Small'], ['value' => 'medium', 'label' => 'Medium'], ['value' => 'large', 'label' => 'Large']]));
$app->run();
''')
(output / 'bin/html.php').write_text(r'''<?php
require __DIR__.'/../vendor/autoload.php';
use Symfony\Component\TuiApp\Application;
use Symfony\Component\TuiHtml\Html\HtmlBuilder;
use Symfony\Component\TuiHtml\Render\Renderer;
use Symfony\Component\TuiHtml\Event\FormSubmitEvent;
if (['-d', 'precision=3'] !== array_slice($argv, 1)) { throw new RuntimeException('Application arguments were not preserved.'); }
if ('12' !== ini_get('precision') || 'Mobile é🙂' !== ini_get('user_agent') || function_exists('exec')) { throw new RuntimeException('Startup INI settings were not applied.'); }
$app = new Application(null, [], null, new Renderer());
$view = (new HtmlBuilder())->build(<<<'HTML'
<style>h1 { color: #00ff00 } form { display: grid; grid-template-columns: 9ch 1fr; gap: 1ch } input, select, textarea, button { min-height: 2ch }</style>
<h1 id="heading">Mobile HTML</h1>
<form id="form">
<label for="name">Name</label><input id="name" name="name" maxlength="40" aria-label="Name">
<label for="secret">Password</label><input id="secret" type="password" name="secret" maxlength="8" aria-label="Password">
<label for="count">Count</label><input id="count" name="count" type="number" min="0" max="10" step="0.5" value="1" aria-label="Count">
<label for="level">Level</label><input id="level" name="level" type="range" value="25" aria-label="Level">
<label for="size">Size</label><select id="size" name="size" aria-label="Size"><option>Small</option><option selected>Medium</option><option>Large</option></select>
<label for="news">News</label><input id="news" name="news" type="checkbox" checked aria-label="News">
<label for="notes">Notes</label><textarea id="notes" name="notes" rows="2" aria-label="Notes"></textarea>
<span></span><button id="submit">Greet</button>
</form><p>Touch, type, rotate and submit.</p>
HTML)->mount($app->getTui());
$view->getById('form')->onSubmit(static function (FormSubmitEvent $event) use ($view): void {
    $values = $event->getValues();
    $view->getById('heading')->setText('Hello '.($values['name'] ?? '').'!');
    file_put_contents(sys_get_temp_dir().'/html-submitted.txt', json_encode($values, JSON_THROW_ON_ERROR));
});
$app->run();
''')
records=[]
paths=[source/'src/Symfony/Component'/name for name in ['Tui','TuiApp','TuiHtml','EventDispatcher','String']]
paths += [source/'src/Symfony/Contracts']
paths += [source/'vendor'/name for name in ['revolt/event-loop','psr/log','psr/event-dispatcher','symfony/polyfill-intl-normalizer','symfony/polyfill-intl-grapheme','symfony/polyfill-mbstring','symfony/polyfill-ctype']]
for path in paths:
    package=json.loads((path/'composer.json').read_text())
    records.append({key:package[key] for key in ['name','require','provide','replace'] if key in package})
(output/'vendor/composer').mkdir(exist_ok=True)
(output/'vendor/composer/installed.json').write_text(json.dumps({'packages':records,'dev-package-names':[]}))
def chunk(kind,data):
    return struct.pack('>I',len(data))+kind+data+struct.pack('>I',zlib.crc32(kind+data))
size=1024
image=b'\x89PNG\r\n\x1a\n'+chunk(b'IHDR',struct.pack('>IIBBBBB',size,size,8,2,0,0,0))+chunk(b'IDAT',zlib.compress((b'\0'+bytes([20,120,140])*size)*size))+chunk(b'IEND',b'')
(output/'icon.png').write_bytes(image)
(output / 'composer.json').write_text('{"name":"symfony/mobile-demo","require":{"ext-dom":"*"}}\n')
config = "<?php return ['app' => ['name' => 'Tui Mobile', 'id' => 'org.symfony.tui.demo', 'icon' => 'icon.png', 'args' => ['-d', 'precision=3'], 'entry' => getenv('TUI_DEMO_HTML') ? 'bin/html.php' : 'bin/app.php'], 'runtime' => ['php' => ['ini' => ['precision' => 12, 'disable_functions' => 'exec', 'user_agent' => 'Mobile é🙂']]], 'build' => ['targets' => ['ios-simulator-arm64', 'ios-arm64', 'android-arm64']]];\n"
(output / 'tui_app.php').write_text(config)
print(output)
