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
        " * @phpstan-type FastDispatch array{segment:int,groups:array<array-key,list<FastDispatchStep>>}\n",
        " * @phpstan-type FastDispatchChild array{segment:int,groups:array<array-key,list<FastDispatchStep>>}\n"
        " * @phpstan-type FastDispatch array{segment:int,groups:array<array-key,list<FastDispatchStep>>,nested?:array<array-key,FastDispatchChild>}\n",
        'compiler fast dispatch type',
    )
    text = replace_once(
        text,
        "private static function bestLiteralSegment(array $routes): ?array\n    {\n        $best = null;\n        foreach ($routes[0]['segments'] as $segment => $_spec) {",
        "private static function bestLiteralSegment(array $routes, ?int $exclude = null): ?array\n    {\n        $best = null;\n        foreach ($routes[0]['segments'] as $segment => $_spec) {\n            if ($segment === $exclude) {\n                continue;\n            }",
        'best literal segment',
    )
    marker = "    /**\n     * Builds a compact-only literal discriminator when one segment is literal\n"
    nested = """    /**
     * @param non-empty-list<array{segments:list<SegmentSpec>,route:RouteValue,id:int}> $routes
     * @return FastDispatchChild|null
     */
    private function compileNestedFastLiteralDispatch(array $routes, int $exclude): ?array
    {
        $routeCount = count($routes);
        if ($routeCount < 8) {
            return null;
        }

        $selection = self::bestLiteralSegment($routes, $exclude);
        if ($selection === null || $selection['distinct'] < 4) {
            return null;
        }
        if ((int) ceil($routeCount / $selection['distinct']) > max(1, intdiv($routeCount, 2))) {
            return null;
        }

        /** @var array<array-key,non-empty-list<array{segments:list<SegmentSpec>,route:RouteValue,id:int}>> $groups */
        $groups = [];
        foreach ($routes as $entry) {
            $key = self::literalSegment($entry['segments'], $selection['segment'])
                ?? throw new \\UnexpectedValueException('Compiled matcher nested literal segment is invalid.');
            $groups[$key][] = $entry;
        }

        $compiled = [];
        foreach ($groups as $key => $group) {
            $compiled[$key] = $this->compileBalancedFastDispatchSteps($group);
        }

        return ['segment' => $selection['segment'], 'groups' => $compiled];
    }

"""
    text = replace_once(text, marker, nested + marker, 'nested dispatch insertion')
    old = """        /** @var array<string,list<array{segments:list<SegmentSpec>,route:RouteValue,id:int}>> $groups */
        $groups = [];
        foreach ($routes as $entry) {
            $key = self::literalSegment($entry['segments'], $selection['segment'])
                ?? throw new \\UnexpectedValueException('Compiled matcher literal segment is invalid.');
            $groups[$key][] = [
                'segments' => $entry['segments'],
                'route' => $entry['route'],
                'id' => $entry['id'],
            ];
        }

        $compiled = [];
        foreach ($groups as $key => $group) {
            $compiled[$key] = $this->compileBalancedFastDispatchSteps($group);
        }

        return ['segment' => $selection['segment'], 'groups' => $compiled];
"""
    new = """        /** @var array<array-key,non-empty-list<array{segments:list<SegmentSpec>,route:RouteValue,id:int}>> $groups */
        $groups = [];
        foreach ($routes as $entry) {
            $key = self::literalSegment($entry['segments'], $selection['segment'])
                ?? throw new \\UnexpectedValueException('Compiled matcher literal segment is invalid.');
            $groups[$key][] = [
                'segments' => $entry['segments'],
                'route' => $entry['route'],
                'id' => $entry['id'],
            ];
        }

        $compiled = [];
        $nested = [];
        foreach ($groups as $key => $group) {
            $child = $this->compileNestedFastLiteralDispatch($group, $selection['segment']);
            if ($child !== null) {
                $nested[$key] = $child;
                continue;
            }
            $compiled[$key] = $this->compileBalancedFastDispatchSteps($group);
        }

        $dispatch = ['segment' => $selection['segment'], 'groups' => $compiled];
        if ($nested !== []) {
            $dispatch['nested'] = $nested;
        }

        return $dispatch;
"""
    text = replace_once(text, old, new, 'fast dispatch body')
    compiler.write_text(text)

    for filename in [
        'src/Router/Matching/CompiledMatcherDynamicEngine.php',
        'src/Router/Matching/CompiledMatcherFastEngine.php',
    ]:
        path = Path(filename)
        text = path.read_text()
        text = replace_once(
            text,
            " * @phpstan-type FastDispatch array{segment:int,groups:array<array-key,list<FastDispatchStep>>}\n",
            " * @phpstan-type FastDispatchChild array{segment:int,groups:array<array-key,list<FastDispatchStep>>}\n"
            " * @phpstan-type FastDispatch array{segment:int,groups:array<array-key,list<FastDispatchStep>>,nested?:array<array-key,FastDispatchChild>}\n",
            f'{filename} fast dispatch type',
        )
        path.write_text(text)

    engine = Path('src/Router/Matching/CompiledMatcherDynamicEngine.php')
    text = engine.read_text()
    text = replace_once(
        text,
        """        $steps = $dispatch['groups'][$value] ?? null;

        return $steps === null ? null : self::findFastDispatchSteps($steps, $path);
""",
        """        $steps = $dispatch['groups'][$value] ?? null;
        if ($steps !== null) {
            return self::findFastDispatchSteps($steps, $path);
        }

        $child = $dispatch['nested'][$value] ?? null;
        if (!is_array($child)) {
            return null;
        }
        $childValue = $segments[$child['segment']] ?? null;
        if (!is_string($childValue)) {
            return null;
        }
        $steps = $child['groups'][$childValue] ?? null;

        return $steps === null ? null : self::findFastDispatchSteps($steps, $path);
""",
        'dynamic engine nested lookup',
    )
    engine.write_text(text)

    validator = Path('src/Router/Matching/CompactMatcherDynamicValidator.php')
    text = validator.read_text()
    text = replace_once(
        text,
        " * @phpstan-import-type FastDispatchStep from CompiledMatcherFastEngine\n",
        " * @phpstan-import-type FastDispatchStep from CompiledMatcherFastEngine\n"
        " * @phpstan-import-type FastDispatchChild from CompiledMatcherFastEngine\n",
        'validator fast dispatch child import',
    )
    text = replace_once(
        text,
        """        $segment = $raw['segment'] ?? null;
        $groups = $raw['groups'] ?? null;
        if (!is_int($segment) || $segment < 0 || !is_array($groups) || $groups === []) {
            throw new \\UnexpectedValueException('Compact matcher adaptive metadata is invalid.');
        }

        return [
            'segment' => $segment,
            'groups' => self::validateFastGroups($groups, $routes, $validateRegex),
        ];
""",
        """        $segment = $raw['segment'] ?? null;
        $groups = $raw['groups'] ?? null;
        $nested = $raw['nested'] ?? [];
        if (!is_int($segment) || $segment < 0 || !is_array($groups) || !is_array($nested)
            || ($groups === [] && $nested === [])) {
            throw new \\UnexpectedValueException('Compact matcher adaptive metadata is invalid.');
        }

        $dispatch = [
            'segment' => $segment,
            'groups' => self::validateFastGroups($groups, $routes, $validateRegex),
        ];
        if ($nested !== []) {
            $dispatch['nested'] = self::validateNestedFastGroups($nested, $routes, $validateRegex);
        }

        return $dispatch;
""",
        'validator dispatch body',
    )
    marker = """    /**
     * @param array<array-key,mixed> $raw
     * @param array<int,mixed> $routes
     * @return array<array-key,list<FastDispatchStep>>
     */
    private static function validateFastGroups"""
    methods = """    /**
     * @param array<int,mixed> $routes
     * @return FastDispatchChild
     */
    private static function validateFastChild(mixed $raw, array $routes, bool $validateRegex): array
    {
        if (!is_array($raw)) {
            throw new \\UnexpectedValueException('Compact matcher nested adaptive dispatch is invalid.');
        }
        $segment = $raw['segment'] ?? null;
        $groups = $raw['groups'] ?? null;
        if (!is_int($segment) || $segment < 0 || !is_array($groups) || $groups === []) {
            throw new \\UnexpectedValueException('Compact matcher nested adaptive metadata is invalid.');
        }

        return [
            'segment' => $segment,
            'groups' => self::validateFastGroups($groups, $routes, $validateRegex),
        ];
    }

    /**
     * @param array<array-key,mixed> $raw
     * @param array<int,mixed> $routes
     * @return array<array-key,FastDispatchChild>
     */
    private static function validateNestedFastGroups(array $raw, array $routes, bool $validateRegex): array
    {
        $groups = [];
        foreach ($raw as $literal => $child) {
            $groups[$literal] = self::validateFastChild($child, $routes, $validateRegex);
        }

        return $groups;
    }

"""
    text = replace_once(text, marker, methods + marker, 'validator nested methods')
    validator.write_text(text)

    for filename in [
        'src/Router/Matching/FusedMatcher.php',
        'src/Router/Matching/ShardedMatcher.php',
    ]:
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

it('preserves matching semantics through a second adaptive literal decision', function (Closure $factory): void {
    $matcher = $factory();
    $index = 0;
    for ($group = 0; $group < 16; ++$group) {
        for ($route = 0; $route < 16; ++$route) {
            $matcher->add(matcherRevisionRoute('GET', \"/adaptive/g{$group}/r{$route}/{id:hex}\", $index++));
        }
    }
    $matcher->finalize();

    expect($matcher->matchCompiled('GET', '*', '/adaptive/g15/r15/deadbeef'))->toBeArray()
        ->and($matcher->matchCompiled('GET', '*', '/adaptive/g15/r15/not-hex'))->toBeInstanceOf(\\Infocyph\\Webrick\\Router\\Matching\\MatchOutcome::class);
})->with('compiled pcre ir matchers');
"""
    current = test.read_text()
    if addition.strip() not in current:
        test.write_text(current + addition)


def parse_benchmark(path: str) -> dict[str, float]:
    text = Path(path).read_text()
    result: dict[str, float] = {}
    for case in ('fused-hit', 'fused-miss', 'sharded-hit', 'sharded-miss'):
        pattern = rf'\| MatcherAdaptiveDecisionBench \| benchAdaptiveDecision \| {case}\s+\|[^\n]*?\|\s*([0-9.]+)μs\s*\|'
        match = re.search(pattern, text)
        if not match:
            raise SystemExit(f'Unable to parse {case} from {path}')
        result[case] = float(match.group(1))
    return result


def decide() -> None:
    baseline = parse_benchmark('/tmp/phase4-baseline.txt')
    candidate = parse_benchmark('/tmp/phase4-candidate.txt')
    ratios = {key: candidate[key] / baseline[key] for key in baseline}
    average = sum(ratios.values()) / len(ratios)
    hit_ok = ratios['fused-hit'] <= 1.03 and ratios['sharded-hit'] <= 1.03
    retain = average <= 0.97 and hit_ok
    Path('/tmp/phase4-decision').write_text('retain' if retain else 'reject')
    metrics = '\n'.join(
        f'{key}: {baseline[key]:.3f} -> {candidate[key]:.3f} us ({ratios[key]:.3f}x)'
        for key in baseline
    ) + f'\naverage ratio: {average:.3f}x\n'
    Path('/tmp/phase4-metrics').write_text(metrics)
    print(metrics)
    print('decision:', 'retain' if retain else 'reject')
    if not retain:
        subprocess.run(['git', 'checkout', '--', *PRODUCTION_FILES], check=True)


def record() -> None:
    decision = Path('/tmp/phase4-decision').read_text().strip()
    metrics = Path('/tmp/phase4-metrics').read_text().strip().replace('\n', '; ')
    plan = Path('MATCHER_OPTIMIZATION_PLAN.md')
    text = plan.read_text()
    text = text.replace(
        'Status: active development plan — Phases 1–3 complete',
        'Status: active development plan — Phases 1–4 complete',
        1,
    )
    marker = '## Phase 4 — Recursive adaptive discriminator\n\n'
    if decision == 'retain':
        body = (
            '## Phase 4 — Recursive adaptive discriminator\n\n'
            '**Status: complete — retained bounded two-level decision DAG (2026-09-01).**\n\n'
            'The existing one-level `fast_dispatch` was extended by at most one secondary literal discriminator. '
            'Recursion is deliberately capped at two decisions; leaves remain the existing balanced PCRE steps. '
            f'The candidate was retained only after same-run A/B measurement on a 16×16 literal family. Metrics: {metrics}.\n\n'
        )
    else:
        body = (
            '## Phase 4 — Recursive adaptive discriminator\n\n'
            '**Status: complete — candidate rejected by benchmark (2026-09-01).**\n\n'
            'A bounded second literal discriminator was implemented and measured on a 16×16 literal family, then '
            'reverted because it did not clear the required performance gate. The one-level `fast_dispatch` remains '
            f'the production design. Metrics: {metrics}.\n\n'
        )
    if marker not in text:
        raise SystemExit('Phase 4 plan marker missing')
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
        raise SystemExit('usage: phase4.py patch|decide|record')
