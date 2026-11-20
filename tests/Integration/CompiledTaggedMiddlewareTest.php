<?php

declare(strict_types=1);

namespace Tests\Integration;

use Closure;
use Fiber;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\ProductionContainer;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\Integration\Runwire\RunwireIntegration;
use Infocyph\Runwire\RequestContext as RunwireRequestContext;
use Infocyph\Runwire\RuntimeContext as RunwireContext;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Build\CompiledRouterArtifact;
use Infocyph\Webrick\Router\Build\RouteCompiler;
use Infocyph\Webrick\Router\Build\RouterArtifactCompiler;
use Infocyph\Webrick\Router\Build\RouterArtifactLoader;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Dispatch\RuntimeDispatcher;
use Infocyph\Webrick\Router\Kernel\CompiledRouterKernel;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Infocyph\Webrick\Router\Runtime\RoutingInput;
use Infocyph\Webrick\Runtime\Http\RuntimeCapabilities;
use Infocyph\Webrick\Runtime\Http\RuntimeRequestContext;
use Infocyph\Webrick\Runtime\Http\RunwireInterMixScopeBridge;
use Infocyph\Webrick\Runtime\InterMixRuntime;
use Infocyph\Webrick\Tests\Fixture\ScopedTaggedMiddlewareFixture;
use Opis\Closure\CodeStream;
use PHPUnit\Framework\Attributes\BackupStaticProperties;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\ExcludeStaticPropertyFromBackup;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use UnexpectedValueException;
use WeakReference;

#[BackupStaticProperties(true)]
#[ExcludeStaticPropertyFromBackup(CodeStream::class, 'isRegistered')]
#[ExcludeStaticPropertyFromBackup(CodeStream::class, 'handlers')]
final class CompiledTaggedMiddlewareTest extends TestCase
{
    /** @var list<string> */
    private array $directories = [];

    /**
     * @throws \UnexpectedValueException
     */
    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            \cleanTestCache($directory);
        }
        parent::tearDown();
    }

    /** @return iterable<string,array{LifetimeEnum}> */
    public static function lifetimes(): iterable
    {
        yield 'scoped' => [LifetimeEnum::Scoped];
        yield 'transient' => [LifetimeEnum::Transient];
    }

    /**
     * @throws \InvalidArgumentException
     * @throws \LogicException
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     */
    #[DataProvider('lifetimes')]
    public function testCachedDispatcherDoesNotRetainTaggedRequestInstances(LifetimeEnum $lifetime): void
    {
        [$kernel, $container, $dispatcher, $artifact] = $this->fixture($lifetime);
        unset($kernel);
        $plan = $artifact->planForIndex(0);
        foreach (['one', 'two'] as $id) {
            $request = Request::fake(uri: '/tag/' . $id);
            $response = $container->withinScope(
                RuntimeRequestContext::REQUEST_SCOPE,
                static fn(): Response => $dispatcher->dispatch(0, $plan, $request, ['id' => $id]),
                [Request::class => $request],
            );
            self::assertInstanceOf(Response::class, $response);
            self::assertSame('/tag/' . $id, $response->getHeaderLine('X-Tagged-pre'));
            self::assertSame('/tag/' . $id, $response->getHeaderLine('X-Tagged-post'));
        }
    }

    /**
     * @throws \InvalidArgumentException
     * @throws \LogicException
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     */
    #[DataProvider('lifetimes')]
    public function testKernelResolvesTagsInsideEachRequestScopeInOrder(LifetimeEnum $lifetime): void
    {
        [$kernel] = $this->fixture($lifetime);
        foreach (['one', 'two'] as $id) {
            $response = $kernel->handle(Request::fake(uri: '/tag/' . $id));
            self::assertSame(200, $response->getStatusCode());
            self::assertSame('/tag/' . $id, $response->getHeaderLine('X-Tagged-pre'));
            self::assertSame('/tag/' . $id, $response->getHeaderLine('X-Tagged-post'));
            self::assertSame(
                'explicit-pre[pre[route[explicit-post[post[/tag/' . $id . ']]]]]',
                (string) $response->getBody(),
            );
        }
    }

    /**
     * @throws \InvalidArgumentException
     * @throws \LogicException
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     */
    public function testInterleavedTaggedMiddlewareKeepsItsOwnRequest(): void
    {
        [$kernel] = $this->fixture(LifetimeEnum::Scoped);
        $a = new Fiber(static fn(): Response => $kernel->handle(Request::fake(uri: '/tag/a')->withAttribute('pause', true)));
        $b = new Fiber(static fn(): Response => $kernel->handle(Request::fake(uri: '/tag/b')->withAttribute('pause', true)));
        self::assertSame('/tag/a', $a->start());
        self::assertSame('/tag/b', $b->start());
        $a->resume();
        $b->resume();
        $responseA = $a->getReturn();
        $responseB = $b->getReturn();
        self::assertInstanceOf(Response::class, $responseA);
        self::assertInstanceOf(Response::class, $responseB);
        self::assertSame('/tag/a', $responseA->getHeaderLine('X-Tagged-pre'));
        self::assertSame('/tag/a', $responseA->getHeaderLine('X-Tagged-post'));
        self::assertSame('/tag/b', $responseB->getHeaderLine('X-Tagged-pre'));
        self::assertSame('/tag/b', $responseB->getHeaderLine('X-Tagged-post'));
    }

    /**
     * @throws \InvalidArgumentException
     * @throws \LogicException
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     */
    public function testTaggedRequestsAreReleasedAfterSuccessAndFailure(): void
    {
        [$kernel] = $this->fixture(LifetimeEnum::Scoped);
        foreach ([false, true, false] as $fail) {
            $request = Request::fake(uri: '/tag/release')->withAttribute('fail', $fail);
            $reference = WeakReference::create($request);
            $response = $kernel->handle($request);
            self::assertSame($fail ? 500 : 200, $response->getStatusCode());
            unset($request);
            gc_collect_cycles();
            self::assertNull($reference->get());
        }
    }

    /**
     * @throws \InvalidArgumentException
     * @throws \LogicException
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     */
    public function testEmptyTaggedPipelineStillRejectsInvalidTerminalResult(): void
    {
        [, $container] = $this->fixture(LifetimeEnum::Scoped, false);
        $tagged = new \Infocyph\Webrick\Router\Dispatch\RuntimeTaggedMiddleware('pre', new InterMixRuntime($container));

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Compiled middleware pipeline must return ');

        $tagged(
            Request::fake(uri: '/tag/invalid'),
            static fn(Request $request): string => $request->getUri()->getPath(),
        );
    }

    /**
     * @throws \InvalidArgumentException
     * @throws \LogicException
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     */
    public function testEmptyTagsPreserveExplicitMiddleware(): void
    {
        [$kernel] = $this->fixture(LifetimeEnum::Scoped, false);
        $response = $kernel->handle(Request::fake(uri: '/tag/empty'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('explicit-pre[route[explicit-post[/tag/empty]]]', (string) $response->getBody());
    }

    /**
     * @throws \InvalidArgumentException
     * @throws \LogicException
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     */
    public function testEmptyTagsDoNotRequireAnUndeclaredRequestInput(): void
    {
        [$kernel] = $this->fixture(LifetimeEnum::Scoped, false, false);
        $response = $kernel->handle(Request::fake(uri: '/tag/native'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('explicit-pre[route[explicit-post[/tag/native]]]', (string) $response->getBody());
    }

    /**
     * @throws \Throwable
     */
    public function testRunwireBridgePreservesNativeHandlersWithoutARequestInput(): void
    {
        [$kernel, $container] = $this->fixture(LifetimeEnum::Scoped, false, false, true);
        $runtime = RunwireContext::standalone();
        $nativeRequest = RunwireRequestContext::create($runtime);
        $integration = new RunwireIntegration($container);
        $integration->bind($runtime);
        try {
            $context = new RuntimeRequestContext(
                new RoutingInput('GET', '/tag/runwire'),
                static fn(): Request => Request::fake(uri: '/tag/runwire'),
                new RuntimeCapabilities('test'),
                scopeBridge: new RunwireInterMixScopeBridge($integration, $nativeRequest),
            );
            $response = $kernel->handleRuntime($context, static function (Response $current) use ($container, $nativeRequest): void {
                self::assertSame($nativeRequest, $container->get(RunwireRequestContext::class));
                self::assertSame(200, $current->getStatusCode());
            });
            self::assertSame(200, $response->getStatusCode());
            self::assertSame('explicit-pre[route[explicit-post[/tag/runwire]]]', (string) $response->getBody());
            self::assertFalse($container->has(Request::class));
        } finally {
            $integration->release($runtime);
        }
    }

    /**
     * @throws \Throwable
     */
    public function testTaggedScopeStaysActiveUntilRuntimeResponseConsumption(): void
    {
        [$kernel, $container] = $this->fixture(LifetimeEnum::Scoped);
        $context = new RuntimeRequestContext(
            new RoutingInput('GET', '/tag/consume'),
            static fn(): Request => Request::fake(uri: '/tag/consume'),
            new RuntimeCapabilities('test'),
        );
        $reference = null;
        $response = $kernel->handleRuntime($context, static function (Response $current) use ($container, &$reference): void {
            $request = $container->get(Request::class);
            self::assertInstanceOf(Request::class, $request);
            self::assertSame('/tag/consume', $request->getUri()->getPath());
            self::assertSame('/tag/consume', $current->getHeaderLine('X-Tagged-pre'));
            self::assertInstanceOf(ScopedTaggedMiddlewareFixture::class, $container->get('tagged.pre'));
            $reference = WeakReference::create($request);
        });
        self::assertSame(200, $response->getStatusCode());
        self::assertInstanceOf(WeakReference::class, $reference);
        unset($context);
        gc_collect_cycles();
        self::assertNull($reference->get());
    }

    /**
     * @return array{CompiledRouterKernel,ProductionContainer,RuntimeDispatcher,CompiledRouterArtifact}
     * @throws \InvalidArgumentException
     * @throws \LogicException
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     */
    private function fixture(LifetimeEnum $lifetime, bool $registerTags = true, bool $declareRequest = true, bool $runwire = false): array
    {
        $directory = sys_get_temp_dir() . '/webrick-tagged-scope-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        $this->directories[] = $directory;
        $builder = ContainerBuilder::create('webrick.tagged.' . bin2hex(random_bytes(6)));
        if ($declareRequest) {
            $builder->input(Request::class);
        }
        if ($runwire) {
            RunwireInterMixScopeBridge::registerInputs($builder);
        }
        if ($registerTags) {
            foreach (['pre', 'post'] as $tag) {
                $builder->autowire(
                    'tagged.' . $tag,
                    ScopedTaggedMiddlewareFixture::class,
                    arguments: ['label' => $tag],
                    lifetime: $lifetime,
                    tags: [$tag],
                );
            }
        }
        $build = new RouteCompiler()->compile(
            register: static function (Registrar $routes): void {
                $routes->get('/tag/{id}', [ScopedTaggedMiddlewareFixture::class, 'reply'], [
                    'middleware' => [static fn(Request $request, Closure $next): Response => ScopedTaggedMiddlewareFixture::wrap($request, $next, 'route')],
                ]);
            },
            environment: 'test',
            configFingerprint: 'tagged-scope',
            preGlobal: [static fn(Request $request, Closure $next): Response => ScopedTaggedMiddlewareFixture::wrap($request, $next, 'explicit-pre')],
            postGlobal: [static fn(Request $request, Closure $next): Response => ScopedTaggedMiddlewareFixture::wrap($request, $next, 'explicit-post')],
            preGlobalTags: ['pre'],
            postGlobalTags: ['post'],
        );
        $builder->compile($directory . '/intermix.php', strict: true);
        $container = $builder->production($directory . '/intermix.php');
        $path = $directory . '/router.php';
        new RouterArtifactCompiler()->compile($build, $path);
        $artifact = new RouterArtifactLoader()->load($path, 'test', 'tagged-scope');
        $kernel = CompiledRouterKernel::fromCompiledArtifact(
            new NullLogger(), FusedMatcher::make(), $container, $path, 'test', 'tagged-scope',
        );

        return [$kernel, $container, new RuntimeDispatcher(new InterMixRuntime($container), $artifact), $artifact];
    }
}
