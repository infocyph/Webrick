from __future__ import annotations

import re
import sys
from pathlib import Path

CASES = (
    'fused-early-hit',
    'fused-late-hit',
    'fused-miss',
    'sharded-early-hit',
    'sharded-late-hit',
    'sharded-miss',
)


def set_chunk(size: int) -> None:
    path = Path('src/Router/Matching/CompiledMatcherIndexCompiler.php')
    text = path.read_text()
    text, count = re.subn(
        r'public const int DEFAULT_CHUNK_SIZE = \d+;',
        f'public const int DEFAULT_CHUNK_SIZE = {size};',
        text,
        count=1,
    )
    if count != 1:
        raise SystemExit('DEFAULT_CHUNK_SIZE marker missing')
    path.write_text(text)


def parse(path: str) -> dict[str, float]:
    text = Path(path).read_text()
    result: dict[str, float] = {}
    for case in CASES:
        match = re.search(
            rf'\| MatcherPcrePolicyBench \| benchPcrePolicy \| {re.escape(case)}\s+\|[^\n]*?\|\s*([0-9.]+)μs\s*\|',
            text,
        )
        if not match:
            raise SystemExit(f'Unable to parse {case} from {path}')
        result[case] = float(match.group(1))
    return result


def decide() -> None:
    measurements = {
        24: parse('/tmp/phase6-24.txt'),
        48: parse('/tmp/phase6-48.txt'),
        96: parse('/tmp/phase6-96.txt'),
    }
    averages = {size: sum(values.values()) / len(values) for size, values in measurements.items()}
    best = min(averages, key=averages.get)
    baseline = averages[48]
    improvement = 1.0 - (averages[best] / baseline)

    if best != 48:
        ratios = [measurements[best][case] / measurements[48][case] for case in CASES]
        any_hit_regression = any(
            measurements[best][case] / measurements[48][case] > 1.03
            for case in CASES
            if case.endswith('hit')
        )
        if improvement < 0.05 or any_hit_regression:
            best = 48
    set_chunk(best)

    if best != 48:
        for filename in ('src/Router/Matching/FusedMatcher.php', 'src/Router/Matching/ShardedMatcher.php'):
            path = Path(filename)
            text = path.read_text()
            text, count = re.subn(
                r'private const int INDEX_CACHE_VERSION = 16;',
                'private const int INDEX_CACHE_VERSION = 17;',
                text,
                count=1,
            )
            if count != 1:
                raise SystemExit(f'cache version marker missing in {filename}')
            path.write_text(text)

    lines = []
    for size in (24, 48, 96):
        lines.append(f'chunk {size}: average {averages[size]:.3f} us')
        for case in CASES:
            lines.append(f'  {case}: {measurements[size][case]:.3f} us')
    lines.append(f'chosen chunk: {best}')
    Path('/tmp/phase6-metrics').write_text('\n'.join(lines) + '\n')
    Path('/tmp/phase6-choice').write_text(str(best))
    print(Path('/tmp/phase6-metrics').read_text())


def record() -> None:
    choice = int(Path('/tmp/phase6-choice').read_text())
    metrics = Path('/tmp/phase6-metrics').read_text().strip().replace('\n', '; ')
    plan = Path('MATCHER_OPTIMIZATION_PLAN.md')
    text = plan.read_text().replace(
        'Status: active development plan — Phases 1–5 complete',
        'Status: active development plan — Phases 1–6 complete',
        1,
    )
    marker = '## Phase 6 — Adaptive PCRE policy\n\n'
    if choice == 48:
        body = (
            '## Phase 6 — Adaptive PCRE policy\n\n'
            '**Status: complete — retained the 48-route PCRE chunk target (2026-09-01).**\n\n'
            'Chunk targets 24, 48, and 96 were measured on the same runner using a 243-route all-PCRE family '
            'whose literal cardinality deliberately stays below the `fast_dispatch` threshold. The global target '
            'changes only when a candidate improves the aggregate by at least 5% without making a hit more than 3% '
            f'slower. No candidate cleared that gate, so the existing target remains 48. Metrics: {metrics}.\n\n'
        )
    else:
        body = (
            '## Phase 6 — Adaptive PCRE policy\n\n'
            f'**Status: complete — PCRE chunk target changed to {choice} (2026-09-01).**\n\n'
            'Chunk targets 24, 48, and 96 were measured on the same runner using a 243-route all-PCRE family. '
            'The retained candidate improved aggregate dispatch by at least 5% without making any hit more than 3% '
            f'slower. Matcher cache versions were advanced because compiled chunk boundaries changed. Metrics: {metrics}.\n\n'
        )
    if marker not in text:
        raise SystemExit('Phase 6 plan marker missing')
    plan.write_text(text.replace(marker, body, 1))


if __name__ == '__main__':
    command = sys.argv[1] if len(sys.argv) > 1 else ''
    if command == 'set':
        set_chunk(int(sys.argv[2]))
    elif command == 'decide':
        decide()
    elif command == 'record':
        record()
    else:
        raise SystemExit('usage: phase6.py set <size>|decide|record')
