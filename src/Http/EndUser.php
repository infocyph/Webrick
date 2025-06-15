<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Http;

use Psr\Http\Message\ServerRequestInterface;

final class EndUser
{
    /* ─────────────────────────  constructor  ──────────────────────── */

    public function __construct(
        private readonly ServerRequestInterface $req,
        private readonly array $extraTrusted = []
    ) {
    }

    public static function from(ServerRequestInterface $req, array $trusted = []): self
    {
        return new self($req, $trusted);
    }

    /* ─────────────────────────  GLOBAL trusted proxies  ───────────── */

    private static array $trustedGlobal = [];   // CIDR strings

    public static function setTrustedProxies(array $cidrs): void
    {
        self::$trustedGlobal = $cidrs;
    }

    /* ───────────────────────── 1. IP helpers  ─────────────────────── */

    private const array LEGACY_IP_HEADERS = [
        'HTTP_X_FORWARDED_FOR',          // de-facto standard – MAY be a list
        'HTTP_CLIENT_IP',                // obsolete, but seen
        'HTTP_CF_CONNECTING_IP',         // Cloudflare
        'HTTP_FASTLY_CLIENT_IP',         // Fastly
        'HTTP_TRUE_CLIENT_IP',           // Akamai
        'HTTP_AKAMAI_EDGE_CLIENT_IP',
        'HTTP_X_AZURE_CLIENTIP',         // Azure Front-Door
        'HTTP_X_APPENGINE_USER_IP',      // Google AppEngine
        'HTTP_X_REAL_IP',                // generic / Nginx
        'HTTP_X_CLUSTER_CLIENT_IP',      // Heroku / AWS ELB
        'FLY_CLIENT_IP',                 // fly.io
        'HTTP_ALI_CLIENT_IP',            // AliCloud
        'HTTP_X_ORACLE_CLIENT_IP',       // Oracle
        'HTTP_X_STACKPATH_EDGE_IP',      // StackPath
    ];

    private ?string $cachedNoProxy = null;   // REMOTE_ADDR
    private ?string $cachedViaProxy = null;  // first public addr
    private array   $ipCheckCache = [];      // CIDR match memoisation

    /** Preferred public IP – honours trusted-proxy lists */
    public function ip(): ?string
    {
        $hop = $this->ipNoProxy();

        return ($hop && $this->isTrustedProxy($hop))
            ? $this->ipViaProxy()          // we trust the chain → pick first public
            : $hop;                        // else REMOTE_ADDR
    }

    /** Alias kept for BC */
    public function getClientIP(): ?string
    {
        return $this->ip();
    }

    /** REMOTE_ADDR (CLI-safe) */
    public function ipNoProxy(): ?string
    {
        if ($this->cachedNoProxy !== null) {
            return $this->cachedNoProxy;
        }

        $ip = \PHP_SAPI === 'cli'
            ? gethostbyname(gethostname())
            : $this->req->getServerParams()['REMOTE_ADDR'] ?? null;

        return $this->cachedNoProxy = \filter_var($ip, \FILTER_VALIDATE_IP) ?: null;
    }

    /** First public IP in Forwarded / X-Forwarded-For chain (falls back to REMOTE_ADDR) */
    public function ipViaProxy(): ?string
    {
        if ($this->cachedViaProxy !== null) {
            return $this->cachedViaProxy;
        }

        $chain = $this->parseForwarded() ?: $this->parseLegacyForwarded();
        $chain[] = $this->ipNoProxy();   // last hop (could be null)

        foreach ($chain as $ip) {
            if (!$ip) {
                continue;
            }
            // public address?
            if (! $this->isPrivate($ip)) {
                return $this->cachedViaProxy = $ip;
            }
            // private but trusted → keep scanning
            if ($this->isTrustedProxy($ip)) {
                continue;
            }
            break;  // hit un-trusted private hop
        }

        return $this->cachedViaProxy = $this->ipNoProxy();
    }

    /* ───────────  anonymise (IPv4 / IPv6)  ─────────── */

    public function anonymize(string $ip): string
    {
        $wrap = $ip[0] === '[' && $ip[-1] === ']';
        $ip   = $wrap ? substr($ip, 1, -1) : $ip;

        $bin  = \inet_pton($ip);
        if ($bin === false) {
            return $ip;                           // bad literal – return untouched
        }

        $mask = strlen($bin) === 4
            ? \inet_pton('255.255.255.0')         // /24
            : \inet_pton('ffff:ffff:ffff:ffff:0:0:0:0'); // /64

        $masked = \inet_ntop($bin & $mask) ?: $ip;

        return $wrap ? '['.$masked.']' : $masked;
    }

    /* ───────────  UA helpers  ─────────── */

    public function userAgent(): ?string
    {
        return $this->req->getHeaderLine('User-Agent') ?: null;
    }

    public function parseUserAgent(): array
    {
        $ua = $this->userAgent() ?? 'Unknown';

        // Prefer WhichBrowser if present
        if (\class_exists(\WhichBrowser\Parser::class)) {
            $wb = new \WhichBrowser\Parser($ua);
            return [
                'raw'      => $ua,
                'browser'  => $wb->browser->name,
                'version'  => $wb->browser->version->value,
                'platform' => $wb->os->toString(),
                'engine'   => $wb->engine->name
            ];
        }

        // Light fallback
        return \array_merge(['raw' => $ua], new UAParser($ua)->parse());
    }

    /* ─────────────────────────  internals  ───────────────────────── */

    /** true ⇢ RFC1918 / RFC4193 / reserved */
    private function isPrivate(string $ip): bool
    {
        return \filter_var(
                $ip,
                \FILTER_VALIDATE_IP,
                \FILTER_FLAG_NO_RES_RANGE | \FILTER_FLAG_NO_PRIV_RANGE
            ) === false;
    }

    /** merged global + local lists */
    private function isTrustedProxy(string $ip): bool
    {
        $all = \array_merge(self::$trustedGlobal, $this->extraTrusted);

        return \array_reduce(
            $all,
            fn (bool $carry, string $cidr): bool =>
                $carry
                || (str_contains($cidr, ':')
                    ? $this->cidrV6($ip, $cidr)
                    : $this->cidrV4($ip, $cidr)),
            false
        );
    }

    /** parse RFC 7239 Forwarded header → IP list L→R */
    private function parseForwarded(): array
    {
        $h = $this->req->getHeaderLine('Forwarded');
        if ($h === '') {
            return [];
        }

        $out = [];
        foreach (\explode(',', $h) as $seg) {
            if (\preg_match('/for=(?:"?\[?)([A-F0-9:.]+)/i', $seg, $m)) {
                $out[] = $m[1];
            }
        }
        return $out;
    }

    /** fallback legacy chain */
    private function parseLegacyForwarded(): array
    {
        $s = $this->req->getServerParams();
        foreach (self::LEGACY_IP_HEADERS as $hdr) {
            if (empty($s[$hdr])) {
                continue;
            }
            return $hdr === 'HTTP_X_FORWARDED_FOR'
                ? \array_map('trim', \explode(',', (string) $s[$hdr]))
                : [trim((string) $s[$hdr])];
        }
        return [];
    }

    /* CIDR helpers (cached) */
    private function cidrV4(string $ip, string $cidr): bool
    {
        $key = "4:$ip|$cidr";
        if (isset($this->ipCheckCache[$key])) {
            return $this->ipCheckCache[$key];
        }

        [$subnet, $mask] = \strpos($cidr, '/') ? \explode('/', $cidr, 2) : [$cidr, 32];
        $mask = (int) $mask;

        $result = (\ip2long($ip) & ~((1 << (32 - $mask)) - 1)) === (\ip2long($subnet) & ~((1 << (32 - $mask)) - 1));

        return $this->ipCheckCache[$key] = $result;
    }

    private function cidrV6(string $ip, string $cidr): bool
    {
        $key = "6:$ip|$cidr";
        if (isset($this->ipCheckCache[$key])) {
            return $this->ipCheckCache[$key];
        }

        [$subnet, $mask] = \strpos($cidr, '/') ? \explode('/', $cidr, 2) : [$cidr, 128];
        $mask = (int) $mask;

        $ipBin  = \inet_pton($ip);
        $netBin = \inet_pton($subnet);
        if ($ipBin === false || $netBin === false) {
            return $this->ipCheckCache[$key] = false;
        }

        $bytes = intdiv($mask, 8);
        $same  = \substr_compare($ipBin, $netBin, 0, $bytes) === 0;

        if ($same && $mask % 8) {
            $bitmask = 0xFF << (8 - ($mask % 8));
            $same = (\ord($ipBin[$bytes]) & $bitmask) === (\ord($netBin[$bytes]) & $bitmask);
        }

        return $this->ipCheckCache[$key] = $same;
    }
}
