<?php

declare(strict_types=1);

use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Support\RouteCache;
use Psr\Log\NullLogger;

describe('Route Caching', function () {
    afterEach(function () {
        $cacheDir = sys_get_temp_dir() . '/webrick-cache-test';
        if (is_dir($cacheDir)) {
            cleanTestCache($cacheDir);
        }
    });

    it('builds and uses fused cache', function () {
        $cacheFile = sys_get_temp_dir() . '/webrick-cache-test/routes.php';

        // Build cache
        $sentinel = RouteCache::build([
            'matcher' => 'fused',
            'cache' => $cacheFile,
            'register' => function (Registrar $r) {
                $r->get('/cached', fn() => Response::plaintext('Cached Route'), 'cached');
            },
            'logger' => new NullLogger(),
        ]);

        expect($sentinel)->toBe($cacheFile);
        expect(file_exists($cacheFile))->toBeTrue();

        // Use cache
        $kernel = RouterKernel::bootWithRegistrar(
            log: new NullLogger(),
            matcher: FusedMatcher::make(),
            register: function (Registrar $r) {
                // This should not be called since cache exists
                $r->get('/not-cached', fn() => Response::plaintext('Should not exist'));
            },
            routeCache: $cacheFile
        );

        // Cached route should work
        $request1 = mockRequest('GET', '/cached');
        $response1 = $kernel->handle($request1);
        expect($response1)->toHaveStatus(200);

        // Non-cached route should not exist
        $request2 = mockRequest('GET', '/not-cached');
        $response2 = $kernel->handle($request2);
        expect($response2)->toHaveStatus(404);
    });

    it('builds and uses sharded cache', function () {
        $cacheDir = sys_get_temp_dir() . '/webrick-cache-test';

        // Build cache
        RouteCache::build([
            'matcher' => 'sharded',
            'cache' => $cacheDir,
            'register' => function (Registrar $r) {
                $r->get('/users', fn() => Response::json([]), 'users.index');
                $r->get('/posts', fn() => Response::json([]), 'posts.index');
            },
            'logger' => new NullLogger(),
        ]);

        // Check that shard files were created
        expect(is_dir($cacheDir))->toBeTrue();

        // Should have separate shards for /users and /posts
        $files = glob($cacheDir . '/*.php');
        expect(count($files))->toBeGreaterThan(1);
    });

    it('clears cache', function () {
        $cacheFile = sys_get_temp_dir() . '/webrick-cache-test/routes.php';

        // Build cache
        RouteCache::build([
            'matcher' => 'fused',
            'cache' => $cacheFile,
            'register' => function (Registrar $r) {
                $r->get('/test', fn() => Response::plaintext('Test'));
            },
            'logger' => new NullLogger(),
        ]);

        expect(file_exists($cacheFile))->toBeTrue();

        // Clear cache
        $removed = RouteCache::clear([
            'matcher' => 'fused',
            'cache' => $cacheFile,
        ]);

        expect($removed)->toBeTrue();
        expect(file_exists($cacheFile))->toBeFalse();
    });
});
