<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

/**
 * Hard caps on request header size (431), header fields count (431),
 * and body size (413).
 *
 * - Header bytes + header fields count apply to every request.
 * - Body cap checks Content-Length (can’t pre-measure chunked streams).
 */
final readonly class RequestLimitsMiddleware
{
    /**
     * @param int      $maxHeaderBytes 0 disables byte check
     * @param int      $maxHeaderCount 0 disables count check (fields = each header value line)
     * @param int|null $maxBodyBytes   null ⇒ use ini_get('post_max_size')
     * @param string[] $bodyLimitVerbs HTTP methods to which body limit applies
     * @param bool     $violateOnUnknownBody When true and no Content-Length is present,
     *                                       treat as violation for the configured verbs.
     */
    public function __construct(
        private int $maxHeaderBytes = 8192,
        private int $maxHeaderCount = 100,
        private ?int $maxBodyBytes = null,
        private array $bodyLimitVerbs = ['POST', 'PUT', 'PATCH', 'DELETE'],
        private bool $violateOnUnknownBody = true,
    ) {}

    public function __invoke(Request $req, Closure $next): Response
    {
        /* ── 0) header fields count → 431 ───────────────────────── */
        if ($this->maxHeaderCount > 0) {
            $fields = $this->totalHeaderFields($req);
            if ($fields > $this->maxHeaderCount) {
                return Response::plaintext('Too many header fields', 431)
                    ->withHeader('Connection', 'close');
            }
        }

        /* ── 1) headers total size → 431 ────────────────────────── */
        if ($this->maxHeaderBytes > 0) {
            $hdrBytes = $this->totalHeaderBytes($req);
            if ($hdrBytes > $this->maxHeaderBytes) {
                return Response::plaintext('Request headers too large', 431)
                    ->withHeader('Connection', 'close');
            }
        }

        /* ── 2) body size → 413 (by Content-Length) ─────────────── */
        $limit = $this->resolveBodyLimit();
        if ($limit > 0 && \in_array(\strtoupper($req->getMethod()), $this->bodyLimitVerbs, true)) {
            $cl = trim($req->getHeaderLine('Content-Length'));
            if ($cl !== '') {
                $len = (int)$cl;
                if ($len > $limit) {
                    return Response::plaintext('Payload exceeds maximum allowed size.', 413)
                        ->withHeader('Connection', 'close');
                }
            } elseif ($this->violateOnUnknownBody) {
                // No Content-Length – optionally reject pre-emptively.
                return Response::plaintext('Payload exceeds maximum allowed size.', 413)
                    ->withHeader('Connection', 'close');
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
        return self::phpIniBytes(\ini_get('post_max_size'));
    }

    /** Conservative byte count for headers (name + ": " + value per line). */
    private function totalHeaderBytes(Request $r): int
    {
        $sum = 0;
        $all = $r->getHeaders(); // supports both map or flat list forms

        foreach ($all as $name => $val) {
            if (\is_int($name)) {
                // flat list of raw header lines
                $sum += \strlen((string)$val);
                continue;
            }
            $values = \is_array($val) ? $val : [$val];
            foreach ($values as $v) {
                $sum += \strlen((string)$name) + 2 + \strlen((string)$v); // "Name: value"
            }
        }
        return $sum;
    }

    /**
     * Count total header fields (each value counts as one field).
     * Example: "Set-Cookie" repeated 5 times + "Accept: a,b" (still 1 field here since
     * your Request flattens repeated-name values as an array).
     */
    private function totalHeaderFields(Request $r): int
    {
        $all = $r->getHeaders();

        $count = 0;
        foreach ($all as $name => $val) {
            if (\is_int($name)) {
                // flat list (raw lines)
                $count++;
                continue;
            }
            $values = \is_array($val) ? $val : [$val];
            $count += \count($values);
        }
        return $count;
    }

    private static function phpIniBytes(string|false $val): int
    {
        if ($val === false) {
            return 0;
        }
        $val = \trim($val);
        if ($val === '') {
            return 0;
        }
        $unit = \strtolower(substr($val, -1));
        $num  = (int)$val;
        return match ($unit) {
            'g' => $num * 1024 * 1024 * 1024,
            'm' => $num * 1024 * 1024,
            'k' => $num * 1024,
            default => (int)$val,
        };
    }
}
