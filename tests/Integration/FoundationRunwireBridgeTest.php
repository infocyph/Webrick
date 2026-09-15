<?php

declare(strict_types=1);

namespace Tests\Integration;

use Closure;
use Fiber;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\ProductionContainer;
use Infocyph\InterMix\DI\ScopeContext;
use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Coroutine\TaskLocal;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\RequestBodyInterface;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Network\Enum\WriteState;
use Infocyph\Runwire\Network\WriteResult;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\Runtime\RuntimeEnvironment;
use Infocyph\Runwire\Runtime\RuntimeSelector;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\Runwire\RuntimeOptions;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Build\RouteCompiler;
use Infocyph\Webrick\Router\Build\RouterArtifactCompiler;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Kernel\CompiledRouterKernel;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Infocyph\Webrick\Runtime\Http\RunwireRuntimeAdapter;
use Infocyph\Webrick\Runtime\Http\RunwireRuntimeApplicationFactory;
use Infocyph\Webrick\Runtime\Http\RuntimeRequestContext;
use Infocyph\Webrick\Runtime\Http\RuntimeServer;
use Infocyph\Webrick\Runtime\InterMixRuntime;
use Opis\Closure\CodeStream;
use PHPUnit\Framework\Attributes\BackupStaticProperties;
use PHPUnit\Framework\Attributes\ExcludeStaticPropertyFromBackup;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

final readonly class FoundationRunwireScopedMarker
{
    public function __construct(public string $id = '') {}
}

final class FoundationRunwireProbe
{
    public static int $cleanupCalls = 0;

    public static ?ProductionContainer $container = null;

    public static ?InterMixRuntime $runtime = null;

    public static int $scopeLeaves = 0;

    public static function currentMarker(): FoundationRunwireScopedMarker
    {
        $container = self::$container ?? throw new RuntimeException('Foundation bridge container is unavailable.');
        $marker = $container->get(FoundationRunwireScopedMarker::class);
        if (!$marker instanceof FoundationRunwireScopedMarker) {
            throw new RuntimeException('Expected Foundation bridge scoped marker.');
        }

        return $marker;
    }

    public static function reset(): void
    {
        self::$cleanupCalls = 0;
        self::$container = null;
        self::$runtime = null;
        self::$scopeLeaves = 0;
    }

    public static function structuredChildShares(FoundationRunwireScopedMarker $parent): bool
    {
        $runtime = self::$runtime ?? throw new RuntimeException('Foundation bridge InterMix runtime is unavailable.');
        $scopeContext = $runtime->captureScopeContext();
        $scopeLocal = new TaskLocal();

        $child = new CoroutineRuntime()->run(
            static function (CoroutineScope $scope) use ($runtime, $scopeContext, $scopeLocal): FoundationRunwireScopedMarker {
                $scope->setLocal($scopeLocal, $scopeContext);
                $task = $scope->spawn(
                    static function () use ($runtime, $scope, $scopeLocal): FoundationRunwireScopedMarker {
                        $captured = $scope->local($scopeLocal);
                        if (!$captured instanceof ScopeContext) {
                            throw new RuntimeException('Foundation structured child did not inherit ScopeContext.');
                        }

                        return $runtime->withinScopeContext(
                            $captured,
                            static fn(ProductionContainer $container): FoundationRunwireScopedMarker => $container->get(
                                FoundationRunwireScopedMarker::class,
                            ),
                        );
                    },
                );

                return $task->await();
            },
        );

        return $child === $parent;
    }
}

final class FoundationRunwireBody implements RequestBodyInterface
{
    public function bufferedBytes(): int
    {
        return 0;
    }

    public function eof(): bool
    {
        return true;
    }

    public function onData(callable $callback): RequestBodyInterface
    {
        unset($callback);

        return $this;
    }

    public function onEnd(callable $callback): RequestBodyInterface
    {
        Closure::fromCallable($callback)($this);

        return $this;
    }

    public function read(int $maxBytes = PHP_INT_MAX): string
    {
        unset($maxBytes);

        return '';
    }

    public function receivedBytes(): int
    {
        return 0;
    }

    public function trailers(): ?Headers
    {
        return new Headers();
    }
}

final class FoundationRunwireWriter implements ResponseWriterInterface
{
    /** @var list<string> */
    public array $chunks = [];

    public int $endCalls = 0;

    private bool $ended = false;

    private bool $started = false;

    public function end(string $finalChunk = ''): WriteResult
    {
        if ($finalChunk !== '') {
            $this->chunks[] = $finalChunk;
        }
        ++$this->endCalls;
        $this->ended = true;

        return new WriteResult(WriteState::ACCEPTED, strlen($finalChunk));
    }

    public function isEnded(): bool
    {
        return $this->ended;
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function onDrain(callable $callback): ResponseWriterInterface
    {
        unset($callback);

        return $this;
    }

    public function start(int $status = 200, ?Headers $headers = null): WriteResult
    {
        unset($status, $headers);
        $this->started = true;

        return new WriteResult(WriteState::ACCEPTED, 0);
    }

    public function write(string $chunk): WriteResult
    {
        $this->chunks[] = $chunk;

        return new WriteResult(WriteState::ACCEPTED, strlen($chunk));
    }
}

#[BackupStaticProperties(true)]
#[ExcludeStaticPropertyFromBackup(CodeStream::class, 'isRegistered')]
#[ExcludeStaticPropertyFromBackup(CodeStream::class, 'handlers')]
final class FoundationRunwireBridgeTest extends TestCase
{
    protected function tearDown(): void
    {
        FoundationRunwireProbe::reset();
    }

    public function testFoundationPolicyComposesConcurrentRequestsStructuredChildrenAndCleanup(): void
    {
        [$factory, $context, $paths] = self::fixture();
        $application = $factory->create($context);
        $writerA = new FoundationRunwireWriter();
        $writerB = new FoundationRunwireWriter();
        $requestA = self::request('/foundation/fiber/a');
        $requestB = self::request('/foundation/fiber/b');

        try {
            $application->start();
            $fiberA = new Fiber(static function () use ($application, $requestA, $writerA): void {
                $application->handle($requestA, $writerA, completeResponse: true);
            });
            $fiberB = new Fiber(static function () use ($application, $requestB, $writerB): void {
                $application->handle($requestB, $writerB, completeResponse: true);
            });

            $markerA = $fiberA->start();
            $markerB = $fiberB->start();

            self::assertIsString($markerA);
            self::assertIsString($markerB);
            self::assertNotSame($markerA, $markerB);
            self::assertSame(2, $application->snapshot()->requestsActive);

            $fiberB->resume();
            $fiberA->resume();

            self::assertTrue($fiberA->isTerminated());
            self::assertTrue($fiberB->isTerminated());
            self::assertSame([$markerA . ':same:/foundation/fiber/a'], $writerA->chunks);
            self::assertSame([$markerB . ':same:/foundation/fiber/b'], $writerB->chunks);
            self::assertSame(1, $writerA->endCalls);
            self::assertSame(1, $writerB->endCalls);
            self::assertSame(2, FoundationRunwireProbe::$scopeLeaves);
            self::assertSame(2, FoundationRunwireProbe::$cleanupCalls);
            self::assertSame(0, $application->snapshot()->requestsActive);

            $structuredWriter = new FoundationRunwireWriter();
            $application->handle(self::request('/foundation/structured'), $structuredWriter, completeResponse: true);

            self::assertSame(['same'], $structuredWriter->chunks);
            self::assertSame(3, FoundationRunwireProbe::$scopeLeaves);
            self::assertSame(3, FoundationRunwireProbe::$cleanupCalls);
            self::assertSame(3, $application->snapshot()->requestsTotal);
        } finally {
            $application->shutdown();
            self::cleanup($paths);
        }
    }

    /** @return array{RunwireRuntimeApplicationFactory,RuntimeContext,list<string>} */
    private static function fixture(): array
    {
        [$intermixPath, $routerPath] = self::artifactPaths();
        $fingerprint = 'foundation-runwire-bridge';
        $builder = ContainerBuilder::create('foundation_runwire_' . bin2hex(random_bytes(4)));
        $builder->scoped(
            FoundationRunwireScopedMarker::class,
            static fn(): FoundationRunwireScopedMarker => new FoundationRunwireScopedMarker(bin2hex(random_bytes(6))),
        );
        $builder->onScopeLeave(
            RuntimeRequestContext::REQUEST_SCOPE,
            static function (string $scope): void {
                if ($scope === RuntimeRequestContext::REQUEST_SCOPE) {
                    ++FoundationRunwireProbe::$scopeLeaves;
                }
            },
        );
        $build = new RouteCompiler()->compile(
            register: static function (Registrar $registrar): void {
                $registrar->get(
                    '/foundation/fiber/{id}',
                    static function (Request $request, FoundationRunwireScopedMarker $marker): Response {
                        $first = $marker->id;
                        Fiber::suspend($first);
                        $same = FoundationRunwireProbe::currentMarker() === $marker ? 'same' : 'different';

                        return Response::plaintext($first . ':' . $same . ':' . $request->getUri()->getPath());
                    },
                );
                $registrar->get(
                    '/foundation/structured',
                    static fn(FoundationRunwireScopedMarker $marker): Response => Response::plaintext(
                        FoundationRunwireProbe::structuredChildShares($marker) ? 'same' : 'different',
                    ),
                );
            },
            environment: 'production',
            configFingerprint: $fingerprint,
        );

        try {
            $builder->compile($intermixPath);
            $container = $builder->production($intermixPath);
            $runtime = new InterMixRuntime($container);
            FoundationRunwireProbe::$container = $container;
            FoundationRunwireProbe::$runtime = $runtime;
            new RouterArtifactCompiler()->compile($build, $routerPath);
            $kernel = CompiledRouterKernel::fromCompiledArtifact(
                log: new NullLogger(),
                matcher: FusedMatcher::make(),
                container: $container,
                artifactPath: $routerPath,
                environment: 'production',
                configFingerprint: $fingerprint,
            );
            $server = new RuntimeServer($kernel, new RunwireRuntimeAdapter());
            $factory = new RunwireRuntimeApplicationFactory(
                handler: static function (HttpRequest $request, ResponseWriterInterface $writer) use ($server): void {
                    $server->handle($request, $writer);
                },
                requestCleanup: static function () use ($runtime): void {
                    ++FoundationRunwireProbe::$cleanupCalls;
                    $runtime->resetCurrentExecutionScope();
                },
            );
            $selection = new RuntimeSelector()->select(
                new RuntimeOptions(driver: RuntimeDriver::NATIVE),
                new RuntimeEnvironment(sapi: 'cli', availableDrivers: [RuntimeDriver::NATIVE]),
            );
            $context = RuntimeContext::fromCapabilities($selection->capabilities, 'foundation');
        } catch (Throwable $error) {
            self::cleanup([$intermixPath, $routerPath]);
            throw $error;
        }

        return [$factory, $context, [$intermixPath, $routerPath]];
    }

    private static function request(string $target): HttpRequest
    {
        return new HttpRequest(
            method: 'GET',
            target: $target,
            version: ProtocolVersion::HTTP_2,
            headers: Headers::fromArray(['Host' => 'foundation.test']),
            body: new FoundationRunwireBody(),
        );
    }

    /** @return array{0:string,1:string} */
    private static function artifactPaths(): array
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'foundation-runwire-' . bin2hex(random_bytes(8));

        return [$base . '-intermix.php', $base . '-router.php'];
    }

    /** @param list<string> $paths */
    private static function cleanup(array $paths): void
    {
        foreach ($paths as $path) {
            foreach ([$path, $path . '.meta.json'] as $candidate) {
                if (is_file($candidate) && !unlink($candidate)) {
                    throw new RuntimeException("Unable to remove Foundation bridge fixture: {$candidate}");
                }
            }
        }
    }
}
