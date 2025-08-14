<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Support\StreamUtil;
use RuntimeException;

/**
 * ResponseLinterMiddleware
 *
 * Strict runtime checks for common HTTP response footguns.
 *
 * Recommended order (dev/test only):
 *   … → Compression → CorsAndPolicies → VaryAccumulator → ResponseLinter
 */
final readonly class ResponseLinterMiddleware
{
    /** bit-flags */
    public const BODY_REQUIRES_CTYPE     = 0b00001;
    public const NO_BODY_STATUSES        = 0b00010;  // 204/304 must have empty body
    public const COMPRESSED_NEEDS_VARY   = 0b00100;  // Content-Encoding ⇒ Vary: Accept-Encoding
    public const ETAG_WEAK_WHEN_ENCODING = 0b01000;  // Content-Encoding ⇒ ETag MUST be weak
    public const CONTENT_LENGTH_MATCH    = 0b10000;  // Content-Length must match actual bytes (when knowable)

    private int $checks;

    /**
     * @param int|bool $checks bitmask of flags; true ⇒ all checks, false ⇒ none
     */
    public function __construct(int|bool $checks = false)
    {
        $this->checks = \is_bool($checks)
            ? ($checks ? (
                self::BODY_REQUIRES_CTYPE
                | self::NO_BODY_STATUSES
                | self::COMPRESSED_NEEDS_VARY
                | self::ETAG_WEAK_WHEN_ENCODING
                | self::CONTENT_LENGTH_MATCH
            ) : 0)
            : $checks;
    }

    public function __invoke(Request $req, Closure $next): Response
    {
        $resp = $next($req);
        if ($this->checks === 0) {
            return $resp;
        }

        $len = StreamUtil::byteLength($resp->getBody(), 0);

        // 1) non-empty body requires Content-Type
        if (($this->checks & self::BODY_REQUIRES_CTYPE) !== 0) {
            $this->assertContentTypeIfBody($resp, $len);
        }

        // 2) 204/304 must not have a body
        if (($this->checks & self::NO_BODY_STATUSES) !== 0) {
            $this->assertNoBodyOnStatuses($resp, $len);
        }

        // 3) compressed replies must declare Vary: Accept-Encoding
        if (($this->checks & self::COMPRESSED_NEEDS_VARY) !== 0) {
            $this->assertVaryOnCompressed($resp);
        }

        // 4) when octets are transformed (Content-Encoding), strong ETag is illegal
        if (($this->checks & self::ETAG_WEAK_WHEN_ENCODING) !== 0) {
            $this->assertWeakEtagWhenEncoded($resp);
        }

        // 5) Content-Length (if present) must match actual bytes when knowable
        if (($this->checks & self::CONTENT_LENGTH_MATCH) !== 0) {
            $this->assertContentLengthMatches($resp, $len);
        }

        return $resp;
    }

    /* ───────────────────────── helpers ───────────────────────── */

    private function assertContentTypeIfBody(Response $r, int $len): void
    {
        if ($len > 0 && $r->getHeaderLine('Content-Type') === '') {
            throw new RuntimeException('Linter: non-empty body without Content-Type');
        }
    }

    private function assertNoBodyOnStatuses(Response $r, int $len): void
    {
        if ($len === 0) {
            return;
        }
        $code = $r->getStatusCode();
        if ($code === 204 || $code === 304) {
            throw new RuntimeException("Linter: body not allowed on {$code}");
        }
    }

    private function assertVaryOnCompressed(Response $r): void
    {
        if (!$r->hasHeader('Content-Encoding')) {
            return;
        }
        if (!$this->lineHasToken($r->getHeaderLine('Vary'), 'accept-encoding')) {
            throw new RuntimeException('Linter: compressed reply missing Vary: Accept-Encoding');
        }
    }

    private function assertWeakEtagWhenEncoded(Response $r): void
    {
        if (!$r->hasHeader('Content-Encoding') || !$r->hasHeader('ETag')) {
            return;
        }
        $etag = trim($r->getHeaderLine('ETag'));
        if ($etag !== '' && !\str_starts_with($etag, 'W/')) {
            throw new RuntimeException('Linter: strong ETag with Content-Encoding; make it weak (W/…).');
        }
    }

    private function assertContentLengthMatches(Response $r, int $len): void
    {
        // If TE is present, ignore (length is controlled by transfer-coding).
        if ($r->hasHeader('Transfer-Encoding')) {
            return;
        }
        $cl = trim($r->getHeaderLine('Content-Length'));
        if ($cl === '' || $len === 0) {
            return;
        }
        if (ctype_digit($cl) && (int)$cl !== $len) {
            throw new RuntimeException(sprintf(
                'Linter: Content-Length (%d) does not match body bytes (%d)',
                (int)$cl,
                $len
            ));
        }
    }

    /** Case-insensitive list-membership check for comma-separated header values. */
    private function lineHasToken(string $line, string $needleLower): bool
    {
        if ($line === '') {
            return false;
        }
        foreach (explode(',', $line) as $tok) {
            if (\strtolower(trim($tok)) === $needleLower) {
                return true;
            }
        }
        return false;
    }
}
