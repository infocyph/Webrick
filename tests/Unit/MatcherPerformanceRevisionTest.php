<?php

declare(strict_types=1);

use Infocyph\Webrick\Router\Constraint\Registry as ConstraintRegistry;
use Infocyph\Webrick\Router\Matching\CanonicalMatcherIndex;
use Infocyph\Webrick\Router\Matching\CompiledMatcherConstraintOpcode;
use Infocyph\Webrick\Router\Matching\CompiledMatcherIndexCompiler;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Infocyph\Webrick\Router\Matching\MatcherInterface;
use Infocyph\Webrick\Router\Matching\MatchOutcome;
use Infocyph\Webrick\Router\Matching\MatchOutcomeType;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;
use Infocyph\Webrick\Router\Route\CompiledRoute;
use Infocyph\Webrick\Router\Route\Route;

function matcherRevisionRoute(string $method, string $path, string $handler = 'matcher-revision-handler'): CompiledRoute
{
    return CompiledRoute::fromRoute(new Route($method, $path, $handler));
}

function matcherRevisionRemoveTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);

        return;
    }
    if (!is_dir($path)) {
        return;
    }

    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) {
        $entryPath = $entry->getPathname();
        if ($entry->isLink() || $entry->isFile()) {
            unlink($entryPath);
        } elseif ($entry->isDir()) {
            matcherRevisionRemoveTree($entryPath);
        }
    }
    rmdir($path);
}

dataset('compiled pcre ir matchers', [
    'fused' => [static fn(): MatcherInterface => FusedMatcher::make()],
    'sharded' => [static fn(): MatcherInterface => ShardedMatcher::make()],
]);

it('compiles safe regex routes and specializes built-in callable fallback segments', function (): void {
    $index = new CanonicalMatcherIndex();
    $index->add('*', matcherRevisionRoute('GET', '/users/{name}'));
    $index->add('*', matcherRevisionRoute('GET', '/orders/{id:int}'));

    $compiled = new CompiledMatcherIndexCompiler()->compile($index->hosts());
    $userSteps = $compiled['*']['dynamic']['GET'][2]['users']['steps'];
    $orderSteps = $compiled['*']['dynamic']['GET'][2]['orders']['steps'];

    expect($userSteps)->toHaveCount(1)
        ->and($userSteps[0]['type'])->toBe('pcre')
        ->and($orderSteps)->toHaveCount(1)
        ->and($orderSteps[0]['type'])->toBe('fallback')
        ->and($orderSteps[0]['segments'][1]['type'])->toBe('op')
        ->and($orderSteps[0]['segments'][1]['code'])->toBe(CompiledMatcherConstraintOpcode::DIGIT);
});

it('keeps slash-consuming built-in regex constraints out of whole-path pcre', function (): void {
    $index = new CanonicalMatcherIndex();
    $index->add('*', matcherRevisionRoute('GET', '/encoded/{value:base64}'));
    $index->add('*', matcherRevisionRoute('GET', '/network/{value:ipv4_cidr}'));

    $compiled = new CompiledMatcherIndexCompiler()->compile($index->hosts());

    expect($compiled['*']['dynamic']['GET'][2]['encoded']['steps'][0]['type'])->toBe('fallback')
        ->and($compiled['*']['dynamic']['GET'][2]['network']['steps'][0]['type'])->toBe('fallback');
});

it('keeps arbitrary registered regexes segment-local', function (): void {
    if (!ConstraintRegistry::frozen()) {
        try {
            ConstraintRegistry::register('matcher_revision_custom', '/^(?=foo)foo$/');
        } catch (InvalidArgumentException) {
            // The test process may already have registered the fixture.
        }

        $index = new CanonicalMatcherIndex();
        $index->add('*', matcherRevisionRoute('GET', '/custom/{value:matcher_revision_custom}'));
        $compiled = new CompiledMatcherIndexCompiler()->compile($index->hosts());

        expect($compiled['*']['dynamic']['GET'][2]['custom']['steps'][0]['type'])->toBe('fallback');
    } else {
        expect(true)->toBeTrue();
    }
});

it('preserves registration precedence across pcre and fallback barriers', function (Closure $factory): void {
    $first = matcherRevisionRoute('GET', '/pick/{value:int}', 'first-handler');
    $second = matcherRevisionRoute('GET', '/pick/{value}', 'second-handler');
    $matcher = $factory();
    $matcher->add($first);
    $matcher->add($second);
    $matcher->finalize();

    expect($matcher->matchCompiled('GET', '*', '/pick/42'))
        ->toBe([$first->getIndex(), ['value' => '42']]);
})->with('compiled pcre ir matchers');

it('dispatches regex dynamic routes through the shared compiled matcher engine', function (Closure $factory): void {
    $route = matcherRevisionRoute('GET', '/users/{name}');
    $matcher = $factory();
    $matcher->add($route);
    $matcher->finalize();

    expect($matcher->matchCompiled('GET', '*', '/users/hasan'))
        ->toBe([$route->getIndex(), ['name' => 'hasan']]);
})->with('compiled pcre ir matchers');

it('dispatches built-in callable constraints through the specialized fallback lane', function (Closure $factory): void {
    $route = matcherRevisionRoute('GET', '/orders/{id:int}');
    $matcher = $factory();
    $matcher->add($route);
    $matcher->finalize();

    expect($matcher->matchCompiled('GET', '*', '/orders/42'))
        ->toBe([$route->getIndex(), ['id' => '42']]);

    $miss = $matcher->matchCompiled('GET', '*', '/orders/not-an-int');
    expect($miss)->toBeInstanceOf(MatchOutcome::class)
        ->and($miss->type)->toBe(MatchOutcomeType::NOT_FOUND);
})->with('compiled pcre ir matchers');

it('accepts numeric literal discriminator keys in compact matcher metadata', function (Closure $factory): void {
    $matcher = $factory();
    for ($index = 0; $index < 80; ++$index) {
        $matcher->add(matcherRevisionRoute('GET', "/numeric-key/{$index}/{id:hex}"));
    }
    $matcher->finalize();

    $hit = $matcher->matchCompiled('GET', '*', '/numeric-key/57/deadbeef');
    expect($hit)->toBeArray();
})->with('compiled pcre ir matchers');

it('preserves every built-in callable constraint semantic through matcher opcodes', function (Closure $factory): void {
    $cases = [
        ['int', '123456', '12x456'],
        ['digit', '123456', '12x456'],
        ['numeric', '-123.45e2', '12x'],
        ['float', '-123.45', '12x'],
        ['alpha', 'Benchmark', 'Bench42'],
        ['alnum', 'Bench42', 'Bench-42'],
        ['bool', 'FALSE', 'maybe'],
        ['json', '{"ok":true}', '{nope}'],
        ['ipv6', '2001:db8::1', 'not-ipv6'],
    ];

    foreach ($cases as [$constraint, $hitValue, $missValue]) {
        $route = matcherRevisionRoute('GET', "/typed/{$constraint}/{value:{$constraint}}");
        $matcher = $factory();
        $matcher->add($route);
        $matcher->finalize();

        expect($matcher->matchCompiled('GET', '*', "/typed/{$constraint}/{$hitValue}"))
            ->toBe([$route->getIndex(), ['value' => $hitValue]]);

        $miss = $matcher->matchCompiled('GET', '*', "/typed/{$constraint}/{$missValue}");
        expect($miss)->toBeInstanceOf(MatchOutcome::class)
            ->and($miss->type)->toBe(MatchOutcomeType::NOT_FOUND);
    }
})->with('compiled pcre ir matchers');

it('keeps unrecognized callable segments on the generic callable fallback representation', function (): void {
    $compiler = new CompiledMatcherIndexCompiler();
    $reflection = new ReflectionClass($compiler);
    $method = $reflection->getMethod('compileFallbackSegments');
    $segments = $method->invoke(null, [
        ['type' => 'lit', 'val' => 'custom'],
        ['type' => 'var', 'name' => 'value', 'call' => 'strrev'],
    ]);

    expect($segments[1]['type'])->toBe('var')
        ->and($segments[1]['call'])->toBe('strrev')
        ->and($segments[1])->not->toHaveKey('code');
});

it('uses method-first dynamic pcre buckets for overlapping route patterns', function (Closure $factory): void {
    $get = matcherRevisionRoute('GET', '/lookup/{value:uuid}');
    $post = matcherRevisionRoute('POST', '/lookup/{value:slug}');
    $matcher = $factory();
    $matcher->add($get);
    $matcher->add($post);
    $matcher->finalize();

    $uuid = '550e8400-e29b-41d4-a716-446655440000';

    expect($matcher->matchCompiled('GET', '*', '/lookup/' . $uuid))
        ->toBe([$get->getIndex(), ['value' => $uuid]])
        ->and($matcher->matchCompiled('POST', '*', '/lookup/' . $uuid))
        ->toBe([$post->getIndex(), ['value' => $uuid]]);
})->with('compiled pcre ir matchers');

it('maps parameters correctly when a constraint contains nested capture groups', function (Closure $factory): void {
    $route = matcherRevisionRoute('GET', '/packages/{version:semver}');
    $matcher = $factory();
    $matcher->add($route);
    $matcher->finalize();

    expect($matcher->matchCompiled('GET', '*', '/packages/1.2.3-beta+build.7'))
        ->toBe([$route->getIndex(), ['version' => '1.2.3-beta+build.7']]);
})->with('compiled pcre ir matchers');

it('preserves dynamic leading and trailing slash matching behavior', function (Closure $factory): void {
    $route = matcherRevisionRoute('GET', '/users/{name}');
    $matcher = $factory();
    $matcher->add($route);
    $matcher->finalize();

    expect($matcher->matchCompiled('GET', '*', '/users/hasan/'))
        ->toBe([$route->getIndex(), ['name' => 'hasan']])
        ->and($matcher->matchCompiled('GET', '*', '//users/hasan//'))
        ->toBe([$route->getIndex(), ['name' => 'hasan']]);
})->with('compiled pcre ir matchers');

it('dispatches across multiple combined pcre chunks', function (Closure $factory): void {
    $matcher = $factory();
    $last = null;

    for ($i = 0; $i < 40; $i++) {
        $route = matcherRevisionRoute('GET', '/bulk/{group}/item-' . $i . '/{id}');
        $matcher->add($route);
        $last = $route;
    }
    $matcher->finalize();

    expect($last)->toBeInstanceOf(CompiledRoute::class)
        ->and($matcher->matchCompiled('GET', '*', '/bulk/main/item-39/99'))
        ->toBe([$last->getIndex(), ['group' => 'main', 'id' => '99']]);
})->with('compiled pcre ir matchers');

it('keeps 405 and automatic options semantics on dynamic misses', function (Closure $factory): void {
    $get = matcherRevisionRoute('GET', '/resource/{id}');
    $post = matcherRevisionRoute('POST', '/resource/{id}');
    $matcher = $factory();
    $matcher->add($get);
    $matcher->add($post);
    $matcher->finalize();

    $miss = $matcher->matchCompiled('DELETE', '*', '/resource/1');
    expect($miss)->toBeInstanceOf(MatchOutcome::class)
        ->and($miss->type)->toBe(MatchOutcomeType::METHOD_NOT_ALLOWED)
        ->and($miss->allowed)->toContain('GET', 'HEAD', 'POST');

    $options = $matcher->matchCompiled('OPTIONS', '*', '/resource/1');
    expect($options)->toBeInstanceOf(MatchOutcome::class)
        ->and($options->type)->toBe(MatchOutcomeType::AUTO_OPTIONS)
        ->and($options->allowed)->toContain('GET', 'HEAD', 'POST');
})->with('compiled pcre ir matchers');

it('boots fused version 15 cache directly into ordered compiled matcher ir', function (): void {
    $root = sys_get_temp_dir() . '/webrick-fused-ir-' . bin2hex(random_bytes(6));
    $cache = $root . '/routes.php';
    mkdir($root, 0775, true);

    try {
        $regex = matcherRevisionRoute('GET', '/users/{name}');
        $callable = matcherRevisionRoute('GET', '/orders/{id:int}');
        $builder = FusedMatcher::make()->enableCache($cache)->enableCacheWrite();
        $builder->add($regex);
        $builder->add($callable);
        $builder->finalize();

        $reader = FusedMatcher::make()->enableCache($cache);
        expect($reader->canBootFromCache())->toBeTrue();
        $reader->finalize();

        expect($reader->matchCompiled('GET', '*', '/users/hasan'))
            ->toBe([$regex->getIndex(), ['name' => 'hasan']])
            ->and($reader->matchCompiled('GET', '*', '/orders/42'))
            ->toBe([$callable->getIndex(), ['id' => '42']]);
    } finally {
        matcherRevisionRemoveTree($root);
    }
});

it('boots sharded version 15 cache directly into ordered compiled matcher shards', function (): void {
    $root = sys_get_temp_dir() . '/webrick-sharded-ir-' . bin2hex(random_bytes(6));
    mkdir($root, 0775, true);

    try {
        $regex = matcherRevisionRoute('GET', '/users/{name}');
        $callable = matcherRevisionRoute('GET', '/orders/{id:int}');
        $builder = ShardedMatcher::make()->enableCache($root)->enableCacheWrite();
        $builder->add($regex);
        $builder->add($callable);
        $builder->finalize();

        $reader = ShardedMatcher::make()->enableCache($root);
        expect($reader->canBootFromCache())->toBeTrue();
        $reader->finalize();

        expect($reader->matchCompiled('GET', '*', '/users/hasan'))
            ->toBe([$regex->getIndex(), ['name' => 'hasan']])
            ->and($reader->matchCompiled('GET', '*', '/orders/42'))
            ->toBe([$callable->getIndex(), ['id' => '42']]);
    } finally {
        matcherRevisionRemoveTree($root);
    }
});

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
