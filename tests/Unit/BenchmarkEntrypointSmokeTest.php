<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Tests\Unit;

use Infocyph\Webrick\Benchmarks\CacheLifecycleBench;
use Infocyph\Webrick\Benchmarks\EtagBench;
use Infocyph\Webrick\Benchmarks\KernelDispatchBench;
use Infocyph\Webrick\Benchmarks\MatcherAdaptiveDecisionBench;
use Infocyph\Webrick\Benchmarks\MatcherBench;
use Infocyph\Webrick\Benchmarks\MatcherMemoryBench;
use Infocyph\Webrick\Benchmarks\MatcherMethodTerminalBench;
use Infocyph\Webrick\Benchmarks\MatcherOutcomeBench;
use Infocyph\Webrick\Benchmarks\MatcherPathInspectionBench;
use Infocyph\Webrick\Benchmarks\MatcherPcrePolicyBench;
use Infocyph\Webrick\Benchmarks\MatcherScaleBench;
use Infocyph\Webrick\Benchmarks\MatcherTypedConstraintBench;
use Infocyph\Webrick\Benchmarks\PreRoutingGateBench;
use Infocyph\Webrick\Benchmarks\ResponseCacheBench;
use Infocyph\Webrick\Benchmarks\RuntimeMiddlewareDescriptorBench;
use Infocyph\Webrick\Benchmarks\RunwireRuntimeBench;
use Infocyph\Webrick\Benchmarks\SecurityMiddlewareKernelBench;
use Infocyph\Webrick\Benchmarks\SignedUrlBench;
use Infocyph\Webrick\Benchmarks\Fixture\ParameterizedRuntimeMiddleware;
use Infocyph\Webrick\Http\HttpFactory;
use Infocyph\Webrick\Middleware\InputSanitizerMiddleware;
use Infocyph\Webrick\Request\Http\SessionCsrfTokenStore;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Headers\Language;
use Infocyph\Webrick\Response\Headers\RateLimit;
use Infocyph\Webrick\Response\Headers\Vary;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Kernel\PhpErrorBridge;
use Infocyph\Webrick\Tests\Fixture\TestController;
use Infocyph\Webrick\Tests\Fixture\TestMiddleware;

/**
 * PHPBench normally discovers subjects and setup hooks reflectively. These
 * targeted smoke cases call real entrypoints, including setup/teardown,
 * so failures cannot hide behind the framework's dynamic discovery path.
 */
test('setup-managed benchmarks execute representative real methods', function (): void {
    $cache = new CacheLifecycleBench();
    $cache->setUp();
    try {
        $cache->benchFusedCachedBoot();
        $cache->benchFusedCachedFirstRequest();
        $cache->benchFusedCacheBuild();
    } finally {
        $cache->tearDown();
    }

    $etag = new EtagBench();
    $etag->setUp();
    $etag->benchHashStream(['algorithm' => 'sha256']);

    $kernel = new KernelDispatchBench();
    $kernel->setUp();
    $kernel->benchClosureHandler();
    $kernel->benchStaticController();
    $kernel->benchTaggedDirectFactoryMiddleware();

    $gate = new PreRoutingGateBench();
    $gate->setUp();
    $gate->benchActiveGate();
    $gate->benchInactiveGate();
    $gate->benchInactiveMaintenanceMiddleware();

    $responseCache = new ResponseCacheBench();
    $responseCache->setUp();
    $responseCache->benchHit();
    $responseCache->benchMissAndWrite();
    $responseCache->benchReadFailure();

    $middleware = new RuntimeMiddlewareDescriptorBench();
    $middleware->setUp();
    $middleware->benchDirectCallableMiddleware();
    $middleware->benchParameterizedRuntimeDescriptor();

    $runwire = new RunwireRuntimeBench();
    $runwire->setUp();
    $runwire->benchContextNormalization();
    $runwire->benchFixedResponseWrite();
    $runwire->benchRequestPromotion();
    $runwire->benchRequestScopeRoundTrip();
    $runwire->benchStreamingResponseWrite();

    $security = new SecurityMiddlewareKernelBench();
    $security->setUp();
    $security->benchAnonymousCacheHit();
    $security->benchConditionalRequest();
    $security->benchTrustedProxyRequest();
});

test('parameter-provider benchmarks run a representative declared scenario', function (): void {
    $adaptive = new MatcherAdaptiveDecisionBench();
    foreach ($adaptive->provideCases() as $params) {
        $adaptive->benchAdaptiveDecision($params);
        break;
    }

    $match = new MatcherBench();
    foreach ($match->provideMatchCases() as $params) {
        $match->benchMatch($params);
        break;
    }

    $memory = new MatcherMemoryBench();
    foreach ($memory->provideRouteScales() as $params) {
        $memory->benchMatcherBuild($params);
        break;
    }

    $terminal = new MatcherMethodTerminalBench();
    foreach ($terminal->provideCases() as $params) {
        $terminal->benchMethodTerminal($params);
        break;
    }

    $outcome = new MatcherOutcomeBench();
    foreach ($outcome->provideOutcomeCases() as $params) {
        $outcome->benchCompiledOutcome($params);
        break;
    }

    $pathInspection = new MatcherPathInspectionBench();
    foreach ($pathInspection->providePaths() as $params) {
        $pathInspection->benchCurrentPathShape($params);
        $pathInspection->benchCurrentSegmentMaterialization($params);
        $pathInspection->benchCurrentShapeAndSegments($params);
        $pathInspection->benchOnePassPathShape($params);
        $pathInspection->benchOnePassViewAndMaterialize($params);
        break;
    }
    $pathInspection->benchCurrentPositionalParameterCapture();

    $pcre = new MatcherPcrePolicyBench();
    foreach ($pcre->provideCases() as $params) {
        $pcre->benchPcrePolicy($params);
        break;
    }

    $scale = new MatcherScaleBench();
    foreach ($scale->provideRouteScales() as $params) {
        $scale->benchRepresentativeHit($params);
        break;
    }

    $typed = new MatcherTypedConstraintBench();
    foreach ($typed->provideCases() as $params) {
        $typed->benchTypedConstraint($params);
        break;
    }
});

test('signed URL and runtime middleware benchmarks exercise real callable contracts', function (): void {
    $signed = new SignedUrlBench();
    $signed->benchAbsoluteSign();
    $signed->benchAbsoluteUntil();
    $signed->benchAbsoluteVerify();
    $signed->benchRelativeSign();
    $signed->benchRelativeVerify();

    $fixture = new ParameterizedRuntimeMiddleware('30', '60');
    $reply = $fixture(
        Request::fake(uri: '/benchmark-fixture'),
        static fn(Request $request): Response => Response::plaintext($request->getUri()->getPath()),
    );
    expect((string) $reply->getBody())->toBe('/benchmark-fixture')
        ->and($fixture->limit)->toBe('30')
        ->and($fixture->window)->toBe('60');
});

test('published extension classes are executable through their actual contracts', function (): void {
    $factory = new HttpFactory();
    expect($factory->createRequest('GET', '/smoke')->getMethod())->toBe('GET')
        ->and($factory->createResponse()->getStatusCode())->toBe(200);

    $nextCalls = 0;
    $sanitized = (new InputSanitizerMiddleware())(
        Request::fake(uri: '/smoke'),
        static function (Request $request) use (&$nextCalls): Response {
            ++$nextCalls;

            return Response::plaintext($request->getUri()->getPath());
        },
    );
    expect($nextCalls)->toBe(1)
        ->and((string) $sanitized->getBody())->toBe('/smoke');

    $store = new SessionCsrfTokenStore('_webrick_psalm_smoke_token');
    try {
        $store->put('smoke-token');
        expect($store->get())->toBe('smoke-token');
    } finally {
        unset($_SESSION['_webrick_psalm_smoke_token']);
    }

    expect(Language::negotiate(['en', 'fr'], 'fr'))->toBe('fr')
        ->and(Language::headers('fr')[0])->toBe(['Content-Language', 'fr'])
        ->and(RateLimit::forUser(10, 5, 100)[0])->toBe(['X-RateLimit-Limit', '10'])
        ->and(RateLimit::retryAfter(2))->toBe(['Retry-After', '2']);

    $vary = Vary::fromString('accept-language')->add('accept-encoding');
    expect((string) $vary)->toBe('Accept-Language, Accept-Encoding')
        ->and((string) $vary->remove('accept-language'))->toBe('Accept-Encoding');

    $errors = new PhpErrorBridge();
    expect($errors->isInstalled())->toBeFalse();
    $errors->install();
    try {
        expect($errors->isInstalled())->toBeTrue();
    } finally {
        $errors->restore();
    }
    expect($errors->isInstalled())->toBeFalse();

    $controller = new TestController();
    expect((string) $controller->hello('bench')->getBody())->toContain('bench');
    $middleware = new TestMiddleware();
    $response = $middleware(
        Request::fake(uri: '/smoke'),
        static fn(Request $request): Response => Response::plaintext($request->getUri()->getPath()),
    );
    expect($response->getHeaderLine('X-Test-Middleware'))->toBe('passed');
});

test('attribute fixture controller is resolvable and dispatches the documented handler', function (): void {
    require_once __DIR__ . '/../Fixture/AttrDemoController.php';
    $controller = new \Infocyph\Webrick\Tests\Fixture\AttrDemoController();
    expect((string) $controller->hello('bench')->getBody())->toBe('{"hello":"bench"}');
});
