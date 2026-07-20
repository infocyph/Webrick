<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Support;

final class IpCidr
{
    private const int MEMO_LIMIT = 256;

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

        if (count(self::$memo) < self::MEMO_LIMIT) {
            self::$memo[$key] = $result;
        }

        return $result;
    }

    private static function matchPackedNetwork(string $ipBin, string $netBin, int $mask): bool
    {
        $bytes = intdiv($mask, 8);
        if ($bytes > 0 && substr_compare($ipBin, $netBin, 0, $bytes) !== 0) {
            return false;
        }

        $rem = $mask % 8;
        if ($rem === 0) {
            return true;
        }

        $bitmask = (0xFF << (8 - $rem)) & 0xFF;

        return (ord($ipBin[$bytes]) & $bitmask) === (ord($netBin[$bytes]) & $bitmask);
    }

    /**
     * @return array{0:string,1:int}
     */
    private static function splitCidr(string $cidr, int $defaultMask): array
    {
        if (!str_contains($cidr, '/')) {
            return [$cidr, $defaultMask];
        }

        [$subnet, $mask] = explode('/', $cidr, 2);

        return [$subnet, (int) $mask];
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
        [$subnet, $mask] = self::splitCidr($cidr, 32);

        $ipBin = inet_pton($ip);
        $netBin = inet_pton($subnet);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== 4) {
            return false;
        }

        return self::matchPackedNetwork($ipBin, $netBin, $mask);
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
        [$subnet, $mask] = self::splitCidr($cidr, 128);

        $ipBin = inet_pton($ip);
        $netBin = inet_pton($subnet);
        if ($ipBin === false || $netBin === false) {
            return false;
        }

        return self::matchPackedNetwork($ipBin, $netBin, $mask);
    }
}
