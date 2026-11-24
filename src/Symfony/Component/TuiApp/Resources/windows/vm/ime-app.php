<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

$address = getenv('TUI_APP_ADDR') ?: exit("TUI_APP_ADDR is not set\n");
$token = getenv('TUI_APP_TOKEN') ?: exit("TUI_APP_TOKEN is not set\n");
$log = fopen(getenv('IME_APP_LOG') ?: 'php://stderr', 'a');
// 0: no updates
$tick = (int) (getenv('IME_APP_TICK') ?: 0) / 1000;
$socket = stream_socket_client($address, $errno, $error, 5) ?: exit("connect: $error\n");

$send = static function (array $message) use ($socket): void {
    foreach ($message['ops'] ?? [] as $i => $op) {
        if (array_key_exists('props', $op)) {
            $message['ops'][$i]['props'] = (object) $op['props'];
        }
    }
    $json = json_encode($message, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    fwrite($socket, pack('N', strlen($json)).$json);
};
$read = static function () use ($socket): ?array {
    $header = stream_get_contents($socket, 4);
    if (4 !== strlen($header)) {
        return null;
    }
    $length = unpack('N', $header)[1];

    return json_decode($length > 0 ? stream_get_contents($socket, $length) : '', true, 512, \JSON_THROW_ON_ERROR);
};

$send(['type' => 'auth', 'token' => $token, 'protocol' => 1]);
$hello = $read() ?? exit("no hello\n");
fwrite($log, json_encode($hello)."\n");
$lines = [[['t' => 'Canvas']], [['t' => '> ']], []];
$send(['type' => 'patch', 'ops' => [
    ['op' => 'create', 'id' => 1, 'kind' => 'stack', 'parent' => null, 'index' => 0, 'props' => ['direction' => 'vertical', 'gap' => 1, 'grow' => 1, 'padding' => 1]],
    ['op' => 'create', 'id' => 2, 'kind' => 'label', 'parent' => 1, 'index' => 0, 'props' => ['text' => 'Typing on after a conversion']],
    ['op' => 'create', 'id' => 3, 'kind' => 'textfield', 'parent' => 1, 'index' => 1, 'props' => ['value' => '', 'label' => 'Free']],
    ['op' => 'create', 'id' => 4, 'kind' => 'textfield', 'parent' => 1, 'index' => 2, 'props' => ['value' => '', 'label' => 'Limited', 'maxLength' => 40]],
    ['op' => 'create', 'id' => 5, 'kind' => 'canvas', 'parent' => 1, 'index' => 3, 'props' => ['columns' => 40, 'lines' => $lines, 'cursor' => [1, 2], 'focusable' => true, 'label' => 'Canvas']],
]]);
$send(['type' => 'window', 'title' => 'IME app']);
$send(['type' => 'focus', 'node' => 3]);

stream_set_blocking($socket, true);
$next = microtime(true) + $tick;
while (true) {
    $wait = $tick > 0 ? max(0, $next - microtime(true)) : 3600;
    $r = [$socket];
    $w = $e = null;
    if (false === stream_select($r, $w, $e, (int) $wait, (int) (fmod($wait, 1) * 1e6))) {
        exit(1);
    }
    if ([] !== $r) {
        $message = $read();
        if (null === $message) {
            exit(0);
        }
        fwrite($log, json_encode($message, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)."\n");
        if ('close' === $message['type']) {
            $send(['type' => 'close-reply', 'accept' => true]);
            $send(['type' => 'bye', 'status' => 0]);
            exit(0);
        }
    }
    if ($tick > 0 && microtime(true) >= $next) {
        $next = microtime(true) + $tick;
        $send(['type' => 'patch', 'ops' => [
            ['op' => 'update', 'id' => 3, 'props' => ['label' => 'Free']],
            ['op' => 'update', 'id' => 4, 'props' => ['label' => 'Limited']],
            ['op' => 'update', 'id' => 5, 'props' => ['cursor' => [1, 2]]],
        ]]);
    }
}
