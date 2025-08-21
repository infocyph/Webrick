<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Http;

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Request\Support\IpCidr;

/**
 * EndUser – information about the *real* client
 * --------------------------------------------
 *   $eu = new EndUser($psrRequest, ['203.0.113.0/24']); // extra trusted proxies
 *   $ip = $eu->ip();                                    // best public address
 *   $log = $eu->anonymize($ip);                         // privacy-safe
 */
final class EndUser
{
    /* ========== static trusted proxy list (shared across requests) ========== */
    private static array $trustedGlobal = [];                    // CIDR strings

    public static function setTrustedProxies(array $cidrs): void
    {
        self::$trustedGlobal = $cidrs;
    }

    /* ----------------------------------------------------------------------- */
    private const LEGACY_IP_HEADERS = [
        'HTTP_X_FORWARDED_FOR',
        'HTTP_CLIENT_IP',
        'HTTP_CF_CONNECTING_IP',
        'HTTP_FASTLY_CLIENT_IP',
        'HTTP_TRUE_CLIENT_IP',
        'HTTP_AKAMAI_EDGE_CLIENT_IP',
        'HTTP_X_AZURE_CLIENTIP',
        'HTTP_X_APPENGINE_USER_IP',
        'HTTP_X_REAL_IP',
        'HTTP_X_CLUSTER_CLIENT_IP',
        'FLY_CLIENT_IP',
        'HTTP_ALI_CLIENT_IP',
        'HTTP_X_ORACLE_CLIENT_IP',
        'HTTP_X_STACKPATH_EDGE_IP',
    ];

    /* ----------------------------------------------------------------------- */
    private ?string $cachedNoProxy = null;
    private ?string $cachedViaProxy = null;

    public function __construct(
        private readonly Request $req,
        private readonly array $extraTrusted = [],
    ) {}

    /* fast factory */
    public static function from(Request $r, array $cidrs = []): self
    {
        return new self($r, $cidrs);
    }

    /* ============================================================
       1)   IP helpers
       ============================================================*/

    /** Public IP (proxy-aware) or null */
    public function ip(): ?string
    {
        $hop = $this->ipNoProxy();

        return ($hop && $this->isTrustedProxy($hop))
            ? $this->ipViaProxy()         // use first non-private hop
            : $hop;                       // use REMOTE_ADDR
    }

    public function ipNoProxy(): ?string
    {
        if ($this->cachedNoProxy !== null) {
            return $this->cachedNoProxy;
        }

        $ip = \PHP_SAPI === 'cli'
            ? gethostbyname(gethostname())
            : ($this->req->getServerParams()['REMOTE_ADDR'] ?? null);

        return $this->cachedNoProxy = \filter_var($ip, \FILTER_VALIDATE_IP) ?: null;
    }

    public function ipViaProxy(): ?string
    {
        if ($this->cachedViaProxy !== null) {
            return $this->cachedViaProxy;
        }

        $chain = $this->parseForwarded() ?: $this->parseLegacyForwarded();
        $chain[] = $this->ipNoProxy();                         // last hop

        foreach ($chain as $ip) {
            if (!$ip) {
                continue;
            }
            if (!$this->isPrivate($ip)) {                      // first public
                return $this->cachedViaProxy = $ip;
            }
            if (!$this->isTrustedProxy($ip)) {                 // private but not trusted
                break;
            }
        }
        return $this->cachedViaProxy = $this->ipNoProxy();     // fallback
    }

    /* mask /24 for IPv4, /64 for IPv6 */
    public function anonymize(string $ip): string
    {
        $wrap = str_starts_with($ip, '[') && str_ends_with($ip, ']');
        $ip = $wrap ? substr($ip, 1, -1) : $ip;

        // Strip zone identifiers (e.g., "%eth0" or "%25eth0" in bracketed URIs)
        if (false !== $pos = strpos($ip, '%')) {
            $ip = substr($ip, 0, $pos);
        }

        $bin = \inet_pton($ip);
        if ($bin === false) {
            return $wrap ? '[' . $ip . ']' : $ip;
        }

        $mask = strlen($bin) === 4
            ? \inet_pton('255.255.255.0')                 // /24
            : \inet_pton('ffff:ffff:ffff:ffff:0:0:0:0');  // /64

        $masked = $bin & $mask;

        return $wrap ? '[' . \inet_ntop($masked) . ']' : \inet_ntop($masked);
    }

    /* ============================================================
       2)  UA helpers (delegated)
       ============================================================*/
    public function userAgent(): ?string
    {
        return $this->req->getHeaderLine('User-Agent') ?: null;
    }

    public function parseUserAgent(): array
    {
        return new UAParser($this->req)->parse()
            + ['raw' => $this->userAgent() ?? ''];        // keep raw for logs
    }

    /* ============================================================
       Internals
       ============================================================*/
    private function isPrivate(string $ip): bool
    {
        return \filter_var(
                $ip,
                \FILTER_VALIDATE_IP,
                \FILTER_FLAG_NO_RES_RANGE | \FILTER_FLAG_NO_PRIV_RANGE,
            ) === false;
    }

    private function isTrustedProxy(string $ip): bool
    {
        return array_any(array_merge(self::$trustedGlobal, $this->extraTrusted), fn($cidr) => IpCidr::match($ip, $cidr));
    }

    /** RFC 7239 Forwarded header → IP list (L→R) */
    private function parseForwarded(): array
    {
        if ((Request::getProxyHeaderFlags() & Request::HEADER_FORWARDED) === 0) {
            return [];
        }
        $h = $this->req->getHeaderLine('Forwarded');
        if ($h === '') {
            return [];
        }
        preg_match_all('/for=(?:"?\[?)([A-F0-9:.]+)/i', $h, $m);
        return $m[1] ?? [];
    }

    /** Fallback: X-Forwarded-For / misc legacy headers */
    private function parseLegacyForwarded(): array
    {
        if ((Request::getProxyHeaderFlags() & Request::HEADER_X_FORWARDED_FOR) === 0) {
            return [];
        }
        $srv = $this->req->getServerParams();
        foreach (self::LEGACY_IP_HEADERS as $hdr) {
            if (empty($srv[$hdr])) {
                continue;
            }
            return $hdr === 'HTTP_X_FORWARDED_FOR'
                ? array_map('trim', explode(',', (string)$srv[$hdr]))
                : [trim((string)$srv[$hdr])];
        }
        return [];
    }
}
