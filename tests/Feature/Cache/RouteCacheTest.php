<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Tests\Feature\Cache;

use function Infocyph\Webrick\Tests\{httpGlobals, makeKernel, status, body};
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Facade\Router as Route;

it('materializes sharded route cache', function () {
    $dir = sys_get_temp_dir() . '/webrick_cache_' . bin2hex(random_bytes(3));
    @mkdir($dir, 0777, true);

    $kernel = makeKernel($dir, function (Registrar $r) {
        Route::get('/ping', fn () => Response::plaintext('pong'));
    });

    httpGlobals('GET', '/ping');
    $res = $kernel->handle();
    expect(status($res))->toBe(200);
    expect(body($res))->toBe('pong');

    $entries = array_values(array_filter(@scandir($dir) ?: [], fn ($f) => $f !== '.' && $f !== '..'));
    expect(count($entries))->toBeGreaterThan(0);
});
