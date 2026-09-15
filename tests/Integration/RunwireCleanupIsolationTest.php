<?php

declare(strict_types=1);

namespace Tests\Integration;

use Closure;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\Exceptions\ContainerException;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\RequestBodyInterface;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Network\Enum\WriteState;
use Infocyph\Runwire\Network\WriteResult;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\Runtime\RequestExecutionPolicy;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Build\RouteCompiler;
use Infocyph\Webrick\Router\Build\RouterArtifactCompiler;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Kernel\CompiledRouterKernel;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Infocyph\Webrick\Runtime\Http\RunwireRuntimeAdapter;
use Infocyph\Webrick\Runtime\Http\RunwireRuntimeApplication;
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

final class RunwireCleanupScopedMarker {}

final class RunwireCleanupProbe
{
    public static int $cleanupCalls = 0;

    public static int $scopeLeaves = 0;

    public static function reset(): void
    {
        self::$cleanupCalls = 0;
        self::$scopeLeaves = 0;
    }
}

final class RunwireCleanupBodyFixture implements RequestBodyInterface
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

final class RunwireCleanupWriterFixture implements ResponseWriterInterface
{
    /** @var list<string> */
    public array $chunks = [];

    public int $endCalls = 0;

    public int $startCalls = 0;

    private bool $ended = false;

    private bool $started = false;

    /** @var Closure(): void|null */
    private ?Closure $afterFirstWrite;

    /** @param callable(): void|null $afterFirstWrite */
    public function __construct(?callable $afterFirstWrite = null)
    {
        $this->afterFirstWrite = $afterFirstWrite === null ? null : Closure::fromCallable($afterFirstWrite);
    }

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
        ++$this->startCalls;
        $this->started = true;

        return new WriteResult(WriteState::ACCEPTED, 0);
    }

    public function write(string $chunk): WriteResult
    {
        $this->chunks[] = $chunk;
        $afterFirstWrite = $this->afterFirstWrite;
        $this->afterFirstWrite = null;
        $afterFirstWrite?->__invoke();

        return new WriteResult(WriteState::ACCEPTED, strlen($chunk));
    }
}

#[BackupStaticProperties(true)]
#[ExcludeStaticPropertyFromBackup(CodeStream::class, 'isRegistered')]
#[ExcludeStaticPropertyFromBackup(CodeStream::class, 'handlers')]
final class RunwireCleanupIsolationTest extends TestCase
{
    protected function tearDown(): void
    {
        RunwireCleanupProbe::reset();
    }

    public function testCleanupAndCarrierResetAreExactlyOnceAcrossRequestOutcomes(): void
    {
        [$application, $container, $paths] = self::applicationFixture();

        try {
            $success = self::request('/cleanup/success');
            $successWriter = new RunwireCleanupWriterFixture();
            $application->handle($success, $successWriter, completeResponse: true);
            self::assertSame(1, RunwireCleanupProbe::$scopeLeaves);
            self::assertSame(1, RunwireCleanupProbe::$cleanupCalls);
            self::assertTrue($success->context->completed());
            self::assertSame(1, $successWriter->endCalls);
            self::assertCarrierClean($container);

            $error = self::request('/cleanup/error');
            $errorWriter = new RunwireCleanupWriterFixture();
            $application->handle($error, $errorWriter, completeResponse: true);
            self::assertSame(2, RunwireCleanupProbe::$scopeLeaves);
            self::assertSame(2, RunwireCleanupProbe::$cleanupCalls);
            self::assertTrue($error->context->completed());
            self::assertSame(1, $errorWriter->endCalls);
            self::assertCarrierClean($container);

            $cancelled = self::request('/cleanup/stream');
            $cancelWriter = new RunwireCleanupWriterFixture(
                static fn() => $cancelled->context->cancel(CancellationReason::TRANSPORT_CANCELLED),
            );
            try {
                $application->handle($cancelled, $cancelWriter, completeResponse: true);
                self::fail('Transport cancellation must stop Webrick response production.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('cancelled', strtolower($error->getMessage()));
            }
            self::assertSame(3, RunwireCleanupProbe::$scopeLeaves);
            self::assertSame(3, RunwireCleanupProbe::$cleanupCalls);
            self::assertTrue($cancelled->context->completed());
            self::assertSame(CancellationReason::TRANSPORT_CANCELLED, $cancelled->context->cancellation->reason());
            self::assertSame(0, $cancelWriter->endCalls);
            self::assertCarrierClean($container);

            $deadline = self::request('/cleanup/deadline');
            $deadlineWriter = new RunwireCleanupWriterFixture();
            try {
                $application->handle($deadline, $deadlineWriter, completeResponse: true);
                self::fail('Expired request deadline must stop Webrick response production.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('cancelled', strtolower($error->getMessage()));
            }
            self::assertSame(4, RunwireCleanupProbe::$scopeLeaves);
            self::assertSame(4, RunwireCleanupProbe::$cleanupCalls);
            self::assertTrue($deadline->context->completed());
            self::assertSame(CancellationReason::DEADLINE_EXCEEDED, $deadline->context->cancellation->reason());
            self::assertSame(0, $deadlineWriter->endCalls);
            self::assertCarrierClean($container);

            $next = self::request('/cleanup/success');
            $application->handle($next, new RunwireCleanupWriterFixture(), completeResponse: true);
            self::assertSame(5, RunwireCleanupProbe::$scopeLeaves);
            self::assertSame(5, RunwireCleanupProbe::$cleanupCalls);
            self::assertTrue($next->context->completed());
            self::assertCarrierClean($container);
            self::assertSame(0, $application->snapshot()->requestsActive);
        } finally {
            $application->shutdown();
            self::cleanup($paths);
        }
    }

    /** @return array{RunwireRuntimeApplication,\Infocyph\InterMix\DI\ProductionContainer,list<string>} */
    private static function applicationFixture(): array
    {
        [$intermixPath, $routerPath] = self::artifactPaths();
        $fingerprint = 'runwire-cleanup-isolation';
        $builder = ContainerBuilder::create('webrick_runwire_cleanup_' . bin2hex(random_bytes(4)));
        $builder->scoped(RunwireCleanupScopedMarker::class);
        $builder->onScopeLeave(
            RuntimeRequestContext::REQUEST_SCOPE,
            static function (string $scope): void {
                if ($scope === RuntimeRequestContext::REQUEST_SCOPE) {
                    ++RunwireCleanupProbe::$scopeLeaves;
                }
            },
        );
        $build = new RouteCompiler()->compile(
            register: static function (Registrar $registrar): void {
                $registrar->get(
                    '/cleanup/success',
                    static fn(RunwireCleanupScopedMarker $marker): Response => Response::create((string) spl_object_id($marker)),
                );
                $registrar->get('/cleanup/error', static function (RunwireCleanupScopedMarker $marker): Response {
                    unset($marker);
                    throw new RuntimeException('cleanup error fixture');
                });
                $registrar->get(
                    '/cleanup/stream',
                    static fn(RunwireCleanupScopedMarker $marker): Response => Response::stream(
                        static function () use ($marker): iterable {
                            yield 'first:' . spl_object_id($marker);
                            yield 'second';
                        },
                    ),
                );
                $registrar->get(
                    '/cleanup/deadline',
                    static fn(RunwireCleanupScopedMarker $marker): Response => Response::stream(
                        static function () use ($marker): iterable {
                            yield 'first:' . spl_object_id($marker);
                            usleep(80_000);
                            yield 'second';
                        },
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
            $application = new RunwireRuntimeApplication(
                static function (HttpRequest $request, ResponseWriterInterface $writer) use ($server): void {
                    $server->handle($request, $writer);
                },
                RuntimeContext::standalone(),
                requestCleanup: static function () use ($runtime): void {
                    ++RunwireCleanupProbe::$cleanupCalls;
                    $runtime->resetCurrentExecutionScope();
                },
                requestExecution: new RequestExecutionPolicy(maxExecutionSeconds: 0.05),
            );
        } catch (Throwable $error) {
            self::cleanup([$intermixPath, $routerPath]);
            throw $error;
        }

        return [$application, $container, [$intermixPath, $routerPath]];
    }

    private static function assertCarrierClean(\Infocyph\InterMix\DI\ProductionContainer $container): void
    {
        try {
            $container->captureScopeContext();
            self::fail('No InterMix scope may survive request cleanup on the current execution carrier.');
        } catch (ContainerException) {
            self::assertTrue(true);
        }
    }

    /** @return array{0:string,1:string} */
    private static function artifactPaths(): array
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webrick-runwire-cleanup-' . bin2hex(random_bytes(8));

        return [$base . '-intermix.php', $base . '-router.php'];
    }

    /** @param list<string> $paths */
    private static function cleanup(array $paths): void
    {
        foreach ($paths as $path) {
            foreach ([$path, $path . '.meta.json'] as $candidate) {
                if (is_file($candidate) && !unlink($candidate)) {
                    throw new RuntimeException("Unable to remove Runwire cleanup fixture: {$candidate}");
                }
            }
        }
    }

    private static function request(string $target): HttpRequest
    {
        return new HttpRequest(
            method: 'GET',
            target: $target,
            version: ProtocolVersion::HTTP_1_1,
            headers: Headers::fromArray(['Host' => 'example.test']),
            body: new RunwireCleanupBodyFixture(),
        );
    }
}