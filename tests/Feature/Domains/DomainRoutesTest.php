<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Tests\Feature\Domains;

use function Infocyph\Webrick\Tests\{httpGlobals, makeKernel, status, body};
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Facade\Router as Route;

it('honors domain-scoped routes for api.localhost', function () {
    $kernel = makeKernel(null, function (Registrar $r) {
        Route::group(prefix: '/v1', domain: 'api.localhost', namePrefix: 'api.', callback: function () {
            Route::get('/ping', fn () => Response::json(['domain' => 'api.localhost', 'ok' => true]), 'ping');
        });
    });

    httpGlobals('GET', '/v1/ping', ['Host' => 'api.localhost']);
    $res = $kernel->handle();
    expect(status($res))->toBe(200);
    expect(body($res))->toContain('\"domain\":\"api.localhost\"');

    httpGlobals('GET', '/v1/ping', ['Host' => 'localhost']);
    $miss = $kernel->handle();
    expect(status($miss))->toBeGreaterThanOrEqual(400);
});
