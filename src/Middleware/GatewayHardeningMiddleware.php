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
 * Consolidated gateway hardening:
 *   • Trust proxy chain (CIDRs + header mask) and derive client IP
 *   • Enforce HTTPS (308) when $forceHttps is true
 *   • Validate Host against an allow-list (wildcards supported)
 *   • Strip hop-by-hop headers on both request and response
 *   • Guard unsafe redirects by limiting Location hosts
 *
 * Place this very early in the stack (right after ErrorHandler).
 */
final class GatewayHardeningMiddleware
{
    /** @var string[] CIDR blocks that are trusted proxies */
    private array $trustedProxyCidrs;
    /** @var string[] CIDR blocks that are outright denied for end-user IPs */
    private array $denyCidrs;
    /** @var string[] allowed Host header patterns (supports "*") */
    private array $trustedHosts;
    /** @var string[] allowed absolute redirect hosts (null ⇒ use $trustedHosts) */
    private ?array $redirectHosts;

    private bool $forceHttps;
    private static array $hostRegex = [];
    private static array $redirHostRegex = [];

    /** Hop-by-hop per RFC 9110 §7.6 */
    private const HOP_BY_HOP = [
        'connection',
        'keep-alive',
        'proxy-authenticate',
        'proxy-authorization',
        'te',
        'trailers',
        'transfer-encoding',
        'upgrade',
    ];

    public function __construct(
        array $trustedProxyCidrs = [],
        array $denyCidrs = [],
        array $trustedHosts = [],
        ?int $proxyHeaderFlags = null,   // Symfony-style bit-mask (X-Forwarded-*)
        bool $forceHttps = true,
        ?array $redirectHosts = null     // null ⇒ defaults to $trustedHosts
    ) {
        $this->trustedProxyCidrs = $trustedProxyCidrs;
        $this->denyCidrs = $denyCidrs;
        $this->trustedHosts = $trustedHosts;
        $this->forceHttps = $forceHttps;
        $this->redirectHosts = $redirectHosts;

        // Configure proxy trust once per worker.
        Request::setTrustedProxies($trustedProxyCidrs, $proxyHeaderFlags);

        // Pre-compile host regexes once per worker.
        if (self::$hostRegex === [] && $trustedHosts !== []) {
            self::$hostRegex = self::compileHostPatterns($trustedHosts);
        }
        if (self::$redirHostRegex === [] && $redirectHosts !== null && $redirectHosts !== []) {
            self::$redirHostRegex = self::compileHostPatterns($redirectHosts);
        }
    }

    public function __invoke(Request $req, Closure $next): Response
    {
        /* 1) Host allow-list (cheap, before anything else) ---------------- */
        if ($this->trustedHosts !== [] && !$this->matchesAny($req->getUri()->getHost(), self::$hostRegex)) {
            return new Response(
                400,
                new Stream('Untrusted Host header.'),
                ['Content-Type' => 'text/plain; charset=utf-8'],
            );
        }

        /* 2) Enforce HTTPS (after proxy trust so scheme is canonical) ----- */
        if ($this->forceHttps && $req->getUri()->getScheme() !== 'https') {
            $target = $req->getUri()->withScheme('https')->withPort(443);
            return new Response(
                status: 308, // permanent, preserves method & body
                headers: ['Location' => (string)$target],
            );
        }

        /* 3) Derive end-user IP, apply deny CIDRs, expose attributes ------ */
        $endUser = EndUser::from($req); // honours trusted proxies/header mask
        $ip = $endUser->ip();

        if ($ip && $this->cidrHit($ip, $this->denyCidrs)) {
            return new Response(
                403,
                new Stream("Forbidden – $ip is not allowed."),
                ['Content-Type' => 'text/plain; charset=utf-8'],
            );
        }

        $isTrustedProxy = $this->cidrHit($ip, $this->trustedProxyCidrs);
        $req = $req
            ->withAttribute('client_ip', $ip)
            ->withAttribute('is_trusted_proxy', $isTrustedProxy);

        /* 4) Strip hop-by-hop headers (request) --------------------------- */
        $req = $this->stripHopByHopOnRequest($req);

        /* 5) Downstream --------------------------------------------------- */
        $resp = $next($req);

        /* 6) Strip hop-by-hop headers (response) -------------------------- */
        $resp = $this->stripHopByHopOnResponse($resp);

        /* 7) Redirect guard ----------------------------------------------- */
        if ($resp->hasHeader('Location')) {
            $loc = $resp->getHeaderLine('Location');

            // Relative redirects are fine.
            if ($this->isAbsoluteUrl($loc)) {
                $host = \parse_url($loc, \PHP_URL_HOST) ?: '';
                $okList = $this->redirectHosts ?? $this->trustedHosts;

                if ($okList !== [] && !$this->matchesAny($host, $this->redirectRegexes())) {
                    return Response::json(['error' => 'Open redirect blocked'], 400);
                }
            }
        }

        return $resp;
    }

    /* ───────────────────────── helpers ─────────────────────────── */

    private static function compileHostPatterns(array $patterns): array
    {
        $rx = [];
        foreach ($patterns as $p) {
            $escaped = \str_replace(['.', '*'], ['\.', '.*'], $p);
            $rx[] = '#^' . $escaped . '$#i';
        }
        return $rx;
    }

    private function redirectRegexes(): array
    {
        // If redirectHosts were not explicitly provided, mirror trustedHosts
        if ($this->redirectHosts === null) {
            return self::$hostRegex;
        }
        if (self::$redirHostRegex === []) {
            self::$redirHostRegex = self::compileHostPatterns($this->redirectHosts);
        }
        return self::$redirHostRegex;
    }

    private function matchesAny(string $host, array $regexes): bool
    {
        if ($host === '') {
            return false;
        }
        foreach ($regexes as $rx) {
            if (\preg_match($rx, $host) === 1) {
                return true;
            }
        }
        return false;
    }

    private function cidrHit(?string $ip, array $cidrs): bool
    {
        if ($ip === null || $cidrs === []) {
            return false;
        }
        foreach ($cidrs as $cidr) {
            if (IpCidr::match($ip, $cidr)) {
                return true;
            }
        }
        return false;
    }

    private function stripHopByHopOnRequest(Request $req): Request
    {
        $tokens = $this->parseConnectionTokens($req->getHeaderLine('Connection'));
        foreach (\array_unique(\array_merge(self::HOP_BY_HOP, $tokens)) as $h) {
            if ($req->hasHeader($h)) {
                $req = $req->withoutHeader($h);
            }
        }
        return $req;
    }

    private function stripHopByHopOnResponse(Response $resp): Response
    {
        $tokens = $this->parseConnectionTokens($resp->getHeaderLine('Connection'));
        foreach (\array_unique(\array_merge(self::HOP_BY_HOP, $tokens)) as $h) {
            if ($resp->hasHeader($h)) {
                $resp = $resp->withoutHeader($h);
            }
        }
        return $resp;
    }

    private function parseConnectionTokens(string $line): array
    {
        if ($line === '') {
            return [];
        }
        $out = [];
        foreach (\explode(',', $line) as $t) {
            $t = \strtolower(\trim($t));
            if ($t !== '') {
                $out[] = $t;
            }
        }
        return $out;
    }

    private function isAbsoluteUrl(string $loc): bool
    {
        return \preg_match('#^[a-z][a-z0-9+.-]*://#i', $loc) === 1;
    }
}
