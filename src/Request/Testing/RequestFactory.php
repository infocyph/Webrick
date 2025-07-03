<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Testing;

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Request\Core\{Stream, Uri};
use Psr\Http\Message\UploadedFileInterface;

/**
 * Quick helpers to fabricate facade Requests for unit / feature tests.
 */
final class RequestFactory
{
    /** Generic builder  -------------------------------------------------- */
    public static function make(
        string $method = 'GET',
        string $uri = '/',
        array  $query = [],
        array|object|string|null $body = null,
        array  $headers = [],
        array  $cookies = [],
        array  $files = [],
        array  $server = []
    ): Request {
        $payloadStream = is_string($body) ? new Stream($body) : new Stream('');

        $req = new Request(
            $method,
            Uri::from($uri),
            $server,
            $headers,
            $payloadStream,
            '1.1',
            (is_array($body) || is_object($body)) ? $body : null,
            $files
        );

        return $req
            ->withQueryParams($query)
            ->withCookieParams($cookies);
    }

    /** JSON helper  ------------------------------------------------------ */
    public static function json(
        string $method,
        string $uri,
        array  $jsonPayload,
        array  $headers = [],
        array  $cookies = []
    ): Request {
        $headers['Content-Type'] = 'application/json';
        return self::make(
            $method,
            $uri,
            query   : [],
            body    : json_encode($jsonPayload, JSON_THROW_ON_ERROR),
            headers : $headers,
            cookies : $cookies
        );
    }

    /** Multipart helper  ------------------------------------------------- *
     * @param array<string,mixed> $fields
     * @param array<string,UploadedFileInterface> $files
     */
    public static function multipart(
        string $uri,
        array $fields,
        array $files
    ): Request {
        $headers = ['Content-Type' => 'multipart/form-data'];
        return self::make('POST', $uri, [], $fields, $headers, [], $files);
    }
}
