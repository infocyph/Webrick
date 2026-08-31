<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Support;

/** CIDR compatibility helper. Prefer pre-compiling CidrNetwork at boot for repeated checks. */
final class IpCidr
{
    public static function compile(string $cidr): CidrNetwork
    {
        return CidrNetwork::from($cidr);
    }

    public static function match(string $ip, string|CidrNetwork $cidr): bool
    {
        try {
            $network = $cidr instanceof CidrNetwork ? $cidr : CidrNetwork::from($cidr);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return $network->matches($ip);
    }
}
