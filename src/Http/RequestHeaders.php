<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Http;

use Infocyph\ArrayKit\Collection\Collection;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A "header bag" that parses advanced HTTP headers:
 *  - Accept (with q-values),
 *  - Content-* (type, charset, length),
 *  - Authorization or Basic auth details,
 *  - etc.
 *
 * This is effectively the "Headers" logic from WebFace, adapted
 * to read from a PSR-7 ServerRequestInterface instead of superglobals.
 */
final class RequestHeaders
{
    /**
     * A cached “Collection” of raw header key => value,
     * plus possibly inserted fields like "PHP_AUTH_USER" etc.
     */
    private ?Collection $headers = null;

    /** A cached “Collection” of parsed Accept headers. */
    private ?Collection $accept = null;

    /** A cached “Collection” for content headers. */
    private ?Collection $content = null;

    /** A cached “Collection” for dependency (If-Modified-Since, If-Match, Range, etc.). */
    private ?Collection $dependency = null;

    public function __construct(private readonly ServerRequestInterface $request)
    {
    }

    /**
     * Return a "raw" header array or a single item by key.
     * Optionally store extra fields like "PHP_AUTH_USER" if we want to emulate
     * the old logic.
     */
    public function all(): ?Collection
    {
        // If we've already built headers, just fetch the requested key.
        if ($this->headers !== null) {
            return $this->headers;
        }

        // Step 1: load server params from the PSR-7 request
        $server = $this->request->getServerParams();

        // Step 2: build an array of mapped headers from server, ignoring AUTH
        $headerVar = $this->buildHeadersFromServer($server);

        // Step 3: integrate possible auth fields (PHP_AUTH_USER, HTTP_AUTHORIZATION, etc.)
        $this->applyAuthorization($headerVar, $server);

        // Step 4: fetch & return
        return $this->headers = new Collection($headerVar);
    }

    /**
     * Build an array of "header => value" from server data,
     * focusing on HTTP_* and CONTENT_* keys.
     */
    private function buildHeadersFromServer(array $server): array
    {
        $filtered = array_filter($server, fn ($v, $k) => str_starts_with((string) $k, 'HTTP_')
            || in_array($k, ['CONTENT_TYPE','CONTENT_LENGTH','CONTENT_MD5'], true), ARRAY_FILTER_USE_BOTH);

        $results = [];
        foreach ($filtered as $name => $value) {
            $clean      = str_starts_with($name, 'HTTP_') ? substr($name, 5) : $name;
            $headerName = $this->formatServerHeaderName($clean);
            $results[$headerName] = $value;
        }
        return $results;
    }

    /**
     * Integrate "PHP_AUTH_USER"/"HTTP_AUTHORIZATION"/"REDIRECT_HTTP_AUTHORIZATION"
     * into the final $headerVar array. Then build "Authorization" if not present.
     */
    private function applyAuthorization(array &$headerVar, array &$server): void
    {
        // Step 1) If there's PHP_AUTH_USER, store them and skip the rest
        if ($this->applyPhpAuthUser($headerVar, $server)) {
            // if we returned true, we skip the next steps
            return;
        }

        // Step 2) If no PHP_AUTH_USER, check for HTTP_AUTHORIZATION or REDIRECT_HTTP_AUTHORIZATION
        $authHeader = $server['HTTP_AUTHORIZATION']
            ?? $server['REDIRECT_HTTP_AUTHORIZATION']
            ?? null;

        if ($authHeader !== null) {
            $this->applyAuthHeader($authHeader, $headerVar, $server);
        }

        // Step 3) If there's still no "Authorization", build one from "PHP_AUTH_USER"/"PHP_AUTH_DIGEST" if possible
        if (!isset($headerVar['Authorization'])) {
            $this->buildAuthorizationIfMissing($headerVar);
        }
    }

    /**
     * If PHP_AUTH_USER is set, store it (and optional PW) in $headerVar. Return true if we did so.
     */
    private function applyPhpAuthUser(array &$headerVar, array $server): bool
    {
        if (isset($server['PHP_AUTH_USER'])) {
            $headerVar['PHP_AUTH_USER'] = $server['PHP_AUTH_USER'];
            $headerVar['PHP_AUTH_PW']   = $server['PHP_AUTH_PW'] ?? '';
            return true;
        }
        return false;
    }

    /**
     * Parse the authorization header (basic/digest/bearer) and update $headerVar / $server accordingly.
     */
    private function applyAuthHeader(string $authHeader, array &$headerVar, array &$server): void
    {
        $prefix = strtolower(strtok($authHeader, ' '));
        match ($prefix) {
            'basic' => $this->applyBasic($authHeader, $headerVar),
            'digest' => $this->applyDigest($authHeader, $headerVar, $server),
            'bearer' => $headerVar['Authorization'] = $authHeader,
            default   => null
        };
    }

    /**
     * If we still don't have "Authorization" but do have "PHP_AUTH_USER" or "PHP_AUTH_DIGEST",
     * let's build an "Authorization" header from them.
     */
    private function buildAuthorizationIfMissing(array &$headerVar): void
    {
        if (isset($headerVar['PHP_AUTH_USER'])) {
            $user = $headerVar['PHP_AUTH_USER'];
            $pass = $headerVar['PHP_AUTH_PW'] ?? '';
            $headerVar['Authorization'] = 'Basic ' . base64_encode("$user:$pass");
        } elseif (isset($headerVar['PHP_AUTH_DIGEST'])) {
            $headerVar['Authorization'] = $headerVar['PHP_AUTH_DIGEST'];
        }
    }

    // -----------------------------------------
    // Additional small helpers for each prefix
    // -----------------------------------------

    /**
     * If the prefix is "basic ...", decode it and store "PHP_AUTH_USER"/"PHP_AUTH_PW".
     */
    private function applyBasic(string $authHeader, array &$headerVar): void
    {
        $decoded = base64_decode(substr($authHeader, 6));
        if ($decoded !== false) {
            $exploded = explode(':', $decoded, 2);
            if (count($exploded) === 2) {
                [$headerVar['PHP_AUTH_USER'], $headerVar['PHP_AUTH_PW']] = $exploded;
            }
        }
    }

    /**
     * If the prefix is "digest ...", store it in $headerVar['PHP_AUTH_DIGEST']
     * if $server['PHP_AUTH_DIGEST'] is empty.
     */
    private function applyDigest(string $authHeader, array &$headerVar, array &$server): void
    {
        // If there's no existing "PHP_AUTH_DIGEST", store it
        if (empty($server['PHP_AUTH_DIGEST'])) {
            $headerVar['PHP_AUTH_DIGEST'] = $authHeader;
            // Also store it in $server if needed
            $server['PHP_AUTH_DIGEST']    = $authHeader;
        }
    }


    /**
     * Convert something like "CONTENT_TYPE" => "Content-Type",
     * or "ACCEPT_ENCODING" => "Accept-Encoding".
     */
    private function formatServerHeaderName(string $raw): string
    {
        $temp = str_replace('_', '-', $raw);
        return ucwords(strtolower($temp), '-');
    }


    /**
     * Return a parsed version of the Accept headers (Accept, Accept-Charset, etc.).
     * Old code used parseAcceptHeader. We'll replicate that here in a simpler approach.
     */
    public function accept(?string $key = null): ?Collection
    {
        if ($this->accept === null) {
            $parsed = [];

            foreach (['Accept', 'Accept-Charset', 'Accept-Encoding', 'Accept-Language'] as $acceptName) {
                $rawLine = $this->request->getHeaderLine($acceptName);
                if ($rawLine !== '') {
                    $parsed[$acceptName] = $this->parseAcceptHeader($rawLine);
                }
            }
            $this->accept = new Collection($parsed);
        }

        if ($key === null) {
            return $this->accept;
        }
        return isset($this->accept[$key]) ? new Collection($this->accept[$key]) : null;
    }

    /**
     * Parse content headers (Content-Type, Content-Length, etc.).
     */
    public function content(): Collection
    {
        if ($this->content !== null) {
            return $this->content;
        }

        // 1) Get Content-Type header
        $rawContentType = $this->request->getHeaderLine('Content-Type');
        $lowerCT  = strtolower($rawContentType);
        $parts    = explode(';', $lowerCT);
        $type     = array_shift($parts) ?? null;
        $charset  = null;

        foreach ($parts as $p) {
            $p = trim($p);
            if (str_starts_with($p, 'charset=')) {
                $charset = substr($p, 8);
                break;
            }
        }

        // 2) Content-Length (as integer), defaulting to 0 if missing
        $rawLength = $this->request->getHeaderLine('Content-Length');
        $length    = is_numeric($rawLength) ? (int) $rawLength : 0;

        // 3) Content-Md5
        $rawMd5 = $this->request->getHeaderLine('Content-Md5');
        $md5    = $rawMd5 !== '' ? strtolower($rawMd5) : '';

        $this->content = new Collection([
            'parts'   => $parts,
            'type'    => $type ?: null,
            'charset' => $charset,
            'length'  => $length,
            'md5'     => $md5
        ]);

        return $this->content;
    }

    /**
     * Similar to the old "responseDependency" concept,
     * parsing If-Modified-Since, If-Match, Range, etc.
     */
    public function dependency(?string $key = null): mixed
    {
        if ($this->dependency === null) {
            $this->all();
            $asset = [
                'if_match' => $this->splitComma($this->headers->get('If-Match') ?? ''),
                'if_none_match' => $this->splitComma($this->headers->get('If-None-Match') ?? ''),
                'if_modified_since' => $this->parseHttpDate($this->headers->get('If-Modified-Since')),
                'if_unmodified_since' => $this->parseHttpDate($this->headers->get('If-Unmodified-Since')),
                'range' => null,
                'prefer_safe' => (strtolower((string) $this->headers->get('Prefer')) === 'safe')
                    && ($this->request->getUri()->getScheme() === 'https'),
            ];

            // If we have "Range" header => parse "bytes=0-1023" or similar
            if ($this->headers->has('Range')) {
                $rawRange = str_replace(' ', '', (string)$this->headers->get('Range'));
                $parts = explode('=', $rawRange, 2);
                if (count($parts) === 2) {
                    $asset['range'] = [
                        'unit' => $parts[0],
                        'span' => explode(',', $parts[1])
                    ];
                }
            }

            $this->dependency = new Collection($asset);
        }

        if ($key === null) {
            return $this->dependency->all();
        }
        return $this->dependency->get($key);
    }

    // ---------------------------------------------------------
    // Internal Helper: parse an Accept-like header with q=0.9, etc.
    // We do a simplified approach
    // ---------------------------------------------------------
    private function parseAcceptHeader(string $content): array
    {
        $prepared = [];
        $parts = explode(',', $content);
        $count = count($parts);
        foreach ($parts as $index => $part) {
            if (empty($part)) {
                continue;
            }
            $params = explode(';', $part);
            $asset = trim(current($params));
            $prepared[$index] = [
                'sort' => $count - $index,
                'accept' => $asset,
                'wild' => $this->compareWildcard(explode('/', $asset)),
                'q' => 1.0
            ];
            while (next($params)) {
                [$name, $value] = explode('=', current($params));
                $prepared[$index][trim($name)] = trim($value);
            }
        }
        usort(
            $prepared,
            fn ($a, $b) => [$b['q'], $b['wild'], $b['sort']] <=> [$a['q'], $a['wild'], $a['sort']]
        );
        return array_column($prepared, 'accept');
    }

    private function compareWildcard($types): bool|int
    {
        return count($types) === 1 ? 0 : ($types[0] === '*') - ($types[1] === '*');
    }

    /**
     * Convert a string with commas into an array, ignoring whitespace
     */
    private function splitComma(string $val): array
    {
        if (trim($val) === '') {
            return [];
        }
        return preg_split('/\s*,\s*/', $val, 0, PREG_SPLIT_NO_EMPTY);
    }

    /**
     * Parse an HTTP-date header field into a Unix timestamp, or null if invalid.
     */
    private function parseHttpDate(?string $val): ?int
    {
        if (empty($val)) {
            return null;
        }
        return strtotime($val) ?: null;
    }

}
