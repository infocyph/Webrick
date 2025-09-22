<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Kernel;

use ErrorException;
use Infocyph\Webrick\Constants\StatusEnum;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Kernel error boundary
 *
 * Top-level error boundary used by the HTTP kernel to catch all Throwables
 * emitted during request handling and convert them into safe HTTP Responses.
 *
 * Responsibilities:
 *  - Optionally convert PHP warnings/notices into ErrorException for unified handling.
 *  - Map exceptions to HTTP status codes via a configurable map.
 *  - Render error responses in multiple media types (problem+json, json, xml, html, plain).
 *  - Attach a request identifier header when available.
 *  - Log errors using a PSR-3 logger with severity based on HTTP status series.
 *
 * Instances are immutable; configuration is supplied via constructor promotion.
 *
 * @package Infocyph\Webrick\Router\Kernel
 */
final class ErrorHandler
{
    /**
     * Construct an ErrorHandler.
     *
     * @param LoggerInterface|null $logger PSR-3 logger used to persist error details (optional)
     * @param bool $debug When true include exception details (file/trace) in responses
     * @param bool $capturePhpErrors Convert PHP warnings/notices/stricter errors into ErrorException
     *                               so they are handled by this boundary (respects @ operator)
     * @param string $requestIdHeader Header name to echo back when a request id is present
     * @param array<class-string,int> $exceptionMap Map of exception class => HTTP status code to override resolution
     */
    public function __construct(
        private readonly ?LoggerInterface $logger = null,
        private readonly bool $debug = false,
        private readonly bool $capturePhpErrors = true,
        private readonly string $requestIdHeader = 'X-Request-Id',
        private readonly array $exceptionMap = [],
    ) {
    }

    /**
     * Handle a request through the wrapped core pipeline with error boundary.
     *
     * This method executes $core with the provided Request. Any Throwable thrown
     * by the pipeline is caught, converted to an HTTP status via resolveStatus()
     * and rendered into a Response by render(). When configured, PHP errors are
     * temporarily converted into ErrorException for unified handling; the previous
     * error handler is always restored.
     *
     * @param Request $req Incoming request
     * @param callable(Request):Response $core Terminal pipeline callable to execute
     * @return Response Response returned by the core or the error renderer
     */
    public function handle(Request $req, callable $core): Response
    {
        $prev = null;
        if ($this->capturePhpErrors) {
            $prev = set_error_handler(
                function (int $severity, string $message, ?string $file = null, ?int $line = null): bool {
                    // Respect error suppression (@) by honouring error_reporting mask.
                    if (!(error_reporting() & $severity)) {
                        return false;
                    }
                    throw new ErrorException($message, 0, $severity, $file ?? 'unknown', $line ?? 0);
                },
            );
        }

        try {
            return $core($req);
        } catch (Throwable $e) {
            $status = $this->resolveStatus($e);
            $resp = $this->render($req, $e, $status);
            $this->log($e, $req, $status);
            return $resp;
        } finally {
            if ($this->capturePhpErrors) {
                // Restore previous error handler to avoid global side-effects.
                set_error_handler($prev);
            }
        }
    }

    /**
     * Extract an Allow header value from a Throwable representing method constraints.
     *
     * Supports:
     *  - public property 'allowed' (array)
     *  - method allowed()
     *  - method getAllowedMethods()
     *
     * Normalises to an RFC-style comma separated list and ensures HEAD/OPTIONS
     * are included where appropriate.
     *
     * @param Throwable $e Source throwable that may expose allowed methods
     * @return string|null Comma-separated methods or null when none present
     */
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

        $list = array_unique(array_map('strtoupper', (array)$list));
        sort($list, SORT_STRING);

        // Ensure HEAD is present whenever GET exists and HEAD was not explicitly given.
        if (in_array('GET', $list, true) && !in_array('HEAD', $list, true)) {
            $list[] = 'HEAD';
        }
        // Always include OPTIONS for method discovery convenience.
        if (!in_array('OPTIONS', $list, true)) {
            $list[] = 'OPTIONS';
        }
        return implode(', ', $list);
    }

    /**
     * Render an HTML error page.
     *
     * When debug mode is enabled the page includes exception details and trace.
     *
     * @param int $status HTTP status code
     * @param string $reason Short reason phrase
     * @param string $msg Public or debug message
     * @param string $rid Request identifier (may be empty)
     * @param Throwable $e Source exception for debug details
     * @return string Full HTML document
     */
    private function htmlError(int $status, string $reason, string $msg, string $rid, Throwable $e): string
    {
        $esc = fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $debug = '';
        if ($this->debug) {
            $debug = sprintf(
                '<div style="margin-top:1rem;padding:.75rem;border:1px solid #ddd;border-radius:6px;">
                    <div><strong>%s</strong></div>
                    <div>%s:%d</div>
                    <pre style="white-space:pre-wrap">%s</pre>
                 </div>',
                $esc($e::class),
                $esc($e->getFile()),
                $e->getLine(),
                $esc($e->getTraceAsString()),
            );
        }
        $ridHtml = $rid !== '' ? '<div style="opacity:.7">Request-Id: ' . $esc($rid) . '</div>' : '';
        return <<<HTML
            <!doctype html>
            <meta charset="utf-8">
            <title>{$status} {$esc($reason)}</title>
            <style>
            body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial; margin: 2rem; line-height:1.45;}
            h1 { margin: 0 0 .25rem 0; font-size: 1.5rem;}
            .card { padding: 1rem; border: 1px solid #e5e7eb; border-radius: .75rem; background: #fff; max-width: 900px;}
            .sub { color: #6b7280; margin-top:.25rem; }
            </style>
            <div class="card">
              <h1>{$status} {$esc($reason)}</h1>
              <div class="sub">{$esc($msg)}</div>
              {$ridHtml}
              {$debug}
            </div>
            HTML;
    }

    /**
     * Check whether an integer is a valid HTTP error/status code.
     *
     * @param int $code Candidate code
     * @return bool True when code is between 400 and 599 inclusive
     */
    private function isHttp(int $code): bool
    {
        return $code >= 400 && $code <= 599;
    }

    /**
     * Log the Throwable using the configured PSR-3 logger.
     *
     * Severity mapping:
     *  - 5xx -> error
     *  - 404 / 405 -> notice
     *  - otherwise -> warning
     *
     * The message includes HTTP status and exception class; context carries
     * request metadata and the exception itself for structured logging.
     *
     * @param Throwable $e Exception to log
     * @param Request $req Request associated with the failure
     * @param int $status Resolved HTTP status code
     * @return void
     */
    private function log(Throwable $e, Request $req, int $status): void
    {
        if (!$this->logger) {
            return;
        }

        $level = match (true) {
            $status >= 500 => 'error',
            $status === StatusEnum::NOT_FOUND->value
            || $status === StatusEnum::METHOD_NOT_ALLOWED->value => 'notice',
            default => 'warning',
        };

        $this->logger->{$level}(
            sprintf('[http:%d] %s: %s', $status, $e::class, $e->getMessage()),
            [
                'status' => $status,
                'series' => StatusEnum::tryFrom($status)?->series(),
                'method' => strtoupper($req->getMethod()),
                'path' => (string)$req->getUri()->getPath(),
                'request_id' => $req->getAttribute('request_id') ?: null,
                'exception' => $e,
            ],
        );
    }

    /**
     * Pick a preferred response content type based on an Accept header value.
     *
     * This is a simple heuristic that looks for well-known types in order of
     * preference and falls back to text/plain.
     *
     * @param string $accept Lowercased Accept header value
     * @return string Chosen MIME type for error rendering
     */
    private function pickType(string $accept): string
    {
        $accept = strtolower($accept);
        if (str_contains($accept, 'application/problem+json')) {
            return 'application/problem+json';
        }
        if (str_contains($accept, 'application/json')) {
            return 'application/json';
        }
        if (str_contains($accept, 'text/html')) {
            return 'text/html';
        }
        if (str_contains($accept, 'application/xml') || str_contains($accept, 'text/xml')) {
            return 'application/xml';
        }
        return 'text/plain';
    }

    /* ──────────────────────── render ──────────────────────── */

    /**
     * Render a Throwable into an HTTP Response according to content negotiation.
     *
     * Behavior:
     *  - If Accept negotiation produced a content type it will be honoured.
     *  - HEAD requests always return an empty body with appropriate headers.
     *  - Certain HTTP statuses disallow bodies (as per Status enum); an empty
     *    Response will be returned in those cases.
     *  - When debug mode is enabled structured details (exception class, file,
     *    trace) are included in the response payload.
     *
     * @param Request $req Request instance (used for Accept, method, URI and request id)
     * @param Throwable $e Exception/Throwable to render
     * @param int $status HTTP status code to use for the response
     * @return Response Generated Response instance
     */
    private function render(Request $req, Throwable $e, int $status): Response
    {
        $statusEnum = StatusEnum::tryFrom($status) ?? StatusEnum::INTERNAL_SERVER_ERROR;
        $reason = $statusEnum->reason();

        $wanted = (string)$req->getAttribute('negotiated.type');
        if ($wanted === '') {
            $accept = strtolower($req->getHeaderLine('Accept'));
            $wanted = $this->pickType($accept);
        }

        $headers = [
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Vary' => 'Accept',
        ];

        $rid = $req->getAttribute('request_id') ?: $req->getHeaderLine($this->requestIdHeader);
        if (is_string($rid) && $rid !== '') {
            $headers[$this->requestIdHeader] = $rid;
        }

        if ($status === StatusEnum::METHOD_NOT_ALLOWED->value) {
            if ($allow = $this->extractAllow($e)) {
                $headers['Allow'] = $allow;
            }
        }

        // HEAD must not include a body regardless of status.
        if (strtoupper($req->getMethod()) === 'HEAD') {
            return Response::empty($status, $headers);
        }

        $public = $reason;
        $msg = $this->debug ? ($e->getMessage() ?: $public) : $public;

        // Some status codes (e.g. 204, 304) do not allow bodies.
        if (!$statusEnum->allowsBody()) {
            return Response::empty($status, $headers);
        }

        switch ($wanted) {
            case 'application/problem+json':
                {
                    $payload = [
                        'type' => 'about:blank',
                        'title' => $reason,
                        'status' => $status,
                        'detail' => $msg,
                        'instance' => (string)$req->getUri()->getPath(),
                    ];
                    if ($rid) {
                        $payload['request_id'] = (string)$rid;
                    }
                    if ($this->debug) {
                        $payload += [
                            'exception' => $e::class,
                            'file' => $e->getFile() . ':' . $e->getLine(),
                            'trace' => explode("\n", $e->getTraceAsString()),
                        ];
                    }
                    $headers['Content-Type'] = 'application/problem+json';
                    $json = json_encode(
                        $payload,
                        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES,
                    );
                    return Response::create($json === false ? '{}' : $json, $status, $headers);
                }

            case 'application/json':
                {
                    $payload = [
                        'error' => $msg,
                        'code' => $status,
                        'reason' => $reason,
                    ];
                    if ($rid) {
                        $payload['request_id'] = (string)$rid;
                    }
                    if ($this->debug) {
                        $payload += [
                            'exception' => $e::class,
                            'file' => $e->getFile() . ':' . $e->getLine(),
                            'trace' => explode("\n", $e->getTraceAsString()),
                        ];
                    }
                    return Response::json($payload, $status, $headers);
                }

            case 'application/xml':
            case 'text/xml':
                $headers['Content-Type'] = 'application/xml';
                $xml = $this->xmlError($status, $reason, $msg, (string)$rid, $e);
                return Response::create($xml, $status, $headers);

            case 'text/html':
                $headers['Content-Type'] = 'text/html; charset=utf-8';
                $html = $this->htmlError($status, $reason, $msg, (string)$rid, $e);
                return Response::create($html, $status, $headers);

            default:
                $headers['Content-Type'] = 'text/plain; charset=utf-8';
                $lines = ["{$status} {$reason}", $msg];
                if ($rid) {
                    $lines[] = "Request-Id: {$rid}";
                }
                if ($this->debug) {
                    $lines[] = $e::class;
                    $lines[] = $e->getFile() . ':' . $e->getLine();
                    $lines[] = $e->getTraceAsString();
                }
                return Response::plaintext(implode("\n", $lines), $status, $headers);
        }
    }

    /* ───────────────────── status + logging ───────────────────── */

    /**
     * Resolve an HTTP status for a Throwable.
     *
     * Resolution order:
     *  1. Check $this->exceptionMap for a mapped exception class (first match)
     *  2. If exception exposes getStatusCode() use that when valid HTTP status
     *  3. If exception has a public property 'status' use it when valid
     *  4. Fall back to exception->getCode() when within HTTP range
     *  5. Otherwise return 500 (INTERNAL_SERVER_ERROR)
     *
     * @param Throwable $e Exception to inspect
     * @return int Resolved HTTP status code (400-599 or 500 fallback)
     */
    private function resolveStatus(Throwable $e): int
    {
        foreach ($this->exceptionMap as $cls => $code) {
            if ($e instanceof $cls && $this->isHttp($code)) {
                return $code;
            }
        }

        if (method_exists($e, 'getStatusCode')) {
            $sc = (int)$e->getStatusCode();
            if ($this->isHttp($sc)) {
                return $sc;
            }
        }
        if (property_exists($e, 'status') && $this->isHttp((int)$e->status)) {
            return (int)$e->status;
        }

        $code = (int)$e->getCode();
        return $this->isHttp($code) ? $code : StatusEnum::INTERNAL_SERVER_ERROR->value;
    }

    /**
     * Render an XML error payload.
     *
     * When debug mode is enabled exception class/file/trace are included.
     *
     * @param int $status HTTP status code
     * @param string $reason Reason phrase
     * @param string $msg Message to include in the payload
     * @param string $rid Request identifier (may be empty)
     * @param Throwable $e Source exception for debug details
     * @return string XML string
     */
    private function xmlError(int $status, string $reason, string $msg, string $rid, Throwable $e): string
    {
        $xe = fn (string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_COMPAT, 'UTF-8');
        $trace = $this->debug ? '<trace>' . $xe($e->getTraceAsString()) . '</trace>' : '';
        $ridEl = $rid !== '' ? '<request_id>' . $xe($rid) . '</request_id>' : '';
        $exEl = $this->debug
            ? '<exception>' . $xe($e::class) . '</exception><file>' . $xe($e->getFile()) . ':' . $e->getLine(
            ) . '</file>'
            : '';
        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <error>
              <code>{$status}</code>
              <reason>{$xe($reason)}</reason>
              <message>{$xe($msg)}</message>
              {$ridEl}
              {$exEl}
              {$trace}
            </error>
            XML;
    }
}
