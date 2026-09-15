<?php

declare(strict_types=1);

use Generator;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Runtime\RoutingInput;
use Infocyph\Webrick\Runtime\Http\RoadRunnerRuntimeAdapter;
use Infocyph\Webrick\Runtime\Http\RuntimeCapabilities;
use Infocyph\Webrick\Runtime\Http\RuntimeRequestContext;
use Infocyph\Webrick\Runtime\Http\SapiRuntimeAdapter;
use Infocyph\Webrick\Runtime\Http\SwooleRuntimeAdapter;
use Infocyph\Webrick\Runtime\Http\WorkermanRuntimeAdapter;
use ReflectionClass;
use RuntimeException;

/**
 * @param class-string $responseClass
 * @param class-string $chunkClass
 */
function runtime_adapter_workerman(
    string $responseClass = stdClass::class,
    string $chunkClass = stdClass::class,
    bool $transportCompression = false,
    bool $transportRequestLimits = false,
): WorkermanRuntimeAdapter {
    $reflection = new ReflectionClass(WorkermanRuntimeAdapter::class);
    $adapter = $reflection->newInstanceWithoutConstructor();
    $constructor = $reflection->getConstructor();
    if ($constructor === null) {
        throw new RuntimeException('Workerman runtime constructor is unavailable.');
    }
    $constructor->invoke(
        $adapter,
        $responseClass,
        $chunkClass,
        $transportCompression,
        $transportRequestLimits,
    );

    return $adapter;
}

function runtime_adapter_context(string $method = 'GET'): RuntimeRequestContext
{
    return new RuntimeRequestContext(
        new RoutingInput($method, '/runtime'),
        static fn(): Request => Request::fake(method: $method, uri: '/runtime'),
        new RuntimeCapabilities('test', persistent: true),
    );
}

test('existing runtime adapters retain their capability contracts', function (): void {
    $sapi = SapiRuntimeAdapter::current(transportCompression: true, transportRequestLimits: true)->capabilities();
    $swoole = SwooleRuntimeAdapter::swoole(true, true)->capabilities();
    $openSwoole = SwooleRuntimeAdapter::openSwoole(true, true)->capabilities();
    $workerman = runtime_adapter_workerman(
        transportCompression: true,
        transportRequestLimits: true,
    )->capabilities();
    $roadRunner = new RoadRunnerRuntimeAdapter(
        static function (int $status, string|Generator $body, array $headers, bool $final): void {
            unset($status, $body, $headers, $final);
        },
        sendfileMiddleware: true,
        transportCompression: true,
        transportRequestLimits: true,
    );
    $roadRunner = $roadRunner->capabilities();

    expect($sapi->concurrent)->toBeFalse()
        ->and($sapi->nativeStreaming)->toBeTrue()
        ->and($sapi->nativeFile)->toBeFalse()
        ->and($sapi->transportCompression)->toBeTrue()
        ->and($sapi->transportRequestLimits)->toBeTrue()
        ->and($swoole->name)->toBe('swoole')
        ->and($swoole->persistent)->toBeTrue()
        ->and($swoole->concurrent)->toBeTrue()
        ->and($swoole->nativeStreaming)->toBeTrue()
        ->and($swoole->nativeFile)->toBeTrue()
        ->and($openSwoole->name)->toBe('openswoole')
        ->and($openSwoole->concurrent)->toBeTrue()
        ->and($workerman->name)->toBe('workerman')
        ->and($workerman->persistent)->toBeTrue()
        ->and($workerman->concurrent)->toBeFalse()
        ->and($workerman->nativeStreaming)->toBeTrue()
        ->and($workerman->nativeFile)->toBeTrue()
        ->and($roadRunner->name)->toBe('roadrunner')
        ->and($roadRunner->persistent)->toBeTrue()
        ->and($roadRunner->concurrent)->toBeFalse()
        ->and($roadRunner->nativeStreaming)->toBeTrue()
        ->and($roadRunner->nativeFile)->toBeTrue();
});

test('swoole normalization stays request local and materializes the request lazily', function (): void {
    $nativeRequest = new class {
        /** @var array<string,mixed> */
        public array $cookie = ['sid' => 'abc'];

        /** @var array<string,mixed> */
        public array $files = [];

        /** @var array<string,mixed> */
        public array $get = ['page' => '2'];

        /** @var array<string,mixed> */
        public array $header = [
            'host' => 'Example.test:8443',
            'content-type' => 'application/json',
        ];

        /** @var array<string,mixed> */
        public array $post = [];

        public int $rawContentCalls = 0;

        /** @var array<string,mixed> */
        public array $server = [
            'request_method' => 'POST',
            'request_uri' => '/orders',
            'query_string' => 'page=2',
            'server_protocol' => 'HTTP/2',
            'remote_addr' => '127.0.0.1',
            'server_port' => 8443,
            'server_name' => 'Example.test',
            'request_scheme' => 'https',
        ];

        public function rawContent(): string
        {
            $this->rawContentCalls++;

            return '{"order":1}';
        }
    };
    $nativeResponse = new stdClass();
    $context = SwooleRuntimeAdapter::swoole()->context($nativeRequest, $nativeResponse, withHost: true);

    expect($context->routing->method)->toBe('POST')
        ->and($context->routing->path)->toBe('/orders')
        ->and($context->routing->host)->toBe('example.test')
        ->and($context->nativeRequest)->toBe($nativeRequest)
        ->and($context->nativeResponse)->toBe($nativeResponse)
        ->and($nativeRequest->rawContentCalls)->toBe(0);

    $request = $context->request();

    expect($request->getMethod())->toBe('POST')
        ->and($request->getUri()->getPath())->toBe('/orders')
        ->and($request->query('page'))->toBe('2')
        ->and($nativeRequest->rawContentCalls)->toBe(1)
        ->and($context->request())->toBe($request)
        ->and($nativeRequest->rawContentCalls)->toBe(1);
});

test('workerman normalization stays request local and materializes the request lazily', function (): void {
    $nativeRequest = new class {
        public int $rawBodyCalls = 0;

        /** @return array<string,mixed> */
        public function cookie(): array
        {
            return ['sid' => 'xyz'];
        }

        /** @return array<string,mixed> */
        public function file(): array
        {
            return [];
        }

        /** @return array<string,mixed> */
        public function get(): array
        {
            return ['page' => '3'];
        }

        /** @return array<string,string> */
        public function header(): array
        {
            return ['host' => 'Worker.test', 'content-type' => 'application/json'];
        }

        public function method(): string
        {
            return 'PATCH';
        }

        /** @return array<string,mixed> */
        public function post(): array
        {
            return [];
        }

        public function protocolVersion(): string
        {
            return '1.1';
        }

        public function rawBody(): string
        {
            $this->rawBodyCalls++;

            return '{"worker":1}';
        }

        public function uri(): string
        {
            return '/workers?page=3';
        }
    };
    $connection = new class {
        public object $worker;

        public function __construct()
        {
            $this->worker = (object) ['transport' => 'ssl'];
        }

        public function getRemoteIp(): string
        {
            return '127.0.0.2';
        }
    };
    $adapter = runtime_adapter_workerman();
    $context = $adapter->context($nativeRequest, $connection, withHost: true);

    expect($context->routing->method)->toBe('PATCH')
        ->and($context->routing->path)->toBe('/workers')
        ->and($context->routing->host)->toBe('worker.test')
        ->and($context->nativeRequest)->toBe($nativeRequest)
        ->and($context->nativeResponse)->toBe($connection)
        ->and($nativeRequest->rawBodyCalls)->toBe(0);

    $request = $context->request();

    expect($request->getMethod())->toBe('PATCH')
        ->and($request->getUri()->getPath())->toBe('/workers')
        ->and($request->query('page'))->toBe('3')
        ->and($nativeRequest->rawBodyCalls)->toBe(1)
        ->and($context->request())->toBe($request)
        ->and($nativeRequest->rawBodyCalls)->toBe(1);
});

test('roadrunner keeps fixed responses and head suppression on the same writer contract', function (): void {
    /** @var list<array{status:int,body:string|Generator,headers:array<string,list<string>>,final:bool}> $writes */
    $writes = [];
    $adapter = new RoadRunnerRuntimeAdapter(
        static function (int $status, string|Generator $body, array $headers, bool $final) use (&$writes): void {
            $writes[] = compact('status', 'body', 'headers', 'final');
        },
    );

    $adapter->write(
        Response::create('hello', 201, ['X-Runtime' => 'roadrunner']),
        runtime_adapter_context(),
    );
    $adapter->write(
        Response::create('hidden', 200, ['X-Runtime' => 'head']),
        runtime_adapter_context('HEAD'),
    );

    expect($writes)->toHaveCount(2)
        ->and($writes[0]['status'])->toBe(201)
        ->and($writes[0]['body'])->toBe('hello')
        ->and($writes[0]['headers']['Content-Length'])->toBe(['5'])
        ->and($writes[0]['final'])->toBeTrue()
        ->and($writes[1]['status'])->toBe(200)
        ->and($writes[1]['body'])->toBe('')
        ->and($writes[1]['headers']['Content-Length'])->toBe(['6'])
        ->and($writes[1]['final'])->toBeTrue();
});

test('swoole streaming writes chunks in order and ends exactly once', function (): void {
    $nativeRequest = new class {
        /** @var array<string,mixed> */
        public array $server = ['server_protocol' => 'HTTP/1.1'];
    };
    $nativeResponse = new class {
        /** @var list<string> */
        public array $chunks = [];

        public int $endCalls = 0;

        /** @var array<string,string|list<string>> */
        public array $headers = [];

        public int $statusCode = 0;

        public function end(?string $body = null): bool
        {
            $this->endCalls++;
            if ($body !== null && $body !== '') {
                $this->chunks[] = $body;
            }

            return true;
        }

        public function header(string $name, string|array $value): bool
        {
            $this->headers[$name] = $value;

            return true;
        }

        public function status(int $status): bool
        {
            $this->statusCode = $status;

            return true;
        }

        public function write(string $chunk): bool
        {
            $this->chunks[] = $chunk;

            return true;
        }
    };
    $context = new RuntimeRequestContext(
        new RoutingInput('GET', '/stream'),
        static fn(): Request => Request::fake(uri: '/stream'),
        SwooleRuntimeAdapter::swoole()->capabilities(),
        $nativeRequest,
        $nativeResponse,
    );
    $response = Response::stream(static fn(): iterable => ['a', 'b', 'c'], status: 202);

    SwooleRuntimeAdapter::swoole()->write($response, $context);

    expect($nativeResponse->statusCode)->toBe(202)
        ->and($nativeResponse->chunks)->toBe(['a', 'b', 'c'])
        ->and($nativeResponse->endCalls)->toBe(1)
        ->and($nativeResponse->headers)->not->toHaveKey('Content-Length');
});

test('runtime adapters reject missing native transport handles', function (): void {
    expect(fn(): RuntimeRequestContext => SwooleRuntimeAdapter::swoole()->context())
        ->toThrow(RuntimeException::class, 'Swoole runtime requires native request and response objects.')
        ->and(fn(): RuntimeRequestContext => runtime_adapter_workerman()->context())
        ->toThrow(RuntimeException::class, 'Workerman runtime requires Request and Connection objects.')
        ->and(fn(): RuntimeRequestContext => new RoadRunnerRuntimeAdapter(
            static function (int $status, string|Generator $body, array $headers, bool $final): void {
                unset($status, $body, $headers, $final);
            },
        )->context(new stdClass()))
        ->toThrow(RuntimeException::class, 'RoadRunner runtime requires a PSR-style server request object.');
});
