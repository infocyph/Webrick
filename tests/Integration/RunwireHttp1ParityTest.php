<?php

declare(strict_types=1);

namespace Tests\Integration;

use Closure;
use Infocyph\InterMix\DI\ContainerBuilder;
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
use Infocyph\Webrick\Router\Kernel\ErrorHandler;
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

final class RunwireHttp1BodyFixture implements RequestBodyInterface
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

final class RunwireHttp1WriterFixture implements ResponseWriterInterface
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

    public function onDrain(callable $_callback): ResponseWriterInterface
    {
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
final class RunwireHttp1ParityTest extends TestCase
{
    public function testHttp11ParityThroughTheFullRunwireApplicationBridge(): void
    {
        [$application, $paths] = self::applicationFixture();

        try {
            $application->start();

            $body = new RunwireHttp1BodyFixture('payload');
            $echoWriter = new RunwireHttp1WriterFixture();
            $application->handle(
                self::request(
                    method: 'POST',
                    target: '/h1/echo/42?view=full',
                    body: $body,
                    headers: [
                        'Host' => 'example.test',
                        'Content-Type' => 'text/plain',
                        'Cookie' => 'sid=a%20b; theme=dark',
                        'X-Repeat' => ['a', 'b'],
                    ],
                    peerAddress: '127.0.0.1:51000',
                    localAddress: '10.0.0.1:8080',
                ),
                $echoWriter,
                completeResponse: true,
            );

            $expectedEcho = json_encode([
                'method' => 'POST',
                'id' => '42',
                'view' => 'full',
                'sid' => 'a b',
                'repeat' => ['a', 'b'],
                'body' => 'payload',
                'protocol' => '1.1',
                'host' => 'example.test',
                'remote' => '127.0.0.1',
                'local' => '10.0.0.1',
            ], JSON_THROW_ON_ERROR);

            self::assertCompleted($echoWriter, 201);
            self::assertSame([$expectedEcho], $echoWriter->chunks);
            self::assertSame('application/json', $echoWriter->headers->first('content-type'));
            self::assertSame('keep-alive', $echoWriter->headers->first('connection'));
            self::assertSame(['a=1; Path=/', 'b=2; Path=/'], $echoWriter->headers->all('set-cookie'));
            self::assertSame((string) strlen($expectedEcho), $echoWriter->headers->first('content-length'));
            self::assertGreaterThan(0, $body->readCalls);

            $headWriter = self::dispatch($application, self::request(method: 'HEAD', target: '/h1/head'));
            self::assertCompleted($headWriter, 200);
            self::assertSame([], $headWriter->chunks);
            self::assertSame('6', $headWriter->headers->first('content-length'));
            self::assertSame('yes', $headWriter->headers->first('x-head'));

            $redirectWriter = self::dispatch($application, self::request(target: '/h1/redirect'));
            self::assertCompleted($redirectWriter, 302);
            self::assertSame('/target', $redirectWriter->headers->first('location'));

            $missingWriter = self::dispatch($application, self::request(target: '/h1/missing'));
            self::assertCompleted($missingWriter, 404);
            self::assertSame('no-store', $missingWriter->headers->first('cache-control'));

            $methodWriter = self::dispatch($application, self::request(method: 'POST', target: '/h1/head'));
            self::assertCompleted($methodWriter, 405);
            self::assertStringContainsString('GET', (string) $methodWriter->headers->first('allow'));

            $errorWriter = self::dispatch($application, self::request(target: '/h1/error'));
            self::assertCompleted($errorWriter, 500);
            self::assertSame(['error:500'], $errorWriter->chunks);

            $streamWriter = self::dispatch($application, self::request(target: '/h1/stream'));
            self::assertCompleted($streamWriter, 202);
            self::assertSame(['a', 'b', 'c'], $streamWriter->chunks);
            self::assertSame('yes', $streamWriter->headers->first('x-stream'));

            self::assertSame(7, $application->snapshot()->requestsTotal);
            self::assertSame(0, $application->snapshot()->requestsActive);
        } finally {
            $application->shutdown();
            self::cleanup($paths);
        }
    }

    /** @return array{RunwireRuntimeApplication,list<string>} */
    private static function applicationFixture(): array
    {
        [$intermixPath, $routerPath] = self::artifactPaths();
        $fingerprint = 'runwire-http1-parity';
        $builder = ContainerBuilder::create('webrick_runwire_h1_' . bin2hex(random_bytes(4)));
        $build = new RouteCompiler()->compile(
            register: static function (Registrar $registrar): void {
                $registrar->post('/h1/echo/{id}', static function (Request $request, string $id): Response {
                    $payload = json_encode([
                        'method' => $request->getMethod(),
                        'id' => $id,
                        'view' => $request->query('view'),
                        'sid' => $request->cookie('sid'),
                        'repeat' => $request->getHeader('X-Repeat'),
                        'body' => (string) $request->getBody(),
                        'protocol' => $request->getProtocolVersion(),
                        'host' => $request->getUri()->getHost(),
                        'remote' => $request->server('REMOTE_ADDR'),
                        'local' => $request->server('SERVER_ADDR'),
                    ], JSON_THROW_ON_ERROR);

                    return Response::create($payload, 201, [
                        'Content-Type' => 'application/json',
                        'Connection' => 'keep-alive',
                        'Set-Cookie' => ['a=1; Path=/', 'b=2; Path=/'],
                    ]);
                });
                $registrar->get('/h1/head', static fn(): Response => Response::create('hidden', headers: ['X-Head' => 'yes']));
                $registrar->get('/h1/redirect', static fn(): Response => Response::create('', 302, ['Location' => '/target']));
                $registrar->get('/h1/error', static function (): Response {
                    throw new RuntimeException('h1 parity failure fixture');
                });
                $registrar->get(
                    '/h1/stream',
                    static fn(): Response => Response::stream(
                        static fn(): iterable => ['a', 'b', 'c'],
                        status: 202,
                        headers: ['X-Stream' => 'yes'],
                    ),
                );
            },
            environment: 'production',
            configFingerprint: $fingerprint,
        );
        $errorHandler = new ErrorHandler(
            logger: new NullLogger(),
            responseRenderer: static fn(
                Request $_request,
                Throwable $_error,
                int $status,
                array $headers,
            ): Response => Response::create('error:' . $status, $status, $headers),
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
                errorHandler: $errorHandler,
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

    private static function assertCompleted(RunwireHttp1WriterFixture $writer, int $status): void
    {
        self::assertSame($status, $writer->status);
        self::assertSame(1, $writer->startCalls);
        self::assertSame(1, $writer->endCalls);
        self::assertTrue($writer->isStarted());
        self::assertTrue($writer->isEnded());
    }

    /** @return array{0:string,1:string} */
    private static function artifactPaths(): array
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webrick-runwire-h1-' . bin2hex(random_bytes(8));

        return [$base . '-intermix.php', $base . '-router.php'];
    }

    /** @param list<string> $paths */
    private static function cleanup(array $paths): void
    {
        foreach ($paths as $path) {
            foreach ([$path, $path . '.meta.json'] as $candidate) {
                if (is_file($candidate) && !unlink($candidate)) {
                    throw new RuntimeException("Unable to remove Runwire HTTP/1.1 parity fixture: {$candidate}");
                }
            }
        }
    }

    /**
     * @param array<string,string|list<string>>|null $headers
     */
    private static function request(
        string $method = 'GET',
        string $target = '/',
        ?RunwireHttp1BodyFixture $body = null,
        ?array $headers = null,
        ?string $peerAddress = null,
        ?string $localAddress = null,
    ): HttpRequest {
        return new HttpRequest(
            method: $method,
            target: $target,
            version: ProtocolVersion::HTTP_1_1,
            headers: Headers::fromArray($headers ?? ['Host' => 'example.test']),
            body: $body ?? new RunwireHttp1BodyFixture(''),
            peerAddress: $peerAddress,
            localAddress: $localAddress,
        );
    }

    private static function dispatch(
        RunwireRuntimeApplication $application,
        HttpRequest $request,
    ): RunwireHttp1WriterFixture {
        $writer = new RunwireHttp1WriterFixture();
        $application->handle($request, $writer, completeResponse: true);

        return $writer;
    }
}
