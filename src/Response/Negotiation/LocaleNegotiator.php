<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Negotiation;

use Infocyph\Webrick\Response\Headers\Language;

/**
 * Pick the best locale (RFC 4647 basic filtering) and
 * supply header tuples ready for the Response.
 *
 * ```php
 * [$chosen, $hdrs] = LocaleNegotiator::negotiate(
 *      ['en', 'bn-BD', 'fr'],
 *      $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''
 * );
 * // $hdrs → [ ['Content-Language','bn-BD'], ['Vary','Accept-Language'] ]
 * ```
 */
final class LocaleNegotiator
{
    /**
     * @param string[] $supported ordered by preference (e.g. config)
     * @return array{0:string,1:array<array{string,string}>}
     */
    public static function negotiate(array $supported, string $acceptLang): array
    {
        $best  = Language::negotiate($supported, $acceptLang);
        $hdrs  = Language::headers($best);
        return [$best, $hdrs];
    }
}
