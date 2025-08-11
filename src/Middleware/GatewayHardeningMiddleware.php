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
 * GatewayHardeningMiddleware
 *
 * • Validates Host against an allow-list.
 * • Honors trusted proxy CIDRs + forwarded header mask; derives end-user IP.
 * • Blocks requests when end-user IP matches a deny-list.
 * • Optionally redirects plain-HTTP → HTTPS (308).
 * • Strips hop-by-hop headers on both request and response (RFC 9110 §7.6).
 * • Guards against open redirects by validating absolute Location hosts.
 */
final class GatewayHardeningMiddleware
{
    /** Hop-by-hop header names (lower-case) */
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

    /** compiled regex list – cached per PHP process */
    private static array $hostRegex = [];

    /**
     * @param string[]    $trustedProxyCidrs  CIDRs considered trusted proxies
     * @param string[]    $denyIpCidrs        CIDRs for end-user IPs to block
     * @param string[]    $trustedHosts       Host header allow-list (supports '*')
     * @param int|null    $forwardedHeaderMask Symfony-style mask (e.g., Request::HEADER_X_FORWARDED_FOR | …)
     * @param bool        $enforceHttps       Force HTTPS (308) when scheme != https
     * @param int         $httpsPort          Port used for HTTPS redirection (typically 443)
     * @param bool        $stripHopByHop      Remove hop-by-hop headers (req + resp)
     * @param string[]    $redirectAllowedHosts Absolute redirect targets allowed; empty ⇒ same-origin only
     */
    public function __construct(
        private array $trustedProxyCidrs = [],
        private array $denyIpCidrs = [],
        private array $trustedHosts = [],
        ?int $forwardedHeaderMask = null,
        private bool $enforceHttps = true,
        private int $httpsPort = 443,
        private bool $stripHopByHop = true,
        private array $redirectAllowedHosts = [],
    ) {
        // ① Configure trusted proxies & which forwarded headers to honor
        Request::setTrustedProxies($this->trustedProxyCidrs, $forwardedHeaderMask);

        // ② Compile host allow-list once per worker
        if (self::$hostRegex === [] && $this->trustedHosts !== []) {
            foreach ($this->trustedHosts as $p) {
                $escaped = str_replace(['.', '*'], ['\.', '.*'], $p);
                self::$hostRegex[] = '#^' . $escaped . '$#i';
            }
        }
    }

    public function __invoke(Request $req, Closure $next): Response
    {
        /* ── Host allow-list check (cheap + first) ─────────────────── */
        if ($this->trustedHosts !== [] && !$this->matchesHost($req->getUri()->getHost())) {
            return new Response(
                400,
                new Stream('Untrusted Host header.'),
                ['Content-Type' => 'text/plain; charset=utf-8'],
            );
        }

        /* ── End-user IP via trusted proxies; block deny-listed ────── */
        $endUser = EndUser::from($req);          // honors proxy mask
        $ip = $endUser->ip();                    // final public address

        if ($ip && $this->cidrHit($ip, $this->denyIpCidrs)) {
            return new Response(
                403,
                new Stream("Forbidden – $ip is not allowed."),
                ['Content-Type' => 'text/plain; charset=utf-8'],
            );
        }

        // Stash derived info for downstream
        $isTrustedProxy = $this->cidrHit($ip, $this->trustedProxyCidrs);
        $req = $req
            ->withAttribute('client_ip', $ip)
            ->withAttribute('is_trusted_proxy', $isTrustedProxy);

        /* ── HTTPS enforce (after Host validation) ─────────────────── */
        if ($this->enforceHttps) {
            $uri = $req->getUri();
            if ($uri->getScheme() !== 'https') {
                $target = $uri->withScheme('https')->withPort($this->httpsPort);
                return new Response(
                    308,
                    headers: ['Location' => (string)$target],
                );
            }
        }

        /* ── Strip hop-by-hop headers (request) ────────────────────── */
        if ($this->stripHopByHop) {
            $req = $this->stripHopByHopFromRequest($req);
        }

        /* ── Downstream ────────────────────────────────────────────── */
        $resp = $next($req);

        /* ── Strip hop-by-hop headers (response) ───────────────────── */
        if ($this->stripHopByHop) {
            $resp = $this->stripHopByHopFromResponse($resp);
        }

        /* ── Redirect guard (absolute Location hosts) ──────────────── */
        if ($resp->hasHeader('Location')) {
            $loc = $resp->getHeaderLine('Location');
            $host = parse_url($loc, PHP_URL_HOST);

            if ($host) {
                if ($this->redirectAllowedHosts === []) {
                    // Same-origin only when no explicit allow-list
                    $current = $req->getUri()->getHost();
                    if (!self::equalsIgnoreCase($host, $current)) {
                        return Response::json(['error' => 'Open redirect blocked'], 400);
                    }
                } elseif (!in_array($host, $this->redirectAllowedHosts, true)) {
                    return Response::json(['error' => 'Open redirect blocked'], 400);
                }
            }
        }

        return $resp;
    }

    /* ───────────────────────── helpers ───────────────────────────── */

    private static function equalsIgnoreCase(string $a, string $b): bool
    {
        return strcasecmp($a, $b) === 0;
    }

    private function matchesHost(string $host): bool
    {
        return self::$hostRegex === [] || array_any(self::$hostRegex, fn ($rx) => preg_match($rx, $host));
    }

    private function cidrHit(?string $ip, array $cidrs): bool
    {
        if ($ip === null || $cidrs === []) {
            return false;
        }
        return array_any($cidrs, fn ($cidr) => IpCidr::match($ip, $cidr));
    }

    private function stripHopByHopFromRequest(Request $r): Request
    {
        // dynamic tokens named in Connection
        $tokens = $this->parseConnectionTokens($r->getHeaderLine('Connection'));
        foreach (array_unique(array_merge(self::HOP_BY_HOP, $tokens)) as $h) {
            if ($r->hasHeader($h)) {
                $r = $r->withoutHeader($h);
            }
        }
        return $r;
    }

    private function stripHopByHopFromResponse(Response $r): Response
    {
        $tokens = $this->parseConnectionTokens($r->getHeaderLine('Connection'));
        foreach (array_unique(array_merge(self::HOP_BY_HOP, $tokens)) as $h) {
            if ($r->hasHeader($h)) {
                $r = $r->withoutHeader($h);
            }
        }
        return $r;
    }

    /** Parse "Connection: foo, bar" into ['foo','bar'] (lower-cased) */
    private function parseConnectionTokens(string $line): array
    {
        if ($line === '') {
            return [];
        }
        $out = [];
        foreach (explode(',', $line) as $t) {
            $t = strtolower(trim($t));
            if ($t !== '') {
                $out[] = $t;
            }
        }
        return $out;
    }
}
