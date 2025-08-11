<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

/**
 * Hard caps for request size concerns:
 *   • 431 when cumulative header bytes exceed $maxHeaderBytes
 *   • 413 when Content-Length exceeds $maxPostBytes (or post_max_size)
 *
 * Place early in the pipeline, before any body parsing.
 */
final readonly class RequestLimitsMiddleware
{
    public function __construct(
        private int $maxHeaderBytes = 8192,
        private ?int $maxPostBytes = null,   // null ⇒ php.ini post_max_size
    ) {
    }

    public function __invoke(Request $req, Closure $next): Response
    {
        /* 1) header budget ------------------------------------------------ */
        $headerBytes = array_sum(array_map('strlen', $req->getHeaders()));
        if ($headerBytes > $this->maxHeaderBytes) {
            return new Response(
                status: 431,
                headers: ['Content-Type' => 'text/plain; charset=utf-8'],
                body: new Stream('Request headers too large'),
            );
        }

        /* 2) entity size budget (best-effort via Content-Length) ---------- */
        $limit = $this->maxPostBytes ?? self::phpIniBytes(\ini_get('post_max_size'));
        if ($limit > 0) {
            $len = (int)$req->getHeaderLine('Content-Length');
            if ($len > $limit) {
                return new Response(
                    status: 413,
                    headers: ['Content-Type' => 'text/plain; charset=utf-8'],
                    body: new Stream('Payload exceeds maximum allowed size.'),
                );
            }
        }

        return $next($req);
    }

    private static function phpIniBytes(string|false $val): int
    {
        if ($val === false) {
            return 0;
        }
        $val = trim($val);
        $unit = strtolower(substr($val, -1));
        $num = (int)$val;
        return match ($unit) {
            'g' => $num * 1024 * 1024 * 1024,
            'm' => $num * 1024 * 1024,
            'k' => $num * 1024,
            default => $num,
        };
    }
}
