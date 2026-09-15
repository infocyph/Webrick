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
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\Runtime\RuntimeEnvironment;
use Infocyph\Runwire\Runtime\RuntimeSelector;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\Runwire\RuntimeOptions;
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

final class InfbyteEndToEndScopedMarker
{
    public readonly string $id;

    public function __construct()
    {
        $this->id = bin2hex(random_bytes(6));
    }
}

final class InfbyteEndToEndBody implements RequestBodyInterface
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

final class InfbyteEndToEndWriter implements ResponseWriterInterface
{
    /** @var list<string> */
    public array $chunks = [];

    public int $endCalls = 0;

    public Headers $headers;

    private bool $ended = false;

    private bool $started = false;

    public int $status = 0;

    public function __construct()
    {
        $this->headers = new Headers();
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
        $this->status = $status;
        $this->headers = $headers ?? new Headers();
        $this->started = true;

        return new WriteResult(WriteState::ACCEPTED, 0);
    }

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
final class InfbyteEndToEndAcceptanceTest extends TestCase
{
    public function testInfbyteShapedRoutesAndRequestScopesRemainCleanOnOnePersistentApplication(): void
    {
        [$application, $paths] = self::applicationFixture();

        try {
            $application->start();

            $health = self::dispatch($application, '/api/health');
            self::assertSame(200, $health->status);
            self::assertSame(['status' => 'ok'], json_decode($health->body(), true, flags: JSON_THROW_ON_ERROR));
            self::assertSame(1, $health->endCalls);

            $json = self::dispatch($application, '/json');
            self::assertSame(200, $json->status);
            $jsonPayload = json_decode($json->body(), true, flags: JSON_THROW_ON_ERROR);
            self::assertArrayHasKey('memory', $jsonPayload);
            self::assertIsInt($jsonPayload['memory']);
            self::assertSame(1, $json->endCalls);

            $scopeA = self::dispatch($application, '/api/runtime-scope');
            $scopeB = self::dispatch($application, '/api/runtime-scope');
            $payloadA = json_decode($scopeA->body(), true, flags: JSON_THROW_ON_ERROR);
            $payloadB = json_decode($scopeB->body(), true, flags: JSON_THROW_ON_ERROR);

            self::assertIsString($payloadA['scope'] ?? null);
            self::assertIsString($payloadB['scope'] ?? null);
            self::assertNotSame($payloadA['scope'], $payloadB['scope']);
            self::assertSame(1, $scopeA->endCalls);
            self::assertSame(1, $scopeB->endCalls);
            self::assertSame(4, $application->snapshot()->requestsTotal);
            self::assertSame(0, $application->snapshot()->requestsActive);
        } finally {
            $application->shutdown();
            self::cleanup($paths);
        }
    }

    /** @return array{\Infocyph\Runwire\Runtime\RuntimeApplicationInterface,list<string>} */
    private static function applicationFixture(): array
    {
        [$intermixPath, $routerPath] = self::artifactPaths();
        $fingerprint = 'infbyte-end-to-end';
        $builder = ContainerBuilder::create('infbyte_e2e_' . bin2hex(random_bytes(4)));
        $builder->scoped(InfbyteEndToEndScopedMarker::class);
        $build = new RouteCompiler()->compile(
            register: static function (Registrar $registrar): void {
                $registrar->get('/api/health', static fn(): array => ['status' => 'ok']);
                $registrar->get('/json', static fn(): array => ['memory' => memory_get_usage(true)]);
                $registrar->get(
                    '/api/runtime-scope',
                    static fn(InfbyteEndToEndScopedMarker $marker): array => ['scope' => $marker->id],
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
            $factory = new RunwireRuntimeApplicationFactory(
                handler: static function (HttpRequest $request, ResponseWriterInterface $writer) use ($server): void {
                    $server->handle($request, $writer);
                },
                requestCleanup: static fn() => $runtime->resetCurrentExecutionScope(),
            );
            $selection = new RuntimeSelector()->select(
                new RuntimeOptions(driver: RuntimeDriver::NATIVE),
                new RuntimeEnvironment(sapi: 'cli', availableDrivers: [RuntimeDriver::NATIVE]),
            );
            $application = $factory->create(RuntimeContext::fromCapabilities($selection->capabilities, 'infbyte'));
        } catch (Throwable $error) {
            self::cleanup([$intermixPath, $routerPath]);
            throw $error;
        }

        return [$application, [$intermixPath, $routerPath]];
    }

    private static function dispatch(
        \Infocyph\Runwire\Runtime\RuntimeApplicationInterface $application,
        string $target,
    ): InfbyteEndToEndWriter {
        $writer = new InfbyteEndToEndWriter();
        $application->handle(
            new HttpRequest(
                method: 'GET',
                target: $target,
                version: ProtocolVersion::HTTP_1_1,
                headers: Headers::fromArray(['Host' => 'infbyte.test']),
                body: new InfbyteEndToEndBody(),
            ),
            $writer,
            completeResponse: true,
        );

        return $writer;
    }

    /** @return array{0:string,1:string} */
    private static function artifactPaths(): array
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'infbyte-e2e-' . bin2hex(random_bytes(8));

        return [$base . '-intermix.php', $base . '-router.php'];
    }

    /** @param list<string> $paths */
    private static function cleanup(array $paths): void
    {
        foreach ($paths as $path) {
            foreach ([$path, $path . '.meta.json'] as $candidate) {
                if (is_file($candidate) && !unlink($candidate)) {
                    throw new RuntimeException("Unable to remove Infbyte E2E fixture: {$candidate}");
                }
            }
        }
    }
}
