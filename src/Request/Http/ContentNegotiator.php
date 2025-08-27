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
    private array $encodings;     // Accept-Encoding
    private array $charsets;      // Accept-Charset
    private array $languages;     // Accept-Language

    public function __construct(RequestHeaders $hdr)
    {
        $m = $hdr->accept();          // map<string,string[]>
        $this->accept = $m['Accept'] ?? ['*/*'];
        $this->encodings = $m['Accept-Encoding'] ?? [];
        $this->charsets = $m['Accept-Charset'] ?? [];
        $this->languages = $m['Accept-Language'] ?? [];
    }

    /* -----------------------------------------------------------------
       1)  MIME-type negotiation
       ---------------------------------------------------------------- */

    /**
     * Return the **first client MIME** that matches one of the given
     * $candidates or `null` (⇒ 406 Not Acceptable).
     *
     *
     * @param string[] $candidates ordered by caller preference
     */
    public function preferred(array $candidates): ?string
    {
        return array_find(
            $this->accept,
            fn ($have) => array_any($candidates, fn ($want) => $this->matches($want, $have)),
        );
    }

    /* ───── helpers ─────────────────────────────────────────────── */

    private function matches(string $want, string $have): bool
    {
        return $want[0] === '+'
            ? str_ends_with($have, $want)
            : $this->mimeMatch($want, $have);
    }

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


    /* -----------------------------------------------------------------
       2)  Encoding / charset / language helpers
       ---------------------------------------------------------------- */

    public function supportsEncoding(string $enc): bool
    {
        return $this->encodings === []                      // header omitted
            || in_array($enc, $this->encodings, true)
            || in_array('*', $this->encodings, true);
    }

    public function supportsCharset(string $cs): bool
    {
        return $this->charsets === []
            || in_array($cs, $this->charsets, true)
            || in_array('*', $this->charsets, true);
    }

    public function supportsLanguage(string $lang): bool
    {
        return $this->languages === []
            || in_array($lang, $this->languages, true)
            || in_array('*', $this->languages, true);
    }

    /* convenient one-liners */

    public function wantsGzip(): bool
    {
        return $this->supportsEncoding('gzip');
    }

    public function wantsBrotli(): bool
    {
        return $this->supportsEncoding('br');
    }

    public function wantsZstd(): bool
    {
        return $this->supportsEncoding('zstd');
    }

    public function acceptsUtf8(): bool
    {
        return $this->supportsCharset('utf-8');
    }

    public function acceptsLatin1(): bool
    {
        return $this->supportsCharset('iso-8859-1');
    }
}
