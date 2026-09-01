<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Router\Build\Artifact\ExecutableRoutePayload;
use Infocyph\Webrick\Router\Route\CompiledRoute;

/** Produces canonical structural metadata used for matcher cache hashing and emission. */
final class MatcherCachePayloadNormalizer
{
    public static function normalize(mixed $value): mixed
    {
        if ($value instanceof CompiledRoute) {
            return ExecutableRoutePayload::encode($value);
        }
        if (!is_array($value)) {
            return $value;
        }

        $normalized = [];
        foreach ($value as $key => $entry) {
            $normalized[$key] = self::normalize($entry);
        }

        return $normalized;
    }
}
