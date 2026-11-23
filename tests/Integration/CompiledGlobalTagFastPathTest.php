<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Build\ReleaseCompiler;
use Infocyph\Webrick\Router\Build\RouterArtifactLoader;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Kernel\CompiledRouterKernel;
use Infocyph\Webrick\Router\Kernel\ErrorHandler;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Infocyph\Webrick\Router\Runtime\RoutingInput;
use Infocyph\Webrick\Runtime\Http\RuntimeCapabilities;
use Infocyph\Webrick\Runtime\Http\RuntimeRequestContext;
use Infocyph\Webrick\Tests\Fixture\ScopedTaggedMiddlewareFixture;
use Opis\Closure\CodeStream;
use PHPUnit\Framework\Attributes\BackupStaticProperties;
use PHPUnit\Framework\Attributes\ExcludeStaticPropertyFromBackup;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[BackupStaticProperties(true)]
#[ExcludeStaticPropertyFromBackup(CodeStream::class, 'isRegistered')]
#[ExcludeStaticPropertyFromBackup(CodeStream::class, 'handlers')]
final class CompiledGlobalTagFastPathTest extends TestCase
{
    /** @throws \Throwable */
    public function testEmptyDefaultTagsKeepDirectHandlerRequestFree(): void
    {
        $directory = sys_get_temp_dir() . '/webrick-empty-tags-' . bin2hex(random_bytes(8));
        if (!mkdir($directory, 0700)) {
            throw new RuntimeException('Unable to create coordinated release fixture.');
        }
        $builder = ContainerBuilder::create('webrick.empty.tags.' . bin2hex(random_bytes(4)));
        $manifestPath = $directory . '/release.json';
        $routerPath = $directory . '/router.php';
        $fingerprint = 'empty-tags';

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

            $artifact = new RouterArtifactLoader()->load($routerPath, 'production', $fingerprint);
            expect($artifact->preGlobalTags)->toBe([])
                ->and($artifact->postGlobalTags)->toBe([]);

            $kernel = CompiledRouterKernel::fromReleaseManifest(
                log: new NullLogger(),
                matcher: FusedMatcher::make(),
                builder: $builder,
                releaseManifestPath: $manifestPath,
                environment: 'production',
                configFingerprint: 'empty-tags',
            );
            $requestCreations = 0;
            $context = new RuntimeRequestContext(
                new RoutingInput('GET', '/direct'),
                static function () use (&$requestCreations): Request {
                    ++$requestCreations;

                    return Request::fake(uri: '/direct');
                },
                new RuntimeCapabilities('test'),
            );
            expect((string) $kernel->handleRuntime($context)->getBody())->toBe('direct')
                ->and($requestCreations)->toBe(0);
        } finally {
            cleanTestCache($directory);
        }
    }

    /** @throws \Throwable */
    public function testScopedGlobalTagsRegisteredDuringGraphEnrichmentArePreserved(): void
    {
        $directory = sys_get_temp_dir() . '/webrick-compiled-tags-' . bin2hex(random_bytes(8));
        if (!mkdir($directory, 0700)) {
            throw new RuntimeException('Unable to create coordinated release fixture.');
        }
        $builder = ContainerBuilder::create('webrick.scoped.tags.' . bin2hex(random_bytes(4)));
        $manifestPath = $directory . '/release.json';
        $routerPath = $directory . '/router.php';
        $fingerprint = 'scoped-tags';

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

            $artifact = new RouterArtifactLoader()->load($routerPath, 'production', $fingerprint);
            expect($artifact->preGlobalTags)->toBe(['webrick.middleware.pre'])
                ->and($artifact->postGlobalTags)->toBe(['webrick.middleware.post']);

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
    }

    /** @throws \Throwable */
    public function testExplicitGlobalsRemainWhenDefaultTagsHaveNoServices(): void
    {
        $directory = sys_get_temp_dir() . '/webrick-explicit-globals-' . bin2hex(random_bytes(8));
        if (!mkdir($directory, 0700)) {
            throw new RuntimeException('Unable to create coordinated release fixture.');
        }
        $builder = ContainerBuilder::create('webrick.explicit.globals.' . bin2hex(random_bytes(4)));
        $routerPath = $directory . '/router.php';
        $fingerprint = 'explicit-global';

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
                    $response = $next($request);
                    if (!$response instanceof Response) {
                        throw new UnexpectedValueException('Global middleware must return a Response.');
                    }

                    return $response->withHeader('X-Explicit-Pre', 'yes');
                }],
                postGlobal: [static function (Request $request, Closure $next): Response {
                    $response = $next($request);
                    if (!$response instanceof Response) {
                        throw new UnexpectedValueException('Global middleware must return a Response.');
                    }

                    return $response->withHeader('X-Explicit-Post', 'yes');
                }],
            );

            $artifact = new RouterArtifactLoader()->load($routerPath, 'production', $fingerprint);
            expect($artifact->preGlobalTags)->toBe([])
                ->and($artifact->postGlobalTags)->toBe([])
                ->and($artifact->preGlobal())->toHaveCount(1)
                ->and($artifact->postGlobal())->toHaveCount(1);

            $kernel = CompiledRouterKernel::fromReleaseManifest(
                log: new NullLogger(),
                matcher: FusedMatcher::make(),
                builder: $builder,
                releaseManifestPath: $directory . '/release.json',
                environment: 'production',
                configFingerprint: 'explicit-global',
                errorHandler: new ErrorHandler(logger: new NullLogger(), debug: true),
            );
            $response = $kernel->handle(Request::fake(uri: '/explicit'));
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            expect($response->getStatusCode())->toBe(200)
                ->and($response->getHeaderLine('X-Explicit-Pre'))->toBe('yes')
                ->and($response->getHeaderLine('X-Explicit-Post'))->toBe('yes');
        } finally {
            cleanTestCache($directory);
        }
    }
}
