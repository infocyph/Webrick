<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

final class CanonicalSegmentMatcher
{
    /**
     * @param array<string,mixed> $spec
     * @param array<string,string> $params
     */
    public static function matches(array $spec, string $piece, array &$params): bool
    {
        if (($spec['type'] ?? null) === 'lit') {
            return ($spec['val'] ?? null) === $piece;
        }

        $name = $spec['name'] ?? null;
        if (($spec['type'] ?? null) !== 'var' || !is_string($name)) {
            return false;
        }

        $matches = isset($spec['regex'])
            ? is_string($spec['regex']) && preg_match($spec['regex'], $piece) === 1
            : is_callable($spec['call'] ?? null) && ($spec['call'])($piece);
        if (!$matches) {
            return false;
        }

        $params[$name] = $piece;

        return true;
    }
}
