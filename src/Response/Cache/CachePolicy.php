<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Cache;

use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Constants\StatusEnum;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

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
        if ($line === '') {
            return [];
        }

        $directives = [];
        foreach (explode(',', $line) as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }
            if (!str_contains($segment, '=')) {
                $directives[strtolower($segment)] = true;

                continue;
            }

            [$name, $value] = array_map(trim(...), explode('=', $segment, 2));
            $directives[strtolower($name)] = trim($value, "\"'");
        }

        return $directives;
    }

    public function lookupAllowed(Request $request, bool $skipPersonalized = true): bool
    {
        $method = HttpMethodEnum::normalize($request->getMethod());
        if ($method !== HttpMethodEnum::GET->value && $method !== HttpMethodEnum::HEAD->value) {
            return false;
        }
        if ($request->hasHeader('Range') || $request->hasHeader('If-Range')) {
            return false;
        }
        if ($request->hasHeader('Authorization') || $request->hasHeader('Cookie')) {
            return false;
        }
        if ($skipPersonalized && $request->getAttribute('personalized') === true) {
            return false;
        }

        $directives = self::directives($request->getHeaderLine('Cache-Control'));

        return !isset($directives['no-store']) && !isset($directives['no-cache']);
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
        if (isset($directives['no-store']) || isset($directives['private'])) {
            return 0;
        }

        $cap = self::seconds($directives['s-maxage'] ?? null)
            ?? self::seconds($directives['max-age'] ?? null);

        return $cap === null ? $ttl : min($ttl, $cap);
    }

    private static function seconds(mixed $value): ?int
    {
        return is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1
            ? (int) $value
            : null;
    }
}
