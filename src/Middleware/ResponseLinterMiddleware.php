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
    public const BODY_REQUIRES_CTYPE   = 0b001;
    public const NO_BODY_STATUSES      = 0b010;
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

        if ($this->checks === 0) {                 // short-circuit when disabled
            return $resp;
        }

        $code    = $resp->getStatusCode();
        $body    = $resp->getBody();
        $bodyLen = $body->getSize();

        if ($bodyLen === null && $body->isSeekable()) {
            $pos     = $body->tell();
            $bodyLen = $body->getSize() ?? strlen($body->getContents());
            $body->seek($pos);
        } elseif ($bodyLen === null) {
            $bodyLen = 0;                          // non-seekable & unknown
        }

        /* ① Content-Type required on non-empty bodies */
        if (
            ($this->checks & self::BODY_REQUIRES_CTYPE) !== 0 &&
            $bodyLen > 0 &&
            $resp->getHeaderLine('Content-Type') === ''
        ) {
            throw new RuntimeException('Linter: missing Content-Type header');
        }

        /* ② Body forbidden for 204 / 304 */
        if (
            ($this->checks & self::NO_BODY_STATUSES) !== 0 &&
            $bodyLen > 0 &&
            ($code === 204 || $code === 304)
        ) {
            throw new RuntimeException("Linter: body not allowed on {$code}");
        }

        /* ③ Compressed payloads must advertise Vary */
        if (
            ($this->checks & self::COMPRESSED_NEEDS_VARY) !== 0 &&
            $resp->hasHeader('Content-Encoding') &&
            stripos($resp->getHeaderLine('Vary'), 'accept-encoding') === false
        ) {
            throw new RuntimeException('Linter: compressed but missing Vary: Accept-Encoding');
        }

        return $resp;
    }
}
