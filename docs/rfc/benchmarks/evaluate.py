#!/usr/bin/env python3
"""Compare baseline, previous Graph, refined Graph and private DI implementations."""
import json
import platform
import random
import statistics
import subprocess
from pathlib import Path

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[2]
rng = random.Random(42)
implementations = ('baseline', 'previous', 'prototype', 'private')
for suite, shapes in (
    ('graph', ('small', 'sparse', 'wide', 'isolated', 'dense', 'duplicates', 'cyclic', 'deep')),
    ('container', ('unconstrained', 'tags', 'decorators')),
):
    raw, summary = [], []
    for shape in shapes:
        pairs = []
        for repetition in range(7):
            order = list(implementations)
            rng.shuffle(order)
            pair = {}
            for implementation in order:
                result = json.loads(subprocess.check_output(
                    ['php', str(HERE / f'{suite}.php'), implementation, shape], cwd=ROOT))
                if suite == 'graph':
                    assert result['visited_nodes'] == result['nodes']
                    assert result['traversed_edges'] == result['edges']
                result['repetition'] = repetition
                result['median_ms'] = statistics.median(result['samples_ms'][1:])
                raw.append(result)
                pair[implementation] = result
            assert len({r['result_hash'] for r in pair.values()}) == 1
            pairs.append(pair)
        item = {'shape': shape}
        for implementation in implementations:
            times = [p[implementation]['median_ms'] for p in pairs]
            median = statistics.median(times)
            ratios = [p[implementation]['median_ms'] / p['baseline']['median_ms'] for p in pairs]
            boots = sorted(statistics.median(rng.choices(ratios, k=len(ratios))) for _ in range(10000))
            item[implementation] = {'median_ms': median,
                'mad_ms': statistics.median(abs(t - median) for t in times),
                'ratio': statistics.median(ratios), 'ratio_ci95': [boots[250], boots[9749]],
                'peak_bytes': max(p[implementation]['peak_bytes'] for p in pairs)}
        summary.append(item)
        print(suite, shape, json.dumps(item), flush=True)
    (HERE / f'{suite}-comparison.json').write_text(json.dumps({
        'platform': platform.platform(), 'process_sets_per_shape': 7,
        'samples_per_process': 11, 'summary': summary, 'raw': raw}, indent=2) + '\n')
    lines = ['| Fixture | Original ms | Previous Graph ms | Refined Graph ms | Private DI ms | Refined / original ratio (95% interval) | Private / original ratio (95% interval) |',
             '|---|---:|---:|---:|---:|---:|---:|']
    for item in summary:
        def ratio(name):
            r = item[name]
            lo, hi = r['ratio_ci95']
            return f"{r['ratio']:.3f} ({lo:.3f}–{hi:.3f})"
        times = ' | '.join(f"{item[name]['median_ms']:.4f}" for name in implementations)
        lines.append(f"| {item['shape']} | {times} | {ratio('prototype')} | {ratio('private')} |")
    (HERE / f'{suite}-comparison.md').write_text('\n'.join(lines) + '\n')
