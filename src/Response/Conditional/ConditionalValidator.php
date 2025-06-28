<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Conditional;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Unified conditional-header evaluator  (RFC 9110 §13).
 *
 * – Handles    If-Match, If-None-Match, If-Modified-Since,
 *              If-Unmodified-Since and If-Range.
 * – No IO; it only inspects request headers against metadata
 *   you provide (ETag / last-modified Unix-epoch).
 */
final class ConditionalValidator
{
    /* result codes */
    private const int HTTP_NOT_MODIFIED = 304;
    private const int HTTP_PRECONDITION = 412;

    public function __construct(
        private readonly ?string $etag = null,
        private readonly ?int $lastModified = null,
    ) {}

    public function evaluate(ServerRequestInterface $req): Outcome
    {
        $echo = $this->buildEchoHeaders();

        if ($this->failsIfMatch($req) || $this->failsIfUnmodSince($req)) {
            return new Outcome(Outcome::FAIL, self::HTTP_PRECONDITION, $echo);
        }
        if ($this->hitsIfNoneMatch($req) || $this->hitsIfModSince($req)) {
            return new Outcome(Outcome::HIT, self::HTTP_NOT_MODIFIED, $echo);
        }
        return new Outcome(Outcome::PASS, 0, $echo);
    }

    /* -------------------------------------------------- public helper */

    /** For Range responder – is Range still fresh?  */
    public function isRangeFresh(ServerRequestInterface $req): bool
    {
        $ifRange = trim($req->getHeaderLine('If-Range'));
        if ($ifRange === '') {
            return true;
        }

        // If-Range can be an ETag or HTTP-date
        if ($this->etag && preg_match('/^W?"/', $ifRange)) {
            return $this->etagEquals($this->etag, $ifRange, true);
        }
        $date = $this->parseDate($ifRange);
        return $date !== null && $this->lastModified !== null && $this->lastModified <= $date;
    }

    /* ================================================================
       Private helpers
       ================================================================*/

    private function buildEchoHeaders(): array
    {
        $h = [];
        if ($this->etag) {
            $h['ETag'] = $this->etag;
        }
        if ($this->lastModified) {
            $h['Last-Modified'] = gmdate('D, d M Y H:i:s', $this->lastModified) . ' GMT';
        }
        return $h;
    }

    /* --- If-Match ---------------------------------------------------- */
    private function failsIfMatch(ServerRequestInterface $req): bool
    {
        $candidates = $this->tokenize($req->getHeaderLine('If-Match'));
        if ($candidates === null) {
            return false;
        }
        if ($this->etag === null) {
            return true;
        } // no current tag ⇒ fail
        return !$this->etagEquals($this->etag, $candidates, true);
    }

    /* --- If-Unmodified-Since ---------------------------------------- */
    private function failsIfUnmodSince(ServerRequestInterface $req): bool
    {
        if ($this->lastModified === null) {
            return false;
        }
        $since = $this->parseDate($req->getHeaderLine('If-Unmodified-Since'));
        return $since !== null && $this->lastModified > $since;
    }

    /* --- If-None-Match ---------------------------------------------- */
    private function hitsIfNoneMatch(ServerRequestInterface $req): bool
    {
        if (!in_array($req->getMethod(), ['GET', 'HEAD'], true)) {
            return false;
        }
        $candidates = $this->tokenize($req->getHeaderLine('If-None-Match'));
        return $candidates !== null
            && $this->etag !== null
            && $this->etagEquals($this->etag, $candidates, false);
    }

    /* --- If-Modified-Since ------------------------------------------ */
    private function hitsIfModSince(ServerRequestInterface $req): bool
    {
        if (!in_array($req->getMethod(), ['GET', 'HEAD'], true)) {
            return false;
        }
        if ($req->getHeaderLine('If-None-Match') !== '') {
            return false; // IMS ignored when INM present
        }
        if ($this->lastModified === null) {
            return false;
        }
        $since = $this->parseDate($req->getHeaderLine('If-Modified-Since'));
        return $since !== null && $this->lastModified <= $since;
    }

    /* ---- token / date / comparison utils --------------------------- */
    private function tokenize(string $list): ?array
    {
        return $list === '' ? null : array_map('trim', explode(',', $list));
    }

    /** strong or weak ETag comparison (RFC 9110 § 8.8.3) */
    private function etagEquals(string $current, array|string $candidates, bool $strong): bool
    {
        if ($candidates === '*') {
            return true;
        }
        $candidates = (array)$candidates;
        foreach ($candidates as $cand) {
            if ($strong) {
                if ($cand === $current) {
                    return true;
                }
            } else {
                if (ltrim($cand, 'W/') === ltrim($current, 'W/')) {
                    return true;               // weak match allowed
                }
            }
        }
        return false;
    }

    private function parseDate(string $httpDate): ?int
    {
        return $httpDate === '' ? null : (strtotime($httpDate) ?: null);
    }
}
