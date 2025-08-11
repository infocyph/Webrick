<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

/**
 * Hard caps on request header size (431) and body size (413).
 *
 * - Header cap applies to every request.
 * - Body cap checks Content-Length (can’t pre-measure chunked streams).
 */
final readonly class RequestLimitsMiddleware
{
    /**
     * @param int|null $maxBodyBytes  null ⇒ use ini_get('post_max_size')
     * @param string[] $bodyLimitVerbs HTTP methods to which body limit applies
     * @param bool $violateOnUnknownBody When true and no Content-Length is present,
     *                                   treat as violation for the configured verbs.
     */
    public function __construct(
        private int $maxHeaderBytes = 8192,
        private ?int $maxBodyBytes = null,
        private array $bodyLimitVerbs = ['POST', 'PUT', 'PATCH', 'DELETE'],
        private bool $violateOnUnknownBody = false,
    ) {}

    public function __invoke(Request $req, Closure $next): Response
    {
        /* ── 1) headers total size → 431 ─────────────────────────── */
        if ($this->maxHeaderBytes > 0) {
            $hdrBytes = $this->totalHeaderBytes($req);
            if ($hdrBytes > $this->maxHeaderBytes) {
                return new Response(
                    431,
                    new Stream('Request headers too large'),
                    ['Content-Type' => 'text/plain; charset=utf-8'],
                );
            }
        }

        /* ── 2) body size → 413 (by Content-Length) ───────────────── */
        $verbs = $this->bodyLimitVerbs;
        $limit = $this->resolveBodyLimit();

        if ($limit > 0 && in_array($req->getMethod(), $verbs, true)) {
            $cl = trim($req->getHeaderLine('Content-Length'));
            if ($cl !== '') {
                $len = (int)$cl;
                if ($len > $limit) {
                    return new Response(
                        413,
                        new Stream('Payload exceeds maximum allowed size.'),
                        ['Content-Type' => 'text/plain; charset=utf-8'],
                    );
                }
            } elseif ($this->violateOnUnknownBody) {
                // No Content-Length – optionally reject pre-emptively.
                return new Response(
                    413,
                    new Stream('Payload exceeds maximum allowed size.'),
                    ['Content-Type' => 'text/plain; charset=utf-8'],
                );
            }
        }

        return $next($req);
    }

    /* ───────────────────────── helpers ─────────────────────────── */

    private function resolveBodyLimit(): int
    {
        if ($this->maxBodyBytes !== null) {
            return $this->maxBodyBytes;
        }
        return self::phpIniBytes(ini_get('post_max_size'));
    }

    /** Conservative byte count for headers (name + ": " + value per line). */
    private function totalHeaderBytes(Request $r): int
    {
        $sum = 0;
        $all = $r->getHeaders(); // supports both map or flat list forms

        foreach ($all as $name => $val) {
            if (is_int($name)) {
                // flat list of raw header lines
                $sum += strlen((string)$val);
                continue;
            }
            $values = is_array($val) ? $val : [$val];
            foreach ($values as $v) {
                $sum += strlen((string)$name) + 2 + strlen((string)$v); // "Name: value"
            }
        }
        return $sum;
    }

    private static function phpIniBytes(string|false $val): int
    {
        if ($val === false) {
            return 0;
        }
        $val = trim($val);
        if ($val === '') {
            return 0;
        }
        $unit = strtolower(substr($val, -1));
        $num = (int)$val;
        return match ($unit) {
            'g' => $num * 1024 * 1024 * 1024,
            'm' => $num * 1024 * 1024,
            'k' => $num * 1024,
            default => (int)$val,
        };
    }
}
