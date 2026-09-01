from __future__ import annotations

import json
import re
import sys
from pathlib import Path

TARGET = Path('src/Router/Matching/ShardedMatcher.php')
BASELINE = Path('/tmp/phase8-baseline.jsonl')
CANDIDATE = Path('/tmp/phase8-candidate.jsonl')
DECISION = Path('/tmp/phase8-decision.txt')


def patch() -> None:
    text = TARGET.read_text()
    marker = "    /** @var array<string,CompiledGroup|null> */\n    private array $loadedGroups = [];\n"
    replacement = marker + "\n    /** @var array<string,array<string,list<CompiledGroup>>> */\n    private array $loadedCandidateGroups = [];\n"
    if marker not in text:
        raise SystemExit('loadedGroups marker missing')
    text = text.replace(marker, replacement, 1)

    marker = "        $this->cacheReadable = false;\n        $this->cachePreloaded = false;\n"
    replacement = marker + "        $this->loadedCandidateGroups = [];\n"
    if marker not in text:
        raise SystemExit('enableCache marker missing')
    text = text.replace(marker, replacement, 1)

    old = '''    /** @return list<CompiledGroup> */
    private function loadCandidateGroups(string $host, string $bucket): array
    {
        $groups = [];
        $primary = $this->loadGroup($host, $bucket);
        if ($primary !== null) {
            $groups[] = $primary;
        }
        if ($bucket !== self::SHARD_DYNAMIC) {
            $dynamic = $this->loadGroup($host, self::SHARD_DYNAMIC);
            if ($dynamic !== null) {
                $groups[] = $dynamic;
            }
        }

        return $groups;
    }
'''
    new = '''    /** @return list<CompiledGroup> */
    private function loadCandidateGroups(string $host, string $bucket): array
    {
        return $this->loadedCandidateGroups[$host][$bucket]
            ??= $this->resolveCandidateGroups($host, $bucket);
    }

    /** @return list<CompiledGroup> */
    private function resolveCandidateGroups(string $host, string $bucket): array
    {
        $groups = [];
        $primary = $this->loadGroup($host, $bucket);
        if ($primary !== null) {
            $groups[] = $primary;
        }
        if ($bucket !== self::SHARD_DYNAMIC) {
            $dynamic = $this->loadGroup($host, self::SHARD_DYNAMIC);
            if ($dynamic !== null) {
                $groups[] = $dynamic;
            }
        }

        return $groups;
    }
'''
    if old not in text:
        raise SystemExit('candidate group marker missing')
    TARGET.write_text(text.replace(old, new, 1))


def load(path: Path) -> dict[int, list[dict[str, object]]]:
    rows: dict[int, list[dict[str, object]]] = {}
    for line in path.read_text().splitlines():
        line = line.strip()
        if not line.startswith('{'):
            continue
        data = json.loads(line)
        rows.setdefault(int(data['routes']), []).append(data)
    return rows


def median(values: list[float]) -> float:
    values = sorted(values)
    return values[len(values) // 2]


def decide() -> None:
    baseline = load(BASELINE)
    candidate = load(CANDIDATE)
    keep = True
    report: list[str] = []
    for routes in (1000, 5000, 10000):
        base_rows = baseline[routes]
        cand_rows = candidate[routes]
        base_warm = median([float(row['warm_ns']) for row in base_rows])
        cand_warm = median([float(row['warm_ns']) for row in cand_rows])
        base_first = median([float(row['first_hit_us']) for row in base_rows])
        cand_first = median([float(row['first_hit_us']) for row in cand_rows])
        warm_ratio = cand_warm / base_warm
        first_ratio = cand_first / base_first
        report.append(
            f'{routes}: warm {base_warm:.1f} -> {cand_warm:.1f} ns ({warm_ratio:.3f}x); '
            f'first {base_first:.1f} -> {cand_first:.1f} us ({first_ratio:.3f}x)'
        )
        keep = keep and warm_ratio <= 0.80 and first_ratio <= 1.15

    report.append('decision: retain' if keep else 'decision: reject')
    DECISION.write_text('\n'.join(report) + '\n')
    print(DECISION.read_text(), end='')
    if not keep:
        TARGET.write_text(
            __import__('subprocess').check_output(['git', 'show', f'HEAD:{TARGET.as_posix()}'], text=True)
        )


if __name__ == '__main__':
    command = sys.argv[1] if len(sys.argv) > 1 else ''
    if command == 'patch':
        patch()
    elif command == 'decide':
        decide()
    else:
        raise SystemExit('usage: _tmp_matcher_phase8.py patch|decide')
