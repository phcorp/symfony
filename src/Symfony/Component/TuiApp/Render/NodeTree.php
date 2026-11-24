<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\TuiApp\Render;

use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\TuiApp\Mapper\MapContext;
use Symfony\Component\TuiApp\Mapper\MapperRegistry;
use Symfony\Component\TuiApp\Mapper\Node;
use Symfony\Component\TuiApp\Mapper\WidgetMapperInterface;

/**
 * Keeps a copy of the node tree the host has, and computes the operations
 * that turn it into the tree of the next frame: create, update, move and
 * remove, in an order the host applies one after the other.
 *
 * @internal
 */
final class NodeTree
{
    private int $nextId = 0;
    private ?int $rootId = null;
    /** @var array<int, array{kind: string, props: array<string, mixed>, parent: int|null, children: list<int>}> */
    private array $nodes = [];
    /** @var \WeakMap<AbstractWidget, int> */
    private \WeakMap $ids;
    /** @var array<int, \WeakReference<AbstractWidget>> */
    private array $widgets = [];
    /** @var array<int, WidgetMapperInterface> The mapper that drew each node of a widget */
    private array $nodeMappers = [];
    /** @var array<string, int> Nodes that stand for no widget, keyed by parent and index */
    private array $synthetic = [];
    /** @var list<array<string, mixed>> */
    private array $ops = [];
    /** @var array<int, true> */
    private array $seen = [];

    public function __construct(
        private readonly MapperRegistry $mappers,
    ) {
        $this->ids = new \WeakMap();
    }

    /**
     * Maps the widget tree and returns the operations for the host.
     *
     * @return list<array<string, mixed>>
     */
    public function sync(AbstractWidget $root, MapContext $context): array
    {
        $this->ops = [];
        $this->seen = [];

        $rootId = $this->place($root, $context);
        if (null !== $this->rootId && $rootId !== $this->rootId) {
            $this->ops[] = ['op' => 'remove', 'id' => $this->rootId];
            $this->forget($this->rootId);
        }
        $this->rootId = $rootId;

        // Remove the top-most nodes that are no longer drawn; the host removes their descendants.
        // A drawn node is never under one that is not: it was moved under its drawn parent.
        $prune = [];
        foreach (array_keys($this->nodes) as $id) {
            // Removing a node forgets its descendants while this loop runs
            if (!\array_key_exists($id, $this->nodes) || isset($this->seen[$id])) {
                continue;
            }
            $parentId = $this->nodes[$id]['parent'];
            if (null === $parentId || isset($this->seen[$parentId])) {
                $this->ops[] = ['op' => 'remove', 'id' => $id];
                if (null !== $parentId) {
                    $prune[$parentId] = true;
                }
                $this->forget($id);
            }
        }

        foreach ($prune as $parentId => $_) {
            $this->nodes[$parentId]['children'] = array_values(array_filter($this->nodes[$parentId]['children'], fn (int $id): bool => isset($this->nodes[$id])));
        }
        $this->synthetic = array_filter($this->synthetic, fn (int $id): bool => isset($this->nodes[$id]));

        $ops = $this->ops;
        $this->ops = [];

        return $ops;
    }

    public function getId(AbstractWidget $widget): ?int
    {
        $id = $this->ids[$widget] ?? null;

        return null !== $id && isset($this->nodes[$id]) ? $id : null;
    }

    public function getWidget(int $id): ?AbstractWidget
    {
        return ($this->widgets[$id] ?? null)?->get();
    }

    public function getKind(int $id): ?string
    {
        return $this->nodes[$id]['kind'] ?? null;
    }

    /**
     * The mapper that drew the node of a widget, which applies the events of its control.
     */
    public function getMapper(int $id): ?WidgetMapperInterface
    {
        return $this->nodeMappers[$id] ?? null;
    }

    /**
     * The props the host shows.
     *
     * @return array<string, mixed>
     */
    public function getProps(int $id): array
    {
        return $this->nodes[$id]['props'] ?? [];
    }

    /**
     * Records props the host already shows.
     *
     * @param array<string, mixed> $props
     */
    public function acknowledge(int $id, array $props): void
    {
        if (isset($this->nodes[$id])) {
            $this->nodes[$id]['props'] = $props + $this->nodes[$id]['props'];
        }
    }

    /**
     * Forgets the whole tree: the next sync creates it again.
     *
     * @return int|null The id of the root node the host must remove
     */
    public function reset(): ?int
    {
        $rootId = $this->rootId;
        $this->nodes = [];
        $this->widgets = [];
        $this->nodeMappers = [];
        $this->synthetic = [];
        $this->ids = new \WeakMap();
        $this->rootId = null;

        return $rootId;
    }

    /**
     * Serializes the tree like the contract's session fixture does, for tests.
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

        return null === $this->rootId ? null : $dump($this->rootId);
    }

    private function place(AbstractWidget $root, MapContext $context): int
    {
        [$node, $id, $mapper] = $this->resolve($root, null, 0, $context);

        return $this->put($root, $node, $id, $mapper, null, 0, true, null, $context);
    }

    /**
     * Maps an item and finds the node it keeps, if any, which it takes for itself.
     *
     * @return array{Node, int|null, WidgetMapperInterface|null}
     */
    private function resolve(AbstractWidget|Node $item, ?int $parentId, int $index, MapContext $context): array
    {
        if ($item instanceof AbstractWidget) {
            $mapper = $this->mappers->get($item);
            $node = $mapper->map($item, $context);
            $id = $this->ids[$item] ?? null;
        } else {
            $node = $item;
            $id = $this->synthetic[$parentId.'#'.$index] ?? null;
            $mapper = null;
        }

        if (null !== $id && (!isset($this->nodes[$id]) || $this->nodes[$id]['kind'] !== $node->kind || isset($this->seen[$id]))) {
            // Another kind of control, or a widget drawn twice: a new node
            $id = null;
        }
        if (null !== $id) {
            $this->seen[$id] = true;
        }

        return [$node, $id, $mapper];
    }

    /**
     * Places the children of a node: those that keep their node and the longest run of them already in order stay,
     * the others are moved or created after the sibling before them.
     *
     * @param list<AbstractWidget|Node> $children
     */
    private function placeChildren(int $parentId, array $children, MapContext $context): void
    {
        $resolved = [];
        foreach ($children as $index => $child) {
            $resolved[$index] = $this->resolve($child, $parentId, $index, $context);
        }

        $current = array_flip($this->nodes[$parentId]['children']);
        $positions = [];
        foreach ($resolved as $index => [, $id]) {
            if (null !== $id && isset($current[$id])) {
                $positions[$index] = $current[$id];
            }
        }
        $stays = self::longestIncreasing($positions);

        $previous = null;
        foreach ($resolved as $index => [$node, $id, $mapper]) {
            $previous = $this->put($children[$index], $node, $id, $mapper, $parentId, $index, isset($stays[$index]), $previous, $context);
        }
    }

    /**
     * @param int|null $previous The sibling to place the node after, the first child when null
     */
    private function put(AbstractWidget|Node $item, Node $node, ?int $id, ?WidgetMapperInterface $mapper, ?int $parentId, int $index, bool $stays, ?int $previous, MapContext $context): int
    {
        $props = array_filter($node->props, static fn (mixed $value): bool => null !== $value);

        if (null === $id) {
            $id = ++$this->nextId;
            $this->seen[$id] = true;
            $this->nodes[$id] = ['kind' => $node->kind, 'props' => $props, 'parent' => null, 'children' => []];
            $position = $this->positionAfter($parentId, $previous);
            $this->ops[] = ['op' => 'create', 'id' => $id, 'kind' => $node->kind, 'parent' => $parentId, 'index' => $position, 'props' => (object) $props];
            $this->attach($id, $parentId, $position);
        } else {
            if (null !== $parentId && !$stays) {
                $this->detach($id);
                $position = $this->positionAfter($parentId, $previous);
                $this->ops[] = ['op' => 'move', 'id' => $id, 'parent' => $parentId, 'index' => $position];
                $this->attach($id, $parentId, $position);
            }

            $changes = [];
            foreach ($props as $name => $value) {
                if (!\array_key_exists($name, $this->nodes[$id]['props']) || $this->nodes[$id]['props'][$name] !== $value) {
                    $changes[$name] = $value;
                }
            }
            foreach ($this->nodes[$id]['props'] as $name => $value) {
                if (!\array_key_exists($name, $props)) {
                    $changes[$name] = null;
                }
            }
            if ($changes) {
                $this->ops[] = ['op' => 'update', 'id' => $id, 'props' => (object) $changes];
                $this->nodes[$id]['props'] = $props;
            }
        }

        if ($item instanceof AbstractWidget) {
            $this->ids[$item] = $id;
            $this->widgets[$id] = \WeakReference::create($item);
            $this->nodeMappers[$id] = $mapper;
        } else {
            $this->synthetic[$parentId.'#'.$index] = $id;
        }

        $this->placeChildren($id, $node->children, $context);

        return $id;
    }

    /**
     * Where a node goes in the children the host has now, nodes about to be removed included: right after a sibling.
     */
    private function positionAfter(?int $parentId, ?int $previous): int
    {
        if (null === $parentId || null === $previous) {
            return 0;
        }

        return (int) array_search($previous, $this->nodes[$parentId]['children'], true) + 1;
    }

    /**
     * The keys of a longest run of increasing positions.
     *
     * @param array<int, int> $positions
     *
     * @return array<int, true>
     */
    private static function longestIncreasing(array $positions): array
    {
        $keys = array_keys($positions);
        $tails = [];
        $before = [];
        foreach ($keys as $k => $key) {
            $low = 0;
            $high = \count($tails);
            while ($low < $high) {
                $middle = intdiv($low + $high, 2);
                if ($positions[$keys[$tails[$middle]]] < $positions[$key]) {
                    $low = $middle + 1;
                } else {
                    $high = $middle;
                }
            }
            $before[$k] = $low > 0 ? $tails[$low - 1] : null;
            $tails[$low] = $k;
        }

        $run = [];
        for ($k = $tails ? $tails[\count($tails) - 1] : null; null !== $k; $k = $before[$k]) {
            $run[$keys[$k]] = true;
        }

        return $run;
    }

    private function attach(int $id, ?int $parentId, int $index): void
    {
        $node = $this->nodes[$id];
        $node['parent'] = $parentId;
        $this->nodes[$id] = $node;
        if (null !== $parentId) {
            $children = $this->nodes[$parentId]['children'];
            array_splice($children, min($index, \count($children)), 0, [$id]);
            $this->nodes[$parentId]['children'] = $children;
        }
    }

    private function detach(int $id): void
    {
        if (!isset($this->nodes[$id])) {
            return;
        }

        $node = $this->nodes[$id];
        if (null !== $node['parent'] && isset($this->nodes[$node['parent']])) {
            $parent = $this->nodes[$node['parent']];
            $parent['children'] = array_values(array_filter($parent['children'], static fn (int $child): bool => $child !== $id));
            $this->nodes[$node['parent']] = $parent;
        }
        $node['parent'] = null;
        $this->nodes[$id] = $node;
    }

    private function forget(int $id): void
    {
        foreach ($this->nodes[$id]['children'] ?? [] as $child) {
            $this->forget($child);
        }
        unset($this->nodes[$id], $this->widgets[$id], $this->nodeMappers[$id]);
    }
}
