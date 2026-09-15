<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\RequestBodyInterface;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Network\Enum\WriteState;
use Infocyph\Runwire\Network\WriteResult;
use Infocyph\Runwire\Runtime\ApplicationLifecycleHooks;
use Infocyph\Runwire\Runtime\RuntimeApplicationInterface;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\Runwire\Supervisor\Enum\ShutdownReason;
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

test('runwire application bridge suppresses lifecycle response completion', function (): void {
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
        ->and($writer->ended)->toBeFalse()
        ->and($writer->endCalls)->toBe(0)
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
