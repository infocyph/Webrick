<?php

declare(strict_types=1);

/** Internal PCRE construction helpers for the compiled matcher IR. */
final class CompiledMatcherPatternCompiler
{
    /** @phpstan-type SegmentSpec array<string,mixed> */

    /** @param list<SegmentSpec> $segments */
    public static function isCompilable(array $segments): bool
    {
        foreach ($segments as $segment) {
            if (($segment['type'] ?? null) !== 'var') {
                continue;
            }
            $regex = $segment['regex'] ?? null;
            if (!is_string($regex) || !\Infocyph\Webrick\Router\Constraint\Registry::isCombinedPcreSafeSegmentRegex($regex)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<SegmentSpec> $segments
     * @return array{0:string,1:array<string,string>}
     */
    public static function namedPattern(array $segments, int $routeOrdinal): array
    {
        if ($segments === []) {
            return ['/*', []];
        }

        $parts = [];
        $params = [];
        $parameterOrdinal = 0;
        foreach ($segments as $segment) {
            $type = $segment['type'] ?? null;
            if ($type === 'lit') {
                $literal = $segment['val'] ?? null;
                if (!is_string($literal)) {
                    throw new \UnexpectedValueException('Compiled matcher literal is invalid.');
                }
                $parts[] = preg_quote($literal, '~');

                continue;
            }
            if ($type !== 'var') {
                throw new \UnexpectedValueException('Compiled matcher segment type is invalid.');
            }
            $regex = $segment['regex'] ?? null;
            $name = $segment['name'] ?? null;
            if (!is_string($regex) || !is_string($name) || $name === '') {
                throw new \LogicException('Non-PCRE matcher segment cannot enter the PCRE fast lane.');
            }
            $capture = 'w' . $routeOrdinal . 'p' . $parameterOrdinal++;
            $parts[] = '(?<' . $capture . '>' . self::segmentInner($regex) . ')';
            $params[$capture] = $name;
        }

        return ['/*' . implode('/', $parts) . '/*', $params];
    }

    /**
     * @param list<SegmentSpec> $segments
     * @return array{0:string,1:list<string>}
     */
    public static function positionalPattern(array $segments): array
    {
        if ($segments === []) {
            return ['/*', []];
        }

        $parts = [];
        $params = [];
        foreach ($segments as $segment) {
            $type = $segment['type'] ?? null;
            if ($type === 'lit') {
                $literal = $segment['val'] ?? null;
                if (!is_string($literal)) {
                    throw new \UnexpectedValueException('Compiled matcher literal is invalid.');
                }
                $parts[] = preg_quote($literal, '~');

                continue;
            }
            if ($type !== 'var') {
                throw new \UnexpectedValueException('Compiled matcher segment type is invalid.');
            }
            $regex = $segment['regex'] ?? null;
            $name = $segment['name'] ?? null;
            if (!is_string($regex) || !is_string($name) || $name === '') {
                throw new \LogicException('Non-PCRE matcher segment cannot enter the positional fast lane.');
            }
            $parts[] = '(' . self::nonCapturing(self::segmentInner($regex)) . ')';
            $params[] = $name;
        }

        return ['/*' . implode('/', $parts) . '/*', $params];
    }

    /** @param list<SegmentSpec> $segments */
    public static function predicate(array $segments): string
    {
        if ($segments === []) {
            return '~\\A/*\\z~D';
        }

        $parts = [];
        foreach ($segments as $segment) {
            $type = $segment['type'] ?? null;
            if ($type === 'lit') {
                $literal = $segment['val'] ?? null;
                if (!is_string($literal)) {
                    throw new \UnexpectedValueException('Compiled matcher literal is invalid.');
                }
                $parts[] = preg_quote($literal, '~');

                continue;
            }
            if ($type !== 'var' || !is_string($segment['regex'] ?? null)) {
                throw new \LogicException('Non-PCRE matcher segment cannot enter allowed-method fast dispatch.');
            }
            $parts[] = '(?:' . self::nonCapturing(self::segmentInner($segment['regex'])) . ')';
        }

        $regex = '~\\A/*' . implode('/', $parts) . '/*\\z~D';
        if (@preg_match($regex, '') === false) {
            throw new \UnexpectedValueException('Failed to compile allowed-method route predicate.');
        }

        return $regex;
    }

    private static function escapeDelimiter(string $pattern, string $delimiter): string
    {
        $out = '';
        $length = strlen($pattern);
        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];
            if ($char === $delimiter && self::isUnescaped($pattern, $i)) {
                $out .= '\\';
            }
            $out .= $char;
        }

        return $out;
    }

    private static function isUnescaped(string $pattern, int $offset): bool
    {
        $slashes = 0;
        for ($index = $offset - 1; $index >= 0 && $pattern[$index] === '\\'; $index--) {
            $slashes++;
        }

        return ($slashes % 2) === 0;
    }

    private static function nonCapturing(string $inner): string
    {
        $out = '';
        $escaped = false;
        $class = false;
        for ($i = 0, $length = strlen($inner); $i < $length; $i++) {
            $char = $inner[$i];
            if ($escaped) {
                $out .= $char;
                $escaped = false;

                continue;
            }
            if ($char === '\\') {
                $out .= $char;
                $escaped = true;

                continue;
            }
            if ($char === '[' || ($char === ']' && $class)) {
                $class = $char === '[';
                $out .= $char;

                continue;
            }
            $out .= !$class && $char === '(' && ($inner[$i + 1] ?? '') !== '?' ? '(?:' : $char;
        }

        return $out;
    }

    private static function segmentInner(string $regex): string
    {
        if (!str_starts_with($regex, '#\\A') || !str_ends_with($regex, '\\z#D')) {
            throw new \UnexpectedValueException('Compiled matcher segment regex has an unsupported form.');
        }
        $inner = substr($regex, 3, -4);
        if ($inner === '') {
            throw new \UnexpectedValueException('Compiled matcher segment regex cannot be empty.');
        }

        return self::escapeDelimiter($inner, '~');
    }
}
