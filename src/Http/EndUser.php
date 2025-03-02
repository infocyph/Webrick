<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Http;

use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

final class EndUser
{
    // cache results
    private ?string $clientIpNoProxy = null;
    private ?string $clientIpProxy = null;

    // For IP check caching
    private array $checkedIps = [];

    public function __construct(private readonly ServerRequestInterface $request)
    {
    }

    public static function from(ServerRequestInterface $request): self
    {
        return new self($request);
    }


    /**
     * Get the client IP (no proxy checking).
     *
     * If PHP_SAPI is "cli", we return `gethostbyname(gethostname())` as the client IP,
     * otherwise we return REMOTE_ADDR.
     *
     * Results are cached.
     *
     * @return string|null
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
     * Get the client IP address (proxy aware).
     *
     * This method will go through a list of known proxy headers and return the first
     * IP address that is found. If no proxy headers are set, it will return the IP
     * address set in the REMOTE_ADDR server parameter.
     *
     * The results are cached.
     *
     * @return string|null
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
            !empty($server['HTTP_CLIENT_IP']) => $server['HTTP_CLIENT_IP'],
            !empty($server['HTTP_X_FORWARDED_FOR']) => explode(',', (string)$server['HTTP_X_FORWARDED_FOR'])[0],
            !empty($server['HTTP_CF_CONNECTING_IP']) => $server['HTTP_CF_CONNECTING_IP'],
            !empty($server['HTTP_FASTLY_CLIENT_IP']) => $server['HTTP_FASTLY_CLIENT_IP'],
            !empty($server['HTTP_TRUE_CLIENT_IP']) => $server['HTTP_TRUE_CLIENT_IP'],
            !empty($server['HTTP_AKAMAI_EDGE_CLIENT_IP']) => $server['HTTP_AKAMAI_EDGE_CLIENT_IP'],
            !empty($server['HTTP_X_AZURE_CLIENTIP']) => $server['HTTP_X_AZURE_CLIENTIP'],
            !empty($server['HTTP_X_APPENGINE_USER_IP']) => $server['HTTP_X_APPENGINE_USER_IP'],
            !empty($server['HTTP_X_REAL_IP']) => $server['HTTP_X_REAL_IP'],
            !empty($server['HTTP_X_CLUSTER_CLIENT_IP']) => $server['HTTP_X_CLUSTER_CLIENT_IP'],
            !empty($server['FLY_CLIENT_IP']) => $server['FLY_CLIENT_IP'],
            !empty($server['HTTP_ALI_CLIENT_IP']) => $server['HTTP_ALI_CLIENT_IP'],
            !empty($server['HTTP_X_ORACLE_CLIENT_IP']) => $server['HTTP_X_ORACLE_CLIENT_IP'],
            !empty($server['HTTP_X_STACKPATH_EDGE_IP']) => $server['HTTP_X_STACKPATH_EDGE_IP'],
            !empty($server['REMOTE_ADDR']) => $server['REMOTE_ADDR'],
            default => null,
        };

        $ip = filter_var($ip, FILTER_VALIDATE_IP) ?: null;
        return $this->clientIpProxy = $ip;
    }


    /**
     * Gets the client IP. If the client is behind a proxy, this method returns the
     * IP of the proxy, not the client. If the client is not behind a proxy, this
     * method returns the IP of the client.
     *
     * @return string|null The client IP, or null if no IP was found.
     */
    public function getClientIP(): ?string
    {
        return $this->getClientIPNoProxy();
    }


    /**
     * Checks if the client's IP address matches any in the given list.
     *
     * This method supports both IPv4 and IPv6 addresses. The IP address to be checked
     * can be overridden by providing the $overrideIp parameter. Optionally, the
     * method can use proxy-aware IP detection if $useProxy is set to true.
     *
     * @param array|string $ips The list of IP addresses or CIDR ranges to check against.
     * @param string|null $overrideIp An optional IP address to override the detected client IP.
     * @param bool $useProxy Whether to use the proxy-aware IP detection.
     * @return bool True if the client's IP matches any in the list, false otherwise.
     */
    public function checkIp(array|string $ips, ?string $overrideIp = null, bool $useProxy = false): bool
    {
        $ips = (array)$ips;
        $ip = $overrideIp ?? ($useProxy ? $this->getClientIPProxy() : $this->getClientIPNoProxy());
        if (!$ip) {
            return false;
        }

        $isV6 = (substr_count($ip, ':') > 1);
        foreach ($ips as $candidate) {
            $candidate = trim((string)$candidate);
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
     * Anonymizes the given IP address by masking parts of it.
     *
     * For IPv4 addresses, the last octet is masked, resulting in a /24 subnet.
     * For IPv6 addresses, the last 64 bits are masked, resulting in a /64 subnet.
     * If the input is a wrapped IPv6 address (e.g., "[::1]"), the output will
     * also be wrapped.
     *
     * @param string $ip The IP address to anonymize, either IPv4 or IPv6.
     * @return string The anonymized IP address.
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


    /**
     * Checks if the given IP address is contained within a given IPv4 CIDR block.
     *
     * @param string $check The IP address to check.
     * @param string $cidr The IPv4 CIDR block to check against, e.g. "192.168.1.0/24".
     * @return bool True if the IP is contained within the given CIDR block, false otherwise.
     */
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
            $netmask = (int)$netmask;
            if ($netmask < 0 || $netmask > 32) {
                return $this->checkedIps[$cacheKey] = false;
            }
        } else {
            $baseIp = $cidr;
            $netmask = 32;
        }

        $baseLong = ip2long($baseIp);
        $checkLong = ip2long($check);
        if ($baseLong === false || $checkLong === false) {
            return $this->checkedIps[$cacheKey] = false;
        }

        $mask = -1 << (32 - $netmask);
        $result = ($checkLong & $mask) === ($baseLong & $mask);
        return $this->checkedIps[$cacheKey] = $result;
    }

    /**
     * Checks if the given IP address is contained within a given IPv6 CIDR block.
     *
     * @param string $check The IP address to check.
     * @param string $cidr The IPv6 CIDR block to check against, e.g. "2001:0db8:85a3:0000:0000:8a2e:0370:7334/64".
     * @return bool True if the IP is contained within the given CIDR block, false otherwise.
     *
     * @throws RuntimeException If IPv6 is not supported in this environment.
     */
    private function checkIp6(string $check, string $cidr): bool
    {
        $cacheKey = "6:$check-$cidr";
        if (isset($this->checkedIps[$cacheKey])) {
            return $this->checkedIps[$cacheKey];
        }

        if (!((extension_loaded('sockets') && defined('AF_INET6')) || @inet_pton('::1'))) {
            throw new RuntimeException('IPv6 not supported in this environment.');
        }

        $baseIp = $cidr;
        $netmask = 128;

        if (str_contains($cidr, '/')) {
            [$baseIp, $netmask] = explode('/', $cidr, 2);
            $netmask = (int)$netmask;
            if ($netmask < 1 || $netmask > 128) {
                return $this->checkedIps[$cacheKey] = false;
            }
        }

        $checkPacked = @inet_pton($check);
        $basePacked = @inet_pton($baseIp);
        if (!$checkPacked || !$basePacked) {
            return $this->checkedIps[$cacheKey] = false;
        }

        $checkWords = unpack('n*', $checkPacked);
        $baseWords = unpack('n*', $basePacked);
        if (!$checkWords || !$baseWords) {
            return $this->checkedIps[$cacheKey] = false;
        }

        $intCount = (int)ceil($netmask / 16);
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

    /**
     * Retrieves the User-Agent string from the request headers.
     *
     * @return string|null The User-Agent string if present, or null if not found.
     */
    public function userAgent(): ?string
    {
        $ua = $this->request->getHeaderLine('User-Agent');
        return $ua !== '' ? $ua : null;
    }


    /**
     * Parses the User-Agent string to extract information about the client.
     *
     * This method first attempts to parse the User-Agent string using an external library.
     * If successful, it returns detailed information including the browser name, version,
     * platform, and rendering engine. If the external library is not available, it falls
     * back to using an internal basic parser.
     *
     * @return array An associative array containing:
     *               - 'raw': The raw User-Agent string.
     *               - 'browser': The detected browser name or 'Unknown'.
     *               - 'version': The detected browser version or an empty string.
     *               - 'platform': The detected platform name or 'Unknown'.
     *               - 'engine': The detected rendering engine or 'Unknown'.
     */
    public function parseUserAgent(): array
    {
        $ua = $this->userAgent() ?? 'Unknown';

        // Attempt external library approach
        $info = $this->parseUserAgentLibrary($ua);
        if ($info !== null) {
            // library succeeded
            return [
                'raw' => $ua,
                'browser' => $info['browser'] ?? 'Unknown',
                'version' => $info['version'] ?? '',
                'platform' => $info['platform'] ?? 'Unknown',
                'engine' => $info['engine'] ?? 'Unknown',
            ];
        }

        // fallback to basic
        return $this->parseUserAgentBasic($ua);
    }


    /**
     * Attempts to parse the User-Agent string using an external library.
     *
     * This method uses the WhichBrowser library to analyze the given User-Agent string
     * and extract detailed information including the browser name, version, platform,
     * and rendering engine. If the library is not available, it returns null.
     *
     * @param string $ua The User-Agent string to be parsed.
     * @return array|null An associative array containing 'raw', 'browser', 'version', 'platform', and 'engine'
     *                    if the parsing is successful, or null if the library is not available.
     */
    protected function parseUserAgentLibrary(string $ua): ?array
    {
        if (class_exists('\WhichBrowser\Parser')) {
            $result = new \WhichBrowser\Parser($ua);
            return [
                'raw' => $ua,
                'browser' => $result->browser->name,
                'version' => $result->browser->version->value,
                'platform' => $result->os->name,
                'engine' => $result->engine->name,
            ];
        }
        return null;
    }


    /**
     * A fallback parser in case no external library is available.
     *
     * It uses our own minimal User-Agent parser.
     *
     * @param string $ua The User-Agent string to parse
     * @return array A flattened array with keys 'raw', 'browser', 'version', 'platform', 'engine'
     */
    protected function parseUserAgentBasic(string $ua): array
    {
        $parser = new UAParser($ua);
        $parsed = $parser->parse();
        return array_merge(['raw' => $ua], $parsed);
    }
}
