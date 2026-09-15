<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\RequestBodyInterface;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Network\Enum\WriteState;
use Infocyph\Runwire\Network\WriteResult;
use Infocyph\Runwire\RequestContext as RunwireRequestContext;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Runtime\Http\RunwireRuntimeAdapter;
use Infocyph\Webrick\Runtime\Http\RuntimeRequestExecution;

final class RunwireAdapterBodyFixture implements RequestBodyInterface
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
            throw new InvalidArgumentException('Maximum body read length cannot be negative.');
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

final class RunwireAdapterWriterFixture implements ResponseWriterInterface
{
    /** @var list<string> */
    public array $chunks = [];

    public int $endCalls = 0;

    public bool $ended = false;

    public Headers $headers;

    public bool $pressureOnWrite = false;

    public int $startCalls = 0;

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
        if ($finalChunk !== '') {
            $this->chunks[] = $finalChunk;
        }
        ++$this->endCalls;
        $this->ended = true;

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
        Closure::fromCallable($callback)($this);

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

        return new WriteResult($this->pressureOnWrite ? WriteState::PRESSURED : WriteState::ACCEPTED, strlen($chunk));
    }
}

function runwire_adapter_request(
    string $method = 'GET',
    string $target = '/',
    ProtocolVersion $version = ProtocolVersion::HTTP_1_1,
    ?RunwireAdapterBodyFixture $body = null,
    ?RunwireRequestContext $context = null,
): HttpRequest {
    return new HttpRequest(
        method: $method,
        target: $target,
        version: $version,
        headers: Headers::fromArray(['Host' => 'example.test']),
        body: $body ?? new RunwireAdapterBodyFixture(''),
        context: $context,
    );
}

test('runwire capabilities advertise only the normalized runtime surface', function (): void {
    $capabilities = new RunwireRuntimeAdapter()->capabilities();

    expect($capabilities->name)->toBe('runwire')
        ->and($capabilities->persistent)->toBeTrue()
        ->and($capabilities->concurrent)->toBeTrue()
        ->and($capabilities->nativeStreaming)->toBeTrue()
        ->and($capabilities->nativeFile)->toBeFalse()
        ->and($capabilities->transportRequestLimits)->toBeTrue()
        ->and($capabilities->nativeRequestStreaming)->toBeTrue()
        ->and($capabilities->cancellationVisibility)->toBeTrue()
        ->and($capabilities->transportDrainVisibility)->toBeTrue();
});

test('runwire request normalization remains lazy and exposes live execution state', function (): void {
    $body = new RunwireAdapterBodyFixture('payload');
    $runwireContext = RunwireRequestContext::standalone('runwire-request-1', 100);
    $request = new HttpRequest(
        method: 'POST',
        target: 'https://example.test/orders?page=2',
        version: ProtocolVersion::HTTP_2,
        headers: Headers::fromArray([
            'Host' => 'example.test',
            'Cookie' => 'sid=a%20b; theme=dark',
            'X-Repeat' => ['a', 'b'],
        ]),
        body: $body,
        peerAddress: '127.0.0.1:51000',
        localAddress: '10.0.0.1:443',
        encrypted: true,
        context: $runwireContext,
    );
    $writer = new RunwireAdapterWriterFixture();
    $context = new RunwireRuntimeAdapter()->context($request, $writer, withHost: true);

    expect($context->routing->method)->toBe('POST')
        ->and($context->routing->path)->toBe('/orders')
        ->and($context->routing->host)->toBe('example.test')
        ->and($context->nativeRequest)->toBe($request)
        ->and($context->nativeResponse)->toBe($writer)
        ->and($body->readCalls)->toBe(0);

    $webRequest = $context->request();
    $execution = $webRequest->getAttribute(RuntimeRequestExecution::ATTRIBUTE);

    expect($webRequest->getMethod())->toBe('POST')
        ->and($webRequest->getProtocolVersion())->toBe('2')
        ->and($webRequest->getUri()->getScheme())->toBe('https')
        ->and($webRequest->getUri()->getHost())->toBe('example.test')
        ->and($webRequest->getUri()->getPath())->toBe('/orders')
        ->and($webRequest->query('page'))->toBe('2')
        ->and($webRequest->cookie('sid'))->toBe('a b')
        ->and($webRequest->cookie('theme'))->toBe('dark')
        ->and($webRequest->getHeader('X-Repeat'))->toBe(['a', 'b'])
        ->and($webRequest->server('REMOTE_ADDR'))->toBe('127.0.0.1')
        ->and($webRequest->server('REMOTE_PORT'))->toBe('51000')
        ->and($webRequest->server('SERVER_ADDR'))->toBe('10.0.0.1')
        ->and($webRequest->server('SERVER_PORT'))->toBe('443')
        ->and($body->readCalls)->toBe(0)
        ->and($execution)->toBeInstanceOf(RuntimeRequestExecution::class)
        ->and($execution->requestId)->toBe('runwire-request-1')
        ->and($execution->startMonotonicNanoseconds)->toBe(100)
        ->and($execution->cancelled())->toBeFalse()
        ->and((string) $webRequest->getBody())->toBe('payload')
        ->and($body->readCalls)->toBe(1);

    $runwireContext->cancel(CancellationReason::TRANSPORT_CANCELLED);

    expect($execution->cancelled())->toBeTrue()
        ->and($execution->cancellationReason())->toBe(CancellationReason::TRANSPORT_CANCELLED->value)
        ->and($context->request())->toBe($webRequest);
});

test('runwire response mapping preserves headers body semantics and exactly once completion', function (): void {
    $adapter = new RunwireRuntimeAdapter();
    $writer = new RunwireAdapterWriterFixture();
    $context = $adapter->context(
        runwire_adapter_request(version: ProtocolVersion::HTTP_2),
        $writer,
    );

    $adapter->write(
        Response::create('hello', 201, [
            'Set-Cookie' => ['a=1', 'b=2'],
            'Connection' => 'close',
        ]),
        $context,
    );

    expect($writer->status)->toBe(201)
        ->and($writer->startCalls)->toBe(1)
        ->and($writer->endCalls)->toBe(1)
        ->and($writer->isEnded())->toBeTrue()
        ->and($writer->chunks)->toBe(['hello'])
        ->and($writer->headers->all('set-cookie'))->toBe(['a=1', 'b=2'])
        ->and($writer->headers->first('content-length'))->toBe('5')
        ->and($writer->headers->has('connection'))->toBeFalse();
});

test('runwire response mapping keeps head body suppression and streaming chunk order', function (): void {
    $adapter = new RunwireRuntimeAdapter();
    $headWriter = new RunwireAdapterWriterFixture();
    $headContext = $adapter->context(runwire_adapter_request(method: 'HEAD'), $headWriter);

    $adapter->write(Response::create('hidden'), $headContext);

    expect($headWriter->chunks)->toBe([])
        ->and($headWriter->headers->first('content-length'))->toBe('6')
        ->and($headWriter->endCalls)->toBe(1);

    $streamWriter = new RunwireAdapterWriterFixture();
    $streamContext = $adapter->context(runwire_adapter_request(), $streamWriter);
    $adapter->write(Response::stream(static fn(): iterable => ['a', 'b', 'c'], status: 202), $streamContext);

    expect($streamWriter->status)->toBe(202)
        ->and($streamWriter->chunks)->toBe(['a', 'b', 'c'])
        ->and($streamWriter->startCalls)->toBe(1)
        ->and($streamWriter->endCalls)->toBe(1);
});

test('runwire adapter detects transport pressure instead of overrunning the writer', function (): void {
    $adapter = new RunwireRuntimeAdapter();
    $writer = new RunwireAdapterWriterFixture();
    $writer->pressureOnWrite = true;
    $context = $adapter->context(runwire_adapter_request(), $writer);

    expect(fn(): null => $adapter->write(Response::stream(static fn(): iterable => ['a', 'b']), $context))
        ->toThrow(RuntimeException::class, 'requires drain continuation');

    expect($writer->chunks)->toBe(['a'])
        ->and($writer->endCalls)->toBe(0);
});

test('runwire adapter rejects missing or mismatched native handles', function (): void {
    $adapter = new RunwireRuntimeAdapter();

    expect(fn() => $adapter->context())
        ->toThrow(RuntimeException::class, 'Runwire runtime requires HttpRequest and ResponseWriterInterface handles.');
    expect(fn() => $adapter->context(runwire_adapter_request(), new stdClass()))
        ->toThrow(RuntimeException::class, 'Runwire runtime requires HttpRequest and ResponseWriterInterface handles.');
});
