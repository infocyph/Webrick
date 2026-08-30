<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Support;

use Infocyph\Webrick\Request\Request;

/**
 * Explicit immutable request-local context.
 *
 * The context travels with Request attributes and never relies on process-global
 * current-request state, making it safe for persistent workers and coroutines.
 */
final readonly class RequestContext
{
    public const string ATTRIBUTE = 'webrick.request_context';

    public function __construct(
        private Request $request,
        private bool $otelAvailable = false,
    ) {}

    /**
     * @return array{trace_id:?string,span_id:?string,parent_span_id:?string,request_id:?string,flags:?string,tracestate:?string}
     */
    public function all(): array
    {
        return [
            'trace_id' => $this->traceId(),
            'span_id' => $this->spanId(),
            'parent_span_id' => $this->parentSpanId(),
            'request_id' => $this->requestId(),
            'flags' => $this->flags(),
            'tracestate' => $this->traceState(),
        ];
    }

    public function flags(): ?string
    {
        return $this->attributeString('trace.flags');
    }

    /** @return array<string,string> */
    public function logArray(): array
    {
        return array_filter([
            'trace_id' => $this->traceId(),
            'span_id' => $this->spanId(),
            'request_id' => $this->requestId(),
        ], static fn(?string $value): bool => $value !== null);
    }

    public function logContext(): string
    {
        $parts = [];
        if (($traceId = $this->traceId()) !== null) {
            $parts[] = 'trace=' . $traceId;
        }
        if (($spanId = $this->spanId()) !== null) {
            $parts[] = 'span=' . $spanId;
        }
        if (($requestId = $this->requestId()) !== null) {
            $parts[] = 'request=' . $requestId;
        }

        return implode(' ', $parts);
    }

    public function otelAvailable(): bool
    {
        return $this->otelAvailable;
    }

    public function parentSpanId(): ?string
    {
        return $this->attributeString('trace.parent_span_id');
    }

    /** @return array<string,string> */
    public function propagationHeaders(bool $includeRequestId = true): array
    {
        $headers = [];
        if (($traceParent = $this->traceParent()) !== null) {
            $headers['traceparent'] = $traceParent;
        }
        if (($traceState = $this->traceState()) !== null) {
            $headers['tracestate'] = $traceState;
        }
        if (($traceId = $this->traceId()) !== null) {
            $headers['X-Trace-Id'] = $traceId;
        }
        if ($includeRequestId && ($requestId = $this->requestId()) !== null) {
            $headers['X-Request-Id'] = $requestId;
        }

        return $headers;
    }

    public function request(): Request
    {
        return $this->request;
    }

    public function requestId(): ?string
    {
        return $this->attributeString('request_id');
    }

    public function sampled(): bool
    {
        $flags = $this->flags();

        return $flags !== null && (hexdec($flags) & 0x01) === 0x01;
    }

    public function spanId(): ?string
    {
        return $this->attributeString('trace.span_id');
    }

    public function traceId(): ?string
    {
        return $this->attributeString('trace.trace_id');
    }

    public function traceParent(): ?string
    {
        $traceId = $this->traceId();
        $spanId = $this->spanId();
        if ($traceId === null || $spanId === null) {
            return null;
        }

        return sprintf('00-%s-%s-%s', $traceId, $spanId, $this->flags() ?? '01');
    }

    public function traceState(): ?string
    {
        return $this->attributeString('trace.tracestate');
    }

    private function attributeString(string $key): ?string
    {
        $value = $this->request->getAttribute($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
