<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Http;

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Request\Support\CidrNetwork;
use Infocyph\Webrick\Request\Support\IpCidr;

/** Resolve the end-user address through an explicitly trusted proxy chain. */
final class EndUser
{
    private ?string $cachedNoProxy = null;

    private ?string $cachedViaProxy = null;

    /**
     * @param list<CidrNetwork> $trustedProxyCidrs
     * @param list<string> $trustedClientIpHeaders
     */
    public function __construct(
        private readonly Request $req,
        private readonly array $trustedProxyCidrs = [],
        private readonly ?int $forwardedHeaderMask = null,
        private readonly array $trustedClientIpHeaders = [],
    ) {}

    /**
     * @param list<string|CidrNetwork> $cidrs
     * @param list<string> $trustedClientIpHeaders
     */
    public static function from(
        Request $request,
        array $cidrs = [],
        ?int $forwardedHeaderMask = null,
        array $trustedClientIpHeaders = [],
    ): self {
        if ($cidrs === []) {
            return new self($request, Request::getTrustedProxyNetworks(), $forwardedHeaderMask, $trustedClientIpHeaders);
        }

        $networks = [];
        foreach ($cidrs as $cidr) {
            $networks[] = $cidr instanceof CidrNetwork ? $cidr : IpCidr::compile($cidr);
        }

        return new self($request, $networks, $forwardedHeaderMask, $trustedClientIpHeaders);
    }

    /** @param list<string> $cidrs */
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
        if (($this->proxyHeaderFlags() & Request::HEADER_FORWARDED) !== 0) {
            $header = $this->req->getHeaderLine('Forwarded');
            if ($header === '') {
                $serverHeader = $this->req->getServerParams()['HTTP_FORWARDED'] ?? null;
                $header = is_string($serverHeader) ? $serverHeader : '';
            }
            if ($header !== '') {
                return $this->parseForwardedHeader($header);
            }
        }

        if (($this->proxyHeaderFlags() & Request::HEADER_X_FORWARDED_FOR) === 0) {
            return [];
        }
        $line = $this->req->getHeaderLine('X-Forwarded-For');
        if ($line === '') {
            $serverLine = $this->req->getServerParams()['HTTP_X_FORWARDED_FOR'] ?? null;
            $line = is_string($serverLine) ? $serverLine : '';
        }

        return $line === '' ? [] : $this->validIpList(explode(',', $line));
    }

    private function isTrustedProxy(string $ip): bool
    {
        return array_any(
            $this->trustedProxyCidrs,
            static fn(CidrNetwork $network): bool => $network->matches($ip),
        );
    }

    private function normalizeForwardedNode(string $raw): ?string
    {
        $raw = trim($raw);
        if (strlen($raw) >= 2 && $raw[0] === '"' && $raw[-1] === '"') {
            $raw = stripcslashes(substr($raw, 1, -1));
        }
        if ($raw === '' || strcasecmp($raw, 'unknown') === 0 || str_starts_with($raw, '_')) {
            return null;
        }
        if ($raw[0] === '[') {
            if (preg_match('/^\[([^\]]+)\](?::\d{1,5})?$/D', $raw, $matches) !== 1) {
                return null;
            }
            $raw = $matches[1];
        } elseif (filter_var($raw, FILTER_VALIDATE_IP) === false) {
            if (preg_match('/^([^:]+):\d{1,5}$/D', $raw, $matches) !== 1) {
                return null;
            }
            $raw = $matches[1];
        }
        [$candidate] = self::normalizeIpToken($raw);
        $validated = filter_var($candidate, FILTER_VALIDATE_IP);

        return is_string($validated) ? $validated : null;
    }

    /** @return list<string> */
    private function parseForwardedHeader(string $header): array
    {
        $elements = $this->splitForwardedElements($header);
        if ($elements === []) {
            return [];
        }

        $values = [];
        foreach ($elements as $element) {
            if (preg_match('/(?:^|;)\s*for\s*=\s*("(?:[^"\\\\]|\\\\.)*"|[^;,\s]+)/i', $element, $matches) !== 1) {
                return [];
            }
            $node = $this->normalizeForwardedNode($matches[1]);
            if ($node === null) {
                return [];
            }
            $values[] = $node;
        }

        return $values;
    }

    private function proxyHeaderFlags(): int
    {
        return $this->forwardedHeaderMask ?? Request::getProxyHeaderFlags();
    }

    /** @return list<string> */
    private function splitForwardedElements(string $header): array
    {
        $out = [];
        $buffer = '';
        $quoted = false;
        $escaped = false;
        for ($i = 0, $length = strlen($header); $i < $length; $i++) {
            $char = $header[$i];
            if ($escaped) {
                $buffer .= $char;
                $escaped = false;

                continue;
            }
            if ($quoted && $char === '\\') {
                $buffer .= $char;
                $escaped = true;

                continue;
            }
            if ($char === '"') {
                $quoted = !$quoted;
                $buffer .= $char;

                continue;
            }
            if ($char === ',' && !$quoted) {
                if (trim($buffer) === '') {
                    return [];
                }
                $out[] = trim($buffer);
                $buffer = '';

                continue;
            }
            $buffer .= $char;
        }
        if ($quoted || trim($buffer) === '') {
            return [];
        }
        $out[] = trim($buffer);

        return $out;
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function validIpList(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            $value = trim($value);
            if ($value === '') {
                return [];
            }
            [$candidate] = self::normalizeIpToken($value);
            $validated = filter_var($candidate, FILTER_VALIDATE_IP);
            if (!is_string($validated)) {
                return [];
            }
            $out[] = $validated;
        }

        return $out;
    }
}
