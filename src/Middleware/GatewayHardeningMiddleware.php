<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
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
 * Attributes set on the request:
 *   - client_ip        → end-user address (honors trusted proxy headers)
 *   - peer_ip          → direct TCP peer (no proxy headers)
 *   - is_trusted_proxy → computed from peer_ip ∈ trustedProxyCidrs
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
        'trailer',               // RFC 9110 (singular)
        'transfer-encoding',
        'upgrade',
    ];

    /** compiled regex list (per instance) */
    private array $hostRegex = [];

    /** EndUser instance for the current request (set in __invoke) */
    private ?EndUser $endUser = null;

    /**
     * @param string[] $trustedProxyCidrs CIDRs considered trusted proxies
     * @param string[] $denyIpCidrs CIDRs for end-user IPs to block
     * @param string[] $trustedHosts Host header allow-list (supports '*')
     * @param int|null $forwardedHeaderMask Symfony-style mask (e.g., Request::HEADER_X_FORWARDED_FOR | …)
     * @param bool $enforceHttps Force HTTPS (308) when scheme != https
     * @param int $httpsPort Port used for HTTPS redirection (typically 443)
     * @param bool $stripHopByHop Remove hop-by-hop headers (req + resp)
     * @param string[] $redirectAllowedHosts Absolute redirect targets allowed; empty ⇒ same-origin only
     */
    public function __construct(
        private readonly array $trustedProxyCidrs = [],
        private readonly array $denyIpCidrs = [],
        private readonly array $trustedHosts = [],
        ?int $forwardedHeaderMask = null,
        private readonly bool $enforceHttps = true,
        private readonly int $httpsPort = 443,
        private readonly bool $stripHopByHop = true,
        private readonly array $redirectAllowedHosts = [],
    ) {
        // ① Configure trusted proxies & which forwarded headers to honor
        Request::setTrustedProxies($this->trustedProxyCidrs, $forwardedHeaderMask);

        // ② Compile host allow-list for this instance
        if ($this->trustedHosts !== []) {
            foreach ($this->trustedHosts as $p) {
                $escaped = str_replace(['.', '*'], ['\.', '.*'], $p);
                $this->hostRegex[] = '#^' . $escaped . '$#i';
            }
        }
    }

    public function __invoke(Request $req, Closure $next): Response
    {
        try {
            if ($resp = $this->rejectIfUntrustedHost($req)) {
                return $resp;
            }

            // Build EndUser once and keep for helpers
            $this->endUser = EndUser::from($req);

            if ($resp = $this->denyIfBlockedEndUser()) {
                return $resp;
            }

            // Attach client/peer/flag attributes for downstream
            $req = $this->attachNetworkAttributes($req);

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
        } finally {
            // avoid leaking request-scoped state across invocations
            $this->endUser = null;
        }
    }

    /* ───────────── step helpers ───────────── */

    private function rejectIfUntrustedHost(Request $req): ?Response
    {
        if ($this->trustedHosts === []) {
            return null;
        }
        if ($this->matchesHost($req->getUri()->getHost())) {
            return null;
        }
        return Response::plaintext('Untrusted Host header.', 400);
    }

    private function denyIfBlockedEndUser(): ?Response
    {
        $clientIp = $this->endUser?->ip(); // honors trusted proxy headers
        if ($clientIp && $this->cidrHit($clientIp, $this->denyIpCidrs)) {
            return Response::plaintext("Forbidden – $clientIp is not allowed.", 403);
        }
        return null;
    }

    private function attachNetworkAttributes(Request $req): Request
    {
        $clientIp = $this->endUser?->ip();        // end-user
        $peerIp = $this->endUser?->ipNoProxy(); // direct socket peer
        $isTrustedProxy = $this->cidrHit($peerIp, $this->trustedProxyCidrs);

        return $req
            ->withAttribute('client_ip', $clientIp)
            ->withAttribute('peer_ip', $peerIp)
            ->withAttribute('is_trusted_proxy', $isTrustedProxy);
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
        $port = ($this->httpsPort === 443) ? null : $this->httpsPort; // avoid :443 in Location
        $target = $uri->withScheme('https')->withPort($port);
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

    /* ───────────── general helpers ───────────── */

    private static function equalsIgnoreCase(string $a, string $b): bool
    {
        return strcasecmp($a, $b) === 0;
    }

    private function matchesHost(string $host): bool
    {
        return $this->hostRegex === [] || array_any($this->hostRegex, fn ($rx) => preg_match($rx, $host));
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
