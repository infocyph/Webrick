<?php

declare(strict_types=1);

use Infocyph\Webrick\Middleware\RiskDecision;
use Infocyph\Webrick\Middleware\RiskAdmissionMiddleware;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

require_once dirname(__DIR__, 2) . '/.github/certification/cert-risk.php';

/**
 * @throws RuntimeException
 * @throws InvalidArgumentException
 */
function certificationTestRiskBinding(string $workload): RiskAdmissionMiddleware
{
    if (!function_exists('certification_risk_middleware')) {
        throw new RuntimeException('Certification policy binding is unavailable.');
    }
    $binding = certification_risk_middleware($workload);
    \PHPUnit\Framework\Assert::assertInstanceOf(RiskAdmissionMiddleware::class, $binding);

    return $binding;
}

test('certification reuses immutable policy bindings without retaining request state', function (): void {
    $middleware = certificationTestRiskBinding('risk_allow');
    expect(certificationTestRiskBinding('risk_allow'))->toBe($middleware);
    expect(certificationTestRiskBinding('risk_counter'))->not->toBe($middleware);
    $decisions = [];
    foreach (['one', 'two'] as $id) {
        $reference = null;
        $response = $middleware(Request::fake(uri: '/risk/' . $id), static function (Request $current) use ($id, &$reference, &$decisions): Response {
            expect($current->getUri()->getPath())->toBe('/risk/' . $id);
            $decisions[] = $current->getAttribute(RiskDecision::ATTRIBUTE);
            $reference = WeakReference::create($current);

            return Response::plaintext('ok', 200);
        });
        expect($response->getStatusCode())->toBe(200);
        \PHPUnit\Framework\Assert::assertInstanceOf(WeakReference::class, $reference);
        gc_collect_cycles();
        expect($reference->get())->toBeNull();
    }
    expect($decisions[0])->toBeInstanceOf(RiskDecision::class)
        ->and($decisions[1])->toBeInstanceOf(RiskDecision::class)
        ->and($decisions[0])->not->toBe($decisions[1]);
});

test('a reused certification binding reevaluates rejection before the handler', function (): void {
    $middleware = certificationTestRiskBinding('risk_allow');
    $calls = 0;
    $next = static function () use (&$calls): Response {
        ++$calls;

        return Response::plaintext('ok', 200);
    };
    $allowed = $middleware(Request::fake(), $next);
    $denied = $middleware(Request::fake()->withHeader('X-Cert-Risk-Action', 'deny'), $next);
    $allowedAgain = $middleware(Request::fake(), $next);
    expect($allowed->getStatusCode())->toBe(200)
        ->and($denied->getStatusCode())->toBe(403)
        ->and($denied->getHeaderLine('Cache-Control'))->toBe('no-store')
        ->and($allowedAgain->getStatusCode())->toBe(200)
        ->and($calls)->toBe(2);
});
