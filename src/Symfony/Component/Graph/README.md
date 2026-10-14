Graph Component
===============

Graph provides directed graph operations over caller-owned data, without requiring a graph object or FrameworkBundle.

```php
use Symfony\Component\Graph\Exception\CycleDetectedException;
use Symfony\Component\Graph\TopologicalSort;
use Symfony\Component\Graph\Traversal;

$dependencies = ['app' => ['database', 'cache'], 'cache' => ['database']];
$getDependencies = static fn ($node) => $dependencies[$node] ?? [];

// Dependencies precede their dependants: database, cache, app.
$order = TopologicalSort::sort(['app'], $getDependencies);

// Breadth-first traversal includes roots: app, database, cache.
foreach (Traversal::breadthFirst(['app'], $getDependencies) as $node) {
    echo $node."\n";
}

try {
    TopologicalSort::sort(['a'], static fn ($node) => 'a' === $node ? ['b'] : ['a']);
} catch (CycleDetectedException $e) {
    $cycle = $e->getCycle(); // ['a', 'b', 'a']
}
```

Contracts
---------

Nodes are integers or strings. Identity is strict: `1` and `'1'` identify different nodes. Other values raise `TypeError`; object nodes must be mapped to stable application identifiers. Iterable keys are ignored. Do not represent both integer and numeric-string identities as PHP array keys in your own adjacency map, since PHP coerces those keys.

Roots need not enumerate every node. Both algorithms include reachable nodes absent from the roots. Cover all disconnected components and isolated nodes to process an entire graph. Duplicate roots and edges are allowed. Roots and adjacency must be finite and return a fresh iterable for each node, in a deterministic order. The logical graph must remain unchanged during the operation. Callback and iterator exceptions propagate unchanged; non-iterable adjacency raises `TypeError`.

`TopologicalSort::sort()` reads list adjacency arrays directly and wraps other iterables without materializing them. It uses iterative DFS postorder and returns a complete list. The callback supplies **dependencies**, so dependencies appear before their dependants. This is reverse topological order when directed edges point from a dependant to its dependencies. Adjacency iterators resume after their current dependency has been visited, as in recursive DFS. Independent items follow DFS input order; this is not a globally minimum-reordering guarantee. Cycles raise `CycleDetectedException` with the first closed cycle encountered. A self-loop returns `[node, node]`. The method does not return a partial order on failure.

`Traversal::breadthFirst()` returns a generator. Roots are consumed and deduplicated before the first yield. Each node is yielded before its adjacency is requested, so stopping iteration can avoid further adjacency evaluation. All roots precede their descendants, and successors follow FIFO discovery order. Cycles are accepted. A node is scheduled and expanded once. Reachability can be checked by stopping at a matching node; no additional result array is necessary.

Both algorithms take O(R + V + E) time for R root entries and reachable V nodes and E adjacency entries, excluding callback work and identifier byte lengths. Sort uses O(V + D) algorithm state for depth D, plus suspended adjacency iterables and its O(V) result. Breadth-first traversal uses O(V) visited state and O(W) queue state for frontier width W, plus callback adjacency storage. No intermediate graph is constructed.

The package currently requires PHP 8.4.1 or later, matching the Symfony development branch. This local prototype is accompanied by the repository proposal at `docs/rfc/graph-component.md` and reproducible benchmarks. It does not claim a performance improvement for all graph shapes.

Resources
---------

 * [Contributing](https://symfony.com/doc/current/contributing/index.html)
 * [Report issues](https://github.com/symfony/symfony/issues)
