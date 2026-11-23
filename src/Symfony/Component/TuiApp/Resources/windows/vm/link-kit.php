<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Runs link-app.php with an autoloader against a stand-in host: a right click on the link, Copy Link Address, a right
 * click, Open Link, close. Its exit code, the clipboard text and what it followed; exits on a step that fails.
 *
 * @return array{int, string, string}
 */
function drive(string $autoload, string $work, ?string $tracer): array
{
    $appLog = "$work/link-app.log";
    @unlink($appLog);
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error) ?: exit("link-kit: listen: $error\n");
    $address = 'tcp://'.stream_socket_get_name($server, false);
    $token = bin2hex(random_bytes(16));
    $env = ['TUI_APP_ADDR' => $address, 'TUI_APP_TOKEN' => $token, 'LINK_APP_LOG' => $appLog, 'KIT_TRACE' => "$work/trace.json"] + getenv();
    $command = null === $tracer ? [\PHP_BINARY, __DIR__.'/link-app.php', $autoload] : [\PHP_BINARY, '-d', "auto_prepend_file=$tracer", __DIR__.'/link-app.php', $autoload];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', "$work/app.out", 'w'], 2 => ['file', "$work/app.err", 'w']], $pipes, null, $env)
        ?: exit("link-kit: cannot start link-app.php\n");
    $peer = @stream_socket_accept($server, 30) ?: exit("link-kit: link-app.php did not connect:\n".@file_get_contents("$work/app.err"));

    $buffer = '';
    $read = static function (float $seconds) use ($peer, &$buffer): ?array {
        $deadline = microtime(true) + $seconds;
        while (true) {
            if (strlen($buffer) >= 4) {
                $length = unpack('N', substr($buffer, 0, 4))[1];
                if (strlen($buffer) >= 4 + $length) {
                    $message = json_decode(substr($buffer, 4, $length), true, 512, \JSON_THROW_ON_ERROR);
                    $buffer = substr($buffer, 4 + $length);

                    return $message;
                }
            }
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                return null;
            }
            $r = [$peer];
            $w = $e = null;
            if (stream_select($r, $w, $e, (int) $left, (int) (fmod($left, 1) * 1e6))) {
                $bytes = fread($peer, 65536);
                if ('' === $bytes || false === $bytes) {
                    return null;
                }
                $buffer .= $bytes;
            }
        }
    };
    $send = static function (array $message) use ($peer): void {
        $json = json_encode($message, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
        fwrite($peer, pack('N', strlen($json)).$json);
    };
    // Messages until one of a type comes, null after the time given
    $until = static function (string $type, float $seconds) use ($read): ?array {
        $deadline = microtime(true) + $seconds;
        while (null !== $message = $read(max(0.01, $deadline - microtime(true)))) {
            if ($type === $message['type']) {
                return $message;
            }
        }

        return null;
    };

    $auth = $read(10);
    if ('auth' !== ($auth['type'] ?? null) || $token !== ($auth['token'] ?? null)) {
        exit("link-kit: no auth from link-app.php:\n".@file_get_contents("$work/app.err"));
    }
    $send(['type' => 'hello', 'protocol' => 1, 'os' => 'windows', 'columns' => 80, 'rows' => 25, 'cell' => ['width' => 8.0, 'height' => 16.0], 'scale' => 1.0, 'appearance' => 'light',
        'capabilities' => ['menu', 'notify', 'progress', 'beep', 'appearance', 'open', 'canvas-controls', 'password', 'number', 'slider', 'listbox', 'pointer', 'contextmenu', 'clipboard']]);
    $canvas = null;
    $deadline = microtime(true) + 20;
    while (null === $canvas && microtime(true) < $deadline && null !== $message = $read(5)) {
        foreach ('patch' === $message['type'] ? $message['ops'] : [] as $op) {
            if ('create' === $op['op'] && 'canvas' === $op['kind']) {
                $canvas = $op['id'];
            }
        }
    }
    null !== $canvas || exit("link-kit: link-app.php drew no canvas:\n".@file_get_contents("$work/app.err"));

    $choose = static function (string $label) use ($send, $until, $canvas): void {
        foreach (['press', 'release'] as $action) {
            $send(['type' => 'pointer', 'node' => $canvas, 'action' => $action, 'row' => 2, 'column' => 3, 'button' => 'right', 'modifiers' => []]);
        }
        $menu = $until('contextmenu', 10) ?? exit("link-kit: no contextmenu after a right click on the link\n");
        $ids = array_column(array_filter($menu['items'], static fn ($item) => isset($item['label'])), 'id', 'label');
        isset($ids[$label]) || exit("link-kit: no $label in the menu: ".implode(', ', array_keys($ids))."\n");
        $send(['type' => 'contextmenu-select', 'id' => $ids[$label]]);
    };
    $choose('Copy Link Address');
    $copied = $until('clipboard', 10) ?? exit("link-kit: Copy Link Address sent no clipboard\n");
    $choose('Open Link');
    $until('nothing', 1.5);
    $send(['type' => 'close']);
    $until('bye', 10);
    fclose($peer);
    $status = proc_close($process);

    return [$status, $copied['text'], is_file($appLog) ? trim(file_get_contents($appLog)) : ''];
}

$work = sys_get_temp_dir().'/link-kit-'.bin2hex(random_bytes(4));
mkdir($work);
// Gone at the end, also after a step that failed
register_shutdown_function(static function () use ($work): void {
    foreach (glob("$work/*") ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($work);
});
if ('--verify' === ($argv[1] ?? null)) {
    is_file($argv[2] ?? '') || exit("usage: php link-kit.php --verify <autoload.php>\n");
    [$status, $copied, $followed] = drive(realpath($argv[2]), $work, null);
    echo "link-kit: with $argv[2]: clipboard \"$copied\", ".($followed ?: 'nothing followed').", exit $status\n";
    exit(0 === $status && 'https://example.com/docs' === $copied && 'followed https://example.com/docs' === $followed ? 0 : 1);
}

[, $checkout, $kit] = $argv + [null, null, null];
if (!$checkout || !$kit || !is_file("$checkout/vendor/autoload.php")) {
    fwrite(\STDERR, "usage: php link-kit.php <checkout with vendor/autoload.php> <kit folder>\n");
    exit(2);
}
if (file_exists($kit)) {
    fwrite(\STDERR, "link-kit: $kit exists\n");
    exit(2);
}
$checkout = realpath($checkout);
$tracer = "$work/tracer.php";
file_put_contents($tracer, <<<'PHP'
    <?php
    register_shutdown_function(static function (): void {
        $classes = [];
        foreach ([...get_declared_classes(), ...get_declared_interfaces(), ...get_declared_traits()] as $class) {
            $file = (new ReflectionClass($class))->getFileName();
            if (false !== $file) {
                $classes[$class] = $file;
            }
        }
        file_put_contents(getenv('KIT_TRACE'), json_encode(['files' => get_included_files(), 'classes' => $classes], JSON_UNESCAPED_SLASHES));
    });
    PHP);
[$status, $copiedText, $followed] = drive("$checkout/vendor/autoload.php", $work, $tracer);
echo "link-kit: link-app.php here: clipboard \"$copiedText\", ".($followed ?: 'nothing followed').", exit $status\n";
// As --verify checks the kit: what the run that is traced did must be right too
0 === $status && 'https://example.com/docs' === $copiedText && 'followed https://example.com/docs' === $followed || exit("link-kit: link-app.php did not do the steps right with the checkout's autoloader\n");
is_file("$work/trace.json") || exit("link-kit: no trace (link-app.php did not end normally):\n".@file_get_contents("$work/app.err"));
$trace = json_decode(file_get_contents("$work/trace.json"), true, 512, \JSON_THROW_ON_ERROR);

// The units the files come from
$unit = static function (string $file) use ($checkout): ?string {
    if (!str_starts_with($file, $checkout.'/')) {
        return null;
    }
    $relative = substr($file, strlen($checkout) + 1);
    if (preg_match('{^(src/Symfony/(?:Component|Contracts)/[^/]+)/}', $relative, $m) || preg_match('{^(vendor/(?!composer/)[^/]+/[^/]+)/}', $relative, $m)) {
        return $m[1];
    }

    return null;
};
$units = array_values(array_unique(array_filter(array_map($unit, [...$trace['files'], ...array_values($trace['classes'])]))));
// And all that the Tui components require (composer.json), whatever this run loaded: another path through them
// needs no class the kit does not hold
$packages = [];
foreach ([...glob("$checkout/src/Symfony/{Component,Contracts}/*/composer.json", \GLOB_BRACE) ?: [], ...glob("$checkout/vendor/*/*/composer.json") ?: []] as $composer) {
    $name = json_decode(file_get_contents($composer), true)['name'] ?? null;
    if (is_string($name)) {
        $packages[$name] ??= substr(dirname($composer), strlen($checkout) + 1);
    }
}
$wanted = ['symfony/tui', 'symfony/tui-app', 'symfony/tui-html'];
for ($i = 0; $i < count($wanted); ++$i) {
    if (null === $dir = $packages[$wanted[$i]] ?? null) {
        continue;
    }
    $units[] = $dir;
    foreach (array_keys(json_decode(file_get_contents("$checkout/$dir/composer.json"), true)['require'] ?? []) as $required) {
        if (str_contains($required, '/') && !in_array($required, $wanted, true)) {
            $wanted[] = $required;
        }
    }
}
$units = array_values(array_unique($units));
sort($units);
mkdir($kit, 0o777, true);
$copied = 0;
foreach ($units as $dir) {
    @mkdir("$kit/$dir", 0o777, true);
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$checkout/$dir", FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($items as $path => $item) {
        $relative = substr($path, strlen($checkout) + 1);
        if (preg_match('{/(Tests|tests|\.git)(/|$)}', substr($relative, strlen($dir)))) {
            continue;
        }
        if ($item->isDir()) {
            @mkdir("$kit/$relative", 0o777, true);
        } elseif (!$item->isLink()) {
            if (!@copy($path, "$kit/$relative")) {
                fwrite(\STDERR, "link-kit: cannot copy $relative\n");
                exit(1);
            }
            ++$copied;
        }
    }
}

// autoload.php: composer's files and PSR-4 prefixes for what was copied, and the classes seen
$inKit = static fn (string $file): bool => null !== ($u = $unit($file)) && in_array($u, $units, true);
$relative = static fn (string $file): string => substr($file, strlen($checkout) + 1);
$files = array_values(array_map($relative, array_filter(is_file($f = "$checkout/vendor/composer/autoload_files.php") ? require $f : [], $inKit)));
$prefixes = [];
foreach (is_file($f = "$checkout/vendor/composer/autoload_psr4.php") ? require $f : [] as $prefix => $dirs) {
    $dirs = array_values(array_map($relative, array_filter($dirs, static fn ($d) => $inKit(rtrim($d, '/').'/x'))));
    if ($dirs) {
        $prefixes[$prefix] = $dirs;
    }
}
foreach ($units as $dir) {
    if (preg_match('{^src/Symfony/(Component|Contracts)/([^/]+)$}', $dir, $m)) {
        $prefixes["Symfony\\{$m[1]}\\{$m[2]}\\"] ??= [$dir];
    }
}
$classes = array_map($relative, array_filter($trace['classes'], $inKit));
ksort($classes);
file_put_contents("$kit/autoload.php", "<?php\n\n// Made by vm/link-kit.php from ".basename($checkout)."\n"
    .'$base = __DIR__;'."\n"
    .'$classes = '.var_export($classes, true).";\n"
    .'$prefixes = '.var_export($prefixes, true).";\n"
    .<<<'PHP'
        spl_autoload_register(static function (string $class) use ($base, $classes, $prefixes): void {
            if (isset($classes[$class])) {
                require $base.'/'.$classes[$class];

                return;
            }
            foreach ($prefixes as $prefix => $dirs) {
                if (str_starts_with($class, $prefix)) {
                    foreach ($dirs as $dir) {
                        if (is_file($file = $base.'/'.$dir.'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php')) {
                            require $file;

                            return;
                        }
                    }
                }
            }
        });

        PHP
    .'foreach ('.var_export($files, true).' as $file) {'."\n    require_once \$base.'/'.\$file;\n}\n");
echo 'link-kit: '.count($units).' units, '.$copied.' files, '.count($classes)." classes into $kit\n";
