<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

$log = fopen(getenv('TUI_PROXY_LOG') ?: 'php://stderr', 'a');
$host = stream_socket_client(getenv('TUI_APP_ADDR'));
$server = stream_socket_server('tcp://127.0.0.1:0');
$addr = 'tcp://'.stream_socket_get_name($server, false);
$env = getenv();
$env['TUI_APP_ADDR'] = $addr;
$proc = proc_open(array_slice($argv, 1), [0 => \STDIN, 1 => \STDOUT, 2 => \STDERR], $pipes, null, $env);
$app = stream_socket_accept($server, 30);
$bufs = [(int) $host => '', (int) $app => ''];
$peer = [(int) $host => $app, (int) $app => $host];
$name = [(int) $host => 'host>app', (int) $app => 'app>host'];
// The password nodes, whose values are not logged
$secret = [];
$redact = static function (string $json) use (&$secret): string {
    $message = json_decode($json, true);
    if (!is_array($message)) {
        return $json;
    }
    $hidden = false;
    foreach ($message['ops'] ?? [] as $i => $op) {
        if ('create' === ($op['op'] ?? null)) {
            unset($secret[$op['id']]);
            if ('password' === ($op['kind'] ?? null)) {
                $secret[$op['id']] = true;
            }
        }
        if (isset($secret[$op['id'] ?? null]) && is_array($op['props'] ?? null) && array_key_exists('value', $op['props'])) {
            $message['ops'][$i]['props']['value'] = '(hidden)';
            $hidden = true;
        }
    }
    if ('event' === ($message['type'] ?? null) && isset($secret[$message['node'] ?? null]) && array_key_exists('value', $message)) {
        $message['value'] = '(hidden)';
        $hidden = true;
    }

    return $hidden ? json_encode($message, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) : $json;
};
while (true) {
    $r = [$host, $app];
    $w = $e = null;
    if (false === stream_select($r, $w, $e, 1)) {
        break;
    }
    foreach ($r as $s) {
        $d = fread($s, 65536);
        if ('' === $d || false === $d) {
            fwrite($log, "{$name[(int) $s]} closed\n");
            exit(0);
        }
        $bufs[(int) $s] .= $d;
        while (strlen($bufs[(int) $s]) >= 4) {
            $len = unpack('N', $bufs[(int) $s])[1];
            if (strlen($bufs[(int) $s]) < 4 + $len) {
                break;
            }
            $frame = substr($bufs[(int) $s], 0, 4 + $len);
            $bufs[(int) $s] = substr($bufs[(int) $s], 4 + $len);
            fwrite($log, $name[(int) $s].' '.$redact(substr($frame, 4))."\n");
            fwrite($peer[(int) $s], $frame);
        }
    }
}
