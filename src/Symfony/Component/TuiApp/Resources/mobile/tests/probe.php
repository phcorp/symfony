<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

foreach (['ctype', 'filter', 'iconv', 'mbstring', 'tokenizer', 'phar', 'dom'] as $extension) {
    if (!extension_loaded($extension)) {
        throw new RuntimeException('Missing mobile extension: '.$extension);
    }
}
if (2 !== mb_strlen('é🙂') || 'café' !== iconv('UTF-8', 'UTF-8', 'café')) {
    throw new RuntimeException('Mobile Unicode conversion failed.');
}
$document = new DOMDocument();
if (!$document->loadXML('<root><item>mobile</item></root>') || 'mobile' !== $document->documentElement->textContent) {
    throw new RuntimeException('Mobile DOM parsing failed.');
}
$socket = stream_socket_client(getenv('TUI_APP_ADDR'), $errno, $error, 5);
if (!$socket) {
    throw new RuntimeException($error, $errno);
}
function sendFrame($socket, array $message): void
{
    $json = json_encode($message, \JSON_THROW_ON_ERROR);
    $frame = pack('N', strlen($json)).$json;
    while ('' !== $frame) {
        $written = fwrite($socket, $frame);
        if (!$written) {
            throw new RuntimeException('Socket stopped accepting writes.');
        }
        $frame = substr($frame, $written);
    }
}
sendFrame($socket, ['type' => 'auth', 'token' => getenv('TUI_APP_TOKEN'), 'protocol' => 1]);
putenv('TUI_APP_TOKEN');
putenv('TUI_APP_ADDR');
stream_set_timeout($socket, 5);
$header = '';
while (strlen($header) < 4 && !feof($socket)) {
    $bytes = fread($socket, 4 - strlen($header));
    if (false === $bytes || '' === $bytes) {
        throw new RuntimeException('Missing hello header.');
    }
    $header .= $bytes;
}
$length = unpack('N', $header)[1];
if ($length < 1 || $length > 16777216) {
    throw new RuntimeException('Invalid hello size.');
}
$json = '';
while (strlen($json) < $length) {
    $bytes = fread($socket, $length - strlen($json));
    if (false === $bytes || '' === $bytes) {
        throw new RuntimeException('Truncated hello.');
    }
    $json .= $bytes;
}
$hello = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
if ('hello' !== $hello['type'] || 1 !== $hello['protocol']) {
    throw new RuntimeException('Invalid hello.');
}
sendFrame($socket, ['type' => 'patch', 'ops' => [['op' => 'create', 'id' => 1, 'kind' => 'label', 'parent' => null, 'index' => 0, 'props' => ['text' => 'PHP mobile runtime passed']]]]);
if ('r' !== fread($socket, 1)) {
    throw new RuntimeException('Missing resume command.');
}
sendFrame($socket, ['type' => 'patch', 'ops' => [], 'resumed' => true]);
fclose($socket);
