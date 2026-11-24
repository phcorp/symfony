<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\TuiApp\Tests\Protocol;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\TuiApp\Exception\ProtocolException;
use Symfony\Component\TuiApp\Mapper\HostEvent;
use Symfony\Component\TuiApp\Mapper\MapContext;
use Symfony\Component\TuiApp\Mapper\MapperRegistry;
use Symfony\Component\TuiApp\Mapper\Node;
use Symfony\Component\TuiApp\Mapper\WidgetMapperInterface;
use Symfony\Component\TuiApp\Protocol\FrameCodec;
use Symfony\Component\TuiApp\Render\NodeTree;
use Symfony\Component\TuiApp\Tests\Fixtures\HostStub;

/**
 * The fixtures native hosts test against, from the PHP side.
 */
class ContractTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../Resources/contract/fixtures/';

    public static function provideFrames(): iterable
    {
        foreach (self::load('frames.json')['frames'] as $frame) {
            yield $frame['name'] => [$frame['json'], $frame['hex']];
        }
    }

    #[DataProvider('provideFrames')]
    public function testFrames(string $json, string $hex)
    {
        $message = json_decode($json, true);
        $this->assertSame($hex, bin2hex(FrameCodec::encode($message)));
        $this->assertSame([$message], (new FrameCodec())->decode(hex2bin($hex)));
    }

    public function testFramesSplitAnywhere()
    {
        $bytes = implode('', array_map(static fn (array $frame): string => hex2bin($frame['hex']), self::load('frames.json')['frames']));
        $codec = new FrameCodec();
        $messages = [];
        foreach (str_split($bytes, 3) as $chunk) {
            array_push($messages, ...$codec->decode($chunk));
        }

        $this->assertCount(\count(self::load('frames.json')['frames']), $messages);
        $this->assertFalse($codec->hasPendingBytes());
    }

    public function testCompleteFramesAreConsumedAfterAnInvalidMessage()
    {
        $codec = new FrameCodec();
        $message = ['type' => 'ping'];
        $invalid = '{';
        try {
            $codec->decode(FrameCodec::encode($message).pack('N', \strlen($invalid)).$invalid.FrameCodec::encode($message));
            $this->fail('Invalid JSON must be rejected.');
        } catch (ProtocolException) {
        }

        $this->assertSame([$message], $codec->decode(''));
        $this->assertFalse($codec->hasPendingBytes());
    }

    public function testBatchedFramesKeepAPartialFollowingFrame()
    {
        $codec = new FrameCodec();
        $message = ['type' => 'ping'];
        $frame = FrameCodec::encode($message);
        $this->assertSame(array_fill(0, 1000, $message), $codec->decode(str_repeat($frame, 1000).substr($frame, 0, 7)));
        $this->assertTrue($codec->hasPendingBytes());
        $this->assertSame([$message], $codec->decode(substr($frame, 7)));
        $this->assertFalse($codec->hasPendingBytes());
    }

    public static function provideMessages(): iterable
    {
        foreach (self::load('messages.json') as $fixture) {
            yield $fixture['name'] => [$fixture['message']];
        }
    }

    #[DataProvider('provideMessages')]
    public function testMessagesRoundTrip(array $message)
    {
        $this->assertSame([$message], (new FrameCodec())->decode(FrameCodec::encode($message)));
    }

    public static function provideRawMessages(): iterable
    {
        // As objects, as the hosts read them: an empty {} stays an object
        foreach (json_decode(file_get_contents(self::FIXTURES.'messages.json'), false, 512, \JSON_THROW_ON_ERROR) as $fixture) {
            yield $fixture->name => [$fixture->message];
        }
    }

    #[DataProvider('provideRawMessages')]
    public function testMessagesFollowTheSchema(object $message)
    {
        $result = self::validate($message);
        $this->assertTrue($result->isValid(), json_encode(null === $result->error() ? [] : (new ErrorFormatter())->format($result->error())));
    }

    public static function provideContextMenuItems(): iterable
    {
        yield 'an item' => [['id' => '1:1', 'label' => 'Open Link'], true];
        yield 'an item, not a separator' => [['id' => '1:1', 'label' => 'Open Link', 'separator' => false], true];
        yield 'a separator' => [['separator' => true], true];
        yield 'a separator with an id and a label' => [['separator' => true, 'id' => 'x', 'label' => 'X'], false];
        yield 'a separator with a label' => [['separator' => true, 'label' => 'X'], false];
        yield 'neither' => [['label' => 'X'], false];
    }

    #[DataProvider('provideContextMenuItems')]
    public function testTheItemsOfAContextMenu(array $item, bool $valid)
    {
        $this->assertSame($valid, self::validate(['type' => 'contextmenu', 'node' => 1, 'row' => 0, 'column' => 0, 'items' => [$item]])->isValid());
    }

    public function testMessagesCoverEveryMessageOfTheSchema()
    {
        $schema = json_decode(file_get_contents(__DIR__.'/../../Resources/contract/protocol.json'), true);
        $types = [];
        foreach ($schema['$defs'] as $definition) {
            if (isset($definition['properties']['type']['const'])) {
                $types[] = $definition['properties']['type']['const'];
            }
        }
        $covered = array_unique(array_map(static fn (array $fixture): string => $fixture['message']['type'], self::load('messages.json')));

        $this->assertEqualsCanonicalizing($types, array_values($covered));
    }

    public function testEveryMapperDrawsAKindOfTheSchema()
    {
        $kinds = json_decode(file_get_contents(__DIR__.'/../../Resources/contract/protocol.json'), true)['$defs']['kind']['enum'];
        $mappers = 0;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__.'/../../Mapper', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $class = 'Symfony\\Component\\TuiApp\\Mapper\\'.str_replace('/', '\\', substr($file->getPathname(), \strlen(__DIR__.'/../../Mapper/'), -4));
            if (!is_subclass_of($class, WidgetMapperInterface::class)) {
                continue;
            }
            ++$mappers;
            $this->assertTrue(\defined($class.'::KIND'), $class.' has a KIND');
            $this->assertContains(\constant($class.'::KIND'), $kinds, $class);
        }

        $this->assertGreaterThanOrEqual(20, $mappers);
    }

    public function testSessionTrees()
    {
        $messages = array_column(self::load('messages.json'), 'message', 'name');
        $host = new HostStub();
        foreach (self::load('session.json')['steps'] as $step) {
            foreach ($messages[$step['patch']]['ops'] as $op) {
                $host->apply($op);
            }
            $this->assertSame(json_encode($step['tree']), json_encode($host->dump()), $step['patch']);
        }
    }

    public function testTheNodeTreeSendsTheSessionPatches()
    {
        $messages = array_column(self::load('messages.json'), 'message', 'name');
        $widgets = [];
        $nodes = [];
        $tree = new NodeTree(new MapperRegistry([new class($widgets, $nodes) implements WidgetMapperInterface {
            public function __construct(private array &$widgets, private array &$nodes)
            {
            }

            public function supports(AbstractWidget $widget): bool
            {
                return true;
            }

            public function map(AbstractWidget $widget, MapContext $context): Node
            {
                $node = $this->nodes[array_search($widget, $this->widgets, true)];

                return new Node($node['kind'], $node['props'], array_map(fn (array $child): AbstractWidget => $this->widgets[$child['id']], $node['children']));
            }

            public function handle(AbstractWidget $widget, HostEvent $event): void
            {
            }
        }]));
        $host = new HostStub();

        foreach (self::load('session.json')['steps'] as $step) {
            $nodes = [];
            $walk = static function (array $node) use (&$walk, &$nodes, &$widgets): void {
                $nodes[$node['id']] = $node;
                $widgets[$node['id']] ??= new TextWidget('');
                array_map($walk, $node['children']);
            };
            $walk($step['tree']);

            $ops = $tree->sync($widgets[$step['tree']['id']], new MapContext(new Renderer(), static fn (): int => 80, 24));
            $patch = json_decode(json_encode(['type' => 'patch', 'ops' => $ops]));
            HostStub::validate($patch);

            // The ids of the fixture, for the nodes of the widgets that stand for them
            $ids = [];
            foreach ($widgets as $id => $widget) {
                if (null !== $nodeId = $tree->getId($widget)) {
                    $ids[$nodeId] = $id;
                }
            }
            $ops = json_decode(json_encode($ops), true);
            foreach ($ops as &$op) {
                $op['id'] = $ids[$op['id']] ?? $op['id'];
                if (isset($op['parent'])) {
                    $op['parent'] = $ids[$op['parent']];
                }
            }
            unset($op);

            $this->assertEquals(self::fewestOps($messages[$step['patch']]['ops']), $ops, $step['patch']);
            foreach ($ops as $op) {
                $host->apply($op);
            }
            $this->assertSame(json_encode($step['tree']), json_encode($host->dump()), $step['patch']);
        }
    }

    public static function provideMessagesOutOfTheProtocol(): iterable
    {
        yield 'an unknown type' => [['type' => 'nope'], 'unknown type'];
        yield 'a missing property' => [['type' => 'notify', 'body' => 'x'], 'does not follow the protocol'];
        yield 'a wrong kind' => [['type' => 'patch', 'ops' => [['op' => 'create', 'id' => 1, 'kind' => 'nope', 'parent' => null, 'index' => 0, 'props' => new \stdClass()]]], 'does not follow the protocol'];
        yield 'a prop of another kind' => [['type' => 'patch', 'ops' => [['op' => 'create', 'id' => 1, 'kind' => 'label', 'parent' => null, 'index' => 0, 'props' => ['value' => 'x']]]], 'the "value" prop to a "label" node'];
        yield 'a prop of another kind, updated' => [['type' => 'patch', 'ops' => [['op' => 'create', 'id' => 1, 'kind' => 'label', 'parent' => null, 'index' => 0, 'props' => new \stdClass()], ['op' => 'update', 'id' => 1, 'props' => ['items' => []]]]], 'the "items" prop to a "label" node'];
    }

    #[DataProvider('provideMessagesOutOfTheProtocol')]
    public function testTheHostStubRejectsMessagesOutOfTheProtocol(array $message, string $error)
    {
        $host = new HostStub();
        $host->connection->send($message);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage($error);

        $host->receive();
    }

    public function testOversizedFrame()
    {
        $this->expectException(ProtocolException::class);

        (new FrameCodec())->decode(pack('N', FrameCodec::MAX_FRAME_BYTES + 1));
    }

    public function testFrameWithoutAType()
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('message object with a type');

        (new FrameCodec())->decode(pack('N', 2).'[]');
    }

    /**
     * The ops of a patch without those that a later one of the patch makes useless: the changes of a node it removes,
     * and the null props of a node it creates; an update of a node it creates goes into its creation.
     *
     * @param list<array<string, mixed>> $ops
     *
     * @return list<array<string, mixed>>
     */
    private static function fewestOps(array $ops): array
    {
        $removed = array_column(array_filter($ops, static fn (array $op): bool => 'remove' === $op['op']), 'id', 'id');
        $fewest = [];
        foreach ($ops as $op) {
            if ('remove' !== $op['op'] && isset($removed[$op['id']])) {
                continue;
            }
            if ('update' === $op['op'] && isset($fewest['create'.$op['id']])) {
                $fewest['create'.$op['id']]['props'] = $op['props'] + $fewest['create'.$op['id']]['props'];
                continue;
            }
            $fewest['create' === $op['op'] ? 'create'.$op['id'] : \count($fewest)] = $op;
        }
        foreach ($fewest as &$op) {
            if ('create' === $op['op']) {
                $op['props'] = array_filter($op['props'], static fn (mixed $value): bool => null !== $value);
            }
        }

        return array_values($fewest);
    }

    private static function validate(array|object $message): \Opis\JsonSchema\ValidationResult
    {
        return (new Validator())->validate(\is_array($message) ? json_decode(json_encode($message)) : $message, file_get_contents(__DIR__.'/../../Resources/contract/protocol.json'));
    }

    private static function load(string $file): array
    {
        return json_decode(file_get_contents(self::FIXTURES.$file), true, 512, \JSON_THROW_ON_ERROR);
    }
}
