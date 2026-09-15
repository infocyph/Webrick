<?php

declare(strict_types=1);

namespace Tests\Integration;

use Closure;
use Fiber;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\Runwire\Exception\RuntimeUnavailableException;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\Http3\Quic\PhpQuicApi;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\RequestBodyInterface;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Network\Enum\WriteState;
use Infocyph\Runwire\Network\WriteResult;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
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
use Infocyph\Webrick\Runtime\Http\RuntimeServer;
use Opis\Closure\CodeStream;
use PHPUnit\Framework\Attributes\BackupStaticProperties;
use PHPUnit\Framework\Attributes\ExcludeStaticPropertyFromBackup;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

final class RunwireMultiplexedBodyFixture implements RequestBodyInterface
{
    private int $offset = 0;

    public int $readCalls = 0;

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
        Closure::fromCallable($callback)($this);

        return $this;
    }

    public function read(int $maxBytes = PHP_INT_MAX): string
    {
        ++$this->readCalls;
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
        return new Headers();
    }
}

final class RunwireMultiplexedWriterFixture implements ResponseWriterInterface
{
    /** @var list<string> */
    public array $chunks = [];

    public int $endCalls = 0;

    public Headers $headers;

    public int $startCalls = 0;

    public int $status = 0;

    private bool $ended = false;

    private bool $started = false;

    public function __construct()
    {
        $this->headers = new Headers();
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
        Closure::fromCallable($callback);

        return $this;
    }

    public function start(int $status = 200, ?Headers $headers = null): WriteResult
    {
        if ($this->ended) {
            return new WriteResult(WriteState::CLOSED, 0);
        }
        if ($this->started) {
            return new WriteResult(WriteState::ACCEPTED, 0);
        }

        ++$this->startCalls;
        $this->headers = $headers ?? new Headers();
        $this->started = true;
        $this->status = $status;

        return new WriteResult(WriteState::ACCEPTED, 0);
    }

    public function write(string $chunk): WriteResult
    {
        if ($this->ended) {
            return new WriteResult(WriteState::CLOSED, 0);
        }

        $this->chunks[] = $chunk;

        return new WriteResult(WriteState::ACCEPTED, strlen($chunk));
    }
}

#[BackupStaticProperties(true)]
#[ExcludeStaticPropertyFromBackup(CodeStream::class, 'isRegistered')]
#[ExcludeStaticPropertyFromBackup(CodeStream::class, 'handlers')]
final class RunwireMultiplexedProtocolIsolationTest extends TestCase
{
    public function testHttp2InterleavedRequestsRemainIsolatedWhenOneStreamIsCancelled(): void
    {
        [$application, $paths] = self::applicationFixture();

        try {
            $application->start();
            [$firstRequest, $firstBody] = self::request(ProtocolVersion::HTTP_2, 'h2-a');
            [$secondRequest, $secondBody] = self::request(ProtocolVersion::HTTP_2, 'h2-b');
            $firstWriter = new RunwireMultiplexedWriterFixture();
            $secondWriter = new RunwireMultiplexedWriterFixture();
            $first = self::fiber($application, $firstRequest, $firstWriter);
            $second = self::fiber($application, $secondRequest, $secondWriter);

            self::assertSame('paused:h2-a', $first->start());
            self::assertSame('paused:h2-b', $second->start());
            self::assertSame(2, $application->snapshot()->requestsActive);
            self::assertSame(1, $firstBody->readCalls);
            self::assertSame(1, $secondBody->readCalls);

            self::assertTrue($firstRequest->context->cancel(CancellationReason::TRANSPORT_CANCELLED));
            self::assertTrue($firstRequest->context->cancelled());
            self::assertFalse($secondRequest->context->cancelled());

            $second->resume();
            self::assertTrue($second->isTerminated());
            self::assertSame(1, $application->snapshot()->requestsActive);
            self::assertCompleted($secondWriter, 'h2-b', '2');

            try {
                $first->resume();
                self::fail('Cancelled HTTP/2 stream unexpectedly completed.');
            } catch (RuntimeException $error) {
                self::assertSame(
                    'Runwire request was cancelled during Webrick response production.',
                    $error->getMessage(),
                );
            }

            self::assertTrue($first->isTerminated());
            self::assertSame(1, $firstWriter->startCalls);
            self::assertSame(0, $firstWriter->endCalls);
            self::assertSame([self::chunk('begin', 'h2-a', '2')], $firstWriter->chunks);
            self::assertTrue($firstRequest->context->completed());
            self::assertTrue($secondRequest->context->completed());
            self::assertSame(2, $application->snapshot()->requestsTotal);
            self::assertSame(0, $application->snapshot()->requestsActive);
        } finally {
            $application->shutdown();
            self::cleanup($paths);
        }
    }

    public function testHttp3InterleavedStreamsRemainIsolatedAndQuicAbsenceIsNonFatal(): void
    {
        [$application, $paths] = self::applicationFixture();

        try {
            $application->start();
            [$firstRequest, $firstBody] = self::request(ProtocolVersion::HTTP_3, 'h3-a');
            [$secondRequest, $secondBody] = self::request(ProtocolVersion::HTTP_3, 'h3-b');
            $firstWriter = new RunwireMultiplexedWriterFixture();
            $secondWriter = new RunwireMultiplexedWriterFixture();
            $first = self::fiber($application, $firstRequest, $firstWriter);
            $second = self::fiber($application, $secondRequest, $secondWriter);

            self::assertSame('paused:h3-a', $first->start());
            self::assertSame('paused:h3-b', $second->start());
            self::assertSame(2, $application->snapshot()->requestsActive);
            self::assertSame(1, $firstBody->readCalls);
            self::assertSame(1, $secondBody->readCalls);

            $second->resume();
            self::assertTrue($second->isTerminated());
            self::assertSame(1, $application->snapshot()->requestsActive);
            self::assertCompleted($secondWriter, 'h3-b', '3');

            $first->resume();
            self::assertTrue($first->isTerminated());
            self::assertCompleted($firstWriter, 'h3-a', '3');
            self::assertSame(2, $application->snapshot()->requestsTotal);
            self::assertSame(0, $application->snapshot()->requestsActive);

            self::assertQuicCapabilityIsPortable();
        } finally {
            $application->shutdown();
            self::cleanup($paths);
        }
    }

    /** @return array{RunwireRuntimeApplication,list<string>} */
    private static function applicationFixture(): array
    {
        [$intermixPath, $routerPath] = self::artifactPaths();
        $fingerprint = 'runwire-multiplexed-isolation';
        $builder = ContainerBuilder::create('webrick_runwire_mux_' . bin2hex(random_bytes(4)));
        $build = new RouteCompiler()->compile(
            register: static function (Registrar $registrar): void {
                $registrar->post('/mux/{label}', static function (Request $request, string $label): Response {
                    $payload = json_encode(self::payload($request, $label), JSON_THROW_ON_ERROR);

                    return Response::stream(
                        static function () use ($label, $payload): iterable {
                            yield 'begin:' . $payload;
                            Fiber::suspend('paused:' . $label);
                            yield 'end:' . $payload;
                        },
                        status: 207,
                        headers: [
                            'Connection' => 'keep-alive',
                            'Set-Cookie' => ["stream={$label}; Path=/", "protocol={$request->getProtocolVersion()}; Path=/"],
                            'X-Stream' => $label,
                        ],
                    );
                });
            },
            environment: 'production',
            configFingerprint: $fingerprint,
        );

        try {
            $builder->compile($intermixPath);
            $container = $builder->production($intermixPath);
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

    private static function assertCompleted(
        RunwireMultiplexedWriterFixture $writer,
        string $label,
        string $protocol,
    ): void {
        self::assertSame(207, $writer->status);
        self::assertSame(1, $writer->startCalls);
        self::assertSame(1, $writer->endCalls);
        self::assertTrue($writer->isStarted());
        self::assertTrue($writer->isEnded());
        self::assertSame([
            self::chunk('begin', $label, $protocol),
            self::chunk('end', $label, $protocol),
        ], $writer->chunks);
        self::assertSame($label, $writer->headers->first('x-stream'));
        self::assertNull($writer->headers->first('connection'));
        self::assertSame([
            "stream={$label}; Path=/",
            "protocol={$protocol}; Path=/",
        ], $writer->headers->all('set-cookie'));
    }

    private static function assertQuicCapabilityIsPortable(): void
    {
        if (PhpQuicApi::available()) {
            PhpQuicApi::assertAvailable();
            self::assertTrue(extension_loaded('quic'));

            return;
        }

        self::assertFalse(PhpQuicApi::available());
        try {
            PhpQuicApi::assertAvailable();
            self::fail('Unavailable QUIC runtime unexpectedly passed its capability assertion.');
        } catch (RuntimeUnavailableException $error) {
            self::assertStringContainsString('HTTP/3 requires ext-quic', $error->getMessage());
        }
    }

    /** @return array{0:string,1:string} */
    private static function artifactPaths(): array
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webrick-runwire-mux-' . bin2hex(random_bytes(8));

        return [$base . '-intermix.php', $base . '-router.php'];
    }

    /** @param list<string> $paths */
    private static function cleanup(array $paths): void
    {
        foreach ($paths as $path) {
            foreach ([$path, $path . '.meta.json'] as $candidate) {
                if (is_file($candidate) && !unlink($candidate)) {
                    throw new RuntimeException("Unable to remove Runwire multiplexed fixture: {$candidate}");
                }
            }
        }
    }

    private static function chunk(string $phase, string $label, string $protocol): string
    {
        return $phase . ':' . json_encode(self::expectedPayload($label, $protocol), JSON_THROW_ON_ERROR);
    }

    /** @return array<string,string|list<string>> */
    private static function expectedPayload(string $label, string $protocol): array
    {
        return [
            'label' => $label,
            'method' => 'POST',
            'query' => 'value-' . $label,
            'cookie' => $label . ' cookie',
            'repeat' => [$label . '-a', $label . '-b'],
            'body' => 'body-' . $label,
            'protocol' => $protocol,
            'scheme' => 'https',
            'host' => $label . '.example.test',
            'remote' => self::remoteAddress($label),
            'local' => '10.0.0.1',
        ];
    }

    /** @return array<string,string|list<string>> */
    private static function payload(Request $request, string $label): array
    {
        return [
            'label' => $label,
            'method' => $request->getMethod(),
            'query' => (string) $request->query('q'),
            'cookie' => (string) $request->cookie('sid'),
            'repeat' => $request->getHeader('X-Repeat'),
            'body' => (string) $request->getBody(),
            'protocol' => $request->getProtocolVersion(),
            'scheme' => $request->getUri()->getScheme(),
            'host' => $request->getUri()->getHost(),
            'remote' => (string) $request->server('REMOTE_ADDR'),
            'local' => (string) $request->server('SERVER_ADDR'),
        ];
    }

    private static function remoteAddress(string $label): string
    {
        return '127.0.0.' . (str_ends_with($label, '-a') ? '11' : '12');
    }

    /** @return array{HttpRequest,RunwireMultiplexedBodyFixture} */
    private static function request(ProtocolVersion $version, string $label): array
    {
        $body = new RunwireMultiplexedBodyFixture('body-' . $label);
        $request = new HttpRequest(
            method: 'POST',
            target: '/mux/' . $label . '?q=value-' . $label,
            version: $version,
            headers: Headers::fromArray([
                'Host' => $label . '.example.test',
                'Content-Type' => 'text/plain',
                'Cookie' => 'sid=' . $label . '%20cookie',
                'X-Repeat' => [$label . '-a', $label . '-b'],
            ]),
            body: $body,
            peerAddress: self::remoteAddress($label) . ':51000',
            localAddress: '10.0.0.1:8443',
            encrypted: true,
        );

        return [$request, $body];
    }

    private static function fiber(
        RunwireRuntimeApplication $application,
        HttpRequest $request,
        RunwireMultiplexedWriterFixture $writer,
    ): Fiber {
        return new Fiber(static function () use ($application, $request, $writer): void {
            $application->handle($request, $writer, completeResponse: true);
        });
    }
}
