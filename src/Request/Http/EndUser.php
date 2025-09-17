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
    private static array $trustedGlobal = [];                    // CIDR strings

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

    /**
     * Creates a new EndUser instance from the given Request.
     *
     * @param Request $req The request to get the end-user from.
     * @param array $extraTrusted Extra trusted proxies (CIDR strings).
     */
    public function __construct(
        private readonly Request $req,
        private readonly array $extraTrusted = [],
    ) {
    }

    /**
     * Sets the global trusted proxies.
     *
     * These are the IP addresses or CIDR ranges that are trusted to pass
     * the client's IP address. The list is global and applies to all
     * EndUser objects.
     *
     * @param array $cidrs An array of IP addresses or CIDR ranges.
     */
    public static function setTrustedProxies(array $cidrs): void
    {
        self::$trustedGlobal = $cidrs;
    }

    /**
     * Creates a new EndUser instance from the given Request.
     *
     * @param Request $r The request to get the end-user from.
     * @param array $cidrs Extra trusted proxies (CIDR strings).
     * @return self
     */
    public static function from(Request $r, array $cidrs = []): self
    {
        return new self($r, $cidrs);
    }


    /**
     * Return the public IP of the client that is not behind a trusted proxy.
     * If the client is behind a trusted proxy, return null.
     *
     * This method first checks if the client is behind a trusted proxy.
     * If so, it returns null. Otherwise, it returns the public IP address of the client.
     *
     * @return string|null public IP or null
     */
    public function ip(): ?string
    {
        $hop = $this->ipNoProxy();

        return ($hop && $this->isTrustedProxy($hop))
            ? $this->ipViaProxy()         // use first non-private hop
            : $hop;                       // use REMOTE_ADDR
    }

    /**
     * Return the public IP of the client that is not behind a trusted proxy.
     * If the client is behind a trusted proxy, return null.
     *
     * This method first checks if the client is behind a trusted proxy.
     * If so, it returns null. Otherwise, it returns the public IP address of the client.
     *
     * @return string|null The public IP address of the client, or null if behind a trusted proxy.
     */
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

    /**
     * First public IP in the chain of forwarded IPs
     * (proxy-aware) or null.
     *
     * This method is more aggressive than ip() and will return
     * the first public IP it finds, even if it's not a trusted proxy.
     *
     * @return string|null public IP or null
     */
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

    /**
     * Anonymize an IP address for privacy.
     *
     * Given an IP address, return an anonymized version of it.
     * IPv4 addresses are anonymized by zeroing out the last 8 bits.
     * IPv6 addresses are anonymized by zeroing out the last 80 bits.
     * If the IP address is bracketed (e.g., "[2001:db8::1f16:3984:2c01:dead:beef:1a2e:4a2e]") strip the brackets.
     * If the IP address contains a zone identifier (e.g., "%eth0" or "%25eth0") strip the zone identifier.
     *
     * @param string $ip the IP address to anonymize
     * @return string the anonymized IP address
     */
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

    /**
     * Retrieves the User-Agent header of the current request.
     *
     * @return string|null the User-Agent header value, or null if not present
     */
    public function userAgent(): ?string
    {
        return $this->req->getHeaderLine('User-Agent') ?: null;
    }

    /**
     * Parses the User-Agent header of the current request.
     *
     * Returns an associative array with the following keys:
     *   - browser: the browser name (e.g. Chrome, Firefox, Safari)
     *   - version: the browser version (e.g. 117, 108.0.1)
     *   - platform: the platform name (e.g. Windows, macOS, Linux)
     *   - engine: the rendering engine name (e.g. Blink, Gecko, WebKit)
     *   - raw: the raw User-Agent string
     *
     * @return array
     */
    public function parseUserAgent(): array
    {
        return new UAParser($this->req)->parse()
            + ['raw' => $this->userAgent() ?? ''];        // keep raw for logs
    }

    /**
     * Checks if an IP address is private (i.e., not routable on the internet).
     *
     * This function uses the `FILTER_VALIDATE_IP` filter with the
     * `FILTER_FLAG_NO_RES_RANGE` and `FILTER_FLAG_NO_PRIV_RANGE` flags to
     * determine if an IP address is private. If the address is not private,
     * `false` is returned, otherwise `true`.
     *
     * @param string $ip The IP address to check.
     * @return bool Whether the IP address is private.
     */
    private function isPrivate(string $ip): bool
    {
        return \filter_var(
            $ip,
            \FILTER_VALIDATE_IP,
            \FILTER_FLAG_NO_RES_RANGE | \FILTER_FLAG_NO_PRIV_RANGE,
        ) === false;
    }

    /**
     * Check if an IP address is trusted according to the configured trusted proxies.
     *
     * This method checks if an IP address is present in the global trusted proxies list
     * or the extra trusted proxies list provided in the constructor.
     *
     * @param string $ip The IP address to check.
     * @return bool True if the IP address is trusted, false otherwise.
     */
    private function isTrustedProxy(string $ip): bool
    {
        return array_any(
            array_merge(self::$trustedGlobal, $this->extraTrusted),
            fn ($cidr) => IpCidr::match($ip, $cidr),
        );
    }

    /**
     * Parses the Forwarded header (if present) and returns an array of IP addresses.
     * The order of the IP addresses is the same as in the header.
     * If the header is not present, returns an empty array.
     * @return array An array of IP addresses.
     */
    private function parseForwarded(): array
    {
        if ((Request::getProxyHeaderFlags() & Request::HEADER_FORWARDED) === 0) {
            return [];
        }
        $h = $this->req->getHeaderLine('Forwarded');
        if ($h === '') {
            return [];
        }
        preg_match_all('/for="?\[?([A-F0-9:.]+)/i', $h, $m);
        return $m[1] ?? [];
    }

    /**
     * Parses legacy IP headers (X-Forwarded-For, Forwarded, Client-IP, etc.).
     * Returns an array of IP addresses (in the order they appear in the headers).
     *
     * @return array An array of IP addresses.
     */
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
