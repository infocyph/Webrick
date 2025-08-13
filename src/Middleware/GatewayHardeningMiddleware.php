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
 *
 * Notes:
 *   - The "is_trusted_proxy" flag reflects the TCP peer (REMOTE_ADDR), not the end-user IP.
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
        if ($resp = $this->rejectIfUntrustedHost($req)) {
            return $resp;
        }

        // Derive end-user IP (safe via trusted proxies), enforce deny-list,
        // and stash attributes including peer + is_trusted_proxy.
        [$req, $denyResp] = $this->applyClientAndProxy($req);
        if ($denyResp) {
            return $denyResp;
        }

        if ($resp = $this->redirectIfHttpsEnforced($req)) {
            return $resp;
        }

        if ($this->stripHopByHop) {
            $req = $this->stripHopByHopFromRequest($req);
        }

        $resp = $next($req);

        if ($this->stripHopByHop) {
            $resp = $this->stripHopByHopFromResponse($resp);
        }

        return $this->guardRedirects($req, $resp);
    }

    /* ───────────────────────── steps ───────────────────────────── */

    private function rejectIfUntrustedHost(Request $req): ?Response
    {
        if ($this->trustedHosts === []) {
            return null;
        }
        if ($this->matchesHost($req->getUri()->getHost())) {
            return null;
        }
        return new Response(
            400,
            new Stream('Untrusted Host header.'),
            ['Content-Type' => 'text/plain; charset=utf-8'],
        );
    }

    /**
     * Resolve end-user IP (via EndUser) and peer IP (REMOTE_ADDR), enforce deny-list,
     * and attach attributes: client_ip, peer_ip, is_trusted_proxy.
     *
     * @return array{0:Request,1:?Response}
     */
    private function applyClientAndProxy(Request $req): array
    {
        $endUser = EndUser::from($req);          // honors proxy mask set in constructor
        $clientIp = $endUser->ip();              // final public address (end user)
        if ($clientIp && $this->cidrHit($clientIp, $this->denyIpCidrs)) {
            return [
                $req,
                new Response(
                    403,
                    new Stream("Forbidden – $clientIp is not allowed."),
                    ['Content-Type' => 'text/plain; charset=utf-8'],
                ),
            ];
        }

        $peerIp = $this->peerIp($req);                               // TCP peer
        $isTrustedProxy = $this->cidrHit($peerIp, $this->trustedProxyCidrs);

        $req = $req
            ->withAttribute('client_ip', $clientIp)
            ->withAttribute('peer_ip', $peerIp)
            ->withAttribute('is_trusted_proxy', $isTrustedProxy);

        return [$req, null];
    }

    private function redirectIfHttpsEnforced(Request $req): ?Response
    {
        if (!$this->enforceHttps) {
            return null;
        }
        $uri = $req->getUri();
        if ($uri->getScheme() === 'https') {
            return null;
        }
        $target = $uri->withScheme('https')->withPort($this->httpsPort);
        return new Response(308, headers: ['Location' => (string)$target]);
    }

    private function guardRedirects(Request $req, Response $resp): Response
    {
        if (!$resp->hasHeader('Location')) {
            return $resp;
        }

        $loc = $resp->getHeaderLine('Location');
        $host = parse_url($loc, PHP_URL_HOST);
        if (!$host) {
            return $resp; // relative | opaque → fine
        }

        if ($this->redirectAllowedHosts === []) {
            // Same-origin only when no explicit allow-list
            $current = $req->getUri()->getHost();
            if (!self::equalsIgnoreCase($host, $current)) {
                return Response::json(['error' => 'Open redirect blocked'], 400);
            }
        } elseif (!in_array($host, $this->redirectAllowedHosts, true)) {
            return Response::json(['error' => 'Open redirect blocked'], 400);
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

    private function peerIp(Request $req): ?string
    {
        $srv = $req->getServerParams();
        $ip = $srv['REMOTE_ADDR'] ?? null;
        return is_string($ip) && $ip !== '' ? $ip : null;
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
