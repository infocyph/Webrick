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
        [$subnet, $mask] = (str_contains($cidr, '/')) ? explode('/', $cidr, 2) : [$cidr, 32];
        $mask = (int)$mask;

        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton($subnet);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== 4) {
            return false;
        }

        $bytes = intdiv($mask, 8);
        if ($bytes && substr_compare($ipBin, $netBin, 0, $bytes) !== 0) {
            return false;
        }
        $rem = $mask % 8;
        if ($rem === 0) {
            return true;
        }
        $bitmask = chr(0xFF << (8 - $rem));
        return (ord($ipBin[$bytes]) & ord($bitmask)) === (ord($netBin[$bytes]) & ord($bitmask));
    }


    private static function v6(string $ip, string $cidr): bool
    {
        [$subnet, $mask] = strpos($cidr, '/') ? explode('/', $cidr, 2) : [$cidr, 128];
        $mask = (int)$mask;
        $ipBin = inet_pton($ip);
        $netBin = inet_pton($subnet);
        if ($ipBin === false || $netBin === false) {
            return false;
        }

        $bytes = intdiv($mask, 8);
        $same = substr_compare($ipBin, $netBin, 0, $bytes) === 0;
        if ($same && $mask % 8) {
            $bitmask = 0xFF << (8 - ($mask % 8));
            $same = (ord($ipBin[$bytes]) & $bitmask) === (ord($netBin[$bytes]) & $bitmask);
        }
        return $same;
    }
}
