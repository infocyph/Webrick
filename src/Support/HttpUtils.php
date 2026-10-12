<?php

/**
 * Webrick - HTTP helper utilities.
 */

declare(strict_types=1);

namespace Infocyph\Webrick\Support;

use Infocyph\Webrick\Constants\MediaTypeEnum;

/** Small stateless HTTP grammar and content-type helpers. */
final class HttpUtils
{
    public static function baseMediaType(string $contentType): string
    {
        if ($contentType === '') {
            return '';
        }

        return strtolower(trim(explode(';', $contentType, 2)[0]));
    }

    public static function contentTypeCharset(string $contentType): ?string
    {
        if ($contentType === '') {
            return null;
        }
        if (preg_match('/(?:^|;)\s*charset\s*=\s*(?:"([^"]*)"|([^;\s]+))/i', $contentType, $matches) !== 1) {
            return null;
        }

        $value = $matches[1] !== '' ? $matches[1] : ($matches[2] ?? '');
        $value = trim($value);

        return $value === '' ? null : strtolower($value);
    }

    public static function isFormContentType(string $contentType): bool
    {
        $mime = self::baseMediaType($contentType);

        return $mime === MediaTypeEnum::FORM_URLENCODED->base()
            || $mime === MediaTypeEnum::MULTIPART_FORM_DATA->base();
    }

    public static function isJsonContentType(string $contentType): bool
    {
        $mime = self::baseMediaType($contentType);

        return $mime === MediaTypeEnum::JSON->base() || str_ends_with($mime, '+json');
    }

    public static function isXmlContentType(string $contentType): bool
    {
        $mime = self::baseMediaType($contentType);

        return $mime === MediaTypeEnum::XML->base()
            || $mime === 'text/xml'
            || str_ends_with($mime, '+xml');
    }

    /** @param list<string> $parameters */
    public static function parameterQuality(array $parameters): float
    {
        foreach ($parameters as $parameter) {
            if (preg_match('/^q\s*=\s*(.*)$/i', $parameter, $matches) === 1) {
                return self::parseQValue($matches[1]) ?? 0.0;
            }
        }

        return 1.0;
    }

    public static function parseHttpDate(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $timezone = new \DateTimeZone('GMT');
        foreach ([
            ['!D, d M Y H:i:s \G\M\T', 'D, d M Y H:i:s \G\M\T'],
            ['!l, d-M-y H:i:s \G\M\T', 'l, d-M-y H:i:s \G\M\T'],
        ] as [$inputFormat, $outputFormat]) {
            $date = \DateTimeImmutable::createFromFormat($inputFormat, $value, $timezone);
            $errors = \DateTimeImmutable::getLastErrors();
            if (
                $date instanceof \DateTimeImmutable
                && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
                && $date->format($outputFormat) === $value
            ) {
                return $date->getTimestamp();
            }
        }

        if (preg_match('/^([A-Z][a-z]{2}) ([A-Z][a-z]{2}) {1,2}([0-9]{1,2}) ([0-9]{2}:[0-9]{2}:[0-9]{2}) ([0-9]{4})$/D', $value, $matches) !== 1) {
            return null;
        }
        $normalized = sprintf('%s %s %02d %s %s', $matches[1], $matches[2], (int) $matches[3], $matches[4], $matches[5]);
        $date = \DateTimeImmutable::createFromFormat('!D M d H:i:s Y', $normalized, $timezone);
        $errors = \DateTimeImmutable::getLastErrors();
        if (
            !$date instanceof \DateTimeImmutable
            || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
            || $date->format('D M d H:i:s Y') !== $normalized
        ) {
            return null;
        }

        return $date->getTimestamp();
    }

    public static function parseQValue(string $value): ?float
    {
        $value = trim($value);
        if (preg_match('/^(?:0(?:\.\d{0,3})?|1(?:\.0{0,3})?)$/D', $value) !== 1) {
            return null;
        }

        return (float) $value;
    }

    public static function parseUnsignedDecimal(string $value): ?int
    {
        if ($value === '' || preg_match('/^[0-9]+$/D', $value) !== 1) {
            return null;
        }

        $digits = ltrim($value, '0');
        $parsed = filter_var($digits === '' ? '0' : $digits, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        return $parsed === false ? null : (int) $parsed;
    }

    /** @return list<string>|null Null means malformed quoted-string syntax or a rejected empty element. */
    public static function splitQuoted(string $raw, string $delimiter, bool $rejectEmpty = false): ?array
    {
        if (strlen($delimiter) !== 1) {
            throw new \InvalidArgumentException('HTTP list delimiter must be exactly one character.');
        }

        return str_contains($raw, '"')
            ? self::splitQuotedTokens($raw, $delimiter, $rejectEmpty)
            : self::splitUnquoted($raw, $delimiter, $rejectEmpty);
    }

    /** @param list<string> $parts */
    private static function appendListToken(array &$parts, string $buffer, bool $rejectEmpty): bool
    {
        $token = trim($buffer);
        if ($token === '') {
            return !$rejectEmpty;
        }
        $parts[] = $token;

        return true;
    }

    private static function appendQuotedCharacter(string &$buffer, bool &$quoted, bool &$escaped, string $char): bool
    {
        if ($escaped) {
            $buffer .= $char;
            $escaped = false;

            return true;
        }
        if ($quoted && $char === '\\') {
            $buffer .= $char;
            $escaped = true;

            return true;
        }
        if ($char !== '"') {
            return false;
        }

        $quoted = !$quoted;
        $buffer .= $char;

        return true;
    }

    /**
     * @param non-empty-string $delimiter
     * @return list<string>|null
     */
    private static function splitQuotedTokens(string $raw, string $delimiter, bool $rejectEmpty): ?array
    {
        $parts = [];
        $buffer = '';
        $quoted = false;
        $escaped = false;
        for ($i = 0, $length = strlen($raw); $i < $length; $i++) {
            $char = $raw[$i];
            if (self::appendQuotedCharacter($buffer, $quoted, $escaped, $char)) {
                continue;
            }
            if ($char === $delimiter && !$quoted) {
                if (!self::appendListToken($parts, $buffer, $rejectEmpty)) {
                    return null;
                }
                $buffer = '';

                continue;
            }
            $buffer .= $char;
        }

        if ($quoted || $escaped || !self::appendListToken($parts, $buffer, $rejectEmpty)) {
            return null;
        }

        return $parts;
    }

    /**
     * @param non-empty-string $delimiter
     * @return list<string>|null
     */
    private static function splitUnquoted(string $raw, string $delimiter, bool $rejectEmpty): ?array
    {
        $parts = [];
        foreach (explode($delimiter, $raw) as $token) {
            if (!self::appendListToken($parts, $token, $rejectEmpty)) {
                return null;
            }
        }

        return $parts;
    }
}
