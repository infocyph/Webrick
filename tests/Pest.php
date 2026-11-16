<?php

declare(strict_types=1);

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

/*
|--------------------------------------------------------------------------
| Test Case (Optional - only if needed)
|--------------------------------------------------------------------------
*/
// Most tests don't need a TestCase, but it's available if you want it
// uses(Tests\TestCase::class)->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

expect()->extend('toHaveStatus', function (int $status) {
    $actual = webrickPestResponse($this->value)->getStatusCode();
    expect($actual)->toBe($status, "Expected status {$status}, got {$actual}");

    return $this;
});

expect()->extend('toHaveHeader', function (string $header) {
    expect(webrickPestResponse($this->value)->hasHeader($header))->toBeTrue("Expected header '{$header}' to be present");

    return $this;
});

/*
|--------------------------------------------------------------------------
| Telemetry & Trace Context Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeTraceId', function () {
    $value = webrickPestString($this->value, 'Trace ID');
    expect($value)
        ->toHaveLength(32, 'Trace ID must be 32 characters')
        ->and(ctype_xdigit($value))->toBeTrue('Trace ID must be hexadecimal');

    return $this;
});

expect()->extend('toBeSpanId', function () {
    $value = webrickPestString($this->value, 'Span ID');
    expect($value)
        ->toHaveLength(16, 'Span ID must be 16 characters')
        ->and(ctype_xdigit($value))->toBeTrue('Span ID must be hexadecimal');

    return $this;
});

expect()->extend('toBeRequestId', function () {
    $value = webrickPestString($this->value, 'Request ID');
    expect($value)
        ->toHaveLength(32, 'Request ID must be 32 characters')
        ->and(ctype_xdigit($value))->toBeTrue('Request ID must be hexadecimal');

    return $this;
});

expect()->extend('toBeValidTraceparent', function () {
    $value = webrickPestString($this->value, 'Traceparent');
    expect($value)
        ->toMatch('/^00-[a-f0-9]{32}-[a-f0-9]{16}-[a-f0-9]{2}$/', 'Traceparent must match W3C format: 00-{trace-id}-{span-id}-{flags}');

    return $this;
});

expect()->extend('toHaveTelemetryHeaders', function () {
    $response = webrickPestResponse($this->value);
    expect($response->hasHeader('X-Response-Time'))->toBeTrue('Expected X-Response-Time header');
    expect($response->hasHeader('X-Request-Id'))->toBeTrue('Expected X-Request-Id header');
    expect($response->hasHeader('Trace-Id'))->toBeTrue('Expected Trace-Id header');

    return $this;
});

expect()->extend('toHaveResponseTimeHeader', function () {
    $response = webrickPestResponse($this->value);
    expect($response->hasHeader('X-Response-Time'))->toBeTrue('Expected X-Response-Time header');
    $timing = $response->getHeaderLine('X-Response-Time');
    expect($timing)->toMatch('/^\d+\.\d+ms$/', 'X-Response-Time must be in format: 45.2ms');

    return $this;
});

expect()->extend('toHaveServerTimingHeader', function () {
    $response = webrickPestResponse($this->value);
    expect($response->hasHeader('Server-Timing'))->toBeTrue('Expected Server-Timing header');
    $timing = $response->getHeaderLine('Server-Timing');
    expect($timing)->toMatch('/^app;dur=\d+\.\d+$/', 'Server-Timing must be in format: app;dur=45.2');

    return $this;
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

/** Validate a value supplied to a response expectation. */
function webrickPestResponse(mixed $value): Response
{
    if (!$value instanceof Response) {
        throw new UnexpectedValueException('Expected a Webrick response for this assertion.');
    }

    return $value;
}

/** Validate a value supplied to a trace identifier expectation. */
function webrickPestString(mixed $value, string $label): string
{
    if (!is_string($value)) {
        throw new UnexpectedValueException("{$label} must be a string");
    }

    return $value;
}

/**
 * Create a mock request with trace attributes.
 *
 * @param array<string,mixed> $headers
 */
function mockRequestWithTrace(
    string $method = 'GET',
    string $uri = '/',
    ?string $traceId = null,
    ?string $spanId = null,
    ?string $requestId = null,
    array $headers = []
): Request {
    $request = mockRequest($method, $uri, $headers);

    if ($traceId) {
        $request = $request->withAttribute('trace.trace_id', $traceId);
    }
    if ($spanId) {
        $request = $request->withAttribute('trace.span_id', $spanId);
    }
    if ($requestId) {
        $request = $request->withAttribute('request_id', $requestId);
    }

    return $request;
}

/**
 * Assert that response has telemetry headers.
 */
function assertHasTelemetryHeaders(Response $response): void
{
    expect($response->hasHeader('X-Response-Time'))
        ->toBeTrue('Expected X-Response-Time header')
        ->and($response->hasHeader('X-Request-Id'))->toBeTrue('Expected X-Request-Id header')
        ->and($response->hasHeader('Trace-Id'))->toBeTrue('Expected Trace-Id header');
}

/**
 * Extract trace context from response body (assumes JSON).
 *
 * @return array{trace_id:mixed,span_id:mixed,request_id:mixed}
 */
function extractTraceContext(Response $response): array
{
    $data = json_decode((string) $response->getBody(), true);
    if (!is_array($data)) {
        throw new UnexpectedValueException('Expected JSON object containing trace context.');
    }

    return [
        'trace_id' => $data['trace_id'] ?? null,
        'span_id' => $data['span_id'] ?? null,
        'request_id' => $data['request_id'] ?? null,
    ];
}

/**
 * Generate a valid W3C trace ID (32 hex chars).
 */
function generateTraceId(): string
{
    return bin2hex(random_bytes(16));
}

/**
 * Generate a valid W3C span ID (16 hex chars).
 */
function generateSpanId(): string
{
    return bin2hex(random_bytes(8));
}

/**
 * Generate a valid request ID (32 hex chars).
 */
function generateRequestId(): string
{
    return bin2hex(random_bytes(16));
}

/**
 * Build a W3C traceparent header.
 */
function buildTraceparent(string $traceId, string $spanId, string $flags = '01'): string
{
    return sprintf('00-%s-%s-%s', $traceId, $spanId, $flags);
}
