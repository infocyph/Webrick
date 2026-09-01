<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Headers;

/** Tokenizes Cache-Control lists without splitting quoted field-name values. */
final class CacheControlCsv
{
    /** @return list<string> */
    public static function split(string $line): array
    {
        if ($line === '') {
            return [];
        }

        $tokens = [];
        $buffer = '';
        $quoted = false;
        $escaped = false;
        $length = strlen($line);
        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];
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
                self::appendToken($tokens, $buffer);
                $buffer = '';

                continue;
            }
            $buffer .= $char;
        }
        self::appendToken($tokens, $buffer);

        return $tokens;
    }

    /** @param list<string> $tokens */
    private static function appendToken(array &$tokens, string $buffer): void
    {
        $token = trim($buffer);
        if ($token !== '') {
            $tokens[] = $token;
        }
    }
}
