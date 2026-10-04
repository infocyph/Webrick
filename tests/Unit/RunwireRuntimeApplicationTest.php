<?php

declare(strict_types=1);

use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\RequestBodyInterface;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Network\Enum\WriteState;
use Infocyph\Runwire\Network\WriteResult;
use Infocyph\Runwire\RequestContext as RunwireRequestContext;
use Infocyph\Runwire\Runtime\ApplicationLifecycleHooks;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\Runtime\RuntimeApplicationInterface;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\Runwire\Supervisor\Enum\ShutdownReason;
use Infocyph\Webrick\Runtime\Http\RunwireResponseContinuation;
use Infocyph\Webrick\Runtime\Http\RunwireRuntimeApplication;
use Infocyph\Webrick\Runtime\Http\RunwireRuntimeApplicationFactory;

final class RunwireApplicationBodyFixture implements RequestBodyInterface
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
        return $this;
    }

    public function onEnd(callable $callback): RequestBodyInterface
    {
        Closure::fromCallable($callback)($this);

        return $this;
    }

    public function read(int $maxBytes = PHP_INT_MAX): string
    {
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

final class RunwireApplicationWriterFixture implements ResponseWriterInterface
{
    use \Infocyph\Webrick\Tests\Fixture\RunwireTerminalWriterTrait;

    public int $endCalls = 0;

    public bool $ended = false;

    public Headers $headers;

    public bool $started = false;

    public int $status = 0;

    public function __construct()
    {
        $this->headers = new Headers();
    }

    public function end(string $finalChunk = ''): WriteResult
    {
        if ($this->ended) {
            return new WriteResult(WriteState::CLOSED, 0);
        }

        ++$this->endCalls;
        $this->ended = true;
        $this->notifyTerminal();

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
        return $this;
    }

    public function start(int $status = 200, ?Headers $headers = null): WriteResult
    {
        $this->headers = $headers ?? new Headers();
        $this->started = true;
        $this->status = $status;

        return new WriteResult(WriteState::ACCEPTED, 0);
    }

    public function write(string $chunk): WriteResult
    {
        return new WriteResult(WriteState::ACCEPTED, strlen($chunk));
    }
}

function runwire_application_request(): HttpRequest
{
    return new HttpRequest(
        method: 'GET',
        target: '/',
        version: ProtocolVersion::HTTP_1_1,
        headers: Headers::fromArray(['Host' => 'example.test']),
        body: new RunwireApplicationBodyFixture(),
    );
}

test('runwire application bridge delegates requested lifecycle completion exactly once', function (): void {
    $application = new RunwireRuntimeApplication(
        function (HttpRequest $request, ResponseWriterInterface $writer): void {
            expect($request->method)->toBe('GET');
            $writer->start();
        },
        RuntimeContext::standalone(),
    );
    $writer = new RunwireApplicationWriterFixture();

    $application->handle(runwire_application_request(), $writer, completeResponse: true);

    expect($writer->started)->toBeTrue()
        ->and($writer->ended)->toBeTrue()
        ->and($writer->endCalls)->toBe(1)
        ->and($application->snapshot()->requestsTotal)->toBe(1)
        ->and($application->snapshot()->requestsActive)->toBe(0);
});

test('runwire application bridge leaves exactly once completion with the Webrick handler', function (): void {
    $application = new RunwireRuntimeApplication(
        function (HttpRequest $request, ResponseWriterInterface $writer): void {
            expect($request->method)->toBe('GET');
            $writer->start();
            $writer->end('ok');
        },
        RuntimeContext::standalone(),
    );
    $writer = new RunwireApplicationWriterFixture();

    $application->handle(runwire_application_request(), $writer, completeResponse: true);

    expect($writer->ended)->toBeTrue()
        ->and($writer->endCalls)->toBe(1)
        ->and($application->snapshot()->requestsTotal)->toBe(1);
});

test('runwire terminal observers fire exactly once and late observers fire immediately', function (): void {
    $writer = new RunwireApplicationWriterFixture();
    $early = 0;
    $late = 0;

    $writer->onTerminal(function (ResponseWriterInterface $terminal) use (&$early, $writer): void {
        expect($terminal)->toBe($writer);
        ++$early;
    });
    $writer->end();
    $writer->onTerminal(function (ResponseWriterInterface $terminal) use (&$late, $writer): void {
        expect($terminal)->toBe($writer);
        ++$late;
    });
    $writer->end();

    expect($early)->toBe(1)
        ->and($late)->toBe(1)
        ->and($writer->endCalls)->toBe(1);
});

test('runwire continuation propagates caller Fiber exceptions through handler cleanup', function (): void {
    $caught = null;
    $finalized = 0;
    $failure = new RuntimeException('caller Fiber cancelled');

    $owner = new Fiber(static function () use (&$caught, &$finalized): void {
        RunwireResponseContinuation::run(static function () use (&$caught, &$finalized): void {
            try {
                Fiber::suspend('handler-suspended');
            } catch (RuntimeException $error) {
                $caught = $error;

                throw $error;
            } finally {
                ++$finalized;
            }
        });
    });

    expect($owner->start())->toBe('handler-suspended')
        ->and($owner->isSuspended())->toBeTrue()
        ->and(fn() => $owner->throw($failure))
        ->toThrow(RuntimeException::class, 'caller Fiber cancelled')
        ->and($caught)->toBe($failure)
        ->and($finalized)->toBe(1)
        ->and($owner->isTerminated())->toBeTrue();
});

test('runwire host task cancellation reaches handler and finalizes request exactly once', function (): void {
    $coroutines = new CoroutineRuntime();
    $runtime = RuntimeContext::standalone();
    $requestContext = RunwireRequestContext::create($runtime);
    $request = new HttpRequest(
        method: 'GET',
        target: '/cancel',
        version: ProtocolVersion::HTTP_1_1,
        headers: Headers::fromArray(['Host' => 'example.test']),
        body: new RunwireApplicationBodyFixture(),
        context: $requestContext,
    );
    $writer = new RunwireApplicationWriterFixture();
    $caught = false;
    $handlerFinally = 0;
    $cleanupCalls = 0;
    $application = null;

    $coroutines->run(
        static function (CoroutineScope $scope) use (
            &$application,
            &$caught,
            &$handlerFinally,
            &$cleanupCalls,
            $runtime,
            $request,
            $writer,
        ): void {
            $application = new RunwireRuntimeApplication(
                static function (HttpRequest $native, ResponseWriterInterface $response) use (
                    $scope,
                    &$caught,
                    &$handlerFinally,
                ): void {
                    unset($native, $response);

                    try {
                        $scope->sleep(60.0);
                    } catch (CancelledException $error) {
                        $caught = true;

                        throw $error;
                    } finally {
                        ++$handlerFinally;
                    }
                },
                $runtime,
                requestCleanup: static function () use (&$cleanupCalls): void {
                    ++$cleanupCalls;
                },
            );

            $task = $scope->spawn(
                static fn(): null => $application->handle($request, $writer, completeResponse: true),
            );
            $scope->yieldNow();

            expect($task->isComplete())->toBeFalse()
                ->and($task->cancel(CancellationReason::HOST_CANCELLED))->toBeTrue()
                ->and(fn() => $task->await())
                ->toThrow(CancelledException::class);
        },
    );

    expect($caught)->toBeTrue()
        ->and($handlerFinally)->toBe(1)
        ->and($cleanupCalls)->toBe(1)
        ->and($requestContext->completed())->toBeTrue()
        ->and($application)->toBeInstanceOf(RunwireRuntimeApplication::class)
        ->and($application->snapshot()->requestsActive)->toBe(0);
});

test('runwire application bridge forwards lifecycle health failure', function (): void {
    $failure = new RuntimeException('runwire cleanup isolation failed');
    $application = new RunwireRuntimeApplication(
        static function (HttpRequest $request, ResponseWriterInterface $writer): void {
            expect($request->method)->toBe('GET');
            $writer->end();
        },
        RuntimeContext::standalone(),
        requestCleanup: static function () use ($failure): void {
            throw $failure;
        },
    );

    expect($application->healthy())->toBeTrue()
        ->and($application->healthFailure())->toBeNull()
        ->and(fn() => $application->handle(
            runwire_application_request(),
            new RunwireApplicationWriterFixture(),
            completeResponse: true,
        ))->toThrow(\Infocyph\Runwire\Exception\RequestLifecycleException::class)
        ->and($application->healthy())->toBeFalse()
        ->and($application->healthFailure())->toBe($failure);
});

test('runwire application factory delegates lifecycle hooks cleanup and shutdown', function (): void {
    $events = [];
    $context = RuntimeContext::standalone();
    $hooks = new ApplicationLifecycleHooks(
        boot: function (RuntimeContext $runtimeContext) use (&$events, $context): void {
            expect($runtimeContext)->toBe($context);
            $events[] = 'boot';
        },
        warmup: function (RuntimeContext $runtimeContext) use (&$events, $context): void {
            expect($runtimeContext)->toBe($context);
            $events[] = 'warmup';
        },
        drain: function (RuntimeContext $runtimeContext, ShutdownReason $reason) use (&$events, $context): void {
            expect($runtimeContext)->toBe($context)
                ->and($reason)->toBe(ShutdownReason::SUPERVISOR_STOP);
            $events[] = 'drain';
        },
        shutdown: function (RuntimeContext $runtimeContext, ShutdownReason $reason) use (&$events, $context): void {
            expect($runtimeContext)->toBe($context)
                ->and($reason)->toBe(ShutdownReason::SUPERVISOR_STOP);
            $events[] = 'shutdown';
        },
    );
    $factory = new RunwireRuntimeApplicationFactory(
        handler: function (HttpRequest $request, ResponseWriterInterface $writer) use (&$events): void {
            expect($request->method)->toBe('GET');
            $events[] = 'handler';
            $writer->start();
            $writer->end();
        },
        requestCleanup: function () use (&$events): void {
            $events[] = 'cleanup';
        },
        shutdown: function () use (&$events): void {
            $events[] = 'legacy-shutdown';
        },
        lifecycle: $hooks,
    );

    $application = $factory->create($context);
    expect($application)->toBeInstanceOf(RuntimeApplicationInterface::class)
        ->and($application)->toBeInstanceOf(RunwireRuntimeApplication::class);

    $application->start();
    $application->handle(runwire_application_request(), new RunwireApplicationWriterFixture(), completeResponse: true);
    $application->drain();
    $application->shutdown();

    expect($events)->toBe([
        'boot',
        'warmup',
        'handler',
        'cleanup',
        'drain',
        'shutdown',
        'legacy-shutdown',
    ]);
});
