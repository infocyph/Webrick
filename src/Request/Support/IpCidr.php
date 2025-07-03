<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Support;

final class IpCidr
{
    /** @var array<string,bool> */
    private static array $memo = [];

    public static function match(string $ip, string $cidr): bool
    {
        $key = $ip . '|' . $cidr;
        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }

        $result = str_contains($cidr, ':')
            ? self::v6($ip, $cidr)
            : self::v4($ip, $cidr);

        return self::$memo[$key] = $result;
    }

    private static function v4(string $ip, string $cidr): bool
    {
        [$subnet, $mask] = strpos($cidr, '/') ? explode('/', $cidr, 2) : [$cidr, 32];
        $mask = (int)$mask;
        return (ip2long($ip) & ~((1 << (32 - $mask)) - 1))
            === (ip2long($subnet) & ~((1 << (32 - $mask)) - 1));
    }

    private static function v6(string $ip, string $cidr): bool
    {
        [$subnet, $mask] = strpos($cidr, '/') ? explode('/', $cidr, 2) : [$cidr, 128];
        $mask = (int)$mask;
        $ipBin  = inet_pton($ip);
        $netBin = inet_pton($subnet);
        if ($ipBin === false || $netBin === false) {
            return false;
        }

        $bytes = intdiv($mask, 8);
        $same  = substr_compare($ipBin, $netBin, 0, $bytes) === 0;
        if ($same && $mask % 8) {
            $bitmask = 0xFF << (8 - ($mask % 8));
            $same = (ord($ipBin[$bytes]) & $bitmask) === (ord($netBin[$bytes]) & $bitmask);
        }
        return $same;
    }
}
