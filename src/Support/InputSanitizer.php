<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Support;

final class InputSanitizer
{
    public function __construct(
        private bool $emptyToNull = true,
        private bool $collapseWs = false,
        private bool $normalizeUnicode = false,
        private ?int $maxBytes = null,
        private array $skipKeys = [],
        private array $skipKeyPatterns = [],
    ) {
    }

    public function sanitizeArray(array $data): array
    {
        foreach ($data as $k => $v) {
            if ($this->shouldSkipKey((string)$k)) {
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

    private function shouldSkipKey(string $key): bool
    {
        if (in_array($key, $this->skipKeys, true)) {
            return true;
        }
        return array_any($this->skipKeyPatterns, fn ($rx) => $rx !== '' && @preg_match($rx, $key) === 1);
    }
}
