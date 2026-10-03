<?php

declare(strict_types=1);

use Fiber;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\RuntimeContainerInterface;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\Integration\Runwire\RunwireIntegration;
use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\RequestBodyInterface;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Network\Enum\WriteState;
use Infocyph\Runwire\Network\WriteResult;
use Infocyph\Runwire\RequestContext as RunwireRequestContext;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\RuntimeCapabilities as RunwireCapabilities;
use Infocyph\Runwire\RuntimeContext as RunwireContext;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Runtime\Http\RunwireInterMixScopeBridge;
use Infocyph\Webrick\Runtime\Http\RunwireRuntimeAdapter;
use Infocyph\Webrick\Runtime\InterMixRuntime;
use LogicException;

final readonly class RunwireBridgeScopedMarker
{
    public function __construct(public string $id) {}
}

final class RunwireBridgeScopeProbe
{
    public static int $runwireLeaves = 0;

    public static function reset(): void
    {
        self::$runwireLeaves = 0;
    }

    public static function scopeLeft(string $scope, RuntimeContainerInterface $container): void
    {
        unset($container);
        if ($scope === 'runwire.request') {
            ++self::$runwireLeaves;
        }
    }
}

final class RunwireBridgeBodyFixture implements RequestBodyInterface
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
        $callback($this);

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

final class RunwireBridgeWriterFixture implements ResponseWriterInterface
{
    use \Infocyph\Webrick\Tests\Fixture\RunwireTerminalWriterTrait;

    private bool $ended = false;

    private bool $started = false;

    public function end(string $finalChunk = ''): WriteResult
    {
        unset($finalChunk);
        if ($this->ended) {
            return new WriteResult(WriteState::CLOSED, 0);
        }

        $this->ended = true;
        $this->notifyTerminal();

        return new WriteResult(WriteState::ACCEPTED, 0);
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
        return new WriteResult(WriteState::ACCEPTED, strlen($chunk));
    }
}

function runwire_bridge_builder(): ContainerBuilder
{
    $builder = ContainerBuilder::create('webrick.runwire.bridge.' . bin2hex(random_bytes(6)));
    RunwireInterMixScopeBridge::registerInputs($builder);
    $builder
        ->input(Request::class)
        ->factory(
            RunwireBridgeScopedMarker::class,
            static fn(): RunwireBridgeScopedMarker => new RunwireBridgeScopedMarker(bin2hex(random_bytes(6))),
            LifetimeEnum::Scoped,
        )
        ->onScopeLeave('runwire.request', [RunwireBridgeScopeProbe::class, 'scopeLeft']);

    return $builder;
}

function runwire_bridge_request(RunwireRequestContext $context): HttpRequest
{
    return new HttpRequest(
        method: 'GET',
        target: '/bridge',
        version: ProtocolVersion::HTTP_1_1,
        headers: Headers::fromArray(['Host' => 'example.test']),
        body: new RunwireBridgeBodyFixture(),
        context: $context,
    );
}

beforeEach(static function (): void {
    RunwireBridgeScopeProbe::reset();
});

test('runwire bridge opens one InterMix request scope and preserves exact runtime identities', function (): void {
    $builder = runwire_bridge_builder();
    $container = $builder->build();
    $runtime = RunwireContext::standalone();
    $requestContext = RunwireRequestContext::create($runtime);
    $integration = new RunwireIntegration($container);
    $integration->bind($runtime);
    $adapter = new RunwireRuntimeAdapter(runtimeContext: $runtime, interMix: $integration);
    $context = $adapter->context(
        runwire_bridge_request($requestContext),
        new RunwireBridgeWriterFixture(),
    );
    $request = Request::fake(uri: '/bridge');
    $interMix = new InterMixRuntime($container);

    expect($context->scopeBridge)->toBeInstanceOf(RunwireInterMixScopeBridge::class);

    $resolved = $context->scopeBridge->withinScope(
        $interMix,
        $request,
        static fn(): array => [
            $container->get(RunwireContext::class),
            $container->get(RunwireRequestContext::class),
            $container->get(Request::class),
            $container->get(RunwireBridgeScopedMarker::class),
        ],
    );

    expect($resolved[0])->toBe($runtime)
        ->and($resolved[1])->toBe($requestContext)
        ->and($resolved[2])->toBe($request)
        ->and($resolved[3])->toBeInstanceOf(RunwireBridgeScopedMarker::class)
        ->and(RunwireBridgeScopeProbe::$runwireLeaves)->toBe(1);

    $integration->release($runtime);
});

test('runwire bridge attaches an exact borrowed InterMix scope without opening a second request scope', function (): void {
    $builder = runwire_bridge_builder();
    $container = $builder->build();
    $runtime = RunwireContext::standalone();
    $requestContext = RunwireRequestContext::create($runtime);
    $integration = new RunwireIntegration($container);
    $integration->bind($runtime);
    $request = Request::fake(uri: '/borrowed');
    $interMix = new InterMixRuntime($container);

    $sameMarker = $container->withinScope(
        'host.request',
        static function (RuntimeContainerInterface $active) use (
            $runtime,
            $requestContext,
            $integration,
            $request,
            $interMix,
        ): bool {
            $parent = $active->get(RunwireBridgeScopedMarker::class);
            $scopeContext = $active->captureScopeContext();
            $fiber = new Fiber(
                static function () use (
                    $runtime,
                    $requestContext,
                    $integration,
                    $scopeContext,
                    $request,
                    $interMix,
                    $parent,
                ): bool {
                    $adapter = new RunwireRuntimeAdapter(
                        runtimeContext: $runtime,
                        interMix: $integration,
                        scopeContext: static fn(HttpRequest $native): \Infocyph\InterMix\DI\ScopeContext => $scopeContext,
                    );
                    $context = $adapter->context(
                        runwire_bridge_request($requestContext),
                        new RunwireBridgeWriterFixture(),
                    );

                    return $context->scopeBridge->withinScope(
                        $interMix,
                        $request,
                        static fn(): bool => $interMix->get(RunwireBridgeScopedMarker::class) === $parent,
                    );
                },
            );
            $fiber->start();

            return $fiber->getReturn();
        },
        [
            RunwireContext::class => $runtime,
            RunwireRequestContext::class => $requestContext,
            Request::class => $request,
        ],
    );

    expect($sameMarker)->toBeTrue()
        ->and(RunwireBridgeScopeProbe::$runwireLeaves)->toBe(0);

    $integration->release($runtime);
});

test('runwire bridge rejects completed requests and conflicting runtime bindings', function (): void {
    $builder = runwire_bridge_builder();
    $container = $builder->build();
    $runtime = RunwireContext::standalone();
    $other = RunwireContext::standalone();
    $integration = new RunwireIntegration($container);
    $integration->bind($runtime);

    expect(fn() => new RunwireRuntimeAdapter(runtimeContext: $other, interMix: $integration))
        ->toThrow(LogicException::class, 'different runtimes');

    $requestContext = RunwireRequestContext::create($runtime);
    $adapter = new RunwireRuntimeAdapter(runtimeContext: $runtime, interMix: $integration);
    $context = $adapter->context(
        runwire_bridge_request($requestContext),
        new RunwireBridgeWriterFixture(),
    );
    $requestContext->complete();

    expect(fn() => $context->scopeBridge->withinScope(
        new InterMixRuntime($container),
        Request::fake(uri: '/completed'),
        static fn(): null => null,
    ))->toThrow(LogicException::class, 'Completed Runwire request context');

    $integration->release($runtime);
});

test('runwire bridge exposes the exact host coroutine scope when capability is available', function (): void {
    $runtime = RunwireContext::fromCapabilities(
        new RunwireCapabilities(
            RuntimeDriver::NATIVE,
            supportsRunwireCoroutines: true,
        ),
        'webrick-coroutine',
        concurrent: true,
    );
    $builder = runwire_bridge_builder();
    $container = $builder->build();
    $integration = new RunwireIntegration($container);
    $integration->bind($runtime);
    $requestContext = RunwireRequestContext::create($runtime);
    $interMix = new InterMixRuntime($container);

    $sameScope = new CoroutineRuntime()->run(
        static function (CoroutineScope $scope) use (
            $runtime,
            $integration,
            $requestContext,
            $interMix,
        ): bool {
            $adapter = new RunwireRuntimeAdapter(
                runtimeContext: $runtime,
                interMix: $integration,
                coroutineScope: static fn(HttpRequest $native): CoroutineScope => $scope,
            );
            $context = $adapter->context(
                runwire_bridge_request($requestContext),
                new RunwireBridgeWriterFixture(),
            );

            return $context->scopeBridge->withinScope(
                $interMix,
                Request::fake(uri: '/coroutine'),
                static fn(): bool => $interMix->get(CoroutineScope::class) === $scope,
            );
        },
    );

    expect($sameScope)->toBeTrue()
        ->and(RunwireBridgeScopeProbe::$runwireLeaves)->toBe(1);

    $integration->release($runtime);
});
