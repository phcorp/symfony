<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\TuiApp\Tests\Fixtures;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Symfony\Component\TuiApp\Protocol\FrameCodec;
use Symfony\Component\TuiApp\Protocol\HostConnection;

/**
 * The host end of a connection, keeping the node tree the way a native host
 * does: it applies patches like the contract's session fixture says, and
 * checks every message it receives against the protocol schema.
 */
final class HostStub
{
    public const HELLO = ['type' => 'hello', 'protocol' => 1, 'os' => 'macos', 'columns' => 60, 'rows' => 20, 'cell' => ['width' => 8, 'height' => 16], 'scale' => 2, 'appearance' => 'dark', 'capabilities' => []];

    public readonly HostConnection $connection;
    /** @var resource */
    private $stream;
    private string $buffer = '';
    private static ?Validator $validator = null;
    /** @var array<string, string> The definition of each message type */
    private static array $definitions = [];
    /** @var array<string, list<string>> The kinds each prop is for, when not all */
    private static array $propKinds = [];
    /** @var array<int, array{kind: string, props: array<string, mixed>, parent: int|null, children: list<int>}> */
    public array $nodes = [];
    public ?int $root = null;
    public int|false|null $focus = false;
    /** @var list<array<string, mixed>> */
    public array $received = [];

    /**
     * @param array<string, mixed> $hello
     */
    public function __construct(array $hello = self::HELLO)
    {
        [$app, $host] = stream_socket_pair('\\' === \DIRECTORY_SEPARATOR ? \STREAM_PF_INET : \STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        $this->stream = $host;
        stream_set_blocking($host, false);
        $this->connection = HostConnection::fromStream($app, $hello);
    }

    /**
     * Reads what the application sent and applies it.
     *
     * @return list<array<string, mixed>> The messages read
     */
    public function receive(): array
    {
        while ('' !== $bytes = (string) fread($this->stream, 65536)) {
            $this->buffer .= $bytes;
        }

        $messages = [];
        while (\strlen($this->buffer) >= 4 && \strlen($this->buffer) >= 4 + $length = unpack('N', $this->buffer)[1]) {
            $json = substr($this->buffer, 4, $length);
            $this->buffer = substr($this->buffer, 4 + $length);
            self::validate(json_decode($json, false, 512, \JSON_THROW_ON_ERROR));
            $messages[] = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        }

        foreach ($messages as $message) {
            if ('patch' === $message['type']) {
                $this->checkKinds($message['ops']);
            }
            $this->received[] = $message;
            match ($message['type']) {
                'patch' => array_map($this->apply(...), $message['ops']),
                'focus' => $this->focus = $message['node'],
                default => null,
            };
        }

        return $messages;
    }

    /**
     * Checks a message against the definition of its type in the protocol schema.
     */
    public static function validate(object $message): void
    {
        if (null === self::$validator) {
            $file = \dirname(__DIR__, 2).'/Resources/contract/protocol.json';
            $schema = json_decode(file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);
            foreach ($schema['$defs'] as $name => $definition) {
                if (isset($definition['properties']['type']['const'])) {
                    self::$definitions[$definition['properties']['type']['const']] = $name;
                }
            }
            foreach ($schema['$defs']['props']['properties'] as $prop => $definition) {
                if (isset($definition['x-kinds'])) {
                    self::$propKinds[$prop] = $definition['x-kinds'];
                }
            }
            self::$validator = new Validator();
            self::$validator->resolver()->registerFile($schema['$id'], $file);
        }

        $type = \is_string($message->type ?? null) ? $message->type : '';
        if (!isset(self::$definitions[$type])) {
            throw new \UnexpectedValueException(\sprintf('The application sent a message of an unknown type: %s.', json_encode($message)));
        }
        $result = self::$validator->validate($message, 'https://symfony.com/schema/tui-app/protocol-1.json#/$defs/'.self::$definitions[$type]);
        if (!$result->isValid()) {
            throw new \UnexpectedValueException(\sprintf('The application sent a "%s" message that does not follow the protocol: %s in %s.', $type, json_encode((new ErrorFormatter())->format($result->error())), json_encode($message)));
        }
    }

    /**
     * Checks that each prop of the nodes a patch creates or updates is one their kind has.
     *
     * @param list<array<string, mixed>> $ops
     */
    private function checkKinds(array $ops): void
    {
        $kinds = array_map(static fn (array $node): string => $node['kind'], $this->nodes);
        foreach ($ops as $op) {
            if ('create' === $op['op']) {
                $kinds[$op['id']] = $op['kind'];
            }
            if (!\in_array($op['op'], ['create', 'update'], true)) {
                continue;
            }
            $kind = $kinds[$op['id']] ?? throw new \UnexpectedValueException(\sprintf('The application updated node %d, which the host does not have.', $op['id']));
            foreach ($op['props'] as $prop => $value) {
                if (isset(self::$propKinds[$prop]) && !\in_array($kind, self::$propKinds[$prop], true)) {
                    throw new \UnexpectedValueException(\sprintf('The application sent the "%s" prop to a "%s" node, which only %s have.', $prop, $kind, implode(', ', self::$propKinds[$prop])));
                }
            }
        }
    }

    /**
     * Sends a message to the application and lets it read it.
     *
     * @param array<string, mixed> $message
     */
    public function send(array $message): void
    {
        if ('event' === $message['type'] && isset($this->nodes[$message['node']])) {
            $this->showUserAction($message['node'], $message['name'], $message['value'] ?? null);
        }
        fwrite($this->stream, FrameCodec::encode($message));
        $this->connection->read();
    }

    /**
     * Updates a node the way its native control changes when the user acts on it.
     */
    private function showUserAction(int $id, string $name, mixed $value): void
    {
        $props = &$this->nodes[$id]['props'];
        match (true) {
            \in_array($name, ['change', 'submit'], true) && \is_string($value) => $props['value'] = $value,
            'select' === $name && \is_int($value) => $props['selected'] = $value,
            'select' === $name && \is_array($value) => $props['checked'] = $value,
            'tab' === $name => $props['active'] = $value,
            'toggle' === $name && 'checkbox' === $this->nodes[$id]['kind'] => $props['checked'] = $value,
            'toggle' === $name => $props['expanded'] = $value,
            'focus' === $name => $this->focus = $id,
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $op
     */
    public function apply(array $op): void
    {
        $props = (array) ($op['props'] ?? []);
        switch ($op['op']) {
            case 'create':
                $this->nodes[$op['id']] = ['kind' => $op['kind'], 'props' => array_filter($props, static fn ($v) => null !== $v), 'parent' => $op['parent'], 'children' => []];
                if (null === $op['parent']) {
                    $this->root = $op['id'];
                } else {
                    array_splice($this->nodes[$op['parent']]['children'], min($op['index'], \count($this->nodes[$op['parent']]['children'])), 0, [$op['id']]);
                }
                break;
            case 'update':
                foreach ($props as $name => $value) {
                    if (null === $value) {
                        unset($this->nodes[$op['id']]['props'][$name]);
                    } else {
                        $this->nodes[$op['id']]['props'][$name] = $value;
                    }
                }
                break;
            case 'move':
                $old = $this->nodes[$op['id']]['parent'];
                $this->nodes[$old]['children'] = array_values(array_diff($this->nodes[$old]['children'], [$op['id']]));
                array_splice($this->nodes[$op['parent']]['children'], min($op['index'], \count($this->nodes[$op['parent']]['children'])), 0, [$op['id']]);
                $this->nodes[$op['id']]['parent'] = $op['parent'];
                break;
            case 'remove':
                if (null !== $parent = $this->nodes[$op['id']]['parent']) {
                    $this->nodes[$parent]['children'] = array_values(array_diff($this->nodes[$parent]['children'], [$op['id']]));
                }
                $stack = [$op['id']];
                while ($stack) {
                    $id = array_pop($stack);
                    array_push($stack, ...$this->nodes[$id]['children']);
                    unset($this->nodes[$id]);
                }
                if ($op['id'] === $this->root) {
                    $this->root = null;
                }
                break;
            default:
                throw new \UnexpectedValueException(\sprintf('Unknown op "%s".', $op['op']));
        }
    }

    /**
     * The tree serialized like the session fixture.
     *
     * @return array<string, mixed>|null
     */
    public function dump(): ?array
    {
        $dump = function (int $id) use (&$dump): array {
            $props = $this->nodes[$id]['props'];
            ksort($props);

            return ['id' => $id, 'kind' => $this->nodes[$id]['kind'], 'props' => $props, 'children' => array_map($dump, $this->nodes[$id]['children'])];
        };

        return null === $this->root ? null : $dump($this->root);
    }

    /**
     * The first node of a kind, in tree order.
     */
    public function find(string $kind, ?callable $filter = null): ?int
    {
        $stack = null === $this->root ? [] : [$this->root];
        while ($stack) {
            $id = array_shift($stack);
            if ($kind === $this->nodes[$id]['kind'] && (null === $filter || $filter($this->nodes[$id]['props']))) {
                return $id;
            }
            array_unshift($stack, ...$this->nodes[$id]['children']);
        }

        return null;
    }
}
