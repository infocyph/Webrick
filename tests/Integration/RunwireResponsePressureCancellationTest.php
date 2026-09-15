<?php

declare(strict_types=1);

namespace Tests\Integration;

use Closure;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\RequestBodyInterface;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Network\Enum\WriteState;
use Infocyph\Runwire\Network\WriteResult;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Runtime\Http\RunwireRuntimeAdapter;
use Infocyph\Webrick\Runtime\Http\RunwireRuntimeApplication;
use PHPUnit\Framework\TestCase;

final class PressureCancellationBody implements RequestBodyInterface
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

final class PressureCancellationWriter implements ResponseWriterInterface
{
    /** @var list<string> */
    public array $chunks = [];

    public int $endCalls = 0;

    public int $startCalls = 0;

    public int $writeCalls = 0;

    private ?Closure $drainCallback = null;

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
        $this->drainCallback = Closure::fromCallable($callback);

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
        ++$this->writeCalls;
        $this->chunks[] = $chunk;

        return new WriteResult(
            $this->writeCalls === 1 ? WriteState::PRESSURED : WriteState::ACCEPTED,
            strlen($chunk),
        );
    }

    public function waitingForDrain(): bool
    {
        return $this->drainCallback instanceof Closure;
    }
}

final class RunwireResponsePressureCancellationTest extends TestCase
{
    public function testCancellationReleasesAResponseSuspendedOnTransportPressure(): void
    {
        $adapter = new RunwireRuntimeAdapter();
        $application = new RunwireRuntimeApplication(
            static function (HttpRequest $request, ResponseWriterInterface $writer) use ($adapter): void {
                $adapter->write(
                    Response::stream(static fn(): iterable => ['first', 'second']),
                    $adapter->context($request, $writer),
                );
            },
            RuntimeContext::standalone(),
        );
        $request = new HttpRequest(
            method: 'GET',
            target: '/pressure-cancel',
            version: ProtocolVersion::HTTP_1_1,
            headers: Headers::fromArray(['Host' => 'example.test']),
            body: new PressureCancellationBody(),
        );
        $writer = new PressureCancellationWriter();

        try {
            $application->start();
            $application->handle($request, $writer, completeResponse: true);

            self::assertSame(1, $writer->startCalls);
            self::assertSame(1, $writer->writeCalls);
            self::assertSame(['first'], $writer->chunks);
            self::assertSame(0, $writer->endCalls);
            self::assertTrue($writer->waitingForDrain());
            self::assertSame(1, $application->snapshot()->requestsActive);
            self::assertFalse($request->context->completed());

            self::assertTrue($request->context->cancel(CancellationReason::TRANSPORT_CANCELLED));

            self::assertTrue($request->context->completed());
            self::assertSame(CancellationReason::TRANSPORT_CANCELLED, $request->context->cancellation->reason());
            self::assertSame(1, $writer->writeCalls);
            self::assertSame(['first'], $writer->chunks);
            self::assertSame(0, $writer->endCalls);
            self::assertSame(1, $application->snapshot()->requestsTotal);
            self::assertSame(0, $application->snapshot()->requestsActive);
        } finally {
            $application->shutdown();
        }
    }
}
