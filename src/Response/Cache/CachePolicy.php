<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Cache;

use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Constants\StatusEnum;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Headers\CacheControl;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Support\HttpUtils;

/** Shared-cache policy used by response-cache middleware. */
final readonly class CachePolicy
{
    private const array DEFAULT_CACHEABLE_STATUS = [
        StatusEnum::OK->value => true,
        StatusEnum::NON_AUTHORITATIVE_INFO->value => true,
        StatusEnum::NO_CONTENT->value => true,
        StatusEnum::MULTIPLE_CHOICES->value => true,
        StatusEnum::MOVED_PERMANENTLY->value => true,
        StatusEnum::PERMANENT_REDIRECT->value => true,
        StatusEnum::NOT_FOUND->value => true,
        StatusEnum::METHOD_NOT_ALLOWED->value => true,
        StatusEnum::GONE->value => true,
        StatusEnum::URI_TOO_LONG->value => true,
        StatusEnum::UNAVAILABLE_FOR_LEGAL_REASONS->value => true,
        StatusEnum::NOT_IMPLEMENTED->value => true,
    ];

    /** @return array<string,true|string> */
    public static function directives(string $line): array
    {
        if (HttpUtils::splitQuoted($line, ',') === null) {
            return ['no-store' => true];
        }

        return CacheControl::directives($line);
    }

    public function lookupAllowed(Request $request, bool $skipPersonalized = true): bool
    {
        $method = HttpMethodEnum::normalize($request->getMethod());
        if ($method !== HttpMethodEnum::GET->value && $method !== HttpMethodEnum::HEAD->value) {
            return false;
        }
        if (array_any(
            ['Range', 'If-Range', 'If-Match', 'If-None-Match', 'If-Modified-Since', 'If-Unmodified-Since'],
            $request->hasHeader(...),
        )) {
            return false;
        }
        if ($request->hasHeader('Authorization') || $request->hasHeader('Cookie')) {
            return false;
        }
        if ($skipPersonalized && $request->getAttribute('personalized') === true) {
            return false;
        }

        $cacheControl = $request->getHeaderLine('Cache-Control');
        $directives = self::directives($cacheControl);
        if (isset($directives['no-store']) || isset($directives['no-cache'])) {
            return false;
        }
        if (isset($directives['max-age']) || isset($directives['min-fresh'])) {
            return false;
        }

        return $cacheControl !== '' || stripos($request->getHeaderLine('Pragma'), 'no-cache') === false;
    }

    public function storeTtl(
        Request $request,
        Response $response,
        int $baseTtl,
        bool $respectCacheControl = true,
        bool $avoidSetCookie = true,
    ): int {
        if (!$this->lookupAllowed($request, false)) {
            return 0;
        }
        if (!isset(self::DEFAULT_CACHEABLE_STATUS[$response->getStatusCode()])) {
            return 0;
        }
        if ($avoidSetCookie && $response->hasHeader('Set-Cookie')) {
            return 0;
        }

        $ttl = max(0, $baseTtl);
        if (!$respectCacheControl) {
            return $ttl;
        }

        $directives = self::directives($response->getHeaderLine('Cache-Control'));
        if (isset($directives['no-store']) || isset($directives['private']) || isset($directives['no-cache'])) {
            return 0;
        }

        $now = time();
        $cap = $this->freshnessLifetime($response, $directives, $now);
        $age = $this->responseAge($response, $now);

        return $age === null ? 0 : min($ttl, max(0, $cap - $age));
    }

    private static function seconds(mixed $value): ?int
    {
        return is_string($value) ? HttpUtils::parseUnsignedDecimal($value) : null;
    }

    /** @param array<string,true|string> $directives */
    private function freshnessLifetime(Response $response, array $directives, int $now): int
    {
        foreach (['s-maxage', 'max-age'] as $name) {
            if (isset($directives[$name])) {
                return self::seconds($directives[$name]) ?? 0;
            }
        }
        if ($response->hasHeader('Expires')) {
            $expires = HttpUtils::parseHttpDate($response->getHeaderLine('Expires'));
            $date = HttpUtils::parseHttpDate($response->getHeaderLine('Date')) ?? $now;

            return $expires === null ? 0 : max(0, $expires - $date);
        }

        return PHP_INT_MAX;
    }

    private function responseAge(Response $response, int $now): ?int
    {
        $age = $response->hasHeader('Age') ? self::seconds($response->getHeaderLine('Age')) : 0;
        if ($age === null) {
            return null;
        }
        $date = HttpUtils::parseHttpDate($response->getHeaderLine('Date'));

        return $date === null ? $age : max($age, $now - $date);
    }
}
