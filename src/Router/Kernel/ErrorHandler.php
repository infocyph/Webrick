<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Kernel;

use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Constants\MediaTypeEnum;
use Infocyph\Webrick\Constants\StatusEnum;
use Infocyph\Webrick\Exceptions\HttpExceptionInterface;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Per-request HTTP error renderer/boundary.
 *
 * PHP warning/notice conversion is intentionally not installed here. Persistent
 * runtimes may opt into PhpErrorBridge once during process/worker bootstrap.
 */
final readonly class ErrorHandler
{
    /**
     * @param LoggerInterface|\Closure|null $logger
     * @param bool $debug
     * @param bool $capturePhpErrors Deprecated compatibility argument; ignored in Webrick 5. Use PhpErrorBridge.
     * @param string $requestIdHeader
     * @param array<class-string,int> $exceptionMap
     * @param null|callable(Request,Throwable,int,array<string,string>):mixed $responseRenderer
     */
    public function __construct(
        private LoggerInterface|\Closure|null $logger = null,
        private bool $debug = false,
        bool $capturePhpErrors = false,
        private string $requestIdHeader = 'X-Request-Id',
        private array $exceptionMap = [],
        private mixed $responseRenderer = null,
    ) {}

    /** @param callable(Request):Response $core */
    public function handle(Request $req, callable $core): Response
    {
        try {
            return $core($req);
        } catch (Throwable $e) {
            $status = $this->resolveStatus($e);
            $response = $this->render($req, $e, $status);
            $this->log($e, $req, $status);

            return $response;
        }
    }

    /** @return array<string,string> */
    private function buildRenderHeaders(Request $req, Throwable $e, int $status): array
    {
        $headers = [
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Vary' => 'Accept',
        ];

        $requestId = $this->resolveRequestId($req);
        if ($requestId !== '') {
            $headers[$this->requestIdHeader] = $requestId;
        }

        if ($status === StatusEnum::METHOD_NOT_ALLOWED->value) {
            $allow = $this->extractAllow($e);
            if ($allow !== null && $allow !== '') {
                $headers['Allow'] = $allow;
            }
        }

        return array_replace($headers, $this->exceptionHeaders($e));
    }

    /** @return array{exception:class-string<Throwable>,file:string} */
    private function debugMeta(Throwable $e): array
    {
        return [
            'exception' => $e::class,
            'file' => $e->getFile() . ':' . $e->getLine(),
        ];
    }

    /** @return array<string,string> */
    private function exceptionHeaders(Throwable $e): array
    {
        return $e instanceof HttpExceptionInterface ? $e->getHeaders() : [];
    }

    private function extractAllow(Throwable $e): ?string
    {
        $list = null;
        if (property_exists($e, 'allowed') && is_array($e->allowed)) {
            $list = $e->allowed;
        } elseif (method_exists($e, 'allowed')) {
            $list = $e->allowed();
        } elseif (method_exists($e, 'getAllowedMethods')) {
            $list = $e->getAllowedMethods();
        }

        if (!$list) {
            return null;
        }

        $methods = [];
        foreach ((array) $list as $method) {
            if (is_string($method) && $method !== '') {
                $methods[] = strtoupper($method);
            }
        }
        $methods = array_values(array_unique($methods));
        sort($methods, SORT_STRING);

        if (in_array(HttpMethodEnum::GET->value, $methods, true) && !in_array(HttpMethodEnum::HEAD->value, $methods, true)) {
            $methods[] = HttpMethodEnum::HEAD->value;
        }
        if (!in_array(HttpMethodEnum::OPTIONS->value, $methods, true)) {
            $methods[] = HttpMethodEnum::OPTIONS->value;
        }

        return implode(', ', $methods);
    }

    private function htmlError(int $status, string $reason, string $msg, string $rid, Throwable $e): string
    {
        $escape = fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $debug = '';
        if ($this->debug) {
            $debug = sprintf(
                '<div style="margin-top:1rem;padding:.75rem;border:1px solid #ddd;border-radius:6px;"><div><strong>%s</strong></div><div>%s:%d</div><pre style="white-space:pre-wrap">%s</pre></div>',
                $escape($e::class),
                $escape($e->getFile()),
                $e->getLine(),
                $escape($e->getTraceAsString()),
            );
        }
        $ridHtml = $rid !== '' ? '<div style="opacity:.7">Request-Id: ' . $escape($rid) . '</div>' : '';

        return <<<HTML
            <!doctype html>
            <meta charset="utf-8">
            <title>{$status} {$escape($reason)}</title>
            <style>
            body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial; margin: 2rem; line-height:1.45;}
            h1 { margin: 0 0 .25rem 0; font-size: 1.5rem;}
            .card { padding: 1rem; border: 1px solid #e5e7eb; border-radius: .75rem; background: #fff; max-width: 900px;}
            .sub { color: #6b7280; margin-top:.25rem; }
            </style>
            <div class="card">
              <h1>{$status} {$escape($reason)}</h1>
              <div class="sub">{$escape($msg)}</div>
              {$ridHtml}
              {$debug}
            </div>
            HTML;
    }

    private function isHttp(int $code): bool
    {
        return StatusEnum::isErrorCode($code);
    }

    private function log(Throwable $e, Request $req, int $status): void
    {
        $logger = $this->resolveLogger();
        if (!$logger instanceof LoggerInterface) {
            return;
        }

        $statusCase = StatusEnum::tryFrom($status);
        $level = match (true) {
            $statusCase?->isServerError() ?? StatusEnum::isServerErrorCode($status) => 'error',
            $status === StatusEnum::NOT_FOUND->value || $status === StatusEnum::METHOD_NOT_ALLOWED->value => 'notice',
            default => 'warning',
        };

        $logger->{$level}(
            sprintf('[http:%d] %s: %s', $status, $e::class, $e->getMessage()),
            [
                'status' => $status,
                'series' => StatusEnum::tryFrom($status)?->series(),
                'method' => HttpMethodEnum::normalize($req->getMethod()),
                'path' => $req->getUri()->getPath(),
                'request_id' => $req->getAttribute('request_id') ?: null,
                'exception' => $e,
            ],
        );
    }

    private function mappedExceptionStatus(Throwable $e): ?int
    {
        foreach ($this->exceptionMap as $class => $code) {
            if ($e instanceof $class && $this->isHttp($code)) {
                return $code;
            }
        }

        return null;
    }

    private function pickType(string $accept): string
    {
        $accept = strtolower($accept);
        if (str_contains($accept, MediaTypeEnum::PROBLEM_JSON->value)) {
            return MediaTypeEnum::PROBLEM_JSON->value;
        }
        if (str_contains($accept, MediaTypeEnum::JSON->base()) || str_contains($accept, '+json')) {
            return MediaTypeEnum::JSON->base();
        }
        if (str_contains($accept, MediaTypeEnum::HTML->base())) {
            return MediaTypeEnum::HTML->base();
        }
        if (str_contains($accept, MediaTypeEnum::XML->base()) || str_contains($accept, 'text/xml')) {
            return MediaTypeEnum::XML->base();
        }

        return MediaTypeEnum::PLAIN->base();
    }

    private function render(Request $req, Throwable $e, int $status): Response
    {
        $statusEnum = StatusEnum::tryFrom($status) ?? StatusEnum::INTERNAL_SERVER_ERROR;
        $reason = $statusEnum->reason();
        $wanted = $this->resolveRenderType($req);
        $headers = $this->buildRenderHeaders($req, $e, $status);
        $requestId = $this->resolveRequestId($req);

        if (HttpMethodEnum::normalize($req->getMethod()) === HttpMethodEnum::HEAD->value) {
            return Response::empty($status, $headers);
        }

        $public = $this->resolvePublicMessage($e, $reason);
        $message = $this->debug ? ($e->getMessage() ?: $public) : $public;

        if (!$statusEnum->allowsBody()) {
            return Response::empty($status, $headers);
        }

        $custom = $this->renderWithOverride($req, $e, $status, $headers);
        if ($custom instanceof Response) {
            return $custom;
        }

        return $this->renderByType($wanted, $req, $e, $status, $reason, $message, $requestId, $headers);
    }

    /** @param array<string,string> $headers */
    private function renderByType(
        string $wanted,
        Request $req,
        Throwable $e,
        int $status,
        string $reason,
        string $msg,
        string $rid,
        array $headers,
    ): Response {
        return match ($wanted) {
            MediaTypeEnum::PROBLEM_JSON->value => $this->renderProblemJson($req, $e, $status, $reason, $msg, $rid, $headers),
            MediaTypeEnum::JSON->value => $this->renderJson($e, $status, $reason, $msg, $rid, $headers),
            MediaTypeEnum::XML->value, 'text/xml' => Response::create(
                $this->xmlError($status, $reason, $msg, $rid, $e),
                $status,
                ['Content-Type' => $headers['Content-Type'] ?? MediaTypeEnum::XML->value] + $headers,
            ),
            MediaTypeEnum::HTML->base() => Response::create(
                $this->htmlError($status, $reason, $msg, $rid, $e),
                $status,
                ['Content-Type' => $headers['Content-Type'] ?? MediaTypeEnum::HTML->value] + $headers,
            ),
            default => $this->renderPlain($e, $status, $reason, $msg, $rid, $headers),
        };
    }

    /** @param array<string,string> $headers */
    private function renderJson(Throwable $e, int $status, string $reason, string $msg, string $rid, array $headers): Response
    {
        $payload = ['error' => $msg, 'code' => $status, 'reason' => $reason];
        if ($rid !== '') {
            $payload['request_id'] = $rid;
        }
        if ($this->debug) {
            $payload += $this->debugMeta($e);
        }

        return Response::json($payload, $status, $headers);
    }

    /** @param array<string,string> $headers */
    private function renderPlain(Throwable $e, int $status, string $reason, string $msg, string $rid, array $headers): Response
    {
        $headers['Content-Type'] ??= MediaTypeEnum::PLAIN->value;
        $lines = ["{$status} {$reason}", $msg];
        if ($rid !== '') {
            $lines[] = "Request-Id: {$rid}";
        }
        if ($this->debug) {
            $lines[] = $e::class;
            $lines[] = $e->getFile() . ':' . $e->getLine();
        }

        return Response::plaintext(implode("\n", $lines), $status, $headers);
    }

    /** @param array<string,string> $headers */
    private function renderProblemJson(
        Request $req,
        Throwable $e,
        int $status,
        string $reason,
        string $msg,
        string $rid,
        array $headers,
    ): Response {
        $payload = [
            'type' => 'about:blank',
            'title' => $reason,
            'status' => $status,
            'detail' => $msg,
            'instance' => $req->getUri()->getPath(),
        ];
        if ($rid !== '') {
            $payload['request_id'] = $rid;
        }
        if ($this->debug) {
            $payload += $this->debugMeta($e);
        }

        $headers['Content-Type'] ??= MediaTypeEnum::PROBLEM_JSON->value;
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);

        return Response::create($json === false ? '{}' : $json, $status, $headers);
    }

    /** @param array<string,string> $headers */
    private function renderWithOverride(Request $req, Throwable $e, int $status, array $headers): ?Response
    {
        if (!is_callable($this->responseRenderer)) {
            return null;
        }

        $response = ($this->responseRenderer)($req, $e, $status, $headers);

        return $response instanceof Response ? $response : null;
    }

    private function resolveLogger(): ?LoggerInterface
    {
        if ($this->logger instanceof LoggerInterface || $this->logger === null) {
            return $this->logger;
        }

        $logger = ($this->logger)();
        if ($logger !== null && !$logger instanceof LoggerInterface) {
            throw new \UnexpectedValueException('The error logger factory must return a PSR-3 logger or null.');
        }

        return $logger;
    }

    private function resolvePublicMessage(Throwable $e, string $fallback): string
    {
        if ($e instanceof HttpExceptionInterface) {
            $message = $e->getPublicMessage();

            return $message !== '' ? $message : $fallback;
        }

        return $fallback;
    }

    private function resolveRenderType(Request $req): string
    {
        $wanted = $req->getAttribute('negotiated.type');
        if (is_string($wanted) && $wanted !== '') {
            return $wanted;
        }

        return $this->pickType(strtolower($req->getHeaderLine('Accept')));
    }

    private function resolveRequestId(Request $req): string
    {
        $requestId = $req->getAttribute('request_id') ?: $req->getHeaderLine($this->requestIdHeader);

        return is_string($requestId) ? $requestId : '';
    }

    private function resolveStatus(Throwable $e): int
    {
        if ($e instanceof HttpExceptionInterface) {
            return $e->getStatusCode();
        }

        $mapped = $this->mappedExceptionStatus($e);
        if ($mapped !== null) {
            return $mapped;
        }

        $methodStatus = $this->statusFromThrowableMethod($e);
        if ($methodStatus !== null) {
            return $methodStatus;
        }

        $propertyStatus = $this->statusFromThrowableProperty($e);
        if ($propertyStatus !== null) {
            return $propertyStatus;
        }

        $code = (int) $e->getCode();

        return $this->isHttp($code) ? $code : StatusEnum::INTERNAL_SERVER_ERROR->value;
    }

    private function statusFromRaw(mixed $raw): ?int
    {
        if (is_int($raw) && $this->isHttp($raw)) {
            return $raw;
        }

        if (is_string($raw) && $raw !== '') {
            $parsed = (int) $raw;
            if ($this->isHttp($parsed)) {
                return $parsed;
            }
        }

        return null;
    }

    private function statusFromThrowableMethod(Throwable $e): ?int
    {
        return method_exists($e, 'getStatusCode') ? $this->statusFromRaw($e->getStatusCode()) : null;
    }

    private function statusFromThrowableProperty(Throwable $e): ?int
    {
        return property_exists($e, 'status') ? $this->statusFromRaw($e->status) : null;
    }

    private function xmlError(int $status, string $reason, string $msg, string $rid, Throwable $e): string
    {
        $escape = fn(string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
        $trace = $this->debug ? '<trace>' . $escape($e->getTraceAsString()) . '</trace>' : '';
        $ridElement = $rid !== '' ? '<request_id>' . $escape($rid) . '</request_id>' : '';
        $exceptionElement = $this->debug
            ? '<exception>' . $escape($e::class) . '</exception><file>' . $escape($e->getFile()) . ':' . $e->getLine() . '</file>'
            : '';

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <error>
              <code>{$status}</code>
              <reason>{$escape($reason)}</reason>
              <message>{$escape($msg)}</message>
              {$ridElement}
              {$exceptionElement}
              {$trace}
            </error>
            XML;
    }
}
