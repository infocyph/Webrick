<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Negotiation;

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Headers\Language;
use Infocyph\Webrick\Response\Response;

final class LocaleNegotiator
{
    /**
     * @param string[] $supported ordered by preference
     * @return array{0:string,1:array<array{string,string}>} [chosen, headerTuples]
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

    /**
     * Convenience: pull Accept-Language from Request and negotiate.
     *
     * @param string[] $supported
     */
    public static function forRequest(Request $req, array $supported, ?string $fallback = null): array
    {
        return self::negotiate($supported, $req->getHeaderLine('Accept-Language'), $fallback);
    }

    /**
     * Apply the header tuples returned by negotiate() to a Response.
     *
     * @param array<array{string,string}> $hdrs
     */
    public static function apply(Response $resp, array $hdrs): Response
    {
        foreach ($hdrs as [$k, $v]) {
            $resp = $resp->withHeader($k, $v);
        }
        return $resp;
    }
}
