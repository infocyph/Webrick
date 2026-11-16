<?php

declare(strict_types=1);

use Infocyph\Webrick\Middleware\RiskAdmissionMiddleware;
use Infocyph\Webrick\Middleware\RiskDecision;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

test('risk admission passes an allow decision once with request-local metadata', function (): void {
    $calls = 0;
    $request = Request::fake(uri: '/login');
    $decision = RiskDecision::allow(5, ['known_device']);
    $middleware = new RiskAdmissionMiddleware(static function (Request $current) use (&$calls, $request, $decision): RiskDecision {
        ++$calls;
        expect($current)->toBe($request);

        return $decision;
    });

    $response = $middleware($request, static function (Request $current) use ($request, $decision): Response {
        expect($current)->not->toBe($request);
        expect($current->getAttribute(RiskDecision::ATTRIBUTE))->toBe($decision);
        expect($request->getAttribute(RiskDecision::ATTRIBUTE))->toBeNull();

        return Response::json(['ok' => true]);
    });

    expect($calls)->toBe(1);
    expect($decision->score)->toBe(5);
    expect($decision->reasonCodes)->toBe(['known_device']);
    expect($response->getStatusCode())->toBe(200);
});

test('risk admission rejects before invoking the protected handler', function (RiskDecision $decision, int $status, string $code): void {
    $calls = 0;
    $middleware = new RiskAdmissionMiddleware(static fn(): RiskDecision => $decision);
    $response = $middleware(Request::fake(uri: '/protected'), static function () use (&$calls): Response {
        ++$calls;
        return Response::json(['ok' => true]);
    });

    expect($calls)->toBe(0);
    expect($response->getStatusCode())->toBe($status);
    expect($response->getHeaderLine('Cache-Control'))->toBe('no-store');
    expect($response->getHeaderLine('Content-Type'))->toContain('application/json');
    expect((string) $response->getBody())->toBe(json_encode(['error' => $code], JSON_THROW_ON_ERROR));
})->with([
    [RiskDecision::challenge(80, ['challenge_needed']), 429, 'challenge_required'],
    [RiskDecision::deny(99, ['policy_deny']), 403, 'access_denied'],
    [RiskDecision::unavailable(null, ['provider_offline']), 503, 'temporarily_unavailable'],
]);

test('shadow mode forwards all verdicts once without performing enforcement', function (RiskDecision $decision): void {
    $calls = 0;
    $middleware = new RiskAdmissionMiddleware(static fn(): RiskDecision => $decision, mode: RiskAdmissionMiddleware::SHADOW);
    $response = $middleware(Request::fake(), static function (Request $request) use (&$calls, $decision): Response {
        ++$calls;
        expect($request->getAttribute(RiskDecision::ATTRIBUTE))->toBe($decision);

        return Response::json(['shadow' => true]);
    });

    expect($calls)->toBe(1);
    expect($response->getStatusCode())->toBe(200);
})->with([
    RiskDecision::allow(),
    RiskDecision::challenge(),
    RiskDecision::deny(),
    RiskDecision::unavailable(),
]);

test('custom renderer cannot permit protected handler execution and cannot override no-store', function (): void {
    $calls = 0;
    $decision = RiskDecision::challenge();
    $middleware = new RiskAdmissionMiddleware(
        static fn(): RiskDecision => $decision,
        renderer: static function (Request $request, RiskDecision $current): Response {
            expect($request->getAttribute(RiskDecision::ATTRIBUTE))->toBe($current);

            return Response::json(['custom' => true], 429, ['Cache-Control' => 'public']);
        },
    );
    $response = $middleware(Request::fake(), static function () use (&$calls): Response {
        ++$calls;
        return Response::json(['ok' => true]);
    });

    expect($calls)->toBe(0);
    expect($response->getHeaderLine('Cache-Control'))->toBe('no-store');
});

test('risk admission rejects invalid mode and malformed reason metadata', function (): void {
    expect(fn(): RiskDecision => RiskDecision::deny(-1))->toThrow(InvalidArgumentException::class);
    expect(fn(): RiskDecision => RiskDecision::deny(null, ['token=secret']))->toThrow(InvalidArgumentException::class);
    expect(fn(): RiskDecision => RiskDecision::deny(null, array_fill(0, 9, 'reason')))->toThrow(InvalidArgumentException::class);
    expect(fn(): RiskDecision => RiskDecision::deny(null, ['reason' => 'value']))->toThrow(InvalidArgumentException::class);
    expect(fn(): RiskDecision => RiskDecision::deny(null, [false]))->toThrow(InvalidArgumentException::class);
    expect(fn(): RiskAdmissionMiddleware => new RiskAdmissionMiddleware(static fn(): RiskDecision => RiskDecision::allow(), mode: 'disabled'))
        ->toThrow(InvalidArgumentException::class);
});

test('risk admission rejects evaluator and renderer contract violations', function (): void {
    // Reflection simulates a dynamically supplied host callback with a broken return contract.
    $type = new ReflectionClass(RiskAdmissionMiddleware::class);
    $badEvaluator = $type->newInstanceArgs([static fn(): string => 'allow']);
    expect(fn(): Response => $badEvaluator(Request::fake(), static fn(): Response => Response::json([])))
        ->toThrow(UnexpectedValueException::class);

    $badRenderer = $type->newInstanceArgs([
        static fn(): RiskDecision => RiskDecision::deny(),
        RiskAdmissionMiddleware::ENFORCE,
        static fn(): null => null,
    ]);
    expect(fn(): Response => $badRenderer(Request::fake(), static fn(): Response => Response::json([])))
        ->toThrow(UnexpectedValueException::class);
});
