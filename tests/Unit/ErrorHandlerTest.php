<?php

declare(strict_types=1);

use Infocyph\Webrick\Exceptions\HttpException;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Kernel\ErrorHandler;
use Psr\Log\NullLogger;

describe('ErrorHandler', function () {
    it('renders framework http exceptions at the kernel boundary', function () {
        $request = Request::fake(
            method: 'GET',
            uri: '/signed',
            headers: ['Accept' => 'text/plain'],
        );

        $response = (new ErrorHandler())->handle(
            $request,
            static fn(): Response => throw HttpException::badRequest('Missing signature'),
        );

        expect($response)
            ->toHaveStatus(400)
            ->and($response->getHeaderLine('Content-Type'))->toContain('text/plain')
            ->and((string) $response->getBody())->toContain('Missing signature');
    });

    it('preserves exception-provided headers when rendering', function () {
        $request = Request::fake(method: 'GET', uri: '/throttled');

        $response = (new ErrorHandler())->handle(
            $request,
            static fn(): Response => throw HttpException::tooManyRequests('Too Many Requests', [
                'Retry-After' => '30',
                'X-RateLimit-Remaining' => '0',
            ]),
        );

        expect($response)
            ->toHaveStatus(429)
            ->toHaveHeader('Retry-After', '30')
            ->toHaveHeader('X-RateLimit-Remaining', '0');
    });

    it('supports a custom response renderer override', function () {
        $request = Request::fake(
            method: 'GET',
            uri: '/signed',
            headers: ['Accept' => 'application/json'],
        );

        $handler = new ErrorHandler(
            responseRenderer: static function (Request $request, \Throwable $e, int $status, array $headers): Response {
                expect($request->getUri()->getPath())->toBe('/signed')
                    ->and($status)->toBe(403)
                    ->and($headers)->toHaveKey('Cache-Control');

                return Response::json([
                    'custom' => true,
                    'status' => $status,
                    'message' => $e->getMessage(),
                ], $status, $headers);
            },
        );

        $response = $handler->handle(
            $request,
            static fn(): Response => throw HttpException::forbidden('Invalid signature'),
        );

        expect($response)
            ->toHaveStatus(403)
            ->and((string) $response->getBody())->toContain('"custom":true');
    });

    it('does not resolve lazy error collaborators on successful requests', function () {
        $loggerResolutions = 0;
        $handler = new ErrorHandler(
            logger: static function () use (&$loggerResolutions): NullLogger {
                ++$loggerResolutions;

                return new NullLogger();
            },
        );

        $response = $handler->handle(Request::fake(), static fn(): Response => Response::json(['ok' => true]));

        expect($response)->toHaveStatus(200)
            ->and($loggerResolutions)->toBe(0);
    });
});
