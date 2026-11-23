<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\TuiApp\Tests\Render;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\TuiApp\Mapper\HostEvent;
use Symfony\Component\TuiApp\Mapper\MapContext;
use Symfony\Component\TuiApp\Mapper\MapperRegistry;
use Symfony\Component\TuiApp\Mapper\Node;
use Symfony\Component\TuiApp\Mapper\WidgetMapperInterface;
use Symfony\Component\TuiApp\Render\NodeTree;
use Symfony\Component\TuiApp\Tests\Fixtures\HostStub;

class NodeTreeTest extends TestCase
{
    private NodeTree $tree;
    private HostStub $host;
    private ContainerWidget $root;

    protected function setUp(): void
    {
        $this->tree = new NodeTree(new MapperRegistry([new class implements WidgetMapperInterface {
            public function supports(AbstractWidget $widget): bool
            {
                return true;
            }

            public function map(AbstractWidget $widget, MapContext $context): Node
            {
                if ($widget instanceof ContainerWidget) {
                    return new Node('stack', [], $widget->all());
                }
                \assert($widget instanceof TextWidget);

                return new Node(\in_array('button', $widget->getStyleClasses(), true) ? 'button' : 'label', ['text' => $widget->getText()]);
            }

            public function handle(AbstractWidget $widget, HostEvent $event): void
            {
            }
        }]));
        $this->host = new HostStub();
        $this->root = new ContainerWidget();
    }

    public function testSyntheticChildrenCanBeRemovedAndRecreated()
    {
        $mapper = new class implements WidgetMapperInterface {
            public array $children = [];

            public function supports(AbstractWidget $widget): bool
            {
                return true;
            }

            public function map(AbstractWidget $widget, MapContext $context): Node
            {
                return new Node('stack', [], $this->children);
            }

            public function handle(AbstractWidget $widget, HostEvent $event): void
            {
            }
        };
        $this->tree = new NodeTree(new MapperRegistry([$mapper]));
        $mapper->children = array_fill(0, 100, new Node('label', ['text' => 'x']));
        $this->sync();
        $this->assertCount(101, $this->host->nodes);

        $mapper->children = [];
        $this->assertCount(100, $this->sync());
        $this->assertCount(1, $this->host->nodes);
        $this->assertSame([], $this->sync());

        $mapper->children = [new Node('label', ['text' => 'new'])];
        $this->assertCount(1, $this->sync());
        $this->assertCount(2, $this->host->nodes);
        $this->assertSame([], $this->sync());
    }

    public function testRemovingTheFirstOfSiblings()
    {
        $texts = $this->texts(6);
        $this->sync();
        $ids = $this->idsOf($texts);

        $this->root->remove($texts[0]);

        $this->assertSame([['op' => 'remove', 'id' => $ids[0]]], $this->sync());
        $this->assertSame(\array_slice($ids, 1), $this->idsOf(\array_slice($texts, 1)));
    }

    public function testRemovingAWidgetKeepsTheIdsOfTheOthers()
    {
        $texts = $this->texts(6);
        $this->sync();
        $ids = $this->idsOf($texts);

        $this->root->remove($texts[2]);
        $ops = $this->sync();

        $this->assertSame([['op' => 'remove', 'id' => $ids[2]]], $ops);
        unset($ids[2]);
        $this->assertSame(array_values($ids), $this->idsOf([...\array_slice($texts, 0, 2), ...\array_slice($texts, 3)]));
    }

    public function testChangingTheKindOfTheFirstSibling()
    {
        $texts = $this->texts(6);
        $this->sync();
        $ids = $this->idsOf($texts);

        $texts[0]->addStyleClass('button');
        $ops = $this->sync();

        $this->assertSame(['create', 'remove'], array_column($ops, 'op'));
        $this->assertSame(0, $ops[0]['index']);
        $this->assertSame($ids[0], $ops[1]['id']);
        $this->assertSame(\array_slice($ids, 1), \array_slice($this->idsOf($texts), 1));
    }

    public function testSwappingTwoSiblings()
    {
        $texts = $this->texts(6);
        $this->sync();
        $ids = $this->idsOf($texts);

        $this->reorder([$texts[0], $texts[2], $texts[1], ...\array_slice($texts, 3)]);
        $ops = $this->sync();

        $this->assertCount(1, $ops);
        $this->assertSame('move', $ops[0]['op']);
        $this->assertSame($ids, $this->idsOf($texts));
    }

    public function testInsertingAtTheStart()
    {
        $texts = $this->texts(4);
        $this->sync();

        $new = new TextWidget('new');
        $this->reorder([$new, ...$texts]);
        $ops = $this->sync();

        $this->assertSame([['op' => 'create', 'id' => $this->tree->getId($new), 'kind' => 'label', 'parent' => $this->tree->getId($this->root), 'index' => 0, 'props' => ['text' => 'new']]], json_decode(json_encode($ops), true));
    }

    public function testMovingTheLastSiblingFirst()
    {
        $texts = $this->texts(6);
        $this->sync();

        $this->reorder([$texts[5], ...\array_slice($texts, 0, 5)]);

        $this->assertSame([['op' => 'move', 'id' => $this->tree->getId($texts[5]), 'parent' => $this->tree->getId($this->root), 'index' => 0]], $this->sync());
    }

    public function testRandomChangesNeedTheFewestMoves()
    {
        $seed = 42;
        // The same changes every run
        $random = static function (int $max) use (&$seed): int {
            $seed = ($seed * 1103515245 + 12345) % 2147483648;

            return intdiv($seed, 65536) % ($max + 1);
        };
        $box = new ContainerWidget();
        $texts = $this->texts(8);
        $this->root->add($box);
        $this->sync();

        for ($round = 0; $round < 200; ++$round) {
            $children = $this->root->all();
            $before = array_map($this->tree->getId(...), $children);
            for ($i = \count($children) - 1; $i > 0; --$i) {
                $j = $random($i);
                [$children[$i], $children[$j]] = [$children[$j], $children[$i]];
            }
            if ($random(1)) {
                array_splice($children, $random(\count($children) - 1), 1);
            }
            if ($random(1)) {
                array_splice($children, $random(\count($children)), 0, [new TextWidget('t'.$round)]);
            }
            if (0 === $random(3) && $children[0] instanceof TextWidget) {
                $children[0]->addStyleClass('button');
            }
            $this->reorder($children);

            // The siblings that keep their node, in their former order
            $kept = [];
            foreach ($children as $child) {
                if (false !== $position = array_search($this->tree->getId($child), $before, true)) {
                    if (!$child instanceof TextWidget || $this->tree->getKind($this->tree->getId($child)) === (\in_array('button', $child->getStyleClasses(), true) ? 'button' : 'label')) {
                        $kept[] = $position;
                    }
                }
            }

            $ops = $this->sync();
            $moves = \count(array_filter($ops, static fn (array $op): bool => 'move' === $op['op']));
            $this->assertSame(\count($kept) - self::longestIncreasing($kept), $moves, 'Round '.$round);
        }
    }

    public function testMovingIntoAnotherParent()
    {
        $box = new ContainerWidget();
        $texts = $this->texts(3);
        $this->root->add($box);
        $this->sync();
        $id = $this->tree->getId($texts[1]);

        $this->root->remove($texts[1]);
        $box->add($texts[1]);
        $ops = $this->sync();

        $this->assertSame([['op' => 'move', 'id' => $id, 'parent' => $this->tree->getId($box), 'index' => 0]], $ops);
    }

    /**
     * @return list<TextWidget>
     */
    private function texts(int $count): array
    {
        $texts = [];
        for ($i = 0; $i < $count; ++$i) {
            $this->root->add($texts[] = new TextWidget((string) $i));
        }

        return $texts;
    }

    /**
     * @param list<AbstractWidget> $children
     */
    private function reorder(array $children): void
    {
        $this->root->clear();
        foreach ($children as $child) {
            $this->root->add($child);
        }
    }

    /**
     * @param list<AbstractWidget> $widgets
     *
     * @return list<int|null>
     */
    private function idsOf(array $widgets): array
    {
        return array_map($this->tree->getId(...), $widgets);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sync(): array
    {
        $ops = $this->tree->sync($this->root, new MapContext(new Renderer(), static fn (): int => 80, 24));
        foreach (json_decode(json_encode($ops), true) as $op) {
            $this->host->apply($op);
        }
        $this->assertSame(json_encode($this->tree->dump()), json_encode($this->host->dump()), 'The host has the tree the application thinks it has');

        return json_decode(json_encode($ops), true);
    }

    /**
     * @param list<int> $sequence
     */
    private static function longestIncreasing(array $sequence): int
    {
        $tails = [];
        foreach ($sequence as $value) {
            $low = 0;
            $high = \count($tails);
            while ($low < $high) {
                $middle = intdiv($low + $high, 2);
                if ($tails[$middle] < $value) {
                    $low = $middle + 1;
                } else {
                    $high = $middle;
                }
            }
            $tails[$low] = $value;
        }

        return \count($tails);
    }
}
