<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Core;

final class ServerRequestHeaderNormalizer
{
    /**
     * @param array<string,string|list<string>> $headers
     * @return array<string,string|list<string>>
     */
    public static function normalize(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[$name] = is_string($value) ? $value : array_values($value);
        }

        return $normalized;
    }
}
