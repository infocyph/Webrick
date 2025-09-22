<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Http;

/**
 * Fast, RFC-9110 compliant content negotiator.
 *
 * ```php
 * $neg = new ContentNegotiator($headers);               // RequestHeaders
 * $mime = $neg->preferred(['application/json', '+json']);
 * ```
 */
final class ContentNegotiator
{
    /** @var string[] quality-sorted by RequestHeaders */
    private array $accept;        // Accept
    private array $charsets;      // Accept-Charset
    private array $encodings;     // Accept-Encoding
    private array $languages;     // Accept-Language

    /**
     * Populate the content negotiator with data from the given RequestHeaders.
     *
     * @param RequestHeaders $hdr Request headers
     */
    public function __construct(RequestHeaders $hdr)
    {
        $m = $hdr->accept();          // map<string,string[]>
        $this->accept = $m['Accept'] ?? ['*/*'];
        $this->encodings = $m['Accept-Encoding'] ?? [];
        $this->charsets = $m['Accept-Charset'] ?? [];
        $this->languages = $m['Accept-Language'] ?? [];
    }

    /**
     * Return true if the client prefers to receive responses with ISO-8859-1 (Latin-1) encoding.
     * False otherwise.
     *
     * @return bool
     */
    public function acceptsLatin1(): bool
    {
        return $this->supportsCharset('iso-8859-1');
    }

    /**
     * Return true if the client prefers to receive responses with UTF-8 encoding.
     * False otherwise.
     *
     * @return bool
     */
    public function acceptsUtf8(): bool
    {
        return $this->supportsCharset('utf-8');
    }

    /**
     * Find the best match from a list of candidates.
     *
     * @param string[] $candidates
     * @return string|null
     */
    public function preferred(array $candidates): ?string
    {
        return array_find(
            $this->accept,
            fn ($have) => array_any($candidates, fn ($want) => $this->matches($want, $have)),
        );
    }

    /**
     * Return true if the character set $cs is supported by the client.
     * False if not supported.
     *
     * @param string $cs the character set to check, e.g. 'utf-8', 'iso-8859-1'
     * @return bool
     */
    public function supportsCharset(string $cs): bool
    {
        return $this->charsets === []
            || in_array($cs, $this->charsets, true)
            || in_array('*', $this->charsets, true);
    }

    /**
     * Return true if the encoding $enc is supported by the client.
     * False if not supported.
     *
     * @param string $enc the encoding to check, e.g. 'gzip', 'br', 'identity'
     * @return bool
     */
    public function supportsEncoding(string $enc): bool
    {
        return $this->encodings === []                      // header omitted
            || in_array($enc, $this->encodings, true)
            || in_array('*', $this->encodings, true);
    }

    /**
     * Return true if the language $lang is supported by the client.
     * False if not supported.
     *
     * @param string $lang the language to check, e.g. 'en', 'fr', 'bn-BD'
     * @return bool
     */
    public function supportsLanguage(string $lang): bool
    {
        return $this->languages === []
            || in_array($lang, $this->languages, true)
            || in_array('*', $this->languages, true);
    }

    /**
     * True if the client prefers to receive responses with Brotli encoding.
     * False otherwise.
     *
     * @return bool
     */
    public function wantsBrotli(): bool
    {
        return $this->supportsEncoding('br');
    }

    /**
     * True if the client prefers to receive responses with gzip encoding.
     * False otherwise.
     *
     * @return bool
     */
    public function wantsGzip(): bool
    {
        return $this->supportsEncoding('gzip');
    }

    /**
     * True if the client prefers to receive responses with Zstd encoding.
     * False otherwise.
     *
     * @return bool
     */
    public function wantsZstd(): bool
    {
        return $this->supportsEncoding('zstd');
    }

    /* ───── helpers ─────────────────────────────────────────────── */

    /**
     * Check if a client-preferred MIME-type matches a server-supported MIME-type.
     *
     * If the client's MIME-type starts with a '+', it is treated as a suffix match.
     * Otherwise, the MIME-type is matched according to the rules of RFC 9110.
     *
     * @param string $want the client's preferred MIME-type
     * @param string $have the server's supported MIME-type
     * @return bool whether the client supports the given MIME-type
     */
    private function matches(string $want, string $have): bool
    {
        return $want[0] === '+'
            ? str_ends_with($have, $want)
            : $this->mimeMatch($want, $have);
    }

    /**
     * MIME-type matching algorithm.
     *
     * Compares two MIME-types according to the rules of RFC 9110.
     * Returns true if the client supports the given MIME-type, false otherwise.
     *
     * @param string $want the client's preferred MIME-type
     * @param string $have the server's supported MIME-type
     * @return bool whether the client supports the given MIME-type
     */
    private function mimeMatch(string $want, string $have): bool
    {
        if ($want === $have) {
            return true;
        }

        // accept-side wildcards
        if ($have === '*/*') {
            return true;
        }
        if (preg_match('#^([^/]+)/\*$#', $have, $m)) {
            return str_starts_with($want, $m[1] . '/');
        }
        // accept-side vendor suffix: application/*+json
        if (preg_match('#^([^/]+)/\*?\+([^;]+)$#', $have, $m)) {
            [$type, $suffix] = [$m[1], $m[2]];
            return preg_match("#^{$type}/[^+]+\+{$suffix}$#", $want) === 1;
        }

        // server-side suffix shorthand: "+json"
        if ($want[0] === '+') {
            return str_ends_with($have, $want);
        }

        return false;
    }
}
