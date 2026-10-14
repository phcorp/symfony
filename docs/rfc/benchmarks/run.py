#!/usr/bin/env python3
"""Run paired fresh-process sorter benchmarks without external dependencies."""
import json
import platform
import random
import statistics
import subprocess
from pathlib import Path

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[2]
rng = random.Random(42)
raw = []
summary = []
for shape in ('small', 'sparse', 'wide', 'isolated', 'dense', 'duplicates', 'cyclic', 'deep'):
    pairs = []
    for repetition in range(7):
        order = ['baseline', 'prototype']
        rng.shuffle(order)
        pair = {}
        for implementation in order:
            result = json.loads(subprocess.check_output(
                ['php', str(HERE / 'graph.php'), implementation, shape], cwd=ROOT))
            assert result['visited_nodes'] == result['nodes']
            assert result['traversed_edges'] == result['edges']
            result['repetition'] = repetition
            # First sample is retained in raw data, but omitted from summary.
            result['median_ms'] = statistics.median(result['samples_ms'][1:])
            raw.append(result)
            pair[implementation] = result
        assert pair['baseline']['result_hash'] == pair['prototype']['result_hash']
        pairs.append(pair)
    ratios = [p['prototype']['median_ms'] / p['baseline']['median_ms'] for p in pairs]
    boots = sorted(statistics.median(rng.choices(ratios, k=len(ratios))) for _ in range(10000))
    item = {'shape': shape, 'nodes': pairs[0]['baseline']['nodes'],
            'edges': pairs[0]['baseline']['edges'],
            'ratio': statistics.median(ratios), 'ratio_ci95': [boots[250], boots[9749]]}
    for implementation in ('baseline', 'prototype'):
        times = [p[implementation]['median_ms'] for p in pairs]
        median = statistics.median(times)
        item[implementation] = {
            'median_ms': median,
            'mad_ms': statistics.median(abs(t - median) for t in times),
            'peak_bytes': max(p[implementation]['peak_bytes'] for p in pairs)}
    summary.append(item)
    print(shape, json.dumps(item), flush=True)
(HERE / 'results.json').write_text(json.dumps({
    'platform': platform.platform(), 'process_pairs_per_shape': 7,
    'samples_per_process': 11, 'summary': summary, 'raw': raw}, indent=2) + '\n')
lines = ['| Fixture (V / E) | Baseline ms (MAD) | Prototype ms (MAD) | Ratio (95% bootstrap interval) | Baseline / prototype peak bytes |',
         '|---|---:|---:|---:|---:|']
for item in summary:
    b, p = item['baseline'], item['prototype']
    lo, hi = item['ratio_ci95']
    lines.append(f"| {item['shape']} ({item['nodes']} / {item['edges']}) | {b['median_ms']:.4f} ({b['mad_ms']:.4f}) | {p['median_ms']:.4f} ({p['mad_ms']:.4f}) | {item['ratio']:.3f} ({lo:.3f}–{hi:.3f}) | {b['peak_bytes']:,} / {p['peak_bytes']:,} |")
(HERE / 'results.md').write_text('\n'.join(lines) + '\n')
