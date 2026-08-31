<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Http;

/** Fast request content negotiator for Accept-family headers. */
final readonly class ContentNegotiator
{
    /** @var list<string> */
    private array $accept;

    /** @var list<string> */
    private array $charsets;

    /** @var list<string> */
    private array $encodings;

    /** @var list<string> */
    private array $languages;

    public function __construct(RequestHeaders $headers)
    {
        $accept = $headers->accept('Accept');
        $this->accept = self::lower($accept === [] ? ['*/*'] : $accept);
        $this->encodings = self::lower($headers->accept('Accept-Encoding'));
        $this->charsets = self::lower($headers->accept('Accept-Charset'));
        $this->languages = self::lower($headers->accept('Accept-Language'));
    }

    public function acceptsLatin1(): bool
    {
        return $this->supportsCharset('iso-8859-1');
    }

    public function acceptsUtf8(): bool
    {
        return $this->supportsCharset('utf-8');
    }

    /** @param list<string> $candidates */
    public function preferred(array $candidates): ?string
    {
        foreach ($this->accept as $accepted) {
            foreach ($candidates as $candidate) {
                if ($candidate === '') {
                    continue;
                }

                $normalized = strtolower($candidate);
                if (!$this->matches($normalized, $accepted)) {
                    continue;
                }

                // A +suffix candidate is shorthand for a concrete client media type.
                return $normalized[0] === '+' ? $accepted : $candidate;
            }
        }

        return null;
    }

    public function supportsCharset(string $charset): bool
    {
        $charset = strtolower($charset);

        return $this->charsets === []
            || in_array($charset, $this->charsets, true)
            || in_array('*', $this->charsets, true);
    }

    public function supportsEncoding(string $encoding): bool
    {
        $encoding = strtolower($encoding);

        return $this->encodings === []
            || in_array($encoding, $this->encodings, true)
            || in_array('*', $this->encodings, true);
    }

    public function supportsLanguage(string $language): bool
    {
        $language = strtolower($language);

        return $this->languages === []
            || in_array($language, $this->languages, true)
            || in_array('*', $this->languages, true);
    }

    public function wantsBrotli(): bool
    {
        return $this->supportsEncoding('br');
    }

    public function wantsGzip(): bool
    {
        return $this->supportsEncoding('gzip');
    }

    public function wantsZstd(): bool
    {
        return $this->supportsEncoding('zstd');
    }

    /** @param list<string> $values @return list<string> */
    private static function lower(array $values): array
    {
        return array_map(strtolower(...), $values);
    }

    private function matches(string $candidate, string $accepted): bool
    {
        if ($candidate[0] === '+') {
            return !str_contains($accepted, '*') && str_ends_with($accepted, $candidate);
        }

        return self::mimeMatch($candidate, $accepted);
    }

    private static function mimeMatch(string $candidate, string $accepted): bool
    {
        if ($candidate === $accepted || $accepted === '*/*') {
            return true;
        }

        $slash = strpos($accepted, '/');
        if ($slash === false) {
            return false;
        }

        $type = substr($accepted, 0, $slash);
        $subtype = substr($accepted, $slash + 1);
        if ($subtype === '*') {
            return str_starts_with($candidate, $type . '/');
        }

        if (!str_starts_with($subtype, '*+')) {
            return false;
        }

        $prefix = $type . '/';
        $suffix = substr($subtype, 1);

        return str_starts_with($candidate, $prefix)
            && str_ends_with($candidate, $suffix)
            && strlen($candidate) > strlen($prefix) + strlen($suffix);
    }
}
