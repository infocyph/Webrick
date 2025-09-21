<?php

/**
 * Webrick - Gateway hardening middleware.
 *
 * Enforces gateway/front-door security and hygiene:
 * - Validates Host header against an allow-list (blocks request if untrusted).
 * - Identifies end-user vs. peer IPs and attaches request attributes.
 * - Optionally enforces HTTPS with 308 redirects.
 * - Optionally strips hop-by-hop headers from request and response.
 * - Guards outgoing redirects to avoid open-redirect vulnerabilities.
 *
 * Caches compiled host allow-list patterns per process for performance.
 *
 * @package Infocyph\Webrick\Middleware
 */

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Http\EndUser;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Request\Support\IpCidr;
use Infocyph\Webrick\Response\Response;

/**
 * Harden the edge/gateway behavior and normalize request/response surfaces.
 *
 * Responsibilities:
 * - Host allow-list enforcement (supports '*' wildcard).
 * - End-user vs. immediate peer IP extraction with trusted proxy semantics.
 * - HTTPS enforcement (port configurable).
 * - Hop-by-hop header stripping (Connection tokens + well-known list).
 * - Redirect validation (scheme allow-list + same-origin policy unless configured).
 *
 * Notes:
 * - Uses Request::setTrustedProxies to configure proxy-awareness.
 * - Uses a per-process static cache for compiled trusted host regexes.
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
        'trailer',
        'transfer-encoding',
        'upgrade',
    ];

    /** compiled regex list for this instance (populated from static cache) */
    private array $hostRegex = [];
    private bool $allowAllHosts = false;

    /** EndUser instance for the current request (set in __invoke) */
    private ?EndUser $endUser = null;

    /** per-process cache: key = sha1(json_encode($trustedHosts)), value = list of compiled regex */
    private static array $hostRegexCache = [];

    /**
     * Configure gateway hardening knobs and pre-compile host allow-list.
     *
     * @param string[]   $trustedProxyCidrs     CIDRs considered trusted proxies.
     * @param string[]   $denyIpCidrs           CIDRs for end-user IPs to block.
     * @param string[]   $trustedHosts          Host header allow-list (supports '*' wildcard).
     * @param int|null   $forwardedHeaderMask   Symfony-style mask (e.g., Request::HEADER_X_FORWARDED_FOR | …).
     * @param bool       $enforceHttps          Force HTTPS (308) when scheme != https.
     * @param int        $httpsPort             Port used for HTTPS redirection (typically 443).
     * @param bool       $stripHopByHop         Remove hop-by-hop headers (req + resp).
     * @param string[]   $redirectAllowedHosts  Absolute redirect targets allowed; empty ⇒ same-origin only.
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

        // ② Compile/resolve host allow-list (static cache)
        $this->allowAllHosts = ($this->trustedHosts === ['*']);
        $this->hostRegex = self::compileHostRegex($this->trustedHosts);
    }

    /**
     * Apply gateway hardening checks/normalizations and pass control downstream.
     *
     * Flow:
     * 1) Reject untrusted Host (400).
     * 2) Resolve end-user/peer IPs and block endpoints in deny lists (403).
     * 3) Attach network attributes (client_ip, peer_ip, is_trusted_proxy).
     * 4) Enforce HTTPS redirect (308) when enabled.
     * 5) Optionally strip hop-by-hop headers on request/response.
     * 6) Validate redirects (scheme and origin) before returning.
     *
     * @param Request $req  Incoming request.
     * @param Closure $next Next handler.
     *
     * @return Response Hardened response.
     */
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

    /**
     * Enforce Host allow-list; reject requests with untrusted/empty Host.
     *
     * @param Request $req
     *
     * @return Response|null 400 response on rejection; null when allowed.
     */
    private function rejectIfUntrustedHost(Request $req): ?Response
    {
        if ($this->allowAllHosts || $this->trustedHosts === []) {
            return null;
        }

        $host = trim($req->getUri()->getHost());

        // Treat empty Host as invalid when enforcing an allow-list
        if ($host === '') {
            return Response::plaintext('Missing or empty Host header.', 400);
        }

        return $this->matchesHost($host)
            ? null
            : Response::plaintext('Untrusted Host header.', 400);
    }

    /**
     * Block requests from end-user IPs that match deny CIDRs.
     *
     * @return Response|null 403 response when blocked; null when allowed.
     */
    private function denyIfBlockedEndUser(): ?Response
    {
        $clientIp = $this->endUser?->ip(); // honors trusted proxy headers
        if ($clientIp && $this->cidrHit($clientIp, $this->denyIpCidrs)) {
            return Response::plaintext("Forbidden – $clientIp is not allowed.", 403);
        }
        return null;
    }

    /**
     * Attach network-related request attributes for downstream consumers.
     *
     * @param Request $req
     *
     * @return Request Request carrying client/peer/flag attributes.
     */
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

    /**
     * Enforce HTTPS by redirecting to https:// with optional port.
     *
     * @param Request $req
     *
     * @return Response|null 308 redirect response; null if already HTTPS or disabled.
     */
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
        return Response::redirect((string)$target, 308);
    }

    /**
     * Validate Location header to prevent open or unsafe redirects.
     *
     * - Only http/https schemes are allowed.
     * - If $redirectAllowedHosts is empty, enforce same-origin redirects.
     * - Otherwise, require destination host to be in the allow-list.
     *
     * @param Request  $req  Current request (for same-origin checks).
     * @param Response $resp Response to validate.
     *
     * @return Response Potentially replaced 400 response on violation, or original response.
     */
    private function guardRedirects(Request $req, Response $resp): Response
    {
        if (!$resp->hasHeader('Location')) {
            return $resp;
        }

        $loc = trim($resp->getHeaderLine('Location'));

        // Only allow http/https schemes (block javascript:, data:, file:, etc.)
        $scheme = parse_url($loc, PHP_URL_SCHEME);
        if ($scheme !== null && $scheme !== '') {
            $scheme = strtolower($scheme);
            if ($scheme !== 'http' && $scheme !== 'https') {
                return Response::json(['error' => 'Invalid redirect scheme'], 400);
            }
        }

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

    /**
     * Case-insensitive string equality.
     *
     * @param string $a
     * @param string $b
     *
     * @return bool
     */
    private static function equalsIgnoreCase(string $a, string $b): bool
    {
        return strcasecmp($a, $b) === 0;
    }

    /**
     * Check if a host matches any compiled allow-list pattern.
     *
     * @param string $host
     *
     * @return bool True when host is allowed.
     */
    private function matchesHost(string $host): bool
    {
        return array_any($this->hostRegex, fn ($rx) => preg_match($rx, $host));
    }

    /**
     * Determine if an IP matches any of the provided CIDRs.
     *
     * @param string|null $ip    Candidate IP address.
     * @param array<int,string> $cidrs CIDR ranges.
     *
     * @return bool True on match; false otherwise.
     */
    private function cidrHit(?string $ip, array $cidrs): bool
    {
        if ($ip === null || $cidrs === []) {
            return false;
        }
        return array_any($cidrs, fn ($cidr) => IpCidr::match($ip, $cidr));
    }

    /**
     * Remove hop-by-hop headers from the request (Connection tokens + known list).
     *
     * @param Request $r
     *
     * @return Request Request without hop-by-hop headers.
     */
    private function stripHopByHopFromRequest(Request $r): Request
    {
        $tokens = $this->parseConnectionTokens($r->getHeaderLine('Connection'));
        foreach (array_unique(array_merge(self::HOP_BY_HOP, $tokens)) as $h) {
            if ($r->hasHeader($h)) {
                $r = $r->withoutHeader($h);
            }
        }
        return $r;
    }

    /**
     * Remove hop-by-hop headers from the response (Connection tokens + known list).
     *
     * @param Response $r
     *
     * @return Response Response without hop-by-hop headers.
     */
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

    /**
     * Parse a Connection header line into lower-cased tokens.
     *
     * @param string $line Raw Connection header value.
     *
     * @return array<int,string> Tokens (lower-cased), empty when header missing.
     */
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

    /* ───────────── static cache ───────────── */

    /**
     * Compile trusted host patterns to case-insensitive regexes (cached per process).
     *
     * @param array<int,string> $trustedHosts Host allow-list patterns.
     *
     * @return list<string> Compiled regexes for this allow-list (cached per process).
     */
    private static function compileHostRegex(array $trustedHosts): array
    {
        if ($trustedHosts === [] || $trustedHosts === ['*']) {
            return []; // caller uses $this->allowAllHosts to short-circuit
        }
        $key = hash('xxh3', json_encode(array_values($trustedHosts), JSON_THROW_ON_ERROR));
        if (!isset(self::$hostRegexCache[$key])) {
            $compiled = [];
            foreach ($trustedHosts as $p) {
                $escaped = str_replace(['.', '*'], ['\.', '.*'], $p);
                $compiled[] = '#^' . $escaped . '$#i';
            }
            self::$hostRegexCache[$key] = $compiled;
        }
        return self::$hostRegexCache[$key];
    }
}