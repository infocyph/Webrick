<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Invoker;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Psr\Log\NullLogger;

/** @param list<callable> $middleware */
function dispatchResponsePayloadForTest(mixed $payload, array $middleware = []): Response
{
    $kernel = RouterKernel::bootWithRegistrar(
        log: new NullLogger,
        matcher: FusedMatcher::make(),
        register: static function (Registrar $registrar) use ($middleware, $payload): void {
            $registrar->get('/payload', static fn(): mixed => $payload, ['middleware' => $middleware]);
        },
        invoker: Invoker::with(new Container('webrick.tests.dispatcher-payload')),
    );

    return $kernel->handle(mockRequest('GET', '/payload'));
}

test('fast dispatch preserves route handler array payloads', function (): void {
    foreach ([
        'empty array' => [[], '[]'],
        'flat list' => [[1, 2, 3], '[1,2,3]'],
        'list of records' => [[['id' => 1], ['id' => 2]], '[{"id":1},{"id":2}]'],
        'associative array with a nested list' => [['ok' => true, 'data' => [1, 2]], '{"ok":true,"data":[1,2]}'],
        'sparse numeric array' => [[2 => 'a', 5 => 'b'], '{"2":"a","5":"b"}'],
        'mixed nested arrays' => [[
            'records' => [['id' => 1], 2],
            'flags' => [true, false],
        ], '{"records":[{"id":1},2],"flags":[true,false]}'],
    ] as [$payload, $expected]) {
        $response = dispatchResponsePayloadForTest($payload);

        expect((string) $response->getBody())->toBe($expected);
    }
});

test('middleware terminal preserves route handler array payloads', function (): void {
    $middleware = [
        static fn(Request $request, Closure $next): Response => $next($request),
    ];

    foreach ([
        [1, 2, 3],
        [['id' => 1], ['id' => 2]],
        ['ok' => true, 'data' => [1, 2]],
        [2 => 'a', 5 => 'b'],
    ] as $payload) {
        $response = dispatchResponsePayloadForTest($payload, $middleware);

        expect((string) $response->getBody())->toBe(json_encode($payload, JSON_THROW_ON_ERROR));
    }
});

test('dispatcher preserves existing non-array route result behavior', function (): void {
    $jsonSerializable = new class implements JsonSerializable {
        public function jsonSerialize(): mixed
        {
            return ['serialized' => true];
        }
    };

    foreach ([
        [null, 'null'],
        [true, 'true'],
        [42, '42'],
        [1.5, '1.5'],
        ['text', '"text"'],
        [$jsonSerializable, '{"serialized":true}'],
    ] as [$payload, $expected]) {
        $response = dispatchResponsePayloadForTest($payload);

        expect((string) $response->getBody())->toBe($expected);
    }

    $response = dispatchResponsePayloadForTest(Response::plaintext('already a response', 202));

    expect($response)->toHaveStatus(202)
        ->and((string) $response->getBody())->toBe('already a response');
});
