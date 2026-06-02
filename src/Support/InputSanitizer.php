<?php

/**
 * Webrick - Input sanitization utilities.
 *
 * Provides helpers to sanitize user-provided strings and nested arrays by trimming,
 * removing control and zero‑width characters, optionally collapsing whitespace,
 * normalizing Unicode, and enforcing byte limits.
 */

declare(strict_types=1);

namespace Infocyph\Webrick\Support;

/**
 * Sanitizes scalar string inputs as well as nested array payloads.
 *
 * Responsibilities:
 * - Remove problematic/invisible Unicode (NBSP, zero-width chars).
 * - Optionally normalize Unicode (NFKC) when available.
 * - Strip ASCII control characters, trim, and optionally collapse whitespace.
 * - Enforce an optional maximum byte length (UTF‑8 aware via mb_strcut when present).
 * - Allow excluding specific keys (exact or regex) when sanitizing arrays.
 */
final readonly class InputSanitizer
{
    /**
     * Configure the sanitizer behavior.
     *
     * @param bool $emptyToNull When true, empty strings become null (array mode only).
     * @param bool $collapseWs When true, collapse runs of spaces/tabs to a single space.
     * @param bool $normalizeUnicode When true and ext/intl is available, normalize to NFKC.
     * @param int|null $maxBytes Maximum allowed byte length (truncate if exceeded); null disables.
     * @param array<int,string> $skipKeys Exact keys to skip during array sanitization.
     * @param array<int,string> $skipKeyPatterns PCRE patterns; matching keys are skipped during array sanitization.
     */
    public function __construct(
        private bool $emptyToNull = true,
        private bool $collapseWs = false,
        private bool $normalizeUnicode = false,
        private ?int $maxBytes = null,
        private array $skipKeys = [],
        private array $skipKeyPatterns = [],
    ) {}

    /**
     * Recursively sanitize a (possibly nested) array payload.
     *
     * - Skips keys listed in $skipKeys or matched by $skipKeyPatterns.
     * - Converts empty sanitized strings to null when $emptyToNull is true.
     *
     * @param array<mixed> $data Input array to sanitize (modified copy is returned).
     * @return array<mixed> The sanitized array.
     */
    public function sanitizeArray(array $data): array
    {
        foreach ($data as $k => $v) {
            if ($this->shouldSkipKey((string) $k)) {
                continue;
            }
            if (is_array($v)) {
                $data[$k] = $this->sanitizeArray($v);

                continue;
            }
            if (is_string($v)) {
                $san = $this->sanitizeString($v);
                if ($this->emptyToNull && $san === '') {
                    $san = null;
                }
                $data[$k] = $san;
            }
        }

        return $data;
    }

    /**
     * Sanitize a single string by removing/control-normalizing whitespace and optionally truncating.
     *
     * Steps:
     * 1) Replace NBSP with regular space and remove zero-width characters.
     * 2) Optionally normalize Unicode to NFKC when ext/intl is available.
     * 3) Strip ASCII control chars, trim leading/trailing whitespace.
     * 4) Optionally collapse runs of space/tab to a single space.
     * 5) Optionally truncate to $maxBytes (UTF‑8 aware when mb_strcut exists).
     *
     * @param string $s Input string.
     * @return string The sanitized string.
     */
    public function sanitizeString(string $s): string
    {
        $s = str_replace("\u{00A0}", ' ', $s);                         // NBSP → space
        $s = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $s) ?? $s; // ZW chars

        if ($this->normalizeUnicode && function_exists('normalizer_normalize')) {
            $s = normalizer_normalize($s, \Normalizer::FORM_KC) ?: $s;
        }

        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s) ?? $s; // strip controls
        $s = preg_replace('/^\s+|\s+$/u', '', $s) ?? trim($s);                  // trim

        if ($this->collapseWs) {
            $s = preg_replace('/[ \t]+/u', ' ', $s) ?? $s;                      // collapse HT/space
        }

        if ($this->maxBytes !== null && $this->maxBytes > 0) {
            $s = function_exists('mb_strcut')
                ? mb_strcut($s, 0, $this->maxBytes, 'UTF-8')
                : substr($s, 0, $this->maxBytes);
        }

        return $s;
    }

    /**
     * Determine whether a key should be skipped during array sanitization.
     *
     * Matches when:
     * - The key is exactly in $skipKeys, or
     * - Any pattern in $skipKeyPatterns matches the key (PCRE; invalid patterns are ignored).
     *
     * @param string $key The array key to test.
     * @return bool True when the key should be skipped.
     */
    private function shouldSkipKey(string $key): bool
    {
        if (in_array($key, $this->skipKeys, true)) {
            return true;
        }

        return array_any($this->skipKeyPatterns, fn($rx) => $rx !== '' && preg_match($rx, $key) === 1);
    }
}
