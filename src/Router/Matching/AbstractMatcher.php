<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Router\Route\CompiledRoute;

require_once __DIR__ . '/matcher_functions.php';

/** Shared build/cache utilities for the canonical matcher implementations. */
abstract class AbstractMatcher
{
    protected const string F_ALIASES = '__aliases.php';

    /** Verify persisted matcher metadata when it is loaded at boot. */
    protected bool $verifyCacheOnLoad = false;

    /** Optional hook for kernels; concrete cache-aware matchers override it. */
    public function canBootFromCache(): bool
    {
        return false;
    }

    /**
     * Enable or disable persisted-cache verification at boot.
     */
    public function verifyCacheOnLoad(bool $enable = true): static
    {
        $this->verifyCacheOnLoad = $enable;

        return $this;
    }

    /**
     * Normalize a configured route host once at route-build time.
     */
    protected function canonicalRouteHost(?string $raw): string
    {
        if ($raw === null || $raw === '' || $raw === '*') {
            return '*';
        }

        $host = rtrim(strtolower($raw), '.');
        if (preg_match('/[\x00-\x20]/', $host)) {
            throw new \InvalidArgumentException("Illegal host name: {$raw}");
        }
        if (function_exists('idn_to_ascii') && !str_contains($host, 'xn--')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false) {
                throw new \InvalidArgumentException("Invalid IDN host name: {$raw}");
            }
            $host = $ascii;
        }
        if (!preg_match('/^[\x21-\x7E]+$/', $host)) {
            throw new \InvalidArgumentException("Host contains non-ASCII bytes: {$raw}");
        }

        return $host;
    }

    /**
     * @param array<mixed,mixed> $values
     */
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

    /** OPcache warm-up is boot/build work and must be valid for the active SAPI. */
    protected function shouldWarmOpcache(): bool
    {
        return matcher_should_warm_opcache();
    }
}
