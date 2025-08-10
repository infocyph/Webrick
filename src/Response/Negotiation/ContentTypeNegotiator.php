<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Negotiation;

use Infocyph\Webrick\Request\Http\RequestHeaders;
use Infocyph\Webrick\Request\Http\ContentNegotiator;
use Infocyph\Webrick\Request\Request;

final class ContentTypeNegotiator
{
    /**
     * @param string[] $supported ordered by caller preference
     * @return string|null        best match or null
     */
    public static function choose(array $supported, string $accept): ?string
    {
        $first = $supported[0] ?? null;
        if ($first === null) {
            return null;
        }

        // Treat empty Accept the same as */* (common convention)
        $wildOrEmpty = ($accept === '' || $accept === '*/*');
        $req = Request::fake(headers: ['Accept' => $wildOrEmpty ? '*/*' : $accept]);

        return new ContentNegotiator(new RequestHeaders($req))->preferred($supported) ?? ($wildOrEmpty ? $first : null);
    }
}
