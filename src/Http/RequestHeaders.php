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
    public function all(?string $key = null): mixed
    {
        // If we've already built headers, just fetch the requested key.
        if ($this->headers !== null) {
            return $key === null ? $this->headers->all() : $this->headers->get($key);
        }

        // Step 1: load server params from the PSR-7 request
        $server = $this->request->getServerParams();

        // Step 2: build an array of mapped headers from server, ignoring AUTH
        $headerVar = $this->buildHeadersFromServer($server);

        // Step 3: integrate possible auth fields (PHP_AUTH_USER, HTTP_AUTHORIZATION, etc.)
        $this->applyAuthorization($headerVar, $server);

        // Step 4: create the final Collection
        $this->headers = new Collection($headerVar);

        // Step 5: fetch & return
        return $key === null ? $this->headers->all() : $this->headers->get($key);
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
        // e.g. "Basic QWxhZGRpbjpvcGVuc2VzYW1l"
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
    public function accept(?string $key = null): mixed
    {
        if ($this->accept === null) {
            $this->all(); // ensure $this->headers is built
            $parsed = [];

            foreach (['Accept', 'Accept-Charset', 'Accept-Encoding', 'Accept-Language'] as $acceptName) {
                if ($this->headers->has($acceptName)) {
                    $raw = (string) $this->headers->get($acceptName);
                    $parsed[$acceptName] = $this->parseAcceptHeader($raw);
                }
            }
            $this->accept = new Collection($parsed);
        }

        if ($key === null) {
            return $this->accept->all();
        }
        return $this->accept->get($key);
    }

    /**
     * Parse content headers (Content-Type, Content-Length, etc.).
     */
    public function content(?string $key = null): mixed
    {
        if ($this->content === null) {
            $this->all(); // ensure $this->headers is built
            $contentType = strtolower((string) ($this->headers->get('Content-Type') ?? ''));
            // e.g. "application/json; charset=utf-8"
            $parts   = explode(';', $contentType);
            $type    = array_shift($parts); // e.g. "application/json"
            $charset = null;

            // parse for "charset=..."
            foreach ($parts as $p) {
                $p = trim($p);
                if (str_starts_with($p, 'charset=')) {
                    $charset = substr($p, 8);
                    break;
                }
            }

            $length = (int) ($this->headers->get('Content-Length') ?? 0);
            $md5    = strtolower((string) ($this->headers->get('Content-Md5') ?? ''));

            $this->content = new Collection([
                'parts'   => $parts,
                'type'    => $type === '' ? null : $type,
                'charset' => $charset,
                'length'  => $length,
                'md5'     => $md5
            ]);
        }

        if ($key === null) {
            return $this->content->all();
        }
        return $this->content->get($key);
    }

    /**
     * Similar to the old "responseDependency" concept,
     * parsing If-Modified-Since, If-Match, Range, etc.
     */
    public function dependency(?string $key = null): mixed
    {
        if ($this->dependency === null) {
            $this->all(); // ensure $this->headers is built
            $asset = [
                'if_match' => $this->splitComma($this->headers->get('If-Match') ?? ''),
                'if_none_match' => $this->splitComma($this->headers->get('If-None-Match') ?? ''),
                'if_modified_since' => $this->parseHttpDate($this->headers->get('If-Modified-Since')),
                'if_unmodified_since' => $this->parseHttpDate($this->headers->get('If-Unmodified-Since')),
                'range' => null,
                // we can check "Prefer: safe" if we want
                'prefer_safe' => (strtolower((string) $this->headers->get('Prefer')) === 'safe')
                    && ($this->request->getUri()->getScheme() === 'https'),
            ];

            // If we have "Range" header => parse "bytes=0-1023" or similar
            if ($this->headers->has('Range')) {
                $rawRange = str_replace(' ', '', (string)$this->headers->get('Range'));
                // e.g. "bytes=0-1024"
                $parts = explode('=', $rawRange, 2);
                if (count($parts) === 2) {
                    $asset['range'] = [
                        'unit' => $parts[0],
                        'span' => explode(',', $parts[1]) // possibly multiple
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
        $items = explode(',', $content);
        $parsed = [];

        foreach ($items as $index => $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $sub = explode(';', $part);
            $token = array_shift($sub);
            $token = trim($token);

            // default q=1
            $q = 1.0;
            foreach ($sub as $param) {
                $param = trim($param);
                if (str_starts_with($param, 'q=')) {
                    $maybeQ = (float)substr($param, 2);
                    if ($maybeQ > 0 && $maybeQ <= 1) {
                        $q = $maybeQ;
                    }
                }
            }
            $parsed[] = [
                'value' => $token,
                'q'     => $q,
                // we can store index so we can stable sort
                '_index' => $index
            ];
        }

        // sort desc by q, then asc by index
        usort($parsed, function ($a, $b) {
            // higher q => earlier
            if ($b['q'] <=> $a['q']) {
                return $b['q'] <=> $a['q'];
            }
            // tie => earlier index
            return $a['_index'] <=> $b['_index'];
        });

        // we can just return an array of values or the full structure
        return array_map(fn ($row) => $row['value'], $parsed);
    }

    /**
     * Convert a string with commas into an array, ignoring whitespace
     */
    private function splitComma(string $val): array
    {
        if (trim($val) === '') {
            return [];
        }
        return preg_split('/\s*,\s*/', $val);
    }

    /**
     * Parse an HTTP-date header field into a Unix timestamp, or null if invalid.
     */
    private function parseHttpDate(?string $val): ?int
    {
        if (empty($val)) {
            return null;
        }
        $ts = strtotime($val);
        return ($ts === false) ? null : $ts;
    }

}
