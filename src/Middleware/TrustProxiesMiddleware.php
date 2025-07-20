<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Request\Http\EndUser;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Request\Request;

/**
 * ① Whitelists *trusted* proxy CIDRs (like the old version)
 * ② **AND** blocks requests coming from a blacklisted *public* IP
 *
 * If the resolved client IP (after proxy‐stripping) lands in `$deny`,
 * the middleware immediately returns **403 Forbidden**.
 *
 * ```php
 * $mw = new TrustProxiesMiddleware(
 *     allow: ['10.0.0.0/8','172.16.0.0/12','192.168.0.0/16'],
 *     deny : ['203.0.113.0/24']   // shady datacentre range
 * );
 * ```
 */
final readonly class TrustProxiesMiddleware
{
    /** @param string[] $allow CIDR blocks that *are* trusted proxies
     * @param string[] $deny CIDR blocks that must be rejected outright
     */
    public function __construct(
        private array $allow = [],
        private array $deny = [],
    ) {
        if ($this->allow) {
            EndUser::setTrustedProxies($this->allow);   // ← one-time
        }
    }

    public function __invoke(Request $req, Closure $next): Response
    {
        /* ---------- 2. resolve final client IP ---------------------- */
        $ip = EndUser::from($req)->ip();   // honours allow-list

        /* ---------- 3. blacklist check ------------------------------ */
        if ($ip && $this->hits($ip, $this->deny)) {
            return new Response(
                status: 403,
                headers: ['Content-Type' => 'text/plain; charset=utf-8'],
                body: new Stream("Forbidden – {$ip} is not allowed."),
            );
        }

        /* ---------- 4. store the IP for downstream use -------------- */
        $trusted = $this->hits($ip, $this->allow);
        $req = $req
            ->withAttribute('client_ip', $ip)
            ->withAttribute('is_trusted_proxy', $trusted);

        return $next($req);
    }

    /* --------------------------------------------------------------- */
    /** Simple CIDR match (v4 & v6). */
    private function hits(string $ip, array $cidrs): bool
    {
        $check = fn (string $cidr): bool
            => str_contains($cidr, ':')
            ? $this->cidrV6($ip, $cidr)
            : $this->cidrV4($ip, $cidr);

        return array_any($cidrs, fn ($c) => $check($c));
    }

    private function cidrV4(string $ip, string $cidr): bool
    {
        [$subnet, $mask] = \strpos($cidr, '/') ? \explode('/', $cidr, 2) : [$cidr, 32];
        $mask = (int)$mask;
        return (\ip2long($ip) & ~((1 << (32 - $mask)) - 1))
            === (\ip2long($subnet) & ~((1 << (32 - $mask)) - 1));
    }

    private function cidrV6(string $ip, string $cidr): bool
    {
        [$subnet, $mask] = \strpos($cidr, '/') ? \explode('/', $cidr, 2) : [$cidr, 128];
        $mask = (int)$mask;
        $ipBin = \inet_pton($ip);
        $netBin = \inet_pton($subnet);
        if ($ipBin === false || $netBin === false) {
            return false;
        }
        $bytes = intdiv($mask, 8);
        $same = \substr_compare($ipBin, $netBin, 0, $bytes) === 0;
        if ($same && $mask % 8) {
            $bitmask = 0xFF << (8 - ($mask % 8));
            $same = (\ord($ipBin[$bytes]) & $bitmask) === (\ord($netBin[$bytes]) & $bitmask);
        }
        return $same;
    }
}
