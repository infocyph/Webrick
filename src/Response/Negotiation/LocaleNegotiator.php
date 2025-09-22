<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Negotiation;

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Headers\Language;
use Infocyph\Webrick\Response\Response;

final class LocaleNegotiator
{
    /**
     * Apply header tuples produced by negotiate() to a Response instance.
     *
     * Iterates over $hdrs and calls withHeader() for each tuple. The Response API
     * is treated as immutable; the returned Response may be a new instance.
     *
     * @param Response $resp Response to apply headers to.
     * @param array<array{string,string}> $hdrs Array of [name, value] header tuples.
     * @return Response Response with the provided headers applied.
     */
    public static function apply(Response $resp, array $hdrs): Response
    {
        foreach ($hdrs as [$k, $v]) {
            $resp = $resp->withHeader($k, $v);
        }
        return $resp;
    }

    /**
     * Convenience helper: extract Accept-Language from a Request and negotiate.
     *
     * Reads the 'Accept-Language' header line from $req and delegates to negotiate().
     *
     * @param Request $req PSR-like request object to read headers from.
     * @param string[] $supported Ordered list of supported language tags.
     * @param string|null $fallback Optional fallback language tag.
     * @return array{0:string,1:array<array{string,string}>} Tuple of chosen language and header tuples.
     */
    public static function forRequest(Request $req, array $supported, ?string $fallback = null): array
    {
        return self::negotiate($supported, $req->getHeaderLine('Accept-Language'), $fallback);
    }
    /**
     * Select a locale from the client's Accept-Language header and produce headers.
     *
     * Behaviour:
     *  - $supported is an ordered list of supported language tags (server preference).
     *  - If $supported is empty a single entry is synthesized from $fallback or 'en'.
     *  - Uses Language::negotiate() to pick the best match from $acceptLang.
     *  - If negotiation yields an empty string and $fallback is provided, $fallback is used.
     *  - Returns a two-element tuple: [chosenLocale, headerTuples], where headerTuples
     *    is an array of [name, value] pairs suitable for applying to a Response.
     *
     * @param string[] $supported Ordered list of supported language tags.
     * @param string $acceptLang Raw Accept-Language header value.
     * @param string|null $fallback Optional fallback language tag to use when no match.
     * @return array{0:string,1:array<array{string,string}>} Tuple of chosen language and header tuples.
     */
    public static function negotiate(array $supported, string $acceptLang, ?string $fallback = null): array
    {
        // guarantee a non-empty supported set
        if ($supported === []) {
            $supported = [$fallback ?? 'en'];
        }

        $chosen = Language::negotiate($supported, $acceptLang);
        if ($chosen === '' && $fallback !== null) {
            $chosen = $fallback;
        }

        return [$chosen, Language::headers($chosen)];
    }
}
