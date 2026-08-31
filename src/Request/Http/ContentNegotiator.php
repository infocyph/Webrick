<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Http;

/** Fast request content negotiator for Accept-family headers with q=0 exclusions preserved. */
final readonly class ContentNegotiator
{
    /** @var list<array{value:string,q:float,specificity:int,order:int}> */
    private array $accept;

    /** @var list<array{value:string,q:float,specificity:int,order:int}> */
    private array $charsets;

    /** @var list<array{value:string,q:float,specificity:int,order:int}> */
    private array $encodings;

    /** @var list<array{value:string,q:float,specificity:int,order:int}> */
    private array $languages;

    public function __construct(RequestHeaders $headers)
    {
        $this->accept = self::parse($headers->raw('Accept'), '*/*');
        $this->encodings = self::parse($headers->raw('Accept-Encoding'));
        $this->charsets = self::parse($headers->raw('Accept-Charset'));
        $this->languages = self::parse($headers->raw('Accept-Language'));
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
        $best = null;
        $bestQ = -1.0;
        $bestIndex = PHP_INT_MAX;

        foreach ($candidates as $index => $candidate) {
            if ($candidate === '') {
                continue;
            }
            $normalized = strtolower($candidate);
            if ($normalized[0] === '+') {
                $suffix = $normalized;
                foreach ($this->accept as $entry) {
                    if ($entry['q'] <= 0.0 || str_contains($entry['value'], '*') || !str_ends_with($entry['value'], $suffix)) {
                        continue;
                    }
                    if ($entry['q'] > $bestQ || ($entry['q'] === $bestQ && $index < $bestIndex)) {
                        $best = $entry['value'];
                        $bestQ = $entry['q'];
                        $bestIndex = $index;
                    }
                }

                continue;
            }

            $q = self::quality($normalized, $this->accept, true);
            if ($q <= 0.0) {
                continue;
            }
            if ($q > $bestQ || ($q === $bestQ && $index < $bestIndex)) {
                $best = $candidate;
                $bestQ = $q;
                $bestIndex = $index;
            }
        }

        return $best;
    }

    public function supportsCharset(string $charset): bool
    {
        return $this->charsets === [] || self::quality(strtolower($charset), $this->charsets, false) > 0.0;
    }

    public function supportsEncoding(string $encoding): bool
    {
        return $this->encodings === [] || self::quality(strtolower($encoding), $this->encodings, false) > 0.0;
    }

    public function supportsLanguage(string $language): bool
    {
        return $this->languages === [] || self::quality(strtolower($language), $this->languages, false) > 0.0;
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

    /**
     * @return list<array{value:string,q:float,specificity:int,order:int}>
     */
    private static function parse(string $raw, ?string $default = null): array
    {
        if ($raw === '') {
            return $default === null ? [] : [['value' => $default, 'q' => 1.0, 'specificity' => 0, 'order' => 0]];
        }

        $entries = [];
        foreach (explode(',', $raw) as $order => $segment) {
            $parts = array_map(trim(...), explode(';', $segment));
            $value = strtolower(array_shift($parts));
            if ($value === '') {
                continue;
            }
            $q = 1.0;
            foreach ($parts as $param) {
                if (preg_match('/^q=([01](?:\.\d{0,3})?)$/i', $param, $matches) === 1) {
                    $q = max(0.0, min(1.0, (float) $matches[1]));

                    break;
                }
            }
            $entries[] = [
                'value' => $value,
                'q' => $q,
                'specificity' => self::specificity($value),
                'order' => $order,
            ];
        }

        return $entries;
    }

    /**
     * @param list<array{value:string,q:float,specificity:int,order:int}> $entries
     */
    private static function quality(string $candidate, array $entries, bool $mediaType): float
    {
        $best = null;
        foreach ($entries as $entry) {
            $matches = $mediaType
                ? self::mimeMatch($candidate, $entry['value'])
                : self::tokenMatch($candidate, $entry['value']);
            if (!$matches) {
                continue;
            }
            if (
                $best === null
                || $entry['specificity'] > $best['specificity']
                || ($entry['specificity'] === $best['specificity'] && $entry['order'] < $best['order'])
            ) {
                $best = $entry;
            }
        }

        return $best['q'] ?? 0.0;
    }

    private static function specificity(string $value): int
    {
        if ($value === '*' || $value === '*/*') {
            return 0;
        }
        if (str_contains($value, '*')) {
            return 1;
        }

        return 2;
    }

    private static function tokenMatch(string $candidate, string $accepted): bool
    {
        if ($accepted === '*' || $candidate === $accepted) {
            return true;
        }

        return str_contains($candidate, '-') && str_starts_with($candidate, $accepted . '-');
    }
}
