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

/** @param list<string> $expected */
function assertAllowed(MatchOutcome $outcome, array $expected, string $message): void
{
    foreach ($expected as $allowed) {
        if (!in_array($allowed, $outcome->allowed, true)) {
            throw new RuntimeException($message . ': missing allowed method ' . $allowed . '.');
        }
    }
}

/** @var array<string,Closure():MatcherInterface> $factories */
$factories = [
    'Fused' => FusedMatcher::make(...),
    'Sharded' => ShardedMatcher::make(...),
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
    $staticGet = sanityRoute('GET', '/static-resource', $name . '-static-get');
    $staticPatch = sanityRoute('PATCH', '/static-resource', $name . '-static-patch');
    $overlapGet = sanityRoute('GET', '/overlap/{value}', $name . '-overlap-get');
    $overlapPost = sanityRoute('POST', '/overlap/{value:slug}', $name . '-overlap-post');
    $explicitOptions = sanityRoute('OPTIONS', '/explicit/{id}', $name . '-explicit-options');
    $extension = sanityRoute('SYNCX', '/extension/{id}', $name . '-extension');
    $hostExact = CompiledRoute::fromRoute(new Route('GET', '/hosted/{id}', $name . '-host-exact', 'api.example.test'));
    $hostWildcard = sanityRoute('GET', '/hosted/{id}', $name . '-host-wildcard');

    $adaptive = [];
    for ($i = 0; $i < 64; $i++) {
        $adaptive[] = sanityRoute('GET', '/catalog/family-' . $i . '/{id}', $name . '-adaptive-' . $i);
    }
    $adaptivePost = sanityRoute('POST', '/catalog/family-63/{id}', $name . '-adaptive-post');

    $matcher = $factory();
    foreach ([
        $regex,
        $semver,
        $hexcolor,
        $ipv4,
        $callable,
        $precedence,
        $later,
        $post,
        $get,
        $staticGet,
        $staticPatch,
        $overlapGet,
        $overlapPost,
        $explicitOptions,
        $extension,
        $hostExact,
        $hostWildcard,
        ...$adaptive,
        $adaptivePost,
    ] as $route) {
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
    assertSameValue(
        [$explicitOptions->getIndex(), ['id' => '1']],
        $matcher->matchCompiled('OPTIONS', '*', '/explicit/1'),
        $name . ': explicit OPTIONS route was replaced by automatic OPTIONS.',
    );
    assertSameValue(
        [$extension->getIndex(), ['id' => '5']],
        $matcher->matchCompiled('SYNCX', '*', '/extension/5'),
        $name . ': extension HTTP method failed.',
    );
    assertSameValue(
        [$hostExact->getIndex(), ['id' => '3']],
        $matcher->matchCompiled('GET', 'api.example.test', '/hosted/3'),
        $name . ': exact host did not override wildcard host.',
    );
    assertSameValue(
        [$hostWildcard->getIndex(), ['id' => '4']],
        $matcher->matchCompiled('GET', 'www.example.test', '/hosted/4'),
        $name . ': wildcard host fallback failed.',
    );

    $richHead = assertOutcome(
        $matcher->matchOutcome('HEAD', '*', '/resource/1'),
        MatchOutcomeType::FOUND,
        $name . ': rich HEAD outcome failed.',
    );
    assertSameValue($get->getIndex(), $richHead->requireRoute()->getIndex(), $name . ': rich HEAD resolved wrong route.');
    assertSameValue(['id' => '1'], $richHead->params, $name . ': rich HEAD params changed.');
    assertSameValue(true, $richHead->headFallback, $name . ': rich HEAD fallback flag was lost.');

    $richDynamic = assertOutcome(
        $matcher->matchOutcome('GET', '*', '/catalog/family-63/9'),
        MatchOutcomeType::FOUND,
        $name . ': rich adaptive outcome failed.',
    );
    assertSameValue($adaptive[63]->getIndex(), $richDynamic->requireRoute()->getIndex(), $name . ': rich adaptive route ID changed.');
    assertSameValue(['id' => '9'], $richDynamic->params, $name . ': rich adaptive params changed.');
    assertSameValue(false, $richDynamic->headFallback, $name . ': rich GET incorrectly marked HEAD fallback.');

    $methodMiss = assertOutcome(
        $matcher->matchCompiled('DELETE', '*', '/resource/1'),
        MatchOutcomeType::METHOD_NOT_ALLOWED,
        $name . ': 405 outcome failed.',
    );
    assertAllowed($methodMiss, ['GET', 'HEAD', 'POST'], $name . ': dynamic fallback 405');

    $staticMethodMiss = assertOutcome(
        $matcher->matchCompiled('DELETE', '*', '/static-resource'),
        MatchOutcomeType::METHOD_NOT_ALLOWED,
        $name . ': compiled static 405 outcome failed.',
    );
    assertAllowed($staticMethodMiss, ['GET', 'HEAD', 'PATCH'], $name . ': compiled static 405');

    $adaptiveMethodMiss = assertOutcome(
        $matcher->matchCompiled('DELETE', '*', '/catalog/family-63/9'),
        MatchOutcomeType::METHOD_NOT_ALLOWED,
        $name . ': adaptive compiled 405 outcome failed.',
    );
    assertAllowed($adaptiveMethodMiss, ['GET', 'HEAD', 'POST'], $name . ': adaptive compiled 405');

    $overlapMethodMiss = assertOutcome(
        $matcher->matchCompiled('DELETE', '*', '/overlap/abc'),
        MatchOutcomeType::METHOD_NOT_ALLOWED,
        $name . ': overlapping dynamic 405 outcome failed.',
    );
    assertAllowed($overlapMethodMiss, ['GET', 'HEAD', 'POST'], $name . ': overlapping dynamic 405');

    $options = assertOutcome(
        $matcher->matchCompiled('OPTIONS', '*', '/resource/1'),
        MatchOutcomeType::AUTO_OPTIONS,
        $name . ': automatic OPTIONS failed.',
    );
    assertAllowed($options, ['GET', 'HEAD', 'POST'], $name . ': automatic OPTIONS');

    $adaptiveOptions = assertOutcome(
        $matcher->matchCompiled('OPTIONS', '*', '/catalog/family-63/9'),
        MatchOutcomeType::AUTO_OPTIONS,
        $name . ': adaptive automatic OPTIONS failed.',
    );
    assertAllowed($adaptiveOptions, ['GET', 'HEAD', 'POST'], $name . ': adaptive automatic OPTIONS');

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
