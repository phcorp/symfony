<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

//
//   php -n check-php.php <expected version> <extension,extension,...>
//
// The host runs `runtime/php -n -d k=v… ./app/<entry>`, and the app talks to the
// host over TCP (TUI_APP_ADDR) with stream_socket_client and stream_select.

$expectedVersion = $argv[1] ?? '';
$extensions = array_values(array_filter(explode(',', $argv[2] ?? '')));

$problems = [];

if (\PHP_VERSION !== $expectedVersion) {
    $problems[] = sprintf('PHP %s, expected %s', \PHP_VERSION, $expectedVersion);
}

foreach ($extensions as $extension) {
    if (!extension_loaded($extension)) {
        $problems[] = 'missing extension '.$extension;
    }
}
// SQLite with full-text search (FTS5), which its builds had before 3.48 by default.
if (extension_loaded('pdo_sqlite')) {
    try {
        $sqlite = new PDO('sqlite::memory:');
        $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $sqlite->exec('CREATE VIRTUAL TABLE t USING fts5(body)');
        $sqlite->exec("INSERT INTO t VALUES ('hello world')");
        if ('1' !== (string) $sqlite->query("SELECT count(*) FROM t WHERE t MATCH 'world'")->fetchColumn()) {
            $problems[] = 'SQLite full-text search found nothing';
        }
    } catch (PDOException $e) {
        $problems[] = 'SQLite '.$e->getMessage();
    }
}
if (extension_loaded('pcntl') && \PHP_ZTS) {
    $problems[] = 'a thread-safe build: pcntl is unreliable under ZTS';
}

foreach (['stream_socket_client', 'stream_socket_server', 'stream_select', 'proc_open'] as $function) {
    if (!function_exists($function)) {
        $problems[] = 'missing function '.$function.'()';
    }
}

// The connection the app opens to the host: a loopback round trip, waited on with stream_select.
if (function_exists('stream_socket_server') && function_exists('stream_socket_client') && function_exists('stream_select')) {
    $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (false === $server) {
        $problems[] = 'cannot listen on 127.0.0.1: '.$error;
    } else {
        $address = stream_socket_get_name($server, false);
        $client = @stream_socket_client('tcp://'.$address, $errno, $error, 5.0);
        $peer = false === $client ? false : @stream_socket_accept($server, 5.0);
        if (false === $client || false === $peer) {
            $problems[] = 'cannot connect to '.$address.': '.$error;
        } else {
            fwrite($client, "ping\n");
            $read = [$peer];
            $write = $except = null;
            $ready = stream_select($read, $write, $except, 5);
            $line = 1 === $ready ? fgets($peer) : false;
            if ("ping\n" !== $line) {
                $problems[] = 'stream_select did not see data on a loopback socket';
            }
            fclose($client);
            fclose($peer);
        }
        fclose($server);
    }
}

// `-n` (no php.ini) with `-d` settings, the way the host starts the app.
if (false !== php_ini_loaded_file()) {
    $problems[] = 'this check must run with -n, yet '.php_ini_loaded_file().' was loaded';
}
if (function_exists('proc_open')) {
    $code = 'echo false === php_ini_loaded_file() ? "no-ini" : "ini", " ", ini_get("memory_limit");';
    $pipes = [];
    $process = @proc_open([\PHP_BINARY, '-n', '-d', 'memory_limit=77M', '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        $problems[] = 'cannot start '.\PHP_BINARY;
    } else {
        $output = trim((string) stream_get_contents($pipes[1]));
        $errors = trim((string) stream_get_contents($pipes[2]));
        $status = proc_close($process);
        if (0 !== $status || 'no-ini 77M' !== $output) {
            $problems[] = sprintf('`php -n -d memory_limit=77M` printed "%s" and exited %d %s', $output, $status, $errors);
        }
    }
}

if ([] !== $problems) {
    fwrite(\STDERR, 'check-php: '.implode('; ', $problems)."\n");
    exit(1);
}
printf(
    "check-php: PHP %s (%s %s, %s) runs with -n, connects over TCP, and loads %s\n",
    \PHP_VERSION,
    \PHP_OS_FAMILY,
    php_uname('m'),
    \PHP_ZTS ? 'ZTS' : 'NTS',
    implode(', ', $extensions),
);
