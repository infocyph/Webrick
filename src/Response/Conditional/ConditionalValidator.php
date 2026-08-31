<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Conditional;

use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Constants\StatusEnum;
use Infocyph\Webrick\Request\Request;

/**
 * Unified conditional-header evaluator (RFC 9110 §13).
 *
 * Handles If-Match, If-None-Match, If-Modified-Since,
 * If-Unmodified-Since and If-Range without performing IO.
 */
final readonly class ConditionalValidator
{
    private const int HTTP_NOT_MODIFIED = StatusEnum::NOT_MODIFIED->value;

    private const int HTTP_PRECONDITION = StatusEnum::PRECONDITION_FAILED->value;

    public function __construct(
        private ?string $etag = null,
        private ?int $lastModified = null,
    ) {}

    public function evaluate(Request $req): Outcome
    {
        $echo = $this->buildEchoHeaders();
        $ifMatchPresent = $req->getHeaderLine('If-Match') !== '';

        // RFC 9110 §13.2.2: If-Match takes precedence over If-Unmodified-Since.
        if ($this->failsIfMatch($req) || (!$ifMatchPresent && $this->failsIfUnmodSince($req))) {
            return new Outcome(Outcome::FAIL, self::HTTP_PRECONDITION, $echo);
        }

        // A matching If-None-Match is 304 for GET/HEAD and 412 for unsafe methods.
        if ($this->hitsIfNoneMatch($req)) {
            $method = HttpMethodEnum::normalize($req->getMethod());
            if ($method === HttpMethodEnum::GET->value || $method === HttpMethodEnum::HEAD->value) {
                return new Outcome(Outcome::HIT, self::HTTP_NOT_MODIFIED, $echo);
            }

            return new Outcome(Outcome::FAIL, self::HTTP_PRECONDITION, $echo);
        }

        // If-Modified-Since is evaluated only when If-None-Match is absent.
        if ($this->hitsIfModSince($req)) {
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
        if ($this->etag !== null && preg_match('/^(?:W\/)?"/', $ifRange) === 1) {
            return $this->etagEquals($this->etag, $ifRange, true);
        }
        $date = $this->parseDate($ifRange);

        return $date !== null && $this->lastModified !== null && $this->lastModified <= $date;
    }

    private static function strongEtagEquals(string $current, string $candidate): bool
    {
        return !str_starts_with($candidate, 'W/')
            && !str_starts_with($current, 'W/')
            && $candidate === $current;
    }

    private static function weakEtagEquals(string $current, string $candidate): bool
    {
        return self::withoutWeakPrefix($candidate) === self::withoutWeakPrefix($current);
    }

    private static function withoutWeakPrefix(string $etag): string
    {
        return str_starts_with($etag, 'W/') ? substr($etag, 2) : $etag;
    }

    /**
     * Reconstructs the metadata headers that were used to evaluate the preconditions.
     *
     * If the ETag or Last-Modified were set, adds them to the response.
     *
     * @return array<string, string> The metadata headers used to evaluate the preconditions
     */
    private function buildEchoHeaders(): array
    {
        /** @var array<string, string> $h */
        $h = [];
        if ($this->etag !== null) {
            $h['ETag'] = $this->etag;
        }
        if ($this->lastModified !== null) {
            $h['Last-Modified'] = gmdate('D, d M Y H:i:s', $this->lastModified) . ' GMT';
        }

        return $h;
    }

    /**
     * Compares a current ETag against a list of candidate ETags (RFC 9110 § 8.8.3).
     *
     * @param string $current The current ETag to compare against.
     * @param list<string>|string $candidates The list of candidate ETags to compare with.
     * @param bool $strong Whether to perform a strong comparison or a weak comparison.
     * @return bool True if the current ETag matches one of the candidate ETags, false otherwise.
     */
    private function etagEquals(string $current, array|string $candidates, bool $strong): bool
    {
        $tokens = is_array($candidates) ? $candidates : [$candidates];
        foreach ($tokens as $cand) {
            if ($cand === '*') {
                return true;
            }
            if ($strong ? self::strongEtagEquals($current, $cand) : self::weakEtagEquals($current, $cand)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Evaluate If-Match pre-condition.
     *
     * @param Request $req The request to evaluate preconditions against
     * @return bool Whether the request has a valid If-Match header
     *              and the resource does not match any of the candidates.
     */
    private function failsIfMatch(Request $req): bool
    {
        $candidates = $this->tokenize($req->getHeaderLine('If-Match'));
        if ($candidates === null) {
            return false;
        }
        if ($this->etag === null) {
            return true;
        }

        return !$this->etagEquals($this->etag, $candidates, true);
    }

    /**
     * Evaluate If-Unmodified-Since pre-condition.
     *
     * @param Request $req The request to evaluate preconditions against
     * @return bool Whether the request has a valid If-Unmodified-Since header
     *              and the resource has been modified since then.
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
     * @param Request $req
     */
    private function hitsIfModSince(Request $req): bool
    {
        $method = HttpMethodEnum::normalize($req->getMethod());
        if ($method !== HttpMethodEnum::GET->value && $method !== HttpMethodEnum::HEAD->value) {
            return false;
        }
        if ($req->getHeaderLine('If-None-Match') !== '') {
            return false;
        }
        if ($this->lastModified === null) {
            return false;
        }
        $since = $this->parseDate($req->getHeaderLine('If-Modified-Since'));

        return $since !== null && $this->lastModified <= $since;
    }

    /**
     * Check whether If-None-Match selects the current representation.
     *
     * Method-specific 304/412 behavior is decided by evaluate().
     * @param Request $req
     */
    private function hitsIfNoneMatch(Request $req): bool
    {
        $candidates = $this->tokenize($req->getHeaderLine('If-None-Match'));

        return $candidates !== null
            && $this->etag !== null
            && $this->etagEquals($this->etag, $candidates, false);
    }

    /**
     * Parses an HTTP date string into a Unix epoch.
     * @param string $httpDate
     */
    private function parseDate(string $httpDate): ?int
    {
        if ($httpDate === '') {
            return null;
        }

        $timestamp = strtotime($httpDate);

        return $timestamp === false ? null : $timestamp;
    }

    /**
     * @return list<string>|null
     * @param string $list
     */
    private function tokenize(string $list): ?array
    {
        return $list === '' ? null : array_map(trim(...), explode(',', $list));
    }
}
