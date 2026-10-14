# Graph prototype benchmarks

From the monorepo root, run:

```sh
php docs/rfc/benchmarks/equivalence.php
python3 docs/rfc/benchmarks/evaluate.py
```

The runner uses only Python's standard library and the local PHP CLI. The PHP worker requires the existing monorepo autoloader. It compares the original sorter, previous Graph adapter, refined Graph adapter and private iterative sorter in separate warmed processes, followed by complete DI compilation fixtures. The working-tree DI source now uses the private algorithm; the Graph adaptation remains an executable fixture. `Fixtures/BeforeAfterSorter.php` is the exact source at e63156feff4fe23338afb374be62026a286149d1, with its namespace changed. It deliberately stays frozen rather than receiving unrelated formatting edits.

`baseline.json` records the first measurements before refactoring. `results.json` and `results.md` preserve the initial shared-prototype measurements. `graph-comparison.json` / `.md` and `container-comparison.json` / `.md` contain the follow-up four-way results. The older `run.py` still compares the original and refined Graph adapter only. Re-running replaces only those result files. Visit/edge counters are collected in an untimed run. The worker can also be run directly with `php docs/rfc/benchmarks/graph.php baseline deep` or `prototype wide`.

Read the RFC's Performance Analysis for fixture definitions, memory measurement limits, confidence intervals, regressions and validation details. The benchmark measures synthetic complete ContainerBuilder compilation, not an existing application or FrameworkBundle kernel. It does not measure cumulative allocations or process RSS. Run `php docs/rfc/benchmarks/container.php prototype tags verify` and `prototype decorators verify` to verify the compilation fixtures reject injected cycles through the expected domain ordering path. The Graph package does not need deepclone; it was installed for the DI functional suite.

## API and role-closure experiments

```sh
php docs/rfc/benchmarks/role-equivalence.php
python3 docs/rfc/benchmarks/api-evaluate.py
```

Use `api-evaluate.py graph` or `roles` to run only one suite. Graph labels `strings` and `arrays` refer to experimental string-ID callback and prevalidated-array sorting APIs. They are not supported public classes. Role labels `graph`, `strings`, `private` and `arrays` refer to generic Graph BFS, string-only BFS, private SplQueue and private array-queue constructors respectively. Production Security uses the array queue; production DI remains private.

`graph-api-comparison.*` and `roles-api-comparison.*` preserve the repeated measurements. `role-counts.json` holds separate untimed processed-entry/edge counts from Counted* fixture copies. Those copies never participate in timed samples. All public role-query results are hashed after construction timing, including their order. The baseline RoleHierarchy fixture stays frozen; the full Core suite was run both before and after the production change.
