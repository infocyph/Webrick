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

final class RunwireDeferredRequestBody implements RequestBodyInterface
{
    /** @var Closure(RequestBodyInterface): void|null */
    private ?Closure $dataCallback = null;

    /** @var Closure(RequestBodyInterface): void|null */
    private ?Closure $endCallback = null;

    private bool $ended = false;

    private string $buffer = '';

    private int $received = 0;

    #[\Override]
    public function bufferedBytes(): int
    {
        return strlen($this->buffer);
    }

    #[\Override]
    public function eof(): bool
    {
        return $this->ended && $this->buffer === '';
    }

    public function finish(): void
    {
        if ($this->ended) {
            return;
        }

        $this->ended = true;
        $this->endCallback?->__invoke($this);
    }

    #[\Override]
    public function onData(callable $callback): RequestBodyInterface
    {
        $this->dataCallback = Closure::fromCallable($callback);
        if ($this->buffer !== '') {
            $this->dataCallback->__invoke($this);
        }

        return $this;
    }

    #[\Override]
    public function onEnd(callable $callback): RequestBodyInterface
    {
        $this->endCallback = Closure::fromCallable($callback);
        if ($this->ended) {
            $this->endCallback->__invoke($this);
        }

        return $this;
    }

    /**
     * @throws \RuntimeException
     */
    public function push(string $chunk): void
    {
        if ($this->ended) {
            throw new RuntimeException('Cannot push request body data after end.');
        }
        if ($chunk === '') {
            return;
        }

        $this->buffer .= $chunk;
        $this->received += strlen($chunk);
        $this->dataCallback?->__invoke($this);
    }

    /**
     * @throws \InvalidArgumentException
     */
    #[\Override]
    public function read(int $maxBytes = PHP_INT_MAX): string
    {
        if ($maxBytes < 0) {
            throw new \InvalidArgumentException('Maximum body read length cannot be negative.');
        }
        if ($maxBytes === 0 || $this->buffer === '') {
            return '';
        }

        $chunk = substr($this->buffer, 0, $maxBytes);
        $this->buffer = (string) substr($this->buffer, strlen($chunk));

        return $chunk;
    }

    #[\Override]
    public function receivedBytes(): int
    {
        return $this->received;
    }

    #[\Override]
    public function trailers(): ?Headers
    {
        return $this->ended ? new Headers() : null;
    }
}

final class RunwireDeferredWriter implements ResponseWriterInterface
{
    use \Infocyph\Webrick\Tests\Fixture\RunwireTerminalWriterTrait;

    /** @var list<string> */
    public array $chunks = [];

    public int $endCalls = 0;

    public int $startCalls = 0;

    public int $status = 0;


    public function startCount(): int
    {
        return $this->startCalls;
    }

    public function endCount(): int
    {
        return $this->endCalls;
    }

    private bool $ended = false;

    private bool $started = false;

    #[\Override]
    public function end(string $finalChunk = ''): WriteResult
    {
        if ($finalChunk !== '') {
            $this->chunks[] = $finalChunk;
        }
        ++$this->endCalls;
        $this->ended = true;
        $this->notifyTerminal();

        return new WriteResult(WriteState::ACCEPTED, strlen($finalChunk));
    }

    #[\Override]
    public function isEnded(): bool
    {
        return $this->ended;
    }

    #[\Override]
    public function isStarted(): bool
    {
        return $this->started;
    }

    #[\Override]
    public function onDrain(callable $callback): ResponseWriterInterface
    {
        unset($callback);

        return $this;
    }

    #[\Override]
    public function start(int $status = 200, ?Headers $headers = null): WriteResult
    {
        unset($headers);
        ++$this->startCalls;
        $this->started = true;
        $this->status = $status;

        return new WriteResult(WriteState::ACCEPTED, 0);
    }

    #[\Override]
    public function write(string $chunk): WriteResult
    {
        $this->chunks[] = $chunk;

        return new WriteResult(WriteState::ACCEPTED, strlen($chunk));
    }

    public function body(): string
    {
        return implode('', $this->chunks);
    }
}

#[BackupStaticProperties(true)]
#[ExcludeStaticPropertyFromBackup(CodeStream::class, 'isRegistered')]
#[ExcludeStaticPropertyFromBackup(CodeStream::class, 'handlers')]
final class RunwireRequestBodyReadinessTest extends TestCase
{
    /**
     * @throws \Infocyph\Runwire\Exception\ApplicationStartupException
     * @throws \Infocyph\Runwire\Exception\CancelledException
     * @throws \LogicException
     * @throws \OverflowException
     * @throws \Throwable
     */
    public function testCancellationWakesARequestWaitingForBodyDataWithoutStartingAResponse(): void
    {
        [$application, $paths] = self::applicationFixture();
        $body = new RunwireDeferredRequestBody();
        $request = self::request('/body/json', 'application/json', $body);
        $writer = new RunwireDeferredWriter();

        try {
            $application->start();
            $application->handle($request, $writer, completeResponse: true);

            self::assertSame(1, $application->snapshot()->requestsActive);
            self::assertSame(0, $writer->startCount());

            $request->context->cancel(CancellationReason::TRANSPORT_CANCELLED);

            self::assertTrue($request->context->completed());
            self::assertSame(CancellationReason::TRANSPORT_CANCELLED, $request->context->cancellation->reason());
            self::assertSame(0, $application->snapshot()->requestsActive);
            self::assertSame(0, $writer->startCount());
            self::assertSame(0, $writer->endCount());
        } finally {
            $application->shutdown();
            self::cleanup($paths);
        }
    }

    /**
     * @throws \Infocyph\Runwire\Exception\ApplicationStartupException
     * @throws \Infocyph\Runwire\Exception\CancelledException
     * @throws \LogicException
     * @throws \OverflowException
     * @throws \RuntimeException
     * @throws \Throwable
     */
    public function testDeferredJsonBodyResumesIncrementallyAndPreservesRawPayload(): void
    {
        [$application, $paths] = self::applicationFixture();
        $body = new RunwireDeferredRequestBody();
        $request = self::request('/body/json', 'application/json', $body);
        $writer = new RunwireDeferredWriter();

        try {
            $application->start();
            $application->handle($request, $writer, completeResponse: true);

            self::assertSame(1, $application->snapshot()->requestsActive);
            self::assertSame(0, $writer->startCount());

            $body->push('{"name":"Ha');
            self::assertSame(1, $application->snapshot()->requestsActive);
            self::assertSame(0, $writer->startCount());

            $body->push('san"}');
            self::assertSame(1, $application->snapshot()->requestsActive);
            self::assertSame(0, $writer->startCount());

            $body->finish();

            self::assertTrue($request->context->completed());
            self::assertSame(0, $application->snapshot()->requestsActive);
            self::assertSame(1, $writer->startCount());
            self::assertSame(1, $writer->endCount());
            self::assertSame(200, $writer->status);
            self::assertSame(
                ['name' => 'Hasan', 'raw' => '{"name":"Hasan"}'],
                json_decode($writer->body(), true, flags: JSON_THROW_ON_ERROR),
            );
        } finally {
            $application->shutdown();
            self::cleanup($paths);
        }
    }

    /**
     * @throws \Infocyph\Runwire\Exception\ApplicationStartupException
     * @throws \Infocyph\Runwire\Exception\CancelledException
     * @throws \LogicException
     * @throws \OverflowException
     * @throws \RuntimeException
     * @throws \Throwable
     */
    public function testDeferredUrlencodedBodyPopulatesParsedFormData(): void
    {
        [$application, $paths] = self::applicationFixture();
        $body = new RunwireDeferredRequestBody();
        $request = self::request('/body/form', 'application/x-www-form-urlencoded', $body);
        $writer = new RunwireDeferredWriter();

        try {
            $application->start();
            $application->handle($request, $writer, completeResponse: true);

            self::assertSame(1, $application->snapshot()->requestsActive);
            $body->push('name=Hasan&meta%5Blevel%5D=');
            self::assertSame(1, $application->snapshot()->requestsActive);
            $body->push('senior');
            $body->finish();

            self::assertSame(0, $application->snapshot()->requestsActive);
            self::assertSame(1, $writer->endCount());
            self::assertSame(
                ['name' => 'Hasan', 'meta' => ['level' => 'senior']],
                json_decode($writer->body(), true, flags: JSON_THROW_ON_ERROR),
            );
        } finally {
            $application->shutdown();
            self::cleanup($paths);
        }
    }

    /**
     * @return array{RunwireRuntimeApplication,list<string>}
     * @throws \InvalidArgumentException
     * @throws \Throwable
     */
    private static function applicationFixture(): array
    {
        [$intermixPath, $routerPath] = self::artifactPaths();
        $fingerprint = 'runwire-request-body-readiness';
        $builder = ContainerBuilder::create('runwire_request_body_' . bin2hex(random_bytes(4)));
        $build = new RouteCompiler()->compile(
            register: static function (Registrar $registrar): void {
                $registrar->post(
                    '/body/form',
                    static fn(Request $request): Response => Response::json([
                        'name' => $request->post('name'),
                        'meta' => $request->post('meta'),
                    ]),
                );
                $registrar->post(
                    '/body/json',
                    static fn(Request $request): Response => Response::json([
                        'name' => $request->parsedJson('name'),
                        'raw' => $request->raw(),
                    ]),
                );
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

    /** @return array{0:string,1:string} */
    private static function artifactPaths(): array
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'runwire-request-body-' . bin2hex(random_bytes(8));

        return [$base . '-intermix.php', $base . '-router.php'];
    }

    /**
     * @param list<string> $paths
     * @throws \RuntimeException
     */
    private static function cleanup(array $paths): void
    {
        foreach ($paths as $path) {
            foreach ([$path, $path . '.meta.json'] as $candidate) {
                if (is_file($candidate) && !unlink($candidate)) {
                    throw new RuntimeException("Unable to remove Runwire request-body fixture: {$candidate}");
                }
            }
        }
    }

    /**
     * @throws \InvalidArgumentException
     * @throws \OverflowException
     */
    private static function request(
        string $target,
        string $contentType,
        RunwireDeferredRequestBody $body,
    ): HttpRequest {
        return new HttpRequest(
            method: 'POST',
            target: $target,
            version: ProtocolVersion::HTTP_1_1,
            headers: Headers::fromArray([
                'Host' => 'example.test',
                'Content-Type' => $contentType,
            ]),
            body: $body,
        );
    }
}
