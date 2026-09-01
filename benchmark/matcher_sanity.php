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
    $semver = sanityRoute('GET', '/version/{value:semver}', $name . '-semver');
    $hexcolor = sanityRoute('GET', '/color/{value:hexcolor}', $name . '-hexcolor');
    $ipv4 = sanityRoute('GET', '/ip/{value:ipv4}', $name . '-ipv4');
    $callable = sanityRoute('GET', '/orders/{id:int}', $name . '-callable');
    $precedence = sanityRoute('GET', '/pick/{value:int}', $name . '-precedence');
    $later = sanityRoute('GET', '/pick/{value}', $name . '-later');
    $post = sanityRoute('POST', '/resource/{id}', $name . '-post');
    $get = sanityRoute('GET', '/resource/{id}', $name . '-get');

    $adaptive = [];
    for ($i = 0; $i < 64; $i++) {
        $adaptive[] = sanityRoute('GET', '/catalog/family-' . $i . '/{id}', $name . '-adaptive-' . $i);
    }

    $matcher = $factory();
    foreach ([$regex, $semver, $hexcolor, $ipv4, $callable, $precedence, $later, $post, $get, ...$adaptive] as $route) {
        $matcher->add($route);
    }
    $matcher->finalize();

    assertSameValue(
        [$regex->getIndex(), ['name' => 'hasan']],
        $matcher->matchCompiled('GET', '*', '/users/hasan'),
        $name . ': regex fast-lane hit failed.',
    );
    assertSameValue(
        [$semver->getIndex(), ['value' => '1.2.3-beta.1+build.7']],
        $matcher->matchCompiled('GET', '*', '/version/1.2.3-beta.1+build.7'),
        $name . ': positional semver capture failed.',
    );
    assertSameValue(
        [$hexcolor->getIndex(), ['value' => '#AABBCC']],
        $matcher->matchCompiled('GET', '*', '/color/#AABBCC'),
        $name . ': positional hexcolor capture failed.',
    );
    assertSameValue(
        [$ipv4->getIndex(), ['value' => '127.0.0.1']],
        $matcher->matchCompiled('GET', '*', '/ip/127.0.0.1'),
        $name . ': positional ipv4 capture failed.',
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
        [$adaptive[0]->getIndex(), ['id' => '7']],
        $matcher->matchCompiled('GET', '*', '/catalog/family-0/7'),
        $name . ': adaptive first literal group failed.',
    );
    assertSameValue(
        [$adaptive[63]->getIndex(), ['id' => '9']],
        $matcher->matchCompiled('GET', '*', '/catalog/family-63/9'),
        $name . ': adaptive last literal group failed.',
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

    $adaptiveMethodMiss = assertOutcome(
        $matcher->matchCompiled('POST', '*', '/catalog/family-63/9'),
        MatchOutcomeType::METHOD_NOT_ALLOWED,
        $name . ': adaptive 405 outcome failed.',
    );
    foreach (['GET', 'HEAD'] as $allowed) {
        if (!in_array($allowed, $adaptiveMethodMiss->allowed, true)) {
            throw new RuntimeException($name . ': adaptive 405 missing ' . $allowed . '.');
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
        $matcher->matchCompiled('GET', '*', '/catalog/family-missing/9'),
        MatchOutcomeType::NOT_FOUND,
        $name . ': adaptive not-found outcome failed.',
    );
    assertOutcome(
        $matcher->matchCompiled('GET', '*', '/missing'),
        MatchOutcomeType::NOT_FOUND,
        $name . ': not-found outcome failed.',
    );
}

fwrite(STDOUT, "Matcher semantic sanity: OK\n");
