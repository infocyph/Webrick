<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Conditional;

use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Constants\StatusEnum;
use Infocyph\Webrick\Request\Request;

/** Unified conditional-header evaluator (RFC 9110 §13). */
final readonly class ConditionalValidator
{
    private const int HTTP_NOT_MODIFIED = StatusEnum::NOT_MODIFIED->value;

    private const int HTTP_PRECONDITION = StatusEnum::PRECONDITION_FAILED->value;

    public function __construct(
        private ?string $etag = null,
        private ?int $lastModified = null,
        private ?bool $representationExists = null,
    ) {}

    public function evaluate(Request $req): Outcome
    {
        $echo = $this->buildEchoHeaders();
        $ifMatchPresent = $req->getHeaderLine('If-Match') !== '';

        if ($this->failsIfMatch($req) || (!$ifMatchPresent && $this->failsIfUnmodSince($req))) {
            return new Outcome(Outcome::FAIL, self::HTTP_PRECONDITION, $echo);
        }
        if ($this->hitsIfNoneMatch($req)) {
            $method = HttpMethodEnum::normalize($req->getMethod());

            return new Outcome(
                $method === HttpMethodEnum::GET->value || $method === HttpMethodEnum::HEAD->value ? Outcome::HIT : Outcome::FAIL,
                $method === HttpMethodEnum::GET->value || $method === HttpMethodEnum::HEAD->value ? self::HTTP_NOT_MODIFIED : self::HTTP_PRECONDITION,
                $echo,
            );
        }
        if ($this->hitsIfModSince($req)) {
            return new Outcome(Outcome::HIT, self::HTTP_NOT_MODIFIED, $echo);
        }

        return new Outcome(Outcome::PASS, 0, $echo);
    }

    public function isRangeFresh(Request $req): bool
    {
        $ifRange = trim($req->getHeaderLine('If-Range'));
        if ($ifRange === '') {
            return true;
        }
        if (preg_match('/^(?:W\/)?"/', $ifRange) === 1) {
            return $this->etag !== null && self::strongEtagEquals($this->etag, $ifRange);
        }
        $date = $this->parseDate($ifRange);

        return $date !== null && $this->lastModified !== null && $this->lastModified <= $date;
    }

    private static function strongEtagEquals(string $current, string $candidate): bool
    {
        return !str_starts_with($candidate, 'W/') && !str_starts_with($current, 'W/') && $candidate === $current;
    }

    private static function weakEtagEquals(string $current, string $candidate): bool
    {
        return self::withoutWeakPrefix($candidate) === self::withoutWeakPrefix($current);
    }

    private static function withoutWeakPrefix(string $etag): string
    {
        return str_starts_with($etag, 'W/') ? substr($etag, 2) : $etag;
    }

    /** @return array<string,string> */
    private function buildEchoHeaders(): array
    {
        $headers = [];
        if ($this->etag !== null) {
            $headers['ETag'] = $this->etag;
        }
        if ($this->lastModified !== null) {
            $headers['Last-Modified'] = gmdate('D, d M Y H:i:s', $this->lastModified) . ' GMT';
        }

        return $headers;
    }

    /** @param list<string>|string $candidates */
    private function etagEquals(string $current, array|string $candidates, bool $strong): bool
    {
        foreach (is_array($candidates) ? $candidates : [$candidates] as $candidate) {
            if ($candidate === '*') {
                return true;
            }
            if ($strong ? self::strongEtagEquals($current, $candidate) : self::weakEtagEquals($current, $candidate)) {
                return true;
            }
        }

        return false;
    }

    private function failsIfMatch(Request $req): bool
    {
        $candidates = $this->tokenize($req->getHeaderLine('If-Match'));
        if ($candidates === null) {
            return false;
        }
        if (in_array('*', $candidates, true)) {
            return $this->representationExists() !== true;
        }

        return $this->etag === null || !$this->etagEquals($this->etag, $candidates, true);
    }

    private function failsIfUnmodSince(Request $req): bool
    {
        if ($this->lastModified === null) {
            return false;
        }
        $since = $this->parseDate($req->getHeaderLine('If-Unmodified-Since'));

        return $since !== null && $this->lastModified > $since;
    }

    private function hitsIfModSince(Request $req): bool
    {
        $method = HttpMethodEnum::normalize($req->getMethod());
        if (($method !== HttpMethodEnum::GET->value && $method !== HttpMethodEnum::HEAD->value)
            || $req->getHeaderLine('If-None-Match') !== ''
            || $this->lastModified === null
        ) {
            return false;
        }
        $since = $this->parseDate($req->getHeaderLine('If-Modified-Since'));

        return $since !== null && $this->lastModified <= $since;
    }

    private function hitsIfNoneMatch(Request $req): bool
    {
        $candidates = $this->tokenize($req->getHeaderLine('If-None-Match'));
        if ($candidates === null) {
            return false;
        }
        if (in_array('*', $candidates, true)) {
            return $this->representationExists() === true;
        }

        return $this->etag !== null && $this->etagEquals($this->etag, $candidates, false);
    }

    private function parseDate(string $httpDate): ?int
    {
        if ($httpDate === '') {
            return null;
        }
        $timestamp = strtotime($httpDate);

        return $timestamp === false ? null : $timestamp;
    }

    private function representationExists(): ?bool
    {
        if ($this->representationExists !== null) {
            return $this->representationExists;
        }

        return $this->etag !== null || $this->lastModified !== null ? true : null;
    }

    /** @return list<string>|null */
    private function tokenize(string $list): ?array
    {
        if ($list === '') {
            return null;
        }

        $tokens = [];
        $token = '';
        $quoted = false;
        $escaped = false;
        for ($i = 0, $length = strlen($list); $i < $length; $i++) {
            $char = $list[$i];
            if ($escaped) {
                $token .= $char;
                $escaped = false;

                continue;
            }
            if ($quoted && $char === '\\') {
                $token .= $char;
                $escaped = true;

                continue;
            }
            if ($char === '"') {
                $quoted = !$quoted;
                $token .= $char;

                continue;
            }
            if ($char === ',' && !$quoted) {
                if (($value = trim($token)) !== '') {
                    $tokens[] = $value;
                }
                $token = '';

                continue;
            }
            $token .= $char;
        }
        if (($value = trim($token)) !== '') {
            $tokens[] = $value;
        }

        return $tokens;
    }
}
