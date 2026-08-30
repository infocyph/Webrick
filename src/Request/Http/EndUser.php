<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Http;

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Request\Support\IpCidr;

/**
 * Resolve the end-user address through an explicitly trusted proxy chain.
 *
 * Forwarded hops are consumed right-to-left. A header is never trusted unless
 * the immediate peer is trusted, and vendor-specific client-IP headers are
 * disabled unless explicitly configured.
 */
final class EndUser
{
    private ?string $cachedNoProxy = null;

    private ?string $cachedViaProxy = null;

    /**
     * @param list<string> $trustedProxyCidrs
     * @param list<string> $trustedClientIpHeaders Explicit vendor/client-IP header names, e.g. ['CF-Connecting-IP'].
     */
    public function __construct(
        private readonly Request $req,
        private readonly array $trustedProxyCidrs = [],
        private readonly ?int $forwardedHeaderMask = null,
        private readonly array $trustedClientIpHeaders = [],
    ) {}

    /**
     * @param list<string> $cidrs
     * @param list<string> $trustedClientIpHeaders
     */
    public static function from(
        Request $request,
        array $cidrs = [],
        ?int $forwardedHeaderMask = null,
        array $trustedClientIpHeaders = [],
    ): self {
        return new self($request, $cidrs, $forwardedHeaderMask, $trustedClientIpHeaders);
    }

    /**
     * Legacy configuration hook. New code should pass trusted CIDRs explicitly.
     *
     * @param list<string> $cidrs
     */
    public static function setTrustedProxies(array $cidrs): void
    {
        Request::setTrustedProxies($cidrs);
    }

    public function anonymize(string $ip): string
    {
        [$plainIp, $wrapped] = self::normalizeIpToken($ip);
        $maskedIp = self::maskedIp($plainIp) ?? $plainIp;

        return $wrapped ? '[' . $maskedIp . ']' : $maskedIp;
    }

    public function ip(): ?string
    {
        $peer = $this->ipNoProxy();
        if ($peer === null || !$this->isTrustedProxy($peer)) {
            return $peer;
        }

        return $this->ipViaProxy();
    }

    public function ipNoProxy(): ?string
    {
        if ($this->cachedNoProxy !== null) {
            return $this->cachedNoProxy;
        }

        $ip = $this->req->getServerParams()['REMOTE_ADDR'] ?? null;
        if (!is_string($ip)) {
            return null;
        }

        $validated = filter_var($ip, FILTER_VALIDATE_IP);

        return $this->cachedNoProxy = is_string($validated) ? $validated : null;
    }

    public function ipViaProxy(): ?string
    {
        if ($this->cachedViaProxy !== null) {
            return $this->cachedViaProxy;
        }

        $peer = $this->ipNoProxy();
        if ($peer === null || !$this->isTrustedProxy($peer)) {
            return $this->cachedViaProxy = $peer;
        }

        $chain = $this->forwardedChain();
        if ($chain === []) {
            $vendor = $this->explicitVendorClientIp();
            if ($vendor !== null) {
                $chain[] = $vendor;
            }
        }

        $candidate = $peer;
        for ($i = count($chain) - 1; $i >= 0; $i--) {
            if (!$this->isTrustedProxy($candidate)) {
                break;
            }
            $candidate = $chain[$i];
        }

        return $this->cachedViaProxy = $candidate;
    }

    /** @return array<string,string> */
    public function parseUserAgent(): array
    {
        $parsed = new UAParser($this->req)->parse();
        $out = ['raw' => $this->userAgent() ?? ''];
        foreach ($parsed as $key => $value) {
            $out[$key] = $value;
        }

        return $out;
    }

    public function userAgent(): ?string
    {
        return $this->req->getHeaderLine('User-Agent') ?: null;
    }

    /** @return list<string> */
    private function explicitVendorChain(): array
    {
        $ip = $this->explicitVendorClientIp();

        return $ip === null ? [] : [$ip];
    }

    private function explicitVendorClientIp(): ?string
    {
        foreach ($this->trustedClientIpHeaders as $header) {
            $value = trim($this->req->getHeaderLine($header));
            if ($value === '') {
                continue;
            }

            $first = trim(explode(',', $value, 2)[0]);
            $validated = filter_var($first, FILTER_VALIDATE_IP);
            if (is_string($validated)) {
                return $validated;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function forwardedChain(): array
    {
        $chain = $this->parseForwarded();
        if ($chain !== []) {
            return $chain;
        }

        if (($this->proxyHeaderFlags() & Request::HEADER_X_FORWARDED_FOR) === 0) {
            return [];
        }

        return $this->validIpList(explode(',', $this->req->getHeaderLine('X-Forwarded-For')));
    }

    private function isTrustedProxy(string $ip): bool
    {
        return array_any(
            $this->trustedProxyCidrs,
            static fn(string $cidr): bool => IpCidr::match($ip, $cidr),
        );
    }

    private static function maskedIp(string $ip): ?string
    {
        $bin = inet_pton($ip);
        if ($bin === false) {
            return null;
        }

        $mask = strlen($bin) === 4
            ? inet_pton('255.255.255.0')
            : inet_pton('ffff:ffff:ffff:ffff:0:0:0:0');
        if (!is_string($mask)) {
            return null;
        }

        $masked = inet_ntop($bin & $mask);

        return is_string($masked) ? $masked : null;
    }

    /** @return array{0:string,1:bool} */
    private static function normalizeIpToken(string $ip): array
    {
        $wrapped = str_starts_with($ip, '[') && str_ends_with($ip, ']');
        $plainIp = $wrapped ? substr($ip, 1, -1) : $ip;
        $zonePos = strpos($plainIp, '%');
        if ($zonePos !== false) {
            $plainIp = substr($plainIp, 0, $zonePos);
        }

        return [$plainIp, $wrapped];
    }

    /** @return list<string> */
    private function parseForwarded(): array
    {
        if (($this->proxyHeaderFlags() & Request::HEADER_FORWARDED) === 0) {
            return [];
        }

        $header = $this->req->getHeaderLine('Forwarded');
        if ($header === '' || preg_match_all('/(?:^|[,;]\s*)for=(?:"?\[?)([A-F0-9:.]+)(?:\]?"?)/i', $header, $matches) === false) {
            return [];
        }

        return $this->validIpList($matches[1]);
    }

    private function proxyHeaderFlags(): int
    {
        return $this->forwardedHeaderMask ?? Request::getProxyHeaderFlags();
    }

    /**
     * @param array<int,string> $values
     * @return list<string>
     */
    private function validIpList(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            [$candidate] = self::normalizeIpToken(trim($value));
            $validated = filter_var($candidate, FILTER_VALIDATE_IP);
            if (is_string($validated)) {
                $out[] = $validated;
            }
        }

        return $out;
    }
}
