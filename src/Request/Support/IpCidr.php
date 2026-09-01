<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Support;

/** Immutable IPv4 or IPv6 CIDR network for repeated membership checks. */
final readonly class IpCidr
{
    private function __construct(
        private string $network,
        private int $mask,
        private int $bytes,
    ) {}

    public static function from(string $cidr): self
    {
        $cidr = trim($cidr);
        if ($cidr === '') {
            throw new \InvalidArgumentException('CIDR must not be empty.');
        }

        $slash = strpos($cidr, '/');
        $address = $slash === false ? $cidr : substr($cidr, 0, $slash);
        $packed = inet_pton($address);
        if ($packed === false) {
            throw new \InvalidArgumentException("Invalid CIDR address '{$cidr}'.");
        }

        $bytes = strlen($packed);
        $maxMask = $bytes === 4 ? 32 : ($bytes === 16 ? 128 : 0);
        if ($maxMask === 0) {
            throw new \InvalidArgumentException("Unsupported CIDR address family '{$cidr}'.");
        }

        $mask = $maxMask;
        if ($slash !== false) {
            $rawMask = substr($cidr, $slash + 1);
            if ($rawMask === '' || preg_match('/^[0-9]+$/D', $rawMask) !== 1) {
                throw new \InvalidArgumentException("Invalid CIDR mask '{$cidr}'.");
            }
            $mask = (int) $rawMask;
            if ($mask > $maxMask) {
                throw new \InvalidArgumentException("CIDR mask exceeds address width '{$cidr}'.");
            }
        }

        return new self($packed, $mask, $bytes);
    }

    public static function match(string $ip, string|self $cidr): bool
    {
        try {
            $network = $cidr instanceof self ? $cidr : self::from($cidr);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return $network->matches($ip);
    }

    public function matches(string $ip): bool
    {
        $packed = inet_pton($ip);
        if ($packed === false || strlen($packed) !== $this->bytes) {
            return false;
        }

        $wholeBytes = intdiv($this->mask, 8);
        if ($wholeBytes > 0 && substr_compare($packed, $this->network, 0, $wholeBytes) !== 0) {
            return false;
        }

        $remaining = $this->mask % 8;
        if ($remaining === 0) {
            return true;
        }

        $bitmask = (0xFF << (8 - $remaining)) & 0xFF;

        return (ord($packed[$wholeBytes]) & $bitmask) === (ord($this->network[$wholeBytes]) & $bitmask);
    }
}
