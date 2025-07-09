<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Contracts;

enum HttpMethod: string
{
    case GET = 'GET';
    case HEAD = 'HEAD';
    case POST = 'POST';
    case PUT = 'PUT';
    case PATCH = 'PATCH';
    case DELETE = 'DELETE';
    case OPTIONS = 'OPTIONS';

    /** Case-insensitive factory; throws on unknown method. */
    public static function fromString(string $verb): self
    {
        return self::from(strtoupper($verb));
    }

    public static function allowed(array $slots): array
    {
        $out = [];
        foreach (self::cases() as $v) {
            if ($slots[$v->value] !== null) {
                $out[] = $v->name;
            }
        }
        return $out;
    }
}
