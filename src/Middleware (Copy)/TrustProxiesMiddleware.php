<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Request\Http\EndUser;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Request\Support\IpCidr;
use Infocyph\Webrick\Response\Response;

/**
 * • Whitelists *trusted* proxy CIDRs **and** specific proxy-headers.<br>
 * • Blocks requests from black-listed public IPs.<br>
 * • Rejects “Host” header spoofing unless it matches `$trustedHosts`.
 *
 * ```php
 * $mw = new TrustProxiesMiddleware(
 *     allow        : ['10.0.0.0/8','172.16.0.0/12'],
 *     deny         : ['203.0.113.0/24'],
 *     trustedHosts : ['*.example.com','api.example.org'],
 *     headerFlags  : Request::HEADER_X_FORWARDED_FOR
 *                 | Request::HEADER_X_FORWARDED_PROTO
 * );
 * ```
 */
final class TrustProxiesMiddleware
{
    /** @var string[] CIDR blocks that are trusted proxies */
    private array $allow;
    /** @var string[] CIDR blocks that are rejected outright */
    private array $deny;
    /** @var string[] host allow-list (wildcards “*” permitted) */
    private array $trustedHosts;
    /** compiled regex list – cached per PHP process */
    private static array $hostRegex = [];

    public function __construct(
        array $allow = [],
        array $deny = [],
        array $trustedHosts = [],
        ?int $headerFlags = null,   // Symfony-style bit-mask
    )
    {
        $this->allow = $allow;
        $this->deny = $deny;
        $this->trustedHosts = $trustedHosts;

        // ① proxy CIDRs & header mask → one static call
        Request::setTrustedProxies($allow, $headerFlags);

        // ② compile host patterns once per worker
        if (self::$hostRegex === [] && $trustedHosts !== []) {
            foreach ($trustedHosts as $p) {
                $escaped = str_replace(['.', '*'], ['\.', '.*'], $p);
                self::$hostRegex[] = '#^' . $escaped . '$#i';
            }
        }
    }

    public function __invoke(Request $req, Closure $next): Response
    {
        /* ---------- host validation (cheap) ----------------------- */
        if ($this->trustedHosts !== []
            && !$this->matchesHost($req->getUri()->getHost())) {
            return new Response(
                400,
                new Stream('Untrusted Host header.'),
                ['Content-Type' => 'text/plain; charset=utf-8'],
            );
        }

        /* ---------- IP derivation & ACLs -------------------------- */
        $endUser = EndUser::from($req);      // already honours proxy mask

        $ip = $endUser->ip();                // final public address

        if ($ip && $this->cidrHit($ip, $this->deny)) {
            return new Response(
                403,
                new Stream("Forbidden – $ip is not allowed."),
                ['Content-Type' => 'text/plain; charset=utf-8'],
            );
        }

        /* ---------- stash for downstream -------------------------- */
        $trusted = $this->cidrHit($ip, $this->allow);

        $req = $req
            ->withAttribute('client_ip', $ip)
            ->withAttribute('is_trusted_proxy', $trusted);

        return $next($req);
    }

    /* ───────────────────────── helpers ─────────────────────────── */

    private function matchesHost(string $host): bool
    {
        return array_any(self::$hostRegex, fn($rx) => preg_match($rx, $host));
    }

    private function cidrHit(?string $ip, array $cidrs): bool
    {
        if ($ip === null || $cidrs === []) {
            return false;
        }
        return array_any($cidrs, fn($cidr) => IpCidr::match($ip, $cidr));
    }
}
