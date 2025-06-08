<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Http;

use Psr\Http\Message\ServerRequestInterface;

final class EndUser
{
    private const array LEGACY_IP_HEADERS = [
        'HTTP_X_FORWARDED_FOR',          // standard de-facto; may contain a list
        'HTTP_CLIENT_IP',                // old RFC 7230 “Client-IP”
        'HTTP_CF_CONNECTING_IP',         // Cloudflare
        'HTTP_FASTLY_CLIENT_IP',         // Fastly
        'HTTP_TRUE_CLIENT_IP',           // Akamai
        'HTTP_AKAMAI_EDGE_CLIENT_IP',
        'HTTP_X_AZURE_CLIENTIP',         // Azure Front-Door
        'HTTP_X_APPENGINE_USER_IP',      // Google AppEngine
        'HTTP_X_REAL_IP',                // Nginx / generic
        'HTTP_X_CLUSTER_CLIENT_IP',      // Heroku / AWS ELB
        'FLY_CLIENT_IP',                 // fly.io
        'HTTP_ALI_CLIENT_IP',            // AliCloud
        'HTTP_X_ORACLE_CLIENT_IP',       // Oracle
        'HTTP_X_STACKPATH_EDGE_IP',      // StackPath
    ];

    private array $extraTrusted = [];          // <— renamed
    /* --------------------------------------------------------------
       Construction
       -------------------------------------------------------------- */
    public function __construct(
        private readonly ServerRequestInterface $req,
        array $trustedProxies = []             // now called $trustedProxies
    ) {
        $this->extraTrusted = $trustedProxies; // store locally
    }

    public static function from(ServerRequestInterface $r, array $trusted = []): self
    {
        return new self($r, $trusted);
    }

    /* --------------------------------------------------------------
       1.  IP helpers
       -------------------------------------------------------------- */

    private ?string $noProxy = null;
    private ?string $viaProxy = null;
    private array   $ipCheckCache = [];
    /* ------------------------------------------------------------------
|  Trusted proxies
|------------------------------------------------------------------*/
    private static array $trusted = [];   // CIDR strings

    public static function setTrustedProxies(array $cidrs): void
    {
        self::$trusted = $cidrs;
    }

    /* --------------------------------------------------------------
       1.  IP helpers – only CHANGES are in isTrustedProxy()
       -------------------------------------------------------------- */

//    private function isTrustedProxy(string $ip): bool
//    {
//        // merge: global list + per-instance overrides
//        $all = \array_merge(self::$trusted, $this->extraTrusted);
//
//        // simple helper (IPv4 vs IPv6)
//        $match = static fn (string $cidr): bool =>
//        \str_contains($cidr, ':')
//            ? $this->ipv6Match($ip, $cidr)
//            : $this->ipv4Match($ip, $cidr);
//
//        return array_any($all, fn($cidr) => $match($cidr));
//    }

    /* ================================================================
| 4-a.  Preferred public IP
|       – takes trusted-proxy list into account
|================================================================*/
    public function ip(): ?string
    {
        // ── 1) Are we behind a proxy we trust? ───────────────────────
        $proxy = $this->getClientIPNoProxy();     // current hop
        if ($proxy && $this->isTrustedProxy($proxy)) {
            // The chain is already scrubbed to the first public address.
            return $this->getClientIPProxy();
        }

        // ── 2) No trusted proxy in front → fall back to REMOTE_ADDR  ─
        return $proxy;
    }

    /* keep BC: alias getClientIP() → ip() */
    public function getClientIP(): ?string
    {
        return $this->ip();
    }



    /** Raw REMOTE_ADDR (or CLI fallback) */
    public function getClientIPNoProxy(): ?string
    {
        if ($this->noProxy !== null) {
            return $this->noProxy;
        }
        if (\PHP_SAPI === 'cli') {
            $ip = gethostbyname(gethostname());
        } else {
            $ip = $this->req->getServerParams()['REMOTE_ADDR'] ?? null;
        }
        return $this->noProxy = \filter_var($ip, \FILTER_VALIDATE_IP) ?: null;
    }

    /** Proxy-aware address (first public address in Forwarded / X-Forwarded-For …) */
    public function getClientIPProxy(): ?string
    {
        if ($this->viaProxy !== null) {
            return $this->viaProxy;
        }

        // 1) canonical list from RFC 7239 Forwarded:
        $ips = $this->forwardedChain();
        // 2) legacy chain (HTTP_X_FORWARDED_FOR, …) if Forwarded header absent
        if (!$ips) {
            $ips = $this->legacyForwardedChain();
        }

        // 3) always append REMOTE_ADDR (comes last => last proxy hop)
        if ($na = $this->getClientIPNoProxy()) {
            $ips[] = $na;
        }

        // 4) walk from left to right, return the first **public** IP or thexcxcxc x
        //    first one that belongs to a trusted proxy net.
        foreach ($ips as $ip) {
            if (!$this->isPrivate($ip) && \filter_var($ip, \FILTER_VALIDATE_IP)) {
                return $this->viaProxy = $ip;
            }
            // If the address *is* private but is one of our *trusted* proxies,
            // continue to next element instead of bailing out.
            if ($this->isTrustedProxy($ip)) {
                continue;
            }
            break;              // reached an un-trusted private hop → stop.
        }
        return $this->viaProxy = $na ?? null;
    }

    /* ----------  whitelist helpers  ---------- */

    private function isTrustedProxy(string $ip): bool
    {
        return array_any(array_merge(self::$trusted, $this->extraTrusted), fn ($cidr)
            => str_contains($cidr, ':')
            ? $this->ipv6Match($ip, $cidr)
            : $this->ipv4Match($ip, $cidr));
    }

    private function isPrivate(string $ip): bool
    {
        return \filter_var(
            $ip,
            \FILTER_VALIDATE_IP,
            \FILTER_FLAG_NO_RES_RANGE | \FILTER_FLAG_NO_PRIV_RANGE
        ) === false;
    }

    /* ----------  Forwarded header parsing ---------- */

    /** returns array of IPs **in incoming order** */
    private function forwardedChain(): array
    {
        $fwd = $this->req->getHeaderLine('Forwarded');
        if ($fwd === '') {
            return [];
        }

        // Forwarded: for=198.51.100.17, for="[2001:db8:cafe::17]"
        $out = [];
        foreach (explode(',', $fwd) as $part) {
            if (preg_match('/for=(?:"?\[?)([A-F0-9:.]+)/i', $part, $m)) {
                $out[] = $m[1];
            }
        }
        return $out;
    }

    private function legacyForwardedChain(): array
    {
        $srv = $this->req->getServerParams();

        foreach (self::LEGACY_IP_HEADERS as $hdr) {
            if (empty($srv[$hdr])) {
                continue;
            }

            // X-Forwarded-For can be a comma-separated list
            return $hdr === 'HTTP_X_FORWARDED_FOR'
                ? array_map('trim', explode(',', $srv[$hdr]))
                : [trim($srv[$hdr])];
        }
        return [];
    }

    /* ----------  IPv4 / v6 utils (CIDR) ---------- */

    private function ipv4Match(string $ip, string $cidr): bool
    {
        $cache = "4:$ip/$cidr";
        if (isset($this->ipCheckCache[$cache])) {
            return $this->ipCheckCache[$cache];
        }

        if (!\filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
            return $this->ipCheckCache[$cache] = false;
        }

        [$net, $mask] = \strpos($cidr, '/') ? explode('/', $cidr, 2) : [$cidr, 32];
        $mask = (int) $mask;
        if ($mask < 0 || $mask > 32) {
            return $this->ipCheckCache[$cache] = false;
        }

        $ipL  = ip2long($ip);
        $netL = ip2long($net);
        return $this->ipCheckCache[$cache] =
            (($ipL ^ $netL) & ~(-1 << (32 - $mask))) === 0;
    }

    private function ipv6Match(string $ip, string $cidr): bool
    {
        $cache = "6:$ip/$cidr";
        if (isset($this->ipCheckCache[$cache])) {
            return $this->ipCheckCache[$cache];
        }
        if (!\filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)) {
            return $this->ipCheckCache[$cache] = false;
        }

        [$net, $mask] = \strpos($cidr, '/') ? explode('/', $cidr, 2) : [$cidr, 128];
        $mask = (int) $mask;
        if ($mask < 1 || $mask > 128) {
            return $this->ipCheckCache[$cache] = false;
        }

        $ipBin  = \inet_pton($ip);
        $netBin = \inet_pton($net);
        if ($ipBin === false || $netBin === false) {
            return $this->ipCheckCache[$cache] = false;
        }

        $bytes = intdiv($mask, 8);
        if (\substr_compare($ipBin, $netBin, 0, $bytes) !== 0) {
            return $this->ipCheckCache[$cache] = false;
        }
        if ($mask % 8) {
            $bitmask = 0xFF << (8 - ($mask % 8));
            if ((\ord($ipBin[$bytes]) & $bitmask) !== (\ord($netBin[$bytes]) & $bitmask)) {
                return $this->ipCheckCache[$cache] = false;
            }
        }
        return $this->ipCheckCache[$cache] = true;
    }

    /* --------------------------------------------------------------
       2.  Public IP-list check
       -------------------------------------------------------------- */
    public function checkIp(array|string $list, ?string $override = null, bool $proxy = false): bool
    {
        $ip = $override ?? ($proxy ? $this->getClientIPProxy() : $this->getClientIPNoProxy());
        if (!$ip) {
            return false;
        }

        return array_any((array)$list, fn ($cidr)
            => str_contains($cidr, ':')
            ? $this->ipv6Match($ip, $cidr)
            : $this->ipv4Match($ip, $cidr));
    }

    /* --------------------------------------------------------------
       3.  IP anonymiser
       -------------------------------------------------------------- */
    public function anonymize(string $ip): string
    {
        $wrap = $ip[0] === '[' && $ip[-1] === ']';
        $ip   = $wrap ? substr($ip, 1, -1) : $ip;
        $bin  = inet_pton($ip);
        if ($bin === false) {
            return $ip;
        }

        $mask = strlen($bin) === 4   // v4 → 255.255.255.0  ( /24 )
            ? inet_pton('255.255.255.0')
            : inet_pton('ffff:ffff:ffff:ffff:0000:0000:0000:0000'); // /64
        $anon = inet_ntop($bin & $mask) ?: $ip;
        return $wrap ? '['.$anon.']' : $anon;
    }

    /* --------------------------------------------------------------
       4.  UA helpers  (delegates to UAParser)
       -------------------------------------------------------------- */
    public function userAgent(): ?string
    {
        return $this->req->getHeaderLine('User-Agent') ?: null;
    }

    public function parseUserAgent(): array
    {
        $ua = $this->userAgent() ?? 'Unknown';

        // external WhichBrowser still supported:
        if (class_exists(\WhichBrowser\Parser::class)) {
            $w = new \WhichBrowser\Parser($ua);
            return [
                'raw'      => $ua,
                'browser'  => $w->browser->name,
                'version'  => $w->browser->version->value,
                'platform' => $w->os->toString(),
                'engine'   => $w->engine->name
            ];
        }

        // else fall back to our light UAParser
        return \array_merge(['raw' => $ua], new UAParser($ua)->parse());
    }
}
