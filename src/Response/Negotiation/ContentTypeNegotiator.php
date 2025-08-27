<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Negotiation;

use Infocyph\Webrick\Request\Http\RequestHeaders;
use Infocyph\Webrick\Request\Http\ContentNegotiator;
use Infocyph\Webrick\Request\Request;

final class ContentTypeNegotiator
{
    /**
     * Convenience for controllers/middleware.
     * Uses Request → RequestHeaders → ContentNegotiator.
     *
     * @param string[] $supported ordered by caller preference
     */
    public static function chooseFromRequest(Request $req, array $supported): ?string
    {
        return self::chooseWithHeaders(new RequestHeaders($req), $supported);
    }

    /**
     * If you’ve already built a RequestHeaders façade, use this.
     *
     * @param string[] $supported ordered by caller preference
     */
    public static function chooseWithHeaders(RequestHeaders $headers, array $supported): ?string
    {
        $first = $supported[0] ?? null;
        if ($first === null) {
            return null;
        }

        $accept = $headers->all()->getHeaderLine('Accept') ?? '';
        if ($accept === '' || $accept === '*/*') {
            return $first; // conventional default
        }

        $neg = new ContentNegotiator($headers);
        return $neg->preferred($supported) ?? null;
    }
}
