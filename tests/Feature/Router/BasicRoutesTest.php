<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Tests\Feature\Router;

use function Infocyph\Webrick\Tests\{httpGlobals, makeKernel, status, body};
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Facade\Router as Route;

it('responds to /ping with plaintext', function () {
    $kernel = makeKernel(null, function (Registrar $r) {
        Route::get('/ping', fn () => Response::plaintext('pong'));
    });

    httpGlobals('GET', '/ping');
    $res = $kernel->handle();

    expect(status($res))->toBe(200);
    expect(body($res))->toBe('pong');
});

it('responds to /json with application/json', function () {
    $kernel = makeKernel(null, function (Registrar $r) {
        Route::get('/json', fn () => Response::json(['ok' => true, 'message' => 'pong']))->name('json');
    });

    httpGlobals('GET', '/json', ['Accept' => 'application/json']);
    $res = $kernel->handle();

    expect(status($res))->toBe(200);
    expect(body($res))->toContain('\"ok\":true');
});
