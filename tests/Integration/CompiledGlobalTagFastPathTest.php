<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Build\ReleaseCompiler;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Kernel\CompiledRouterKernel;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Infocyph\Webrick\Router\Runtime\RoutingInput;
use Infocyph\Webrick\Runtime\Http\RuntimeCapabilities;
use Infocyph\Webrick\Runtime\Http\RuntimeRequestContext;
use Infocyph\Webrick\Tests\Fixture\ScopedTaggedMiddlewareFixture;
use Psr\Log\NullLogger;

test('coordinated release omits empty default tag bindings and stays request-free', function (): void {
    $directory = sys_get_temp_dir() . '/webrick-empty-tags-' . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0700)) {
        throw new RuntimeException('Unable to create coordinated release fixture.');
    }
    $builder = ContainerBuilder::create('webrick.empty.tags.' . bin2hex(random_bytes(4)));
    $manifestPath = $directory . '/release.json';
    $routerPath = $directory . '/router.php';

    try {
        new ReleaseCompiler()->compile(
            builder: $builder,
            register: static function (Registrar $routes): void {
                $routes->get('/direct', static fn(): Response => Response::plaintext('direct'));
            },
            environment: 'production',
            configFingerprint: 'empty-tags',
            intermixPath: $directory . '/intermix.php',
            routerPath: $routerPath,
            releaseManifestPath: $manifestPath,
        );

        $payload = require $routerPath;
        expect($payload['pre_global_tags'])->toBe([])
            ->and($payload['post_global_tags'])->toBe([]);

        $kernel = CompiledRouterKernel::fromReleaseManifest(
            log: new NullLogger(),
            matcher: FusedMatcher::make(),
            builder: $builder,
            releaseManifestPath: $manifestPath,
            environment: 'production',
            configFingerprint: 'empty-tags',
        );
        $context = new RuntimeRequestContext(
            new RoutingInput('GET', '/direct'),
            static fn(): Request => throw new RuntimeException('Direct route must not create a Request.'),
            new RuntimeCapabilities('test'),
        );
        expect((string) $kernel->handleRuntime($context)->getBody())->toBe('direct');
    } finally {
        cleanTestCache($directory);
    }
});

test('coordinated release preserves scoped tags registered after route discovery', function (): void {
    $directory = sys_get_temp_dir() . '/webrick-compiled-tags-' . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0700)) {
        throw new RuntimeException('Unable to create coordinated release fixture.');
    }
    $builder = ContainerBuilder::create('webrick.scoped.tags.' . bin2hex(random_bytes(4)));
    $manifestPath = $directory . '/release.json';
    $routerPath = $directory . '/router.php';

    try {
        new ReleaseCompiler()->compile(
            builder: $builder,
            register: static function (Registrar $routes): void {
                $routes->get('/tag', static fn(Request $request): Response => Response::plaintext($request->getUri()->getPath()));
            },
            environment: 'production',
            configFingerprint: 'scoped-tags',
            intermixPath: $directory . '/intermix.php',
            routerPath: $routerPath,
            releaseManifestPath: $manifestPath,
            enrichGraph: static function (ContainerBuilder $graph): void {
                $graph->autowire(
                    'webrick.test.pre',
                    ScopedTaggedMiddlewareFixture::class,
                    arguments: ['label' => 'pre'],
                    lifetime: LifetimeEnum::Scoped,
                    tags: ['webrick.middleware.pre'],
                );
                $graph->autowire(
                    'webrick.test.post',
                    ScopedTaggedMiddlewareFixture::class,
                    arguments: ['label' => 'post'],
                    lifetime: LifetimeEnum::Scoped,
                    tags: ['webrick.middleware.post'],
                );
            },
        );

        $payload = require $routerPath;
        expect($payload['pre_global_tags'])->toBe(['webrick.middleware.pre'])
            ->and($payload['post_global_tags'])->toBe(['webrick.middleware.post']);

        $kernel = CompiledRouterKernel::fromReleaseManifest(
            log: new NullLogger(),
            matcher: FusedMatcher::make(),
            builder: $builder,
            releaseManifestPath: $manifestPath,
            environment: 'production',
            configFingerprint: 'scoped-tags',
        );
        foreach (['one', 'two'] as $id) {
            $response = $kernel->handle(Request::fake(uri: '/tag')->withAttribute('id', $id));
            expect((string) $response->getBody())->toBe('pre[post[/tag]]')
                ->and($response->getHeaderLine('X-Tagged-pre'))->toBe('/tag')
                ->and($response->getHeaderLine('X-Tagged-post'))->toBe('/tag');
        }
    } finally {
        cleanTestCache($directory);
    }
});

test('coordinated release preserves explicit globals while pruning only unbound tags', function (): void {
    $directory = sys_get_temp_dir() . '/webrick-explicit-globals-' . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0700)) {
        throw new RuntimeException('Unable to create coordinated release fixture.');
    }
    $builder = ContainerBuilder::create('webrick.explicit.globals.' . bin2hex(random_bytes(4)));
    $routerPath = $directory . '/router.php';

    try {
        new ReleaseCompiler()->compile(
            builder: $builder,
            register: static function (Registrar $routes): void {
                $routes->get('/explicit', static fn(): Response => Response::plaintext('terminal'));
            },
            environment: 'production',
            configFingerprint: 'explicit-global',
            intermixPath: $directory . '/intermix.php',
            routerPath: $routerPath,
            releaseManifestPath: $directory . '/release.json',
            preGlobal: [static function (Request $request, Closure $next): Response {
                return $next($request)->withHeader('X-Explicit-Pre', 'yes');
            }],
            postGlobal: [static function (Request $request, Closure $next): Response {
                return $next($request)->withHeader('X-Explicit-Post', 'yes');
            }],
        );

        $payload = require $routerPath;
        expect($payload['pre_global_tags'])->toBe([])
            ->and($payload['post_global_tags'])->toBe([])
            ->and($payload['pre_global'])->toHaveCount(1)
            ->and($payload['post_global'])->toHaveCount(1);

        $kernel = CompiledRouterKernel::fromReleaseManifest(
            log: new NullLogger(),
            matcher: FusedMatcher::make(),
            builder: $builder,
            releaseManifestPath: $directory . '/release.json',
            environment: 'production',
            configFingerprint: 'explicit-global',
        );
        $response = $kernel->handle(Request::fake(uri: '/explicit'));
        expect($response->getStatusCode())->toBe(200)
            ->and($response->getHeaderLine('X-Explicit-Pre'))->toBe('yes')
            ->and($response->getHeaderLine('X-Explicit-Post'))->toBe('yes');
    } finally {
        cleanTestCache($directory);
    }
});
