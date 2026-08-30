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
     * Malformed addresses, masks, and mixed IP families fail closed.
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
     * @return array{0:string,1:int}|null
     */
    private static function splitCidr(string $cidr, int $defaultMask, int $maxMask): ?array
    {
        if (!str_contains($cidr, '/')) {
            return $cidr === '' ? null : [$cidr, $defaultMask];
        }

        [$subnet, $mask] = explode('/', $cidr, 2);
        if ($subnet === '' || $mask === '' || preg_match('/^[0-9]+$/D', $mask) !== 1) {
            return null;
        }

        $maskBits = (int) $mask;
        if ($maskBits > $maxMask) {
            return null;
        }

        return [$subnet, $maskBits];
    }

    /**
     * Check if an IPv4 address matches a CIDR.
     */
    private static function v4(string $ip, string $cidr): bool
    {
        $parts = self::splitCidr($cidr, 32, 32);
        if ($parts === null) {
            return false;
        }
        [$subnet, $mask] = $parts;

        $ipBin = inet_pton($ip);
        $netBin = inet_pton($subnet);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== 4 || strlen($netBin) !== 4) {
            return false;
        }

        return self::matchPackedNetwork($ipBin, $netBin, $mask);
    }

    /**
     * Check if an IPv6 address matches a CIDR.
     */
    private static function v6(string $ip, string $cidr): bool
    {
        $parts = self::splitCidr($cidr, 128, 128);
        if ($parts === null) {
            return false;
        }
        [$subnet, $mask] = $parts;

        $ipBin = inet_pton($ip);
        $netBin = inet_pton($subnet);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== 16 || strlen($netBin) !== 16) {
            return false;
        }

        return self::matchPackedNetwork($ipBin, $netBin, $mask);
    }
}
