<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Http;

/** Fast request content negotiator for Accept-family headers with q=0 exclusions preserved. */
final readonly class ContentNegotiator
{
    private const string MATCH_LANGUAGE = 'language';

    private const string MATCH_MEDIA = 'media';

    private const string MATCH_TOKEN = 'token';

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
            $selection = $this->candidateSelection($candidate);
            if ($selection === null || $selection['q'] <= 0.0) {
                continue;
            }
            if ($selection['q'] > $bestQ || ($selection['q'] === $bestQ && $index < $bestIndex)) {
                $best = $selection['value'];
                $bestQ = $selection['q'];
                $bestIndex = $index;
            }
        }

        return $best;
    }

    public function supportsCharset(string $charset): bool
    {
        return $this->charsets === [] || self::quality(strtolower($charset), $this->charsets, self::MATCH_TOKEN) > 0.0;
    }

    public function supportsEncoding(string $encoding): bool
    {
        return $this->encodings === [] || self::quality(strtolower($encoding), $this->encodings, self::MATCH_TOKEN) > 0.0;
    }

    public function supportsLanguage(string $language): bool
    {
        return $this->languages === [] || self::quality(strtolower($language), $this->languages, self::MATCH_LANGUAGE) > 0.0;
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

    private static function languageMatch(string $candidate, string $accepted): bool
    {
        return $accepted === '*'
            || $candidate === $accepted
            || str_starts_with($candidate, $accepted . '-');
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

    /** @return list<array{value:string,q:float,specificity:int,order:int}> */
    private static function parse(string $raw, ?string $default = null): array
    {
        if ($raw === '') {
            return $default === null ? [] : [['value' => $default, 'q' => 1.0, 'specificity' => 0, 'order' => 0]];
        }

        $entries = [];
        foreach (self::splitCsv($raw) as $order => $segment) {
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

    /** @param list<array{value:string,q:float,specificity:int,order:int}> $entries */
    private static function quality(string $candidate, array $entries, string $mode): float
    {
        $best = null;
        foreach ($entries as $entry) {
            $matches = match ($mode) {
                self::MATCH_MEDIA => self::mimeMatch($candidate, $entry['value']),
                self::MATCH_LANGUAGE => self::languageMatch($candidate, $entry['value']),
                default => self::tokenMatch($candidate, $entry['value']),
            };
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

        return str_contains($value, '*') ? 1 : 2;
    }

    /** @return list<string> */
    private static function splitCsv(string $raw): array
    {
        $out = [];
        $buffer = '';
        $quoted = false;
        $escaped = false;
        for ($i = 0, $length = strlen($raw); $i < $length; $i++) {
            $char = $raw[$i];
            if ($escaped) {
                $buffer .= $char;
                $escaped = false;

                continue;
            }
            if ($quoted && $char === '\\') {
                $buffer .= $char;
                $escaped = true;

                continue;
            }
            if ($char === '"') {
                $quoted = !$quoted;
                $buffer .= $char;

                continue;
            }
            if ($char === ',' && !$quoted) {
                if (trim($buffer) !== '') {
                    $out[] = trim($buffer);
                }
                $buffer = '';

                continue;
            }
            $buffer .= $char;
        }
        if (trim($buffer) !== '') {
            $out[] = trim($buffer);
        }

        return $out;
    }

    private static function tokenMatch(string $candidate, string $accepted): bool
    {
        return $accepted === '*' || $candidate === $accepted;
    }

    /** @return array{value:string,q:float}|null */
    private function candidateSelection(string $candidate): ?array
    {
        $normalized = strtolower($candidate);
        if (!str_starts_with($normalized, '+')) {
            return ['value' => $candidate, 'q' => self::quality($normalized, $this->accept, self::MATCH_MEDIA)];
        }

        return $this->suffixSelection($normalized);
    }

    /** @return array{value:string,q:float}|null */
    private function suffixSelection(string $suffix): ?array
    {
        $best = null;
        foreach ($this->accept as $entry) {
            if ($entry['q'] <= 0.0 || str_contains($entry['value'], '*') || !str_ends_with($entry['value'], $suffix)) {
                continue;
            }
            if ($best === null || $entry['q'] > $best['q']) {
                $best = ['value' => $entry['value'], 'q' => $entry['q']];
            }
        }

        return $best;
    }
}
