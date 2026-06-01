<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Support;

final class IpCidr
{
    /** @var array<string,bool> */
    private static array $memo = [];

    /**
     * Checks if an IP address matches a CIDR.
     *
     * This function first checks if the result is already cached.
     * If not, it checks if the CIDR contains an IPv6 address (indicated by a colon).
     * If so, it calls the v6 method, otherwise it calls the v4 method.
     * The result is then cached for future use.
     *
     * @param string $ip The IP address to check.
     * @param string $cidr The CIDR to check against.
     * @return bool Whether the address matches the CIDR.
     */
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

    /**
     * Check if an IPv4 address matches a CIDR.
     *
     * @param string $ip The IPv4 address to check.
     * @param string $cidr The CIDR to check against.
     * @return bool Whether the address matches the CIDR.
     */
    private static function v4(string $ip, string $cidr): bool
    {
        [$subnet, $mask] = (str_contains($cidr, '/')) ? explode('/', $cidr, 2) : [$cidr, 32];
        $mask = (int) $mask;

        $ipBin = inet_pton($ip);
        $netBin = inet_pton($subnet);
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

    /**
     * Check if an IPv6 address matches a CIDR.
     *
     * @param string $ip The IPv6 address to check.
     * @param string $cidr The CIDR to check against.
     * @return bool Whether the address matches the CIDR.
     */
    private static function v6(string $ip, string $cidr): bool
    {
        [$subnet, $mask] = strpos($cidr, '/') ? explode('/', $cidr, 2) : [$cidr, 128];
        $mask = (int) $mask;
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
