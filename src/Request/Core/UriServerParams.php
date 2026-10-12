<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Core;

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Support\HttpUtils;

final class UriServerParams
{
    /**
     * @param array<string,mixed> $server
     * @return array{0:string,1:int|null}
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

    /** @param array<string,mixed> $server */
    public static function detectRequestUri(array $server): string
    {
        return self::serverString($server, 'REQUEST_URI') ?? '/';
    }

    /** @param array<string,mixed> $server */
    public static function detectScheme(array $server, ?int $trustedProxyFlags = null): string
    {
        $fromForwarded = self::protoFromForwarded($server, $trustedProxyFlags);
        if ($fromForwarded !== null) {
            return $fromForwarded;
        }
        $fromXForwarded = self::protoFromXForwarded($server, $trustedProxyFlags);

        return $fromXForwarded ?? self::protoFromServer($server);
    }

    /**
     * @param array<string,mixed> $server
     * @return array{0:string,1:int|null}|null
     */
    private static function detectForwardedHost(array $server, ?int $trustedProxyFlags): ?array
    {
        $forwarded = self::proxyFlagEnabled(Request::HEADER_FORWARDED, $server, $trustedProxyFlags)
            ? self::forwardedParameter($server, 'host') : null;
        if ($forwarded !== null) {
            return self::splitHostPort($forwarded);
        }

        $forwardedHost = self::proxyFlagEnabled(Request::HEADER_X_FORWARDED_HOST, $server, $trustedProxyFlags)
            ? self::firstServerCsvToken($server, 'HTTP_X_FORWARDED_HOST') : null;
        if ($forwardedHost !== null) {
            return self::splitHostPort($forwardedHost);
        }

        return null;
    }

    /** @param array<string,mixed> $server */
    private static function firstServerCsvToken(array $server, string $key): ?string
    {
        $value = self::serverString($server, $key);
        if ($value === null || $value === '') {
            return null;
        }

        $tokens = HttpUtils::splitQuoted($value, ',', rejectEmpty: true) ?? [];
        if ($tokens === []) {
            return null;
        }
        $first = trim($tokens[0]);

        return $first === '' ? null : $first;
    }

    /** @param array<string,mixed> $server */
    private static function forwardedParameter(array $server, string $name): ?string
    {
        $first = self::firstServerCsvToken($server, 'HTTP_FORWARDED');
        $parameters = $first === null ? null : HttpUtils::splitQuoted($first, ';', rejectEmpty: true);
        if ($parameters === null) {
            return null;
        }
        $values = [];
        foreach ($parameters as $parameter) {
            if (preg_match('/^([!#$%&\x27*+.^_`|~0-9A-Za-z-]+)=("(?:[^"\\\\]|\\\\.)*"|[^\x00-\x20\x7F";,=]+)$/D', $parameter, $matches) !== 1) {
                return null;
            }
            $key = strtolower($matches[1]);
            if (isset($values[$key])) {
                return null;
            }
            $value = $matches[2];
            $values[$key] = $value[0] === '"'
                ? preg_replace('/\\\\(.)/s', '$1', substr($value, 1, -1))
                : $value;
        }

        return $values[$name] ?? null;
    }

    /** @param array<string,mixed> $server */
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
        if (preg_match('/^[0-9]{1,5}$/D', trim($port)) !== 1) {
            return null;
        }
        $number = (int) $port;

        return ($number > 0 && $number <= 65535) ? $number : null;
    }

    /** @param array<string,mixed> $server */
    private static function protoFromForwarded(array $server, ?int $trustedProxyFlags): ?string
    {
        if (!self::proxyFlagEnabled(Request::HEADER_FORWARDED, $server, $trustedProxyFlags)) {
            return null;
        }

        $protocol = self::forwardedParameter($server, 'proto');
        if ($protocol === null) {
            return null;
        }
        $protocol = strtolower($protocol);

        return ($protocol === 'https' || $protocol === 'http') ? $protocol : null;
    }

    /** @param array<string,mixed> $server */
    private static function protoFromServer(array $server): string
    {
        $httpsValue = self::serverString($server, 'HTTPS');
        $requestScheme = self::serverString($server, 'REQUEST_SCHEME');
        $serverPort = self::serverString($server, 'SERVER_PORT');

        $https
            = ($httpsValue !== null && in_array(strtolower($httpsValue), ['on', '1'], true))
            || ($requestScheme !== null && strtolower($requestScheme) === 'https')
            || ($serverPort !== null && self::normPort($serverPort) === 443);

        return $https ? 'https' : 'http';
    }

    /** @param array<string,mixed> $server */
    private static function protoFromXForwarded(array $server, ?int $trustedProxyFlags): ?string
    {
        if (!self::proxyFlagEnabled(Request::HEADER_X_FORWARDED_PROTO, $server, $trustedProxyFlags)) {
            return null;
        }

        $last = self::firstServerCsvToken($server, 'HTTP_X_FORWARDED_PROTO');
        if ($last === null) {
            return null;
        }
        $last = strtolower($last);

        return $last === 'https' ? 'https' : ($last === 'http' ? 'http' : null);
    }

    /** @param array<string,mixed> $server */
    private static function proxyFlagEnabled(int $flag, array $server, ?int $trustedProxyFlags): bool
    {
        if ($trustedProxyFlags !== null) {
            return ($trustedProxyFlags & $flag) !== 0;
        }

        return Request::isFromTrustedProxy($server)
            && (Request::getProxyHeaderFlags() & $flag) !== 0;
    }

    /** @param array<string,mixed> $server */
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

    /** @return array{0:string,1:int|null} */
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
            return [$matches['host'], self::normPort($matches['port'])];
        }

        return [$value, null];
    }
}
