<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Http;

use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use WhichBrowser\Parser;

/**
 * A helper class for advanced IP detection and user agent parsing,
 * returning only 'browser', 'version', 'platform', and 'engine'.
 *
 * Feedback changes:
 *  - No $trustProxy in constructor; we have getClientIPNoProxy() & getClientIPProxy() separately.
 *  - parseUserAgent() tries parseUserAgentLibrary() first, else parseUserAgentBasic().
 */
final class EndUser
{
    // cache results
    private ?string $clientIpNoProxy = null;
    private ?string $clientIpProxy   = null;

    // For IP check caching
    private array $checkedIps = [];

    public function __construct(private readonly ServerRequestInterface $request)
    {
    }

    public static function from(ServerRequestInterface $request): self
    {
        return new self($request);
    }

    // ====================================
    //  IP DETECTION
    // ====================================

    /**
     * Return client IP from REMOTE_ADDR only (no proxy headers).
     */
    public function getClientIPNoProxy(): ?string
    {
        if ($this->clientIpNoProxy !== null) {
            return $this->clientIpNoProxy;
        }

        // If CLI
        if (PHP_SAPI === 'cli') {
            $ip = gethostbyname(gethostname());
            return $this->clientIpNoProxy = (filter_var($ip, FILTER_VALIDATE_IP) ?: '127.0.0.1');
        }

        $server = $this->request->getServerParams();
        $ip = $server['REMOTE_ADDR'] ?? null;
        // Validate
        $ip = filter_var($ip, FILTER_VALIDATE_IP) ?: null;

        return $this->clientIpNoProxy = $ip;
    }

    /**
     * Return client IP by checking known proxy/CDN headers first, then REMOTE_ADDR.
     */
    public function getClientIPProxy(): ?string
    {
        if ($this->clientIpProxy !== null) {
            return $this->clientIpProxy;
        }

        // If CLI
        if (PHP_SAPI === 'cli') {
            $ip = gethostbyname(gethostname());
            return $this->clientIpProxy = (filter_var($ip, FILTER_VALIDATE_IP) ?: '127.0.0.1');
        }

        $server = $this->request->getServerParams();

        $ip = match (true) {
            !empty($server['HTTP_CLIENT_IP'])               => $server['HTTP_CLIENT_IP'],
            !empty($server['HTTP_X_FORWARDED_FOR'])         => explode(',', (string)$server['HTTP_X_FORWARDED_FOR'])[0],
            !empty($server['HTTP_CF_CONNECTING_IP'])        => $server['HTTP_CF_CONNECTING_IP'],
            !empty($server['HTTP_FASTLY_CLIENT_IP'])        => $server['HTTP_FASTLY_CLIENT_IP'],
            !empty($server['HTTP_TRUE_CLIENT_IP'])          => $server['HTTP_TRUE_CLIENT_IP'],
            !empty($server['HTTP_AKAMAI_EDGE_CLIENT_IP'])   => $server['HTTP_AKAMAI_EDGE_CLIENT_IP'],
            !empty($server['HTTP_X_AZURE_CLIENTIP'])        => $server['HTTP_X_AZURE_CLIENTIP'],
            !empty($server['HTTP_X_APPENGINE_USER_IP'])     => $server['HTTP_X_APPENGINE_USER_IP'],
            !empty($server['HTTP_X_REAL_IP'])               => $server['HTTP_X_REAL_IP'],
            !empty($server['HTTP_X_CLUSTER_CLIENT_IP'])     => $server['HTTP_X_CLUSTER_CLIENT_IP'],
            !empty($server['FLY_CLIENT_IP'])                => $server['FLY_CLIENT_IP'],
            !empty($server['HTTP_ALI_CLIENT_IP'])           => $server['HTTP_ALI_CLIENT_IP'],
            !empty($server['HTTP_X_ORACLE_CLIENT_IP'])      => $server['HTTP_X_ORACLE_CLIENT_IP'],
            !empty($server['HTTP_X_STACKPATH_EDGE_IP'])     => $server['HTTP_X_STACKPATH_EDGE_IP'],
            !empty($server['REMOTE_ADDR'])                  => $server['REMOTE_ADDR'],
            default                                         => null,
        };

        $ip = filter_var($ip, FILTER_VALIDATE_IP) ?: null;
        return $this->clientIpProxy = $ip;
    }

    /**
     * By default, we do *not* trust proxy. So let's just call getClientIPNoProxy().
     * If you want proxy logic, call getClientIPProxy() directly.
     */
    public function getClientIP(): ?string
    {
        return $this->getClientIPNoProxy();
    }

    /**
     * Checks if the given IP (client or override) is in one or more IPs/subnets (v4 or v6).
     */
    public function checkIp(array|string $ips, ?string $overrideIp = null, bool $useProxy = false): bool
    {
        $ips = (array)$ips;
        $ip  = $overrideIp ?? ($useProxy ? $this->getClientIPProxy() : $this->getClientIPNoProxy());
        if (!$ip) {
            return false;
        }

        $isV6 = (substr_count($ip, ':') > 1);
        foreach ($ips as $candidate) {
            $candidate = trim((string) $candidate);
            if ($isV6) {
                if ($this->checkIp6($ip, $candidate)) {
                    return true;
                }
            } else {
                if ($this->checkIp4($ip, $candidate)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Anonymize IP: remove last byte for v4, last 8 bytes for v6.
     */
    public function anonymize(string $ip): string
    {
        $wrappedIPv6 = false;
        if (str_starts_with($ip, '[') && str_ends_with($ip, ']')) {
            $wrappedIPv6 = true;
            $ip = substr($ip, 1, -1);
        }

        $packed = @inet_pton($ip);
        if (!$packed) {
            return $ip;
        }

        $mask = (strlen($packed) === 4)
            ? '255.255.255.0'
            : 'ffff:ffff:ffff:ffff:0000:0000:0000:0000';

        $anon = @inet_ntop($packed & @inet_pton($mask)) ?: $ip;
        if ($wrappedIPv6) {
            $anon = '[' . $anon . ']';
        }
        return $anon;
    }

    // ====================================
    //   IPv4 & IPv6 Checking
    // ====================================
    private function checkIp4(string $check, string $cidr): bool
    {
        $cacheKey = "4:$check-$cidr";
        if (isset($this->checkedIps[$cacheKey])) {
            return $this->checkedIps[$cacheKey];
        }

        if (!filter_var($check, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $this->checkedIps[$cacheKey] = false;
        }

        if (str_contains($cidr, '/')) {
            [$baseIp, $netmask] = explode('/', $cidr, 2);
            $netmask = (int) $netmask;
            if ($netmask < 0 || $netmask > 32) {
                return $this->checkedIps[$cacheKey] = false;
            }
        } else {
            $baseIp  = $cidr;
            $netmask = 32;
        }

        $baseLong  = ip2long($baseIp);
        $checkLong = ip2long($check);
        if ($baseLong === false || $checkLong === false) {
            return $this->checkedIps[$cacheKey] = false;
        }

        $mask = -1 << (32 - $netmask);
        $result = ($checkLong & $mask) === ($baseLong & $mask);
        return $this->checkedIps[$cacheKey] = $result;
    }

    private function checkIp6(string $check, string $cidr): bool
    {
        $cacheKey = "6:$check-$cidr";
        if (isset($this->checkedIps[$cacheKey])) {
            return $this->checkedIps[$cacheKey];
        }

        if (!((extension_loaded('sockets') && defined('AF_INET6')) || @inet_pton('::1'))) {
            throw new RuntimeException('IPv6 not supported in this environment.');
        }

        if (str_contains($cidr, '/')) {
            [$baseIp, $netmask] = explode('/', $cidr, 2);
            $netmask = (int)$netmask;
            if ($netmask < 1 || $netmask > 128) {
                return $this->checkedIps[$cacheKey] = false;
            }
        } else {
            $baseIp  = $cidr;
            $netmask = 128;
        }

        $checkPacked = @inet_pton($check);
        $basePacked  = @inet_pton($baseIp);
        if (!$checkPacked || !$basePacked) {
            return $this->checkedIps[$cacheKey] = false;
        }

        $checkWords = unpack('n*', $checkPacked);
        $baseWords  = unpack('n*', $basePacked);
        if (!$checkWords || !$baseWords) {
            return $this->checkedIps[$cacheKey] = false;
        }

        $intCount = (int) ceil($netmask / 16);
        for ($i = 1; $i <= $intCount; $i++) {
            $bits = $netmask - 16 * ($i - 1);
            $bits = ($bits > 16) ? 16 : $bits;
            $mask = ~(0xFFFF >> $bits) & 0xFFFF;

            if (($checkWords[$i] & $mask) !== ($baseWords[$i] & $mask)) {
                return $this->checkedIps[$cacheKey] = false;
            }
        }
        return $this->checkedIps[$cacheKey] = true;
    }

    // ====================================
    //   User-Agent Parsing
    // ====================================
    public function userAgent(): ?string
    {
        $ua = $this->request->getHeaderLine('User-Agent');
        return $ua !== '' ? $ua : null;
    }

    /**
     * Public method to parse user agent. By default:
     * 1) tries parseUserAgentLibrary($ua),
     * 2) if that fails, parseUserAgentBasic($ua).
     */
    public function parseUserAgent(): array
    {
        $ua = $this->userAgent() ?? 'Unknown';

        // Attempt external library approach
        $info = $this->parseUserAgentLibrary($ua);
        if ($info !== null) {
            // library succeeded
            return [
                'raw'      => $ua,
                'browser'  => $info['browser']  ?? 'Unknown',
                'version'  => $info['version']  ?? '',
                'platform' => $info['platform'] ?? 'Unknown',
                'engine'   => $info['engine']   ?? 'Unknown'
            ];
        }

        // fallback to basic
        return $this->parseUserAgentBasic($ua);
    }

    /**
     * Attempt to use browscap or any other installed library (like 'whichbrowser') if desired.
     * If we detect browscap, we do get_browser().
     * If that fails, return null.
     */
    protected function parseUserAgentLibrary(string $ua): ?array
    {
        if (class_exists(Parser::class)) {
            $result = new Parser($ua);
            return [
                'raw'      => $ua,
                'browser'  => $result->browser->name,
                'version'  => $result->browser->version->value,
                'platform' => $result->os->name,
                'engine'   => $result->engine->name
            ];
        }
        return null;
    }

    /**
     * Minimal fallback using local UAParser
     */
    protected function parseUserAgentBasic(string $ua): array
    {
        $parser = new UAParser($ua);
        $parsed = $parser->parse();
        // We add 'raw' field for completeness
        return array_merge(['raw' => $ua], $parsed);
    }
}
