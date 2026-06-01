<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Negotiation;

use Infocyph\Webrick\Request\Http\ContentNegotiator;
use Infocyph\Webrick\Request\Http\RequestHeaders;
use Infocyph\Webrick\Request\Request;

/**
 * Helpers to select the best response Content-Type from an Accept header.
 *
 * Thin convenience wrappers around RequestHeaders + ContentNegotiator to keep
 * controller/middleware code small.
 */
final class ContentTypeNegotiator
{
    /**
     * Choose a preferred content type using a full Request object.
     *
     * Convenience wrapper that constructs a RequestHeaders facade from the
     * given Request and delegates to chooseWithHeaders().
     *
     * @param Request $req PSR-like request object (used to read headers)
     * @param string[] $supported Ordered list of server-supported media types
     *                            (first is the server preference)
     * @return string|null The chosen media type from $supported, or null if none
     *                     of the provided types are acceptable (or $supported empty)
     */
    public static function chooseFromRequest(Request $req, array $supported): ?string
    {
        return self::chooseWithHeaders(new RequestHeaders($req), $supported);
    }

    /**
     * Choose a preferred content type using a RequestHeaders facade.
     *
     * Behaviour:
     *  - If $supported is empty returns null.
     *  - If the Accept header is missing or wildcard returns the server's
     *    first-preference media type.
     *  - Otherwise uses Request\Http\ContentNegotiator to select the preferred
     *    media type from $supported (respecting q-values and client preferences).
     *
     * @param RequestHeaders $headers Header facade providing access to Accept
     * @param string[] $supported Ordered list of server-supported media types
     * @return string|null Preferred media type from $supported, or null if none match
     */
    public static function chooseWithHeaders(RequestHeaders $headers, array $supported): ?string
    {
        $first = $supported[0] ?? null;
        if ($first === null) {
            return null;
        }

        $accept = $headers->all()->getHeaderLine('Accept');
        if ($accept === '' || $accept === '*/*') {
            return $first; // conventional default
        }

        $neg = new ContentNegotiator($headers);

        return $neg->preferred($supported) ?? null;
    }
}
