<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Conditional;

use Infocyph\Webrick\Request\Request;

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
    ) {
    }

    public function evaluate(Request $req): Outcome
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

    /**
     * Checks if the If-Range header is fresh.
     *
     * Returns true if the If-Range header is empty (i.e., not present) or if it matches
     * the ETag or Last-Modified of the resource and the resource has not been modified since then.
     *
     * @param Request $req The request to check the If-Range header of
     * @return bool Whether the If-Range header is fresh
     */
    public function isRangeFresh(Request $req): bool
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

    /**
     * Reconstructs the metadata headers that were used to evaluate the preconditions.
     *
     * If the ETag or Last-Modified were set, adds them to the response.
     *
     * @return array The metadata headers used to evaluate the preconditions
     */
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

    /**
     * Compares a current ETag against a list of candidate ETags (RFC 9110 § 8.8.3).
     *
     * @param string $current The current ETag to compare against.
     * @param array|string $candidates The list of candidate ETags to compare with.
     * @param bool $strong Whether to perform a strong comparison (exact match)
     *     or a weak comparison (ignoring W/ prefix and ignoring case).
     * @return bool True if the current ETag matches one of the candidate ETags, false otherwise.
     */
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

    /**
     * Evaluate If-Match pre-condition.
     *
     * @param Request $req The request to evaluate preconditions against
     * @return bool Whether the request has a valid If-Match header
     *     and the resource does not match any of the candidates.
     */
    private function failsIfMatch(Request $req): bool
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

    /**
     * Evaluate If-Unmodified-Since pre-condition.
     *
     * @param Request $req The request to evaluate preconditions against
     * @return bool Whether the request has a valid If-Unmodified-Since header
     *     and the resource has been modified since then.
     */
    private function failsIfUnmodSince(Request $req): bool
    {
        if ($this->lastModified === null) {
            return false;
        }
        $since = $this->parseDate($req->getHeaderLine('If-Unmodified-Since'));
        return $since !== null && $this->lastModified > $since;
    }

    /**
     * Check if the request has a valid If-Modified-Since header
     * and the resource has not been modified since then.
     *
     * Returns true if the request has a valid If-Modified-Since header
     * and the resource has not been modified since then, false otherwise.
     *
     * @param Request $req The request to evaluate.
     * @return bool Whether the request has a valid If-Modified-Since header
     *     and the resource has not been modified since then.
     */
    private function hitsIfModSince(Request $req): bool
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

    /**
     * Check if the request has a valid If-None-Match header
     * and the resource has the same ETag as one of the candidates.
     *
     * Returns true if the request has a valid If-None-Match header
     * and the resource has the same ETag as one of the candidates, false otherwise.
     *
     * @param Request $req The request to evaluate.
     * @return bool Whether the request has a valid If-None-Match header
     *     and the resource has the same ETag as one of the candidates.
     */
    private function hitsIfNoneMatch(Request $req): bool
    {
        if (!in_array($req->getMethod(), ['GET', 'HEAD'], true)) {
            return false;
        }
        $candidates = $this->tokenize($req->getHeaderLine('If-None-Match'));
        return $candidates !== null
            && $this->etag !== null
            && $this->etagEquals($this->etag, $candidates, false);
    }

    /**
     * Parses an HTTP date string (RFC 7231) into a Unix epoch.
     *
     * Returns null if the input string is empty or invalid.
     *
     * @param string $httpDate HTTP date string (e.g. "Fri, 12 Jan 2018 08:00:00 GMT")
     * @return int|null Unix epoch or null if invalid
     */
    private function parseDate(string $httpDate): ?int
    {
        return $httpDate === '' ? null : (strtotime($httpDate) ?: null);
    }

    /**
     * Tokenizes a comma-separated list of strings into an array of strings.
     *
     * If the list is empty, returns null.
     *
     * @param string $list The list of strings to tokenize.
     * @return array|null The tokenized list of strings, or null if the list is empty.
     */
    private function tokenize(string $list): ?array
    {
        return $list === '' ? null : array_map('trim', explode(',', $list));
    }
}
