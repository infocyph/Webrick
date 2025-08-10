<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use RuntimeException;

/**
 * Guard that aborts when a response violates our internal HTTP spec.
 *
 * Enable individual checks through bit-flags:
 *  • BODY_REQUIRES_CTYPE     – any non-empty body must declare Content-Type
 *  • NO_BODY_STATUSES        – 204/304 MUST have an empty body
 *  • COMPRESSED_NEEDS_VARY   – compressed reply MUST send Vary: Accept-Encoding
 *
 * Example (container binding):
 *     // dev - everything on
 *     new ResponseLinterMiddleware(
 *         ResponseLinterMiddleware::BODY_REQUIRES_CTYPE
 *       | ResponseLinterMiddleware::NO_BODY_STATUSES
 *       | ResponseLinterMiddleware::COMPRESSED_NEEDS_VARY
 *     );
 *
 *     // prod - keep only the cheap status-code guard
 *     new ResponseLinterMiddleware(ResponseLinterMiddleware::NO_BODY_STATUSES);
 */
final readonly class ResponseLinterMiddleware
{
    /** @var int bitmask of enabled checks */
    private int $checks;

    /*───────────────────────── flags ─────────────────────────*/
    public const BODY_REQUIRES_CTYPE = 0b001;
    public const NO_BODY_STATUSES = 0b010;
    public const COMPRESSED_NEEDS_VARY = 0b100;

    /**
     * @param int|bool $checks
     *        • int  – bitmask of flags
     *        • bool – kept for BC; `true` ⇒ all flags, `false` ⇒ none
     */
    public function __construct(int|bool $checks = false)
    {
        // bool → full-on/full-off for legacy signature
        $this->checks = \is_bool($checks)
            ? ($checks ? self::BODY_REQUIRES_CTYPE
                | self::NO_BODY_STATUSES
                | self::COMPRESSED_NEEDS_VARY
                : 0)
            : $checks;
    }

    public function __invoke(Request $req, Closure $next): Response
    {
        $resp = $next($req);
        if ($this->checks === 0) {
            return $resp;
        }

        $len = $this->bodyLength($resp);
        $this->assertContentTypeIfBody($resp, $len);
        $this->assertNoBodyOnStatuses($resp, $len);
        $this->assertVaryOnCompressed($resp);

        return $resp;
    }

    private function bodyLength(Response $r): int
    {
        $b = $r->getBody();
        $len = $b->getSize();
        if ($len !== null) {
            return $len;
        }
        if ($b->isSeekable()) {
            $pos = $b->tell();
            $len = $b->getSize() ?? strlen($b->getContents());
            $b->seek($pos);
            return $len;
        }
        return 0;
    }

    private function assertContentTypeIfBody(Response $r, int $len): void
    {
        if (($this->checks & self::BODY_REQUIRES_CTYPE) !== 0 &&
            $len > 0 &&
            $r->getHeaderLine('Content-Type') === '') {
            throw new \RuntimeException('Linter: missing Content-Type header');
        }
    }

    private function assertNoBodyOnStatuses(Response $r, int $len): void
    {
        if (($this->checks & self::NO_BODY_STATUSES) === 0 || $len === 0) {
            return;
        }
        $code = $r->getStatusCode();
        if ($code === 204 || $code === 304) {
            throw new \RuntimeException("Linter: body not allowed on {$code}");
        }
    }

    private function assertVaryOnCompressed(Response $r): void
    {
        if (($this->checks & self::COMPRESSED_NEEDS_VARY) === 0) {
            return;
        }
        if ($r->hasHeader('Content-Encoding') &&
            stripos($r->getHeaderLine('Vary'), 'accept-encoding') === false) {
            throw new \RuntimeException('Linter: compressed but missing Vary: Accept-Encoding');
        }
    }
}
