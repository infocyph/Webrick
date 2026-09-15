<?php

declare(strict_types=1);

namespace Tests\Integration;

use Closure;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\ProductionContainer;
use Infocyph\InterMix\Exceptions\ContainerException;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\RequestBodyInterface;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Network\Enum\WriteState;
use Infocyph\Runwire\Network\WriteResult;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\Webrick\Request\Request;
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
use Opis\Closure\CodeStream;
use PHPUnit\Framework\Attributes\BackupStaticProperties;
use PHPUnit\Framework\Attributes\ExcludeStaticPropertyFromBackup;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

final class RunwireStreamingScopedMarker {}

final class RunwireStreamingScopeProbe
{
    public static ?ProductionContainer $container = null;

    public static int $producerAdvances = 0;

    public static int $scopeLeaves = 0;

    public static function marker(): RunwireStreamingScopedMarker
    {
        $container = self::$container ?? throw new RuntimeException('Streaming scope probe container is unavailable.');
        $marker = $container->get(RunwireStreamingScopedMarker::class);
        if (!$marker instanceof RunwireStreamingScopedMarker) {
            throw new RuntimeException('Expected the request-scoped streaming marker.');
        }

        return $marker;
    }

    public static function requestPath(): string
    {
        $container = self::$container ?? throw new RuntimeException('Streaming scope probe container is unavailable.');
        $request = $container->get(Request::class);
        if (!$request instanceof Request) {
            throw new RuntimeException('Expected the request seed to remain available during body production.');
        }

        return $request->getUri()->getPath();
    }

    public static function scopeContextIsActive(): bool
    {
        $container = self::$container ?? throw new RuntimeException('Streaming scope probe container is unavailable.');

        try {
            $container->captureScopeContext();

            return true;
        } catch (ContainerException) {
            return false;
        }
    }

    public static function reset(): void
    {
        self::$container = null;
        self::$producerAdvances = 0;
        self::$scopeLeaves = 0;
    }
}

final class RunwireStreamingBodyFixture implements RequestBodyInterface
{
    public int $readCalls = 0;

    /** @var list<int> */
    public array $requestedBytes = [];

    private int $offset = 0;

    public function __construct(private readonly string $body) {}

    public function bufferedBytes(): int
    {
        return strlen($this->body) - $this->offset;
    }

    public function eof(): bool
    {
        return $this->offset >= strlen($this->body);
    }

    public function onData(callable $callback): RequestBodyInterface
    {
        if (!$this->eof()) {
            Closure::fromCallable($callback)($this);
        }

        return $this;
    }

    public function onEnd(callable $callback): RequestBodyInterface
    {
        if ($this->eof()) {
            Closure::fromCallable($callback)($this);
        }

        return $this;
    }

    public function read(int $maxBytes = PHP_INT_MAX): string
    {
        ++$this->readCalls;
        $this->requestedBytes[] = $maxBytes;
        if ($maxBytes < 0) {
            throw new \InvalidArgumentException('Maximum body read length cannot be negative.');
        }
        if ($maxBytes === 0 || $this->eof()) {
            return '';
        }

        $chunk = substr($this->body, $this->offset, $maxBytes);
        $this->offset += strlen($chunk);

        return $chunk;
    }

    public function receivedBytes(): int
    {
        return strlen($this->body);
    }

    public function trailers(): ?Headers
    {
        return $this->eof() ? new Headers() : null;
    }
}

final class RunwirePressureWriterFixture implements ResponseWriterInterface
{
    /** @var list<string> */
    public array $chunks = [];

    public int $endCalls = 0;

    public int $startCalls = 0;

    public int $writeCalls = 0;

    private ?Closure $drainCallback = null;

    private bool $ended = false;

    private bool $started = false;

    public function __construct(
        private readonly ?int $pressureOnWriteCall = null,
        private readonly bool $pressureOnEnd = false,
    ) {}

    public function drain(): void
    {
        $callback = $this->drainCallback ?? throw new RuntimeException('No Runwire drain callback is pending.');
        $this->drainCallback = null;
        $callback($this);
    }

    public function end(string $finalChunk = ''): WriteResult
    {
        if ($this->ended) {
            return new WriteResult(WriteState::CLOSED, 0);
        }
        if ($finalChunk !== '') {
            $this->chunks[] = $finalChunk;
        }

        ++$this->endCalls;
        $this->ended = true;

        return new WriteResult(
            $this->pressureOnEnd ? WriteState::PRESSURED : WriteState::ACCEPTED,
            strlen($finalChunk),
        );
    }

    public function hasPendingDrain(): bool
    {
        return $this->drainCallback instanceof Closure;
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
        $this->drainCallback = Closure::fromCallable($callback);

        return $this;
    }

    public function start(int $status = 200, ?Headers $headers = null): WriteResult
    {
        unset($status, $headers);
        if ($this->ended) {
            return new WriteResult(WriteState::CLOSED, 0);
        }

        ++$this->startCalls;
        $this->started = true;

        return new WriteResult(WriteState::ACCEPTED, 0);
    }

    public function write(string $chunk): WriteResult
    {
        if ($this->ended) {
            return new WriteResult(WriteState::CLOSED, 0);
        }

        ++$this->writeCalls;
        $this->chunks[] = $chunk;
        $state = $this->pressureOnWriteCall === $this->writeCalls
            ? WriteState::PRESSURED
            : WriteState::ACCEPTED;

        return new WriteResult($state, strlen($chunk));
    }
}

#[BackupStaticProperties(true)]
#[ExcludeStaticPropertyFromBackup(CodeStream::class, 'isRegistered')]
#[ExcludeStaticPropertyFromBackup(CodeStream::class, 'handlers')]
final class RunwireStreamingLifecycleTest extends TestCase
{
    protected function tearDown(): void
    {
        RunwireStreamingScopeProbe::reset();
    }

    public function testPressureStopsProducerUntilDrainWhileRequestScopeAndLifecycleRemainActive(): void
    {
        [$application, $paths] = self::applicationFixture();
        $writer = new RunwirePressureWriterFixture(pressureOnWriteCall: 1);
        $request = self::request('/runtime/scoped-stream');

        try {
            $application->handle($request, $writer, completeResponse: true);

            self::assertSame(1, $writer->writeCalls);
            self::assertCount(1, $writer->chunks);
            self::assertSame(1, RunwireStreamingScopeProbe::$producerAdvances);
            self::assertSame(0, RunwireStreamingScopeProbe::$scopeLeaves);
            self::assertSame(0, $writer->endCalls);
            self::assertTrue($writer->hasPendingDrain());
            self::assertSame(1, $application->snapshot()->requestsActive);
            self::assertFalse($request->context->completed());

            $writer->drain();

            self::assertSame(2, $writer->writeCalls);
            self::assertCount(2, $writer->chunks);
            self::assertStringContainsString('/runtime/scoped-stream', $writer->chunks[0]);
            self::assertSame('second:same', $writer->chunks[1]);
            self::assertSame(2, RunwireStreamingScopeProbe::$producerAdvances);
            self::assertSame(1, RunwireStreamingScopeProbe::$scopeLeaves);
            self::assertSame(1, $writer->endCalls);
            self::assertTrue($writer->isEnded());
            self::assertFalse($writer->hasPendingDrain());
            self::assertSame(0, $application->snapshot()->requestsActive);
            self::assertTrue($request->context->completed());
        } finally {
            $application->shutdown();
            self::cleanup($paths);
        }
    }

    public function testZeroScopeStreamingRouteDoesNotOpenOrCaptureIntermixScope(): void
    {
        [$application, $paths] = self::applicationFixture();
        $writer = new RunwirePressureWriterFixture(pressureOnEnd: true);

        try {
            $application->handle(self::request('/runtime/zero-stream'), $writer, completeResponse: true);

            self::assertSame(['no-scope'], $writer->chunks);
            self::assertSame(0, RunwireStreamingScopeProbe::$scopeLeaves);
            self::assertSame(1, $writer->endCalls);
            self::assertTrue($writer->isEnded());
            self::assertFalse($writer->hasPendingDrain());
        } finally {
            $application->shutdown();
            self::cleanup($paths);
        }
    }

    public function testRequestBodyRemainsIncrementalThroughTheRunwireRuntimeBridge(): void
    {
        [$application, $paths] = self::applicationFixture();
        $body = new RunwireStreamingBodyFixture(str_repeat('x', 131_072));
        $writer = new RunwirePressureWriterFixture();

        try {
            $application->handle(self::request('/runtime/body-prefix', $body), $writer, completeResponse: true);

            self::assertSame(['xxx'], $writer->chunks);
            self::assertSame(1, $body->readCalls);
            self::assertSame([3], $body->requestedBytes);
            self::assertSame(131_069, $body->bufferedBytes());
            self::assertSame(1, $writer->endCalls);
        } finally {
            $application->shutdown();
            self::cleanup($paths);
        }
    }

    /** @return array{RunwireRuntimeApplication,list<string>} */
    private static function applicationFixture(): array
    {
        [$intermixPath, $routerPath] = self::artifactPaths();
        $fingerprint = 'runwire-streaming-lifecycle';
        $builder = ContainerBuilder::create('webrick_runwire_stream_' . bin2hex(random_bytes(4)));
        $builder->scoped(RunwireStreamingScopedMarker::class);
        $builder->onScopeLeave(
            RuntimeRequestContext::REQUEST_SCOPE,
            static function (string $scope): void {
                if ($scope === RuntimeRequestContext::REQUEST_SCOPE) {
                    ++RunwireStreamingScopeProbe::$scopeLeaves;
                }
            },
        );

        $build = new RouteCompiler()->compile(
            register: static function (Registrar $registrar): void {
                $registrar->get(
                    '/runtime/scoped-stream',
                    static function (RunwireStreamingScopedMarker $marker): Response {
                        $identity = spl_object_id($marker);

                        return Response::stream(static function () use ($identity, $marker): iterable {
                            ++RunwireStreamingScopeProbe::$producerAdvances;
                            yield 'first:' . $identity . ':' . RunwireStreamingScopeProbe::requestPath();

                            ++RunwireStreamingScopeProbe::$producerAdvances;
                            yield 'second:' . (RunwireStreamingScopeProbe::marker() === $marker ? 'same' : 'different');
                        });
                    },
                );
                $registrar->get(
                    '/runtime/zero-stream',
                    static fn(): Response => Response::stream(
                        static function (): iterable {
                            yield RunwireStreamingScopeProbe::scopeContextIsActive() ? 'scope' : 'no-scope';
                        },
                    ),
                );
                $registrar->post(
                    '/runtime/body-prefix',
                    static function (Request $request): Response {
                        return Response::create($request->getBody()->read(3));
                    },
                );
            },
            environment: 'production',
            configFingerprint: $fingerprint,
            preGlobalTags: [],
            postGlobalTags: [],
        );

        try {
            $builder->compile($intermixPath);
            $container = $builder->production($intermixPath);
            RunwireStreamingScopeProbe::$container = $container;
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
            );
        } catch (Throwable $error) {
            self::cleanup([$intermixPath, $routerPath]);
            throw $error;
        }

        return [$application, [$intermixPath, $routerPath]];
    }

    /** @return array{0:string,1:string} */
    private static function artifactPaths(): array
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webrick-runwire-stream-' . bin2hex(random_bytes(8));

        return [$base . '-intermix.php', $base . '-router.php'];
    }

    /** @param list<string> $paths */
    private static function cleanup(array $paths): void
    {
        RunwireStreamingScopeProbe::$container = null;
        foreach ($paths as $path) {
            foreach ([$path, $path . '.meta.json'] as $candidate) {
                if (is_file($candidate) && !unlink($candidate)) {
                    throw new RuntimeException("Unable to remove Runwire streaming lifecycle fixture: {$candidate}");
                }
            }
        }
    }

    private static function request(string $target, ?RunwireStreamingBodyFixture $body = null): HttpRequest
    {
        return new HttpRequest(
            method: $body instanceof RunwireStreamingBodyFixture ? 'POST' : 'GET',
            target: $target,
            version: ProtocolVersion::HTTP_1_1,
            headers: Headers::fromArray(['Host' => 'example.test']),
            body: $body ?? new RunwireStreamingBodyFixture(''),
        );
    }
}
