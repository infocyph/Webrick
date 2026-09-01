from __future__ import annotations

import re
import subprocess
import sys
from pathlib import Path

PRODUCTION_FILES = [
    'src/Router/Matching/CompiledMatcherIndexCompiler.php',
    'src/Router/Matching/CompiledMatcherDynamicEngine.php',
    'src/Router/Matching/CompiledMatcherFastEngine.php',
    'src/Router/Matching/CompactMatcherDynamicValidator.php',
    'src/Router/Matching/FusedMatcher.php',
    'src/Router/Matching/ShardedMatcher.php',
    'tests/Unit/MatcherPerformanceRevisionTest.php',
]


def replace_once(text: str, old: str, new: str, label: str) -> str:
    if old not in text:
        raise SystemExit(f'{label} marker missing')
    return text.replace(old, new, 1)


def patch() -> None:
    compiler = Path('src/Router/Matching/CompiledMatcherIndexCompiler.php')
    text = compiler.read_text()
    text = replace_once(
        text,
        " * @phpstan-type AllowedBucket array{type:'literal',segment:int,groups:array<array-key,AllowedLiteralEntry>}|array{type:'fallback'}\n",
        " * @phpstan-type AllowedBucket array{type:'single',regex:string,methods:list<string>}|array{type:'literal',segment:int,groups:array<array-key,AllowedLiteralEntry>}|array{type:'fallback'}\n",
        'compiler allowed bucket type',
    )
    old = """        $routeCount = count($entries);
        if ($routeCount < 4) {
            return ['type' => 'fallback'];
        }

        $routes = array_values($entries);
        foreach ($routes as $entry) {
            if (!CompiledMatcherPatternCompiler::isCompilable($entry['segments'])) {
                return ['type' => 'fallback'];
            }
        }
"""
    new = """        $routeCount = count($entries);
        $routes = array_values($entries);
        if ($routeCount === 1) {
            $entry = $routes[0];
            if (!CompiledMatcherPatternCompiler::isCompilable($entry['segments'])) {
                return ['type' => 'fallback'];
            }

            return [
                'type' => 'single',
                'regex' => CompiledMatcherPatternCompiler::predicate($entry['segments']),
                'methods' => self::allowedMethods($entry['verbs']),
            ];
        }
        if ($routeCount < 4) {
            return ['type' => 'fallback'];
        }

        foreach ($routes as $entry) {
            if (!CompiledMatcherPatternCompiler::isCompilable($entry['segments'])) {
                return ['type' => 'fallback'];
            }
        }
"""
    text = replace_once(text, old, new, 'single allowed terminal compiler')
    compiler.write_text(text)

    for filename in [
        'src/Router/Matching/CompiledMatcherDynamicEngine.php',
        'src/Router/Matching/CompiledMatcherFastEngine.php',
    ]:
        path = Path(filename)
        text = replace_once(
            path.read_text(),
            " * @phpstan-type AllowedBucket array{type:'literal',segment:int,groups:array<array-key,AllowedLiteralEntry>}|array{type:'fallback'}\n",
            " * @phpstan-type AllowedBucket array{type:'single',regex:string,methods:list<string>}|array{type:'literal',segment:int,groups:array<array-key,AllowedLiteralEntry>}|array{type:'fallback'}\n",
            f'{filename} allowed bucket type',
        )
        path.write_text(text)

    engine = Path('src/Router/Matching/CompiledMatcherDynamicEngine.php')
    text = engine.read_text()
    old = """        if ($bucket['type'] === 'fallback') {
            return true;
        }

        $segments ??= self::pathSegments($path);
        $this->collectLiteralAllowed($bucket, $path, $skip, $allowed, $segments);

        return false;
"""
    new = """        if ($bucket['type'] === 'fallback') {
            return true;
        }
        if ($bucket['type'] === 'single') {
            $status = preg_match($bucket['regex'], $path);
            if ($status === false) {
                throw new \\RuntimeException('Compiled matcher single-terminal PCRE failed during dispatch.');
            }
            if ($status === 1) {
                self::addAllowedMethods($allowed, $bucket['methods'], $skip);
            }

            return false;
        }

        $segments ??= self::pathSegments($path);
        $this->collectLiteralAllowed($bucket, $path, $skip, $allowed, $segments);

        return false;
"""
    text = replace_once(text, old, new, 'single terminal runtime')
    engine.write_text(text)

    validator = Path('src/Router/Matching/CompactMatcherDynamicValidator.php')
    text = validator.read_text()
    old = """        if (($raw['type'] ?? null) === 'fallback') {
            return ['type' => 'fallback'];
        }

        $segment = $raw['segment'] ?? null;
"""
    new = """        if (($raw['type'] ?? null) === 'fallback') {
            return ['type' => 'fallback'];
        }
        if (($raw['type'] ?? null) === 'single') {
            $regex = $raw['regex'] ?? null;
            if (!is_string($regex)) {
                throw new \\UnexpectedValueException('Compact matcher single allowed-method terminal is invalid.');
            }
            self::assertRegex($regex, $validateRegex, 'Compact matcher single allowed-method PCRE cannot be compiled.');

            return [
                'type' => 'single',
                'regex' => $regex,
                'methods' => self::validateMethodList($raw['methods'] ?? null),
            ];
        }

        $segment = $raw['segment'] ?? null;
"""
    text = replace_once(text, old, new, 'single terminal validator')
    validator.write_text(text)

    for filename in ['src/Router/Matching/FusedMatcher.php', 'src/Router/Matching/ShardedMatcher.php']:
        path = Path(filename)
        text = replace_once(
            path.read_text(),
            'private const int INDEX_CACHE_VERSION = 15;',
            'private const int INDEX_CACHE_VERSION = 16;',
            f'{filename} cache version',
        )
        path.write_text(text)

    test = Path('tests/Unit/MatcherPerformanceRevisionTest.php')
    addition = """

it('compiles one canonical dynamic shape into method-independent terminal metadata', function (): void {
    $index = new CanonicalMatcherIndex();
    foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
        $index->add('*', matcherRevisionRoute($method, '/terminal/users/{id:hex}'));
    }

    $compiled = new CompiledMatcherIndexCompiler()->compile($index->hosts());
    $terminal = $compiled['*']['dynamic_allowed'][3]['terminal'];

    expect($terminal['type'])->toBe('single')
        ->and($terminal['methods'])->toContain('GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE');
});
"""
    current = test.read_text()
    if addition.strip() not in current:
        test.write_text(current + addition)


def parse(path: str) -> dict[str, float]:
    text = Path(path).read_text()
    result: dict[str, float] = {}
    for matcher in ('fused', 'sharded'):
        for case in ('hit', 'options', '405', '404'):
            key = f'{matcher}-{case}'
            pattern = rf'\| MatcherMethodTerminalBench \| benchMethodTerminal \| {re.escape(key)}\s+\|[^\n]*?\|\s*([0-9.]+)μs\s*\|'
            match = re.search(pattern, text)
            if not match:
                raise SystemExit(f'Unable to parse {key} from {path}')
            result[key] = float(match.group(1))
    return result


def decide() -> None:
    baseline = parse('/tmp/phase5-baseline.txt')
    candidate = parse('/tmp/phase5-candidate.txt')
    ratios = {key: candidate[key] / baseline[key] for key in baseline}
    miss_keys = [key for key in ratios if not key.endswith('-hit')]
    miss_average = sum(ratios[key] for key in miss_keys) / len(miss_keys)
    hits_ok = ratios['fused-hit'] <= 1.03 and ratios['sharded-hit'] <= 1.03
    retain = miss_average <= 0.85 and hits_ok
    Path('/tmp/phase5-decision').write_text('retain' if retain else 'reject')
    metrics = '\n'.join(
        f'{key}: {baseline[key]:.3f} -> {candidate[key]:.3f} us ({ratios[key]:.3f}x)'
        for key in baseline
    ) + f'\nmiss/options average ratio: {miss_average:.3f}x\n'
    Path('/tmp/phase5-metrics').write_text(metrics)
    print(metrics)
    print('decision:', 'retain' if retain else 'reject')
    if not retain:
        subprocess.run(['git', 'checkout', '--', *PRODUCTION_FILES], check=True)


def record() -> None:
    decision = Path('/tmp/phase5-decision').read_text().strip()
    metrics = Path('/tmp/phase5-metrics').read_text().strip().replace('\n', '; ')
    plan = Path('MATCHER_OPTIMIZATION_PLAN.md')
    text = plan.read_text().replace(
        'Status: active development plan — Phases 1–4 complete',
        'Status: active development plan — Phases 1–5 complete',
        1,
    )
    marker = '## Phase 5 — Method-independent path terminals\n\n'
    if decision == 'retain':
        body = (
            '## Phase 5 — Method-independent path terminals\n\n'
            '**Status: complete — retained single-shape method terminal (2026-09-01).**\n\n'
            'When a dynamic count/prefix bucket contains one canonical PCRE-safe route shape, its complete method set '
            'is now compiled beside one path predicate. 405 and automatic OPTIONS therefore evaluate that path once '
            'instead of re-running the dynamic matcher once per registered method. Requested-method hits remain on the '
            f'existing method-first path. Same-run A/B metrics: {metrics}.\n\n'
        )
    else:
        body = (
            '## Phase 5 — Method-independent path terminals\n\n'
            '**Status: complete — candidate rejected by benchmark (2026-09-01).**\n\n'
            'A single-shape method terminal was implemented and measured, then reverted because it did not clear the '
            f'405/OPTIONS performance gate without hit regression. Metrics: {metrics}.\n\n'
        )
    if marker not in text:
        raise SystemExit('Phase 5 plan marker missing')
    plan.write_text(text.replace(marker, body, 1))


if __name__ == '__main__':
    command = sys.argv[1] if len(sys.argv) > 1 else ''
    if command == 'patch':
        patch()
    elif command == 'decide':
        decide()
    elif command == 'record':
        record()
    else:
        raise SystemExit('usage: phase5.py patch|decide|record')
