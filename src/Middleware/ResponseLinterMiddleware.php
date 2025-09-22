<?php

/**
 * Webrick - Response linter middleware.
 *
 * Performs strict runtime checks to catch common HTTP response pitfalls during
 * development and testing. Controlled via bitmask flags or a single boolean.
 *
 * Recommended order (dev/test only):
 *   … → Compression → CorsAndPolicies → VaryAccumulator → ResponseLinter
 *
 * @package Infocyph\Webrick\Middleware
 */

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Support\StreamUtil;
use RuntimeException;

/**
 * Strict response validator for common HTTP footguns.
 *
 * Flags:
 * - BODY_REQUIRES_CTYPE: Non-empty body must have Content-Type header.
 * - NO_BODY_STATUSES: Status 204/304 must not include a response body.
 * - COMPRESSED_NEEDS_VARY: Content-Encoding implies Vary: Accept-Encoding.
 * - ETAG_WEAK_WHEN_ENCODING: Strong ETag is invalid when Content-Encoding is present.
 * - CONTENT_LENGTH_MATCH: Content-Length must match actual byte length (when knowable).
 */
final readonly class ResponseLinterMiddleware
{
    /** bit-flags */
    public const BODY_REQUIRES_CTYPE = 0b00001;
    public const COMPRESSED_NEEDS_VARY = 0b00100;       // Content-Encoding ⇒ Vary: Accept-Encoding
    public const CONTENT_LENGTH_MATCH = 0b10000;        // Content-Length must match actual bytes (when knowable)
    public const ETAG_WEAK_WHEN_ENCODING = 0b01000;     // Content-Encoding ⇒ ETag MUST be weak
    public const NO_BODY_STATUSES = 0b00010;            // 204/304 must have empty body

    /**
     * Enabled checks bitmask.
     *
     * @var int
     */
    private int $checks;

    /**
     * Configure which checks are active.
     *
     * When $checks is a boolean:
     * - true enables all checks
     * - false disables all checks
     *
     * @param int|bool $checks Bitmask of flags; true ⇒ all checks, false ⇒ none.
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

    /**
     * Run response checks after invoking the next handler.
     *
     * @param Request $req  Incoming request.
     * @param Closure $next Next handler.
     *
     * @return Response Possibly unmodified response; exceptions thrown on violations.
     *
     * @throws RuntimeException If any enabled check fails.
     */
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

    /**
     * Validate Content-Length against actual byte length (when knowable).
     *
     * Skips check when Transfer-Encoding is present or when length is missing/zero.
     *
     * @param Response $r   Response to inspect.
     * @param int      $len Actual body byte length.
     *
     * @return void
     *
     * @throws RuntimeException If Content-Length is numeric and mismatches $len.
     */
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
            throw new RuntimeException(
                sprintf(
                    'Linter: Content-Length (%d) does not match body bytes (%d)',
                    (int)$cl,
                    $len,
                ),
            );
        }
    }

    /* ───────────────────────── helpers ───────────────────────── */

    /**
     * Ensure non-empty bodies have a Content-Type header.
     *
     * @param Response $r   Response to inspect.
     * @param int      $len Body byte length.
     *
     * @return void
     *
     * @throws RuntimeException If len > 0 and Content-Type is missing.
     */
    private function assertContentTypeIfBody(Response $r, int $len): void
    {
        if ($len > 0 && $r->getHeaderLine('Content-Type') === '') {
            throw new RuntimeException('Linter: non-empty body without Content-Type');
        }
    }

    /**
     * Disallow bodies on 204/304 status codes.
     *
     * @param Response $r   Response to inspect.
     * @param int      $len Body byte length.
     *
     * @return void
     *
     * @throws RuntimeException If a body is present for 204 or 304 responses.
     */
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

    /**
     * Ensure compressed responses imply Vary: Accept-Encoding.
     *
     * @param Response $r Response to inspect.
     *
     * @return void
     *
     * @throws RuntimeException If Content-Encoding is set but Vary lacks Accept-Encoding.
     */
    private function assertVaryOnCompressed(Response $r): void
    {
        if (!$r->hasHeader('Content-Encoding')) {
            return;
        }
        if (!$this->lineHasToken($r->getHeaderLine('Vary'), 'accept-encoding')) {
            throw new RuntimeException('Linter: compressed reply missing Vary: Accept-Encoding');
        }
    }

    /**
     * Enforce weak ETag when Content-Encoding is present.
     *
     * @param Response $r Response to inspect.
     *
     * @return void
     *
     * @throws RuntimeException If a strong ETag is used with Content-Encoding.
     */
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

    /**
     * Case-insensitive membership check within a comma-separated header value.
     *
     * @param string $line         Raw header value (possibly CSV).
     * @param string $needleLower  Lower-cased token to search for.
     *
     * @return bool True if token is present; false otherwise.
     */
    private function lineHasToken(string $line, string $needleLower): bool
    {
        if ($line === '') {
            return false;
        }
        return array_any(explode(',', $line), fn ($tok) => \strtolower(trim($tok)) === $needleLower);
    }
}
