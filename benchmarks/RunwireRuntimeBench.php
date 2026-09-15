<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Benchmarks;

use Infocyph\InterMix\DI\Container;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\RequestBodyInterface;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Network\Enum\WriteState;
use Infocyph\Runwire\Network\WriteResult;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Runtime\Http\RuntimeRequestContext;
use Infocyph\Webrick\Runtime\Http\RunwireRuntimeAdapter;
use Infocyph\Webrick\Runtime\InterMixRuntime;
use PhpBench\Attributes as Bench;
use RuntimeException;

#[Bench\Groups(['runtime', 'runwire', 'intermix'])]
#[Bench\Iterations(5)]
#[Bench\Revs(1000)]
#[Bench\Warmup(1)]
final class RunwireRuntimeBench
{
    private RunwireRuntimeAdapter $adapter;

    private Response $fixedResponse;

    private InterMixRuntime $interMixRuntime;

    private HttpRequest $request;

    private Response $streamingResponse;

    public function setUp(): void
    {
        $this->adapter = new RunwireRuntimeAdapter();
        $this->request = new HttpRequest(
            method: 'POST',
            target: '/bench/42?view=full',
            version: ProtocolVersion::HTTP_1_1,
            headers: Headers::fromArray([
                'Host' => 'example.test',
                'Content-Type' => 'application/json',
                'Cookie' => 'sid=abc; theme=dark',
                'X-Repeat' => ['a', 'b'],
            ]),
            body: self::body(),
            peerAddress: '127.0.0.1:51000',
            localAddress: '10.0.0.1:8080',
            encrypted: true,
        );
        $this->fixedResponse = Response::plaintext('ok');
        $this->streamingResponse = Response::stream(static fn(): iterable => ['a', 'b', 'c']);
        $this->interMixRuntime = new InterMixRuntime(new Container('webrick.benchmark.runwire'));

        $context = $this->adapter->context($this->request, self::writer(), true);
        if ($context->routing->method !== 'POST' || $context->request()->getUri()->getHost() !== 'example.test') {
            throw new RuntimeException('Runwire runtime benchmark fixture failed request normalization.');
        }
    }

    #[Bench\BeforeMethods('setUp')]
    public function benchContextNormalization(): void
    {
        $this->adapter->context($this->request, self::writer(), true);
    }

    #[Bench\BeforeMethods('setUp')]
    public function benchFixedResponseWrite(): void
    {
        $writer = self::writer();
        $context = $this->adapter->context($this->request, $writer);
        $this->adapter->write($this->fixedResponse, $context);
    }

    #[Bench\BeforeMethods('setUp')]
    public function benchRequestPromotion(): void
    {
        $this->adapter->context($this->request, self::writer(), true)->request();
    }

    #[Bench\BeforeMethods('setUp')]
    public function benchRequestScopeRoundTrip(): void
    {
        $this->interMixRuntime->withinScope(
            RuntimeRequestContext::REQUEST_SCOPE,
            static fn(): null => null,
        );
    }

    #[Bench\BeforeMethods('setUp')]
    public function benchStreamingResponseWrite(): void
    {
        $writer = self::writer();
        $context = $this->adapter->context($this->request, $writer);
        $this->adapter->write($this->streamingResponse, $context);
    }

    private static function body(): RequestBodyInterface
    {
        return new class implements RequestBodyInterface {
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
        };
    }

    private static function writer(): ResponseWriterInterface
    {
        return new class implements ResponseWriterInterface {
            private bool $ended = false;

            private bool $started = false;

            public function end(string $finalChunk = ''): WriteResult
            {
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
                unset($status, $headers);
                $this->started = true;

                return new WriteResult(WriteState::ACCEPTED, 0);
            }

            public function write(string $chunk): WriteResult
            {
                return new WriteResult(WriteState::ACCEPTED, strlen($chunk));
            }
        };
    }
}
