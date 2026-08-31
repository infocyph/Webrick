<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Runtime\Http;

use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Interfaces\BodyStream;
use Infocyph\Webrick\Request\Core\StringBody;
use Infocyph\Webrick\Request\Core\Uri;
use Infocyph\Webrick\Request\Request;

/** Build a full Request only after the compiled execution plan asks for one. */
final readonly class TransportRequestFactory
{
    /**
     * @param array<string,mixed> $server
     * @param array<string,string|list<string>> $headers
     * @param array<string,mixed>|object|null $parsed
     * @param array<string,mixed> $files
     * @param array<string,mixed> $query
     * @param array<string,mixed> $cookies
     */
    public static function fromParts(
        array $server,
        array $headers,
        string|BodyStream $body = '',
        array|object|null $parsed = null,
        array $files = [],
        array $query = [],
        array $cookies = [],
    ): Request {
        $protocol = self::stringValue($server, 'SERVER_PROTOCOL', 'HTTP/1.1');
        $httpVersion = str_starts_with($protocol, 'HTTP/') ? substr($protocol, 5) : '1.1';

        return new Request(
            HttpMethodEnum::normalize(self::stringValue($server, 'REQUEST_METHOD', HttpMethodEnum::GET->value)),
            Uri::fromServerParams($server),
            $server,
            $headers,
            is_string($body) ? new StringBody($body) : $body,
            $httpVersion,
            $parsed,
            $files,
            query: $query,
            cookies: $cookies,
        );
    }

    /**
     * @param array<string,mixed> $source
     */
    private static function stringValue(array $source, string $key, string $default = ''): string
    {
        $value = $source[$key] ?? null;

        return is_string($value) ? $value : $default;
    }
}
