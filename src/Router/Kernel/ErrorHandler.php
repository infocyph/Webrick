<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Kernel;

use ErrorException;
use Throwable;
use Psr\Log\LoggerInterface;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Constants\Status;

/**
 * Kernel-native error boundary (formerly ErrorHandlerMiddleware).
 *
 * Wrap the whole request pipeline with ->handle($req, $core).
 */
final class ErrorHandler
{
    /**
     * @param LoggerInterface|null     $logger
     * @param bool                     $debug            Expose details (stack, file) when true
     * @param bool                     $capturePhpErrors Convert PHP warnings/notices into ErrorException
     * @param string                   $requestIdHeader  Header to echo if present
     * @param array<class-string,int>  $exceptionMap     Map specific exceptions to HTTP status
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
     * @param callable(Request):Response $core
     */
    public function handle(Request $req, callable $core): Response
    {
        $prev = null;
        if ($this->capturePhpErrors) {
            $prev = set_error_handler(function (int $severity, string $message, ?string $file = null, ?int $line = null): bool {
                if (!(error_reporting() & $severity)) {
                    return false; // respect @
                }
                throw new ErrorException($message, 0, $severity, $file ?? 'unknown', $line ?? 0);
            });
        }

        try {
            return $core($req);
        } catch (Throwable $e) {
            $status = $this->resolveStatus($e);
            $resp   = $this->render($req, $e, $status);
            $this->log($e, $req, $status);
            return $resp;
        } finally {
            if ($this->capturePhpErrors) {
                set_error_handler($prev);
            }
        }
    }

    /* ──────────────────────── render ──────────────────────── */

    private function render(Request $req, Throwable $e, int $status): Response
    {
        $statusEnum = Status::tryFrom($status) ?? Status::INTERNAL_SERVER_ERROR;
        $reason = $statusEnum->reason();

        // Choose type: use result of NegotiationMiddleware if present; else Accept
        $wanted = (string)$req->getAttribute('negotiated.type');
        if ($wanted === '') {
            $accept = strtolower($req->getHeaderLine('Accept'));
            $wanted = $this->pickType($accept);
        }

        $headers = [
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Vary' => 'Accept', // explicit, since post-stack may not run on errors
        ];

        // Echo request id if any
        $rid = $req->getAttribute('request_id') ?: $req->getHeaderLine($this->requestIdHeader);
        if (is_string($rid) && $rid !== '') {
            $headers[$this->requestIdHeader] = $rid;
        }

        // 405: include Allow if extractable
        if ($status === Status::METHOD_NOT_ALLOWED->value) {
            if ($allow = $this->extractAllow($e)) {
                $headers['Allow'] = $allow;
            }
        }

        $public = $reason;
        $msg = $this->debug ? ($e->getMessage() ?: $public) : $public;

        if (!$statusEnum->allowsBody()) {
            return Response::empty($status, $headers);
        }

        switch ($wanted) {
            case 'application/problem+json': {
                $payload = [
                    'type'     => 'about:blank',
                    'title'    => $reason,
                    'status'   => $status,
                    'detail'   => $msg,
                    'instance' => (string)$req->getUri()->getPath(),
                ];
                if ($rid) {
                    $payload['request_id'] = (string)$rid;
                }
                if ($this->debug) {
                    $payload += [
                        'exception' => $e::class,
                        'file'      => $e->getFile() . ':' . $e->getLine(),
                        'trace'     => explode("\n", $e->getTraceAsString()),
                    ];
                }
                $headers['Content-Type'] = 'application/problem+json';
                $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
                return Response::create($json === false ? '{}' : $json, $status, $headers);
            }

            case 'application/json': {
                $payload = [
                    'error'  => $msg,
                    'code'   => $status,
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

    private function xmlError(int $status, string $reason, string $msg, string $rid, Throwable $e): string
    {
        $xe = fn (string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_COMPAT, 'UTF-8');
        $trace = $this->debug ? '<trace>' . $xe($e->getTraceAsString()) . '</trace>' : '';
        $ridEl = $rid !== '' ? '<request_id>' . $xe($rid) . '</request_id>' : '';
        $exEl = $this->debug
            ? '<exception>' . $xe($e::class) . '</exception><file>' . $xe($e->getFile()) . ':' . $e->getLine() . '</file>'
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

    /* ───────────────────── status + logging ───────────────────── */

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
        return $this->isHttp($code) ? $code : Status::INTERNAL_SERVER_ERROR->value;
    }

    private function isHttp(int $code): bool
    {
        return $code >= 400 && $code <= 599;
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

        $list = array_unique(array_map('strtoupper', (array)$list));
        sort($list, SORT_STRING);

        if (in_array('GET', $list, true) && !in_array('HEAD', $list, true)) {
            $list[] = 'HEAD';
        }
        if (!in_array('OPTIONS', $list, true)) {
            $list[] = 'OPTIONS';
        }
        return implode(', ', $list);
    }

    private function log(Throwable $e, Request $req, int $status): void
    {
        if (!$this->logger) {
            return;
        }

        $level = match (true) {
            $status >= 500 => 'error',
            $status === Status::NOT_FOUND->value
            || $status === Status::METHOD_NOT_ALLOWED->value => 'notice',
            default => 'warning',
        };

        $this->logger->{$level}(
            sprintf('[http:%d] %s: %s', $status, $e::class, $e->getMessage()),
            [
                'status' => $status,
                'series' => Status::tryFrom($status)?->series(),
                'method' => strtoupper($req->getMethod()),
                'path'   => (string)$req->getUri()->getPath(),
                'request_id' => $req->getAttribute('request_id') ?: null,
                'exception' => $e,
            ],
        );
    }
}
