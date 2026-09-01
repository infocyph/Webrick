<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Request\Core\Uri;
use Infocyph\Webrick\Router\Route\CompiledRoute;

require_once __DIR__ . '/matcher_functions.php';

/** Shared build/cache utilities for the canonical matcher implementations. */
abstract class AbstractMatcher
{
    protected const string F_ALIASES = '__aliases.php';

    /** Verify persisted matcher metadata when it is loaded at boot. */
    protected bool $verifyCacheOnLoad = false;

    public function canBootFromCache(): bool
    {
        return false;
    }

    public function verifyCacheOnLoad(bool $enable = true): static
    {
        $this->verifyCacheOnLoad = $enable;

        return $this;
    }

    /** Normalize a configured route host once at route-build time. */
    protected function canonicalRouteHost(?string $raw): string
    {
        if ($raw === null || $raw === '' || $raw === '*') {
            return '*';
        }

        try {
            $host = Uri::fromComponents(host: rtrim($raw, '.'))->getHost();
        } catch (\InvalidArgumentException $exception) {
            throw new \InvalidArgumentException("Illegal host name: {$raw}", 0, $exception);
        }
        if ($host === '') {
            throw new \InvalidArgumentException("Illegal host name: {$raw}");
        }

        return rtrim(strtolower($host), '.');
    }

    /** @param array<array-key,mixed> $values */
    protected function exportArray(array $values, int $depth = 0): string
    {
        $indent = str_repeat('    ', $depth);
        $out = "[\n";
        foreach ($values as $key => $value) {
            $out .= $indent . '    ' . var_export($key, true) . ' => ';
            $out .= is_array($value)
                ? $this->exportArray($value, $depth + 1)
                : $this->exportValue($value, $depth + 1);
            $out .= ",\n";
        }

        return $indent . rtrim($out, ",\n") . "\n" . $indent . ']';
    }

    protected function exportValue(mixed $value, int $depth): string
    {
        if ($value instanceof CompiledRoute) {
            return var_export(MatcherCachePayloadNormalizer::normalize($value), true);
        }

        return is_array($value)
            ? $this->exportArray($value, $depth)
            : var_export($value, true);
    }

    protected function shouldWarmOpcache(): bool
    {
        return matcher_should_warm_opcache();
    }
}
