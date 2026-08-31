<?php

declare(strict_types=1);

use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Infocyph\Webrick\Router\Matching\MatcherInterface;
use Infocyph\Webrick\Router\Matching\MatchOutcome;
use Infocyph\Webrick\Router\Matching\MatchOutcomeType;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;
use Infocyph\Webrick\Router\Route\CompiledRoute;
use Infocyph\Webrick\Router\Route\Route;

require dirname(__DIR__) . '/vendor/autoload.php';

function sanityRoute(string $method, string $path, string $handler): CompiledRoute
{
    return CompiledRoute::fromRoute(new Route($method, $path, $handler));
}

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($actual !== $expected) {
        throw new RuntimeException($message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
    }
}

function assertOutcome(mixed $actual, MatchOutcomeType $type, string $message): MatchOutcome
{
    if (!$actual instanceof MatchOutcome || $actual->type !== $type) {
        throw new RuntimeException($message);
    }

    return $actual;
}

/** @var array<string,Closure():MatcherInterface> $factories */
$factories = [
    'Fused' => static fn(): MatcherInterface => FusedMatcher::make(),
    'Sharded' => static fn(): MatcherInterface => ShardedMatcher::make(),
];

foreach ($factories as $name => $factory) {
    $regex = sanityRoute('GET', '/users/{name}', $name . '-regex');
    $callable = sanityRoute('GET', '/orders/{id:int}', $name . '-callable');
    $precedence = sanityRoute('GET', '/pick/{value:int}', $name . '-precedence');
    $later = sanityRoute('GET', '/pick/{value}', $name . '-later');
    $post = sanityRoute('POST', '/resource/{id}', $name . '-post');
    $get = sanityRoute('GET', '/resource/{id}', $name . '-get');

    $matcher = $factory();
    foreach ([$regex, $callable, $precedence, $later, $post, $get] as $route) {
        $matcher->add($route);
    }
    $matcher->finalize();

    assertSameValue(
        [$regex->getIndex(), ['name' => 'hasan']],
        $matcher->matchCompiled('GET', '*', '/users/hasan'),
        $name . ': regex fast-lane hit failed.',
    );
    assertSameValue(
        [$callable->getIndex(), ['id' => '42']],
        $matcher->matchCompiled('GET', '*', '/orders/42'),
        $name . ': callable fallback hit failed.',
    );
    assertSameValue(
        [$precedence->getIndex(), ['value' => '42']],
        $matcher->matchCompiled('GET', '*', '/pick/42'),
        $name . ': registration precedence changed.',
    );
    assertSameValue(
        [$get->getIndex(), ['id' => '1']],
        $matcher->matchCompiled('HEAD', '*', '/resource/1'),
        $name . ': HEAD-to-GET fallback failed.',
    );

    $methodMiss = assertOutcome(
        $matcher->matchCompiled('DELETE', '*', '/resource/1'),
        MatchOutcomeType::METHOD_NOT_ALLOWED,
        $name . ': 405 outcome failed.',
    );
    foreach (['GET', 'HEAD', 'POST'] as $allowed) {
        if (!in_array($allowed, $methodMiss->allowed, true)) {
            throw new RuntimeException($name . ': missing allowed method ' . $allowed . '.');
        }
    }

    $options = assertOutcome(
        $matcher->matchCompiled('OPTIONS', '*', '/resource/1'),
        MatchOutcomeType::AUTO_OPTIONS,
        $name . ': automatic OPTIONS failed.',
    );
    foreach (['GET', 'HEAD', 'POST'] as $allowed) {
        if (!in_array($allowed, $options->allowed, true)) {
            throw new RuntimeException($name . ': automatic OPTIONS missing ' . $allowed . '.');
        }
    }

    assertOutcome(
        $matcher->matchCompiled('GET', '*', '/missing'),
        MatchOutcomeType::NOT_FOUND,
        $name . ': not-found outcome failed.',
    );
}

fwrite(STDOUT, "Matcher semantic sanity: OK\n");
