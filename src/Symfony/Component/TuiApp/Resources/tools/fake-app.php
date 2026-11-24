<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

$fixtures = array_column(json_decode(file_get_contents(__DIR__.'/../contract/fixtures/messages.json'), true), 'message', 'name');
$address = getenv('TUI_APP_ADDR') ?: exit("TUI_APP_ADDR is not set\n");
$token = getenv('TUI_APP_TOKEN') ?: exit("TUI_APP_TOKEN is not set\n");
$log = fopen(getenv('FAKE_APP_LOG') ?: 'php://stderr', 'a');
$refuseClose = '1' === getenv('FAKE_APP_REFUSE_CLOSE');
$features = '1' === getenv('FAKE_APP_FEATURES');
$canvas = '1' === getenv('FAKE_APP_CANVAS');
$typed = '';
$firstWidth = null;
$shownWidth = null;
$closes = 0;

$socket = stream_socket_client($address, $errno, $error, 5) ?: exit("connect: $error\n");

$send = static function (array $message) use ($socket): void {
    // props is always an object, also when empty
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
    $json = $length > 0 ? stream_get_contents($socket, $length) : '';

    return json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
};

$send(['type' => 'auth', 'token' => $token, 'protocol' => 1]);
$hello = $read() ?? exit("no hello\n");
fwrite($log, json_encode($hello)."\n");

$send($fixtures['patch-create']);
$send($fixtures['patch-all-kinds']);
$send($fixtures['menu']);
$send(['type' => 'window', 'title' => 'Fake app']);
$send($fixtures['focus']);

$show = static function (string $text) use ($send): void {
    $send(['type' => 'patch', 'ops' => [['op' => 'update', 'id' => 2, 'props' => ['text' => $text]]]]);
};
if ($features) {
    $send(['type' => 'notify', 'title' => 'Feature test', 'body' => 'notify reached the host']);
    $send(['type' => 'window', 'progress' => ['state' => 'normal', 'value' => 0.5]]);
    $send(['type' => 'beep']);
    $show('ready');
}
$nativeInputs = $canvas && [] === array_diff(['password', 'number', 'slider', 'listbox'], $hello['capabilities'] ?? []);
if ($canvas) {
    $send($fixtures['patch-canvas-controls']);
    // A host that draws them gets a password field (36), a number field (37), a slider (38) and a list box (39) too
    if ($nativeInputs) {
        $send($fixtures['patch-native-inputs']);
    }
    $send(['type' => 'patch', 'ops' => [
        ['op' => 'update', 'id' => 35, 'props' => ['clip' => [8, 10, 30, 1], 'maxLength' => 8]],
        ['op' => 'update', 'id' => 21, 'props' => ['focusable' => true]],
    ]]);
    $show('canvas ready');
}

while (null !== $message = $read()) {
    // Nor logged
    $logged = 'event' === $message['type'] && 36 === ($message['node'] ?? null) && isset($message['value']) ? ['value' => '(hidden)'] + $message : $message;
    fwrite($log, json_encode($logged, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)."\n");
    if ($canvas && 'close' !== $message['type']) {
        if ('event' === $message['type'] && 'focus' !== $message['name']) {
            $value = array_key_exists('value', $message) ? ' '.json_encode($message['value'], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) : '';
            // A password is never shown: its length only
            if (36 === $message['node'] && is_string($message['value'] ?? null)) {
                $value = sprintf(' (%d characters)', preg_match_all('/./su', $message['value']));
            }
            $show(sprintf('event %d %s%s', $message['node'], $message['name'], $value));
            // Once text area 35 is full, the limit of text field 30 (40 characters, full too) goes down to 30: its
            // value is past it
            if (35 === $message['node'] && 'change' === $message['name'] && 'Notesabc' === $message['value']) {
                $send(['type' => 'patch', 'ops' => [['op' => 'update', 'id' => 30, 'props' => ['maxLength' => 30]]]]);
            }
        } elseif ('key' === $message['type'] && null !== ($message['text'] ?? null)) {
            $typed .= $message['text'];
            $show('typed '.$typed);
        } elseif ('key' === $message['type']) {
            $show('key '.$message['key']);
            if ('tab' === $message['key']) {
                $send(['type' => 'focus', 'node' => 34]);
            }
        } elseif ('pointer' === $message['type']) {
            $show(sprintf('pointer %s %d %d%s%s%s', $message['action'], $message['row'], $message['column'], isset($message['button']) ? ' '.$message['button'] : '',
                'wheel' === $message['action'] ? sprintf(' %d %d', $message['rows'] ?? 0, $message['columns'] ?? 0) : '',
                $message['modifiers'] ?? [] ? ' +'.implode('+', $message['modifiers']) : ''));
        } elseif ('rects' === $message['type'] && isset($message['rects']['21'])) {
            // Once the width changed, from a resize: a label update lays the window out again too
            $width = $message['rects']['21'][2];
            $firstWidth ??= $width;
            if ($width !== $firstWidth && $width !== $shownWidth) {
                $shownWidth = $width;
                $show('rects '.$width);
            }
        }
        continue;
    }
    if ($features) {
        match ($message['type']) {
            'open' => $show('open: '.implode(' | ', $message['paths'])),
            'appearance' => $show('appearance: '.$message['value']),
            'menu-select' => $show('menu: '.$message['id']),
            default => null,
        };
        if ('close' === $message['type']) {
            ++$closes;
            if (1 === $closes) {
                $send(['type' => 'close-reply', 'accept' => false]);
                $show('close: kept open');
            } elseif (3 === $closes) {
                // Hung: no answer, and SIGTERM does not stop it either.
                fwrite($log, "hanging\n");
                if (function_exists('pcntl_signal')) {
                    pcntl_signal(\SIGTERM, \SIG_IGN);
                }
                while (true) {
                    sleep(60);
                }
            }
            // The second close gets no answer at all.
        }
        continue;
    }
    if ('event' === $message['type'] && 3 === $message['node'] && 'submit' === $message['name']) {
        $send(['type' => 'patch', 'ops' => [['op' => 'update', 'id' => 2, 'props' => ['text' => 'Hello '.$message['value'].'!']]]]);
    } elseif ('close' === $message['type']) {
        if ($refuseClose) {
            $refuseClose = false;
            $send(['type' => 'close-reply', 'accept' => false]);
            continue;
        }
        $send(['type' => 'close-reply', 'accept' => true]);
        $send(['type' => 'bye', 'status' => 0]);
        exit(0);
    }
}
