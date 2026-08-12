<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Core;

use Infocyph\Webrick\Request\Request;

final class UriServerParams
{
    /**
     * @param array<string, mixed> $server
     * @return array{0: string, 1: int|null}
     */
    public static function detectHostPort(array $server, ?int $trustedProxyFlags = null): array
    {
        $forwardedHost = self::detectForwardedHost($server, $trustedProxyFlags);
        if ($forwardedHost !== null) {
            [$host, $port] = $forwardedHost;

            $port ??= self::forwardedPort($server, $trustedProxyFlags);

            return [$host, $port];
        }

        $rawHost = self::serverString($server, 'HTTP_HOST') ?? self::serverString($server, 'SERVER_NAME') ?? 'localhost';
        [$host, $port] = self::splitHostPort($rawHost);

        $port = self::forwardedPort($server, $trustedProxyFlags) ?? $port;

        $serverPort = self::serverString($server, 'SERVER_PORT');
        if ($port === null && $serverPort !== null) {
            $port = self::normPort($serverPort);
        }

        return [$host, $port];
    }

    /**
     * @param array<string, mixed> $server
     */
    public static function detectRequestUri(array $server): string
    {
        return self::serverString($server, 'REQUEST_URI') ?? '/';
    }

    /**
     * @param array<string, mixed> $server
     */
    public static function detectScheme(array $server, ?int $trustedProxyFlags = null): string
    {
        $fromForwarded = self::protoFromForwarded($server, $trustedProxyFlags);
        if ($fromForwarded !== null) {
            return $fromForwarded;
        }

        $fromXForwarded = self::protoFromXForwarded($server, $trustedProxyFlags);
        if ($fromXForwarded !== null) {
            return $fromXForwarded;
        }

        return self::protoFromServer($server);
    }

    /**
     * @param array<string, mixed> $server
     * @return array{0: string, 1: int|null}|null
     */
    private static function detectForwardedHost(array $server, ?int $trustedProxyFlags): ?array
    {
        $forwarded = self::firstServerCsvToken($server, 'HTTP_FORWARDED');
        if (
            self::proxyFlagEnabled(Request::HEADER_FORWARDED, $server, $trustedProxyFlags)
            && $forwarded !== null
            && preg_match('/host=(?:"([^"]+)"|([^;,\s]+))/i', $forwarded, $matches) === 1
        ) {
            $quoted = $matches[1];
            $raw = $quoted;
            if ($raw === '' && isset($matches[2])) {
                $raw = $matches[2];
            }

            return self::splitHostPort(trim($raw));
        }

        $forwardedHost = self::firstServerCsvToken($server, 'HTTP_X_FORWARDED_HOST');
        if (self::proxyFlagEnabled(Request::HEADER_X_FORWARDED_HOST, $server, $trustedProxyFlags) && $forwardedHost !== null) {
            return self::splitHostPort($forwardedHost);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function firstServerCsvToken(array $server, string $key): ?string
    {
        $value = self::serverString($server, $key);
        if ($value === null || $value === '') {
            return null;
        }

        $first = trim(explode(',', $value)[0]);

        return $first === '' ? null : $first;
    }

    /** @param array<string, mixed> $server */
    private static function forwardedPort(array $server, ?int $trustedProxyFlags): ?int
    {
        if (!self::proxyFlagEnabled(Request::HEADER_X_FORWARDED_PORT, $server, $trustedProxyFlags)) {
            return null;
        }

        $forwardedPort = self::firstServerCsvToken($server, 'HTTP_X_FORWARDED_PORT');

        return $forwardedPort === null ? null : self::normPort($forwardedPort);
    }

    private static function normPort(string $port): ?int
    {
        $number = (int) $port;

        return ($number > 0 && $number <= 65535) ? $number : null;
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function protoFromForwarded(array $server, ?int $trustedProxyFlags): ?string
    {
        if (!self::proxyFlagEnabled(Request::HEADER_FORWARDED, $server, $trustedProxyFlags)) {
            return null;
        }

        $first = self::firstServerCsvToken($server, 'HTTP_FORWARDED');
        if ($first === null) {
            return null;
        }

        if (preg_match('/proto="?([a-z]+)"?/i', $first, $matches) === 1) {
            $protocol = strtolower($matches[1]);

            return ($protocol === 'https' || $protocol === 'http') ? $protocol : null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function protoFromServer(array $server): string
    {
        $httpsValue = self::serverString($server, 'HTTPS');
        $requestScheme = self::serverString($server, 'REQUEST_SCHEME');
        $frontEndHttps = self::serverString($server, 'HTTP_FRONT_END_HTTPS');
        $serverPort = self::serverString($server, 'SERVER_PORT');

        $https
            = ($httpsValue !== null && strtolower($httpsValue) === 'on')
            || ($requestScheme !== null && strtolower($requestScheme) === 'https')
            || ($frontEndHttps !== null && strtolower($frontEndHttps) === 'on')
            || ($serverPort !== null && self::normPort($serverPort) === 443);

        return $https ? 'https' : 'http';
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function protoFromXForwarded(array $server, ?int $trustedProxyFlags): ?string
    {
        if (!self::proxyFlagEnabled(Request::HEADER_X_FORWARDED_PROTO, $server, $trustedProxyFlags)) {
            return null;
        }

        $first = self::firstServerCsvToken($server, 'HTTP_X_FORWARDED_PROTO');
        if ($first === null) {
            return null;
        }

        $first = strtolower($first);

        return $first === 'https' ? 'https' : ($first === 'http' ? 'http' : null);
    }

    /** @param array<string, mixed> $server */
    private static function proxyFlagEnabled(int $flag, array $server, ?int $trustedProxyFlags): bool
    {
        if ($trustedProxyFlags !== null) {
            return ($trustedProxyFlags & $flag) !== 0;
        }

        return Request::isFromTrustedProxy($server)
            && (Request::getProxyHeaderFlags() & $flag) !== 0;
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function serverString(array $server, string $key): ?string
    {
        if (!array_key_exists($key, $server)) {
            return null;
        }

        $value = $server[$key];
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        return null;
    }

    /**
     * @return array{0: string, 1: int|null}
     */
    private static function splitHostPort(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return ['', null];
        }

        if ($value[0] === '[') {
            if (preg_match('/^\[(?<host>[^\]]+)\](?::(?<port>\d{1,5}))?$/', $value, $matches) === 1) {
                $host = '[' . $matches['host'] . ']';
                $port = isset($matches['port']) ? self::normPort($matches['port']) : null;

                return [$host, $port];
            }

            return [$value, null];
        }

        if (preg_match('/^(?<host>[^:]+):(?<port>\d{1,5})$/', $value, $matches) === 1) {
            $port = self::normPort($matches['port']);

            return [$matches['host'], $port];
        }

        return [$value, null];
    }
}
