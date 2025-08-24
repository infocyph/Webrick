<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Url;

final class Signature
{
    public static function make(string $payload, string $key): string
    {
        return hash_hmac('sha3-256', $payload, $key);
    }

    public static function check(string $payload, string $sig, string $key): bool
    {
        return \hash_equals(self::make($payload, $key), $sig);
    }

    private function __construct()
    {
    }
}
