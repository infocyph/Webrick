<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Psr7;

final class ServerRequestHeaderNormalizer
{
    /**
     * @param array<string, string|list<string>> $headers
     * @return array<string, string|list<string>>
     */
    public static function normalize(array $headers): array
    {
        $out = [];
        foreach ($headers as $key => $value) {
            if (is_string($value)) {
                $out[$key] = $value;

                continue;
            }

            $vals = [];
            foreach ($value as $headerValue) {
                $vals[] = $headerValue;
            }
            $out[$key] = $vals;
        }

        return $out;
    }
}
