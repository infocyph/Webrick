<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Tests\Feature\Groups;

use function Infocyph\Webrick\Tests\{httpGlobals, makeKernel, status, body};
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Facade\Router as Route;

it('serves blog index and show', function () {
    $kernel = makeKernel(null, function (Registrar $r) {
        Route::group(prefix: '/blog', namePrefix: 'blog.', callback: function (Registrar $blog) {
            $blog->get('/', fn () => Response::json(['section' => 'blog', 'action' => 'index']), 'index');
            Route::get('/{slug}', fn ($slug) => Response::json(['section' => 'blog', 'slug' => $slug]), 'show');
        });
    });

    httpGlobals('GET', '/blog');
    $res1 = $kernel->handle();
    expect(status($res1))->toBe(200);
    expect(body($res1))->toContain('\"section\":\"blog\"');

    httpGlobals('GET', '/blog/hello-world');
    $res2 = $kernel->handle();
    expect(status($res2))->toBe(200);
    expect(body($res2))->toContain('\"slug\":\"hello-world\"');
});
