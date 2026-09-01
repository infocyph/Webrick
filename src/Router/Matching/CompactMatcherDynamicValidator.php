<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

/**
 * Validates dynamic portions of the compact matcher IR.
 *
 * @phpstan-import-type PcreEntry from CompiledMatcherFastEngine
 * @phpstan-import-type PcreStep from CompiledMatcherFastEngine
 * @phpstan-import-type FastDispatchStep from CompiledMatcherFastEngine
 * @phpstan-import-type FastDispatch from CompiledMatcherFastEngine
 * @phpstan-import-type AllowedBucket from CompiledMatcherFastEngine
 * @phpstan-import-type FallbackStep from CompiledMatcherFastEngine
 * @phpstan-import-type DynamicBucket from CompiledMatcherFastEngine
 */
final class CompactMatcherDynamicValidator
{
    private function __construct() {}

    /**
     * @param array<int,mixed> $routes
     * @return array<string,array<int,array<string,DynamicBucket>>>
     */
    public static function validateDynamic(mixed $raw, array $routes, bool $validateRegex): array
    {
        if (!is_array($raw)) {
            throw new \UnexpectedValueException('Compact matcher dynamic map must be an array.');
        }

        $dynamic = [];
        foreach ($raw as $method => $counts) {
            if (!is_string($method) || $method === '' || !is_array($counts)) {
                throw new \UnexpectedValueException('Compact matcher dynamic method map is invalid.');
            }
            $dynamic[$method] = self::validateCounts($counts, $routes, $validateRegex);
        }

        return $dynamic;
    }

    /** @return array<int,array<string,AllowedBucket>> */
    public static function validateDynamicAllowed(mixed $raw, bool $validateRegex): array
    {
        if (!is_array($raw)) {
            throw new \UnexpectedValueException('Compact matcher dynamic allowed-method map must be an array.');
        }

        $allowed = [];
        foreach ($raw as $count => $prefixes) {
            if (!is_int($count) || $count < 0 || !is_array($prefixes)) {
                throw new \UnexpectedValueException('Compact matcher allowed-method count bucket is invalid.');
            }
            $allowed[$count] = self::validateAllowedPrefixes($prefixes, $validateRegex);
        }

        return $allowed;
    }

    private static function assertRegex(string $regex, bool $validateRegex, string $message): void
    {
        if ($validateRegex && preg_match($regex, '') === false) {
            throw new \UnexpectedValueException($message);
        }
    }

    /**
     * @phpstan-return AllowedBucket
     */
    private static function validateAllowedBucket(mixed $raw, bool $validateRegex): array
    {
        if (!is_array($raw)) {
            throw new \UnexpectedValueException('Compact matcher allowed-method prefix bucket is invalid.');
        }
        if (($raw['type'] ?? null) === 'fallback') {
            return ['type' => 'fallback'];
        }
        if (($raw['type'] ?? null) === 'single') {
            $regex = $raw['regex'] ?? null;
            if (!is_string($regex)) {
                throw new \UnexpectedValueException('Compact matcher single allowed-method terminal is invalid.');
            }
            self::assertRegex($regex, $validateRegex, 'Compact matcher single allowed-method PCRE cannot be compiled.');

            return [
                'type' => 'single',
                'regex' => $regex,
                'methods' => self::validateMethodList($raw['methods'] ?? null),
            ];
        }

        $segment = $raw['segment'] ?? null;
        $groups = $raw['groups'] ?? null;
        if (($raw['type'] ?? null) !== 'literal' || !is_int($segment) || $segment < 0 || !is_array($groups)) {
            throw new \UnexpectedValueException('Compact matcher allowed-method literal bucket is invalid.');
        }

        return [
            'type' => 'literal',
            'segment' => $segment,
            'groups' => self::validateAllowedGroups($groups, $validateRegex),
        ];
    }

    /**
     * @param array<array-key,mixed> $raw
     * @return array<array-key,array{regex:string,methods:list<string>}>
     */
    private static function validateAllowedGroups(array $raw, bool $validateRegex): array
    {
        $groups = [];
        foreach ($raw as $literal => $entry) {
            if (!is_array($entry) || !is_string($entry['regex'] ?? null)) {
                throw new \UnexpectedValueException('Compact matcher allowed-method entry is invalid.');
            }

            $regex = $entry['regex'];
            self::assertRegex($regex, $validateRegex, 'Compact matcher allowed-method PCRE cannot be compiled.');
            $groups[$literal] = [
                'regex' => $regex,
                'methods' => self::validateMethodList($entry['methods'] ?? null),
            ];
        }

        return $groups;
    }

    /**
     * @param array<array-key,mixed> $raw
     * @return array<string,AllowedBucket>
     */
    private static function validateAllowedPrefixes(array $raw, bool $validateRegex): array
    {
        $prefixes = [];
        foreach ($raw as $prefix => $bucket) {
            if (!is_string($prefix)) {
                throw new \UnexpectedValueException('Compact matcher allowed-method prefix bucket is invalid.');
            }
            $prefixes[$prefix] = self::validateAllowedBucket($bucket, $validateRegex);
        }

        return $prefixes;
    }

    /**
     * @param array<int,mixed> $routes
     * @phpstan-return DynamicBucket
     */
    private static function validateBucket(mixed $raw, array $routes, bool $validateRegex): array
    {
        if (!is_array($raw)) {
            throw new \UnexpectedValueException('Compact matcher dynamic bucket is invalid.');
        }

        $stepsRaw = $raw['steps'] ?? null;
        if (!is_array($stepsRaw) || !array_is_list($stepsRaw) || $stepsRaw === []) {
            throw new \UnexpectedValueException('Compact matcher dynamic steps are invalid.');
        }

        $steps = [];
        foreach ($stepsRaw as $step) {
            $steps[] = self::validateStep($step, $routes, $validateRegex);
        }

        $bucket = ['steps' => $steps];
        if (array_key_exists('fast_dispatch', $raw)) {
            $bucket['fast_dispatch'] = self::validateFastDispatch($raw['fast_dispatch'], $routes, $validateRegex);
        }

        return $bucket;
    }

    /**
     * @param array<array-key,mixed> $raw
     * @param array<int,mixed> $routes
     * @return array<int,array<string,DynamicBucket>>
     */
    private static function validateCounts(array $raw, array $routes, bool $validateRegex): array
    {
        $counts = [];
        foreach ($raw as $count => $prefixes) {
            if (!is_int($count) || $count < 0 || !is_array($prefixes)) {
                throw new \UnexpectedValueException('Compact matcher segment-count map is invalid.');
            }
            $counts[$count] = self::validatePrefixes($prefixes, $routes, $validateRegex);
        }

        return $counts;
    }

    /**
     * @param array<array-key,mixed> $raw
     * @param array<int,mixed> $routes
     * @phpstan-return FallbackStep
     */
    private static function validateFallbackStep(array $raw, array $routes): array
    {
        $id = $raw['id'] ?? null;
        if (!is_int($id) || !array_key_exists($id, $routes)) {
            throw new \UnexpectedValueException('Compact matcher fallback route ID is invalid.');
        }

        return [
            'type' => 'fallback',
            'segments' => self::validateSegments($raw['segments'] ?? null),
            'id' => $id,
        ];
    }

    /**
     * @param array<int,mixed> $routes
     * @phpstan-return FastDispatch
     */
    private static function validateFastDispatch(mixed $raw, array $routes, bool $validateRegex): array
    {
        if (!is_array($raw)) {
            throw new \UnexpectedValueException('Compact matcher adaptive dispatch is invalid.');
        }

        $segment = $raw['segment'] ?? null;
        $groups = $raw['groups'] ?? null;
        if (!is_int($segment) || $segment < 0 || !is_array($groups) || $groups === []) {
            throw new \UnexpectedValueException('Compact matcher adaptive metadata is invalid.');
        }

        return [
            'segment' => $segment,
            'groups' => self::validateFastGroups($groups, $routes, $validateRegex),
        ];
    }

    /**
     * @param array<array-key,mixed> $raw
     * @param array<int,mixed> $routes
     * @return array<array-key,list<FastDispatchStep>>
     */
    private static function validateFastGroups(array $raw, array $routes, bool $validateRegex): array
    {
        $groups = [];
        foreach ($raw as $literal => $steps) {
            if (!is_array($steps) || !array_is_list($steps) || $steps === []) {
                throw new \UnexpectedValueException('Compact matcher adaptive literal group is invalid.');
            }
            $groups[$literal] = self::validateFastSteps($steps, $routes, $validateRegex);
        }

        return $groups;
    }

    /**
     * @param array<int,mixed> $routes
     * @phpstan-return FastDispatchStep
     */
    private static function validateFastStep(mixed $raw, array $routes, bool $validateRegex): array
    {
        if (!is_array($raw) || !is_string($raw['regex'] ?? null) || !is_array($raw['routes'] ?? null)) {
            throw new \UnexpectedValueException('Compact matcher adaptive PCRE step is invalid.');
        }

        $regex = $raw['regex'];
        self::assertRegex($regex, $validateRegex, 'Compact matcher adaptive PCRE cannot be compiled.');

        return ['regex' => $regex, 'routes' => self::validateRouteMap($raw['routes'], $routes)];
    }

    /**
     * @param list<mixed> $raw
     * @param array<int,mixed> $routes
     * @return list<FastDispatchStep>
     */
    private static function validateFastSteps(array $raw, array $routes, bool $validateRegex): array
    {
        $steps = [];
        foreach ($raw as $step) {
            $steps[] = self::validateFastStep($step, $routes, $validateRegex);
        }

        return $steps;
    }

    /**
     * @param array<string,mixed> $segment
     * @return array{type:'lit',val:string}
     */
    private static function validateLiteralSegment(array $segment): array
    {
        $value = $segment['val'] ?? null;
        if (!is_string($value)) {
            throw new \UnexpectedValueException('Compact matcher fallback literal segment is invalid.');
        }

        return ['type' => 'lit', 'val' => $value];
    }

    /** @return list<string> */
    private static function validateMethodList(mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw) || $raw === []) {
            throw new \UnexpectedValueException('Compact matcher method list is invalid.');
        }

        $seen = [];
        foreach ($raw as $method) {
            if (!is_string($method) || $method === '' || isset($seen[$method])) {
                throw new \UnexpectedValueException('Compact matcher method token is invalid or duplicated.');
            }
            $seen[$method] = true;
        }

        return array_keys($seen);
    }

    /**
     * @param array<string,mixed> $segment
     * @return array{type:'op',name:string,code:int}
     */
    private static function validateOpcodeSegment(array $segment): array
    {
        $name = $segment['name'] ?? null;
        $code = $segment['code'] ?? null;
        if (!is_string($name) || $name === '' || !is_int($code) || !CompiledMatcherConstraintOpcode::isValid($code)) {
            throw new \UnexpectedValueException('Compact matcher fallback opcode segment is invalid.');
        }

        return ['type' => 'op', 'name' => $name, 'code' => $code];
    }

    /** @return list<string> */
    private static function validateParams(mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            throw new \UnexpectedValueException('Compact matcher parameter list is invalid.');
        }

        $params = [];
        foreach ($raw as $name) {
            if (!is_string($name) || $name === '') {
                throw new \UnexpectedValueException('Compact matcher parameter name is invalid.');
            }
            $params[] = $name;
        }

        return $params;
    }

    /**
     * @param array<array-key,mixed> $raw
     * @param array<int,mixed> $routes
     * @phpstan-return PcreStep
     */
    private static function validatePcreStep(array $raw, array $routes, bool $validateRegex): array
    {
        $regex = $raw['regex'] ?? null;
        $map = $raw['routes'] ?? null;
        if (!is_string($regex) || $regex === '' || !is_array($map) || $map === []) {
            throw new \UnexpectedValueException('Compact matcher PCRE step is invalid.');
        }

        self::assertRegex($regex, $validateRegex, 'Compact matcher PCRE cannot be compiled.');

        return ['type' => 'pcre', 'regex' => $regex, 'routes' => self::validateRouteMap($map, $routes)];
    }

    /**
     * @param array<array-key,mixed> $raw
     * @param array<int,mixed> $routes
     * @return array<string,DynamicBucket>
     */
    private static function validatePrefixes(array $raw, array $routes, bool $validateRegex): array
    {
        $prefixes = [];
        foreach ($raw as $prefix => $bucket) {
            if (!is_string($prefix)) {
                throw new \UnexpectedValueException('Compact matcher prefix bucket is invalid.');
            }
            $prefixes[$prefix] = self::validateBucket($bucket, $routes, $validateRegex);
        }

        return $prefixes;
    }

    /**
     * @param array<array-key,mixed> $raw
     * @param array<int,mixed> $routes
     * @return array<string,PcreEntry>
     */
    private static function validateRouteMap(array $raw, array $routes): array
    {
        $map = [];
        foreach ($raw as $mark => $entry) {
            if (!is_string($mark) || $mark === '' || !is_array($entry)) {
                throw new \UnexpectedValueException('Compact matcher PCRE route map is invalid.');
            }

            $id = $entry['id'] ?? null;
            if (!is_int($id) || !array_key_exists($id, $routes)) {
                throw new \UnexpectedValueException('Compact matcher PCRE route ID is invalid.');
            }
            $map[$mark] = ['id' => $id, 'params' => self::validateParams($entry['params'] ?? null)];
        }

        return $map;
    }

    /** @return array<string,mixed> */
    private static function validateSegment(mixed $segment): array
    {
        if (!is_array($segment) || !is_string($segment['type'] ?? null)) {
            throw new \UnexpectedValueException('Compact matcher fallback segment is invalid.');
        }

        /** @var array<string,mixed> $segment */
        return match ($segment['type']) {
            'lit' => self::validateLiteralSegment($segment),
            'op' => self::validateOpcodeSegment($segment),
            'var' => self::validateVariableSegment($segment),
            default => throw new \UnexpectedValueException('Compact matcher fallback segment type is invalid.'),
        };
    }

    /** @return list<array<string,mixed>> */
    private static function validateSegments(mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            throw new \UnexpectedValueException('Compact matcher fallback segments are invalid.');
        }

        $segments = [];
        foreach ($raw as $segment) {
            $segments[] = self::validateSegment($segment);
        }

        return $segments;
    }

    /**
     * @param array<int,mixed> $routes
     * @phpstan-return PcreStep|FallbackStep
     */
    private static function validateStep(mixed $raw, array $routes, bool $validateRegex): array
    {
        if (!is_array($raw)) {
            throw new \UnexpectedValueException('Compact matcher dynamic step is invalid.');
        }

        return match ($raw['type'] ?? null) {
            'pcre' => self::validatePcreStep($raw, $routes, $validateRegex),
            'fallback' => self::validateFallbackStep($raw, $routes),
            default => throw new \UnexpectedValueException('Compact matcher dynamic step type is invalid.'),
        };
    }

    /**
     * @param array<string,mixed> $segment
     * @return array<string,mixed>
     */
    private static function validateVariableSegment(array $segment): array
    {
        $name = $segment['name'] ?? null;
        if (!is_string($name) || $name === '') {
            throw new \UnexpectedValueException('Compact matcher fallback variable segment is invalid.');
        }

        $regex = $segment['regex'] ?? null;
        if (is_string($regex)) {
            return ['type' => 'var', 'name' => $name, 'regex' => $regex];
        }

        $call = $segment['call'] ?? null;
        if (!is_string($call) || !is_callable($call)) {
            throw new \UnexpectedValueException('Compact matcher fallback callable segment is invalid.');
        }

        return ['type' => 'var', 'name' => $name, 'call' => $call];
    }
}
