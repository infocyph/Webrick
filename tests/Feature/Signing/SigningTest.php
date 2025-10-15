<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Tests\Feature\Signing;

use function Infocyph\Webrick\Tests\{httpGlobals, makeKernel, status, headerLine, body};
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Facade\Router as Route;
use Infocyph\Webrick\Middleware\VerifySignedUrlMiddleware;

it('blocks /secure without signature', function () {
    $kernel = makeKernel(null, function (Registrar $r) {
        Route::get('/secure/{id:int}', fn ($id) => Response::json(['secure' => true, 'id' => (int)$id]), [
            'as' => 'secure.show',
            'middleware' => [VerifySignedUrlMiddleware::class],
        ]);
    }, ['signKey' => 'tests-secret', 'signTtl' => 5]);

    httpGlobals('GET', '/secure/42');
    $res = $kernel->handle();
    expect(status($res))->toBeGreaterThanOrEqual(400);
});

it('redirects from /make-signed to a signed url, which then allows access', function () {
    $kernel = makeKernel(null, function (Registrar $r) {
        Route::get('/secure/{id:int}', fn ($id) => Response::json(['ok' => true, 'id' => (int)$id]), [
            'as' => 'secure.show',
            'middleware' => [VerifySignedUrlMiddleware::class],
        ]);

        Route::get('/make-signed/{id:int}', function ($id) {
            $signed = Response::temporaryUrlFor('secure.show', ['id' => (int)$id], 60, [], false);
            return Response::redirect($signed, 302);
        }, 'make.signed');
    }, ['signKey' => 'tests-secret', 'signTtl' => 60]);

    httpGlobals('GET', '/make-signed/42');
    $res1 = $kernel->handle();
    expect(status($res1))->toBe(302);
    $loc = headerLine($res1, 'Location');
    expect($loc)->not()->toBeNull();

    httpGlobals('GET', $loc ?? '/');
    $res2 = $kernel->handle();
    expect(status($res2))->toBe(200);
    expect(body($res2))->toContain('\"id\":42');
});
