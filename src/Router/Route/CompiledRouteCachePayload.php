<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Route;

/**
 * Validates persisted route metadata at the cache boundary.
 *
 * @phpstan-type SegmentSpec array{type:'lit',val:string}|array{type:'var',name:string,regex:string}|array{type:'var',name:string,call:callable-string}
 * @phpstan-type CorsPayload array{origins:list<string>,methods:?string,headers:string|list<string>|null,exposeHeaders:string|list<string>|null,maxAgeSeconds:?int,allowCredentials:?bool,allowPrivateNetwork:?bool}
 * @phpstan-type ProducesPayload array{types:list<string>,charsets:list<string>|null}
 */
final class CompiledRouteCachePayload
{
    /**
     * @param array<mixed> $payload
     * @return array{0:int,1:string,2:string,3:array{0:string,1:string}|string,4:?string,5:list<string>,6:?string,7:bool,8:string,9:list<string>,10:int,11:?CorsPayload,12:?ProducesPayload,13:list<SegmentSpec>}
     */
    public static function validate(array $payload): array
    {
        if (count($payload) !== 14 || ($payload[0] ?? null) !== CompiledRoute::CACHE_PAYLOAD_VERSION) {
            throw new \UnexpectedValueException('Invalid compiled-route cache payload.');
        }

        return [
            CompiledRoute::CACHE_PAYLOAD_VERSION,
            self::string($payload[1] ?? null),
            self::string($payload[2] ?? null),
            self::handler($payload[3] ?? null),
            self::nullableString($payload[4] ?? null),
            self::stringList($payload[5] ?? null),
            self::nullableString($payload[6] ?? null),
            self::boolean($payload[7] ?? null),
            self::string($payload[8] ?? null),
            self::stringList($payload[9] ?? null),
            self::integer($payload[10] ?? null),
            self::cors($payload[11] ?? null),
            self::produces($payload[12] ?? null),
            self::segments($payload[13] ?? null),
        ];
    }

    private static function boolean(mixed $value): bool
    {
        if (!is_bool($value)) {
            throw new \UnexpectedValueException('Invalid boolean in compiled-route cache payload.');
        }

        return $value;
    }

    /**
     * @param callable-string $call
     * @return array{type:'var',name:string,call:callable-string}
     * @param string $name
     */
    private static function callSegment(string $name, string $call): array
    {
        return ['type' => 'var', 'name' => $name, 'call' => $call];
    }

    /**
     * @return CorsPayload|null
     * @param mixed $value
     */
    private static function cors(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value)) {
            throw new \UnexpectedValueException('Invalid CORS metadata in compiled-route cache payload.');
        }

        return [
            'origins' => self::stringList($value['origins'] ?? null),
            'methods' => self::nullableString($value['methods'] ?? null),
            'headers' => self::nullableStringList($value['headers'] ?? null),
            'exposeHeaders' => self::nullableStringList($value['exposeHeaders'] ?? null),
            'maxAgeSeconds' => self::nullableInteger($value['maxAgeSeconds'] ?? null),
            'allowCredentials' => self::nullableBoolean($value['allowCredentials'] ?? null),
            'allowPrivateNetwork' => self::nullableBoolean($value['allowPrivateNetwork'] ?? null),
        ];
    }

    /**
     * @return array{0:string,1:string}|string
     * @param mixed $value
     */
    private static function handler(mixed $value): array|string
    {
        if (is_string($value)) {
            return $value;
        }
        if (!is_array($value) || !array_is_list($value) || count($value) !== 2 || !is_string($value[0]) || !is_string($value[1])) {
            throw new \UnexpectedValueException('Invalid handler in compiled-route cache payload.');
        }

        return [$value[0], $value[1]];
    }

    private static function integer(mixed $value): int
    {
        if (!is_int($value)) {
            throw new \UnexpectedValueException('Invalid integer in compiled-route cache payload.');
        }

        return $value;
    }

    /**
     * @return array{type:'lit',val:string}
     * @param string $value
     */
    private static function literalSegment(string $value): array
    {
        return ['type' => 'lit', 'val' => $value];
    }

    private static function nullableBoolean(mixed $value): ?bool
    {
        return $value === null ? null : self::boolean($value);
    }

    private static function nullableInteger(mixed $value): ?int
    {
        return $value === null ? null : self::integer($value);
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : self::string($value);
    }

    /**
     * @return string|list<string>|null
     * @param mixed $value
     */
    private static function nullableStringList(mixed $value): array|string|null
    {
        if ($value === null || is_string($value)) {
            return $value;
        }

        return self::stringList($value);
    }

    /**
     * @return ProducesPayload|null
     * @param mixed $value
     */
    private static function produces(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value)) {
            throw new \UnexpectedValueException('Invalid Produces metadata in compiled-route cache payload.');
        }

        $types = self::stringList($value['types'] ?? null);
        if ($types === []) {
            throw new \UnexpectedValueException('Produces metadata requires at least one media type.');
        }

        $charsets = $value['charsets'] ?? null;

        return [
            'types' => $types,
            'charsets' => $charsets === null ? null : self::stringList($charsets),
        ];
    }

    /**
     * @return array{type:'var',name:string,regex:string}
     * @param string $name
     * @param string $regex
     */
    private static function regexSegment(string $name, string $regex): array
    {
        return ['type' => 'var', 'name' => $name, 'regex' => $regex];
    }

    /**
     * @return list<SegmentSpec>
     * @param mixed $value
     */
    private static function segments(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException('Invalid segments in compiled-route cache payload.');
        }

        $segments = [];
        foreach ($value as $segment) {
            if (!is_array($segment)) {
                throw new \UnexpectedValueException('Invalid segment in compiled-route cache payload.');
            }

            $type = self::string($segment['type'] ?? null);
            if ($type === 'lit') {
                $segments[] = self::literalSegment(self::string($segment['val'] ?? null));

                continue;
            }
            if ($type !== 'var') {
                throw new \UnexpectedValueException('Unknown segment type in compiled-route cache payload.');
            }

            $name = self::string($segment['name'] ?? null);
            if (is_string($segment['regex'] ?? null)) {
                $segments[] = self::regexSegment($name, $segment['regex']);

                continue;
            }

            $call = $segment['call'] ?? null;
            if (is_string($call) && is_callable($call)) {
                $segments[] = self::callSegment($name, $call);

                continue;
            }

            throw new \UnexpectedValueException('Invalid variable segment in compiled-route cache payload.');
        }

        return $segments;
    }

    private static function string(mixed $value): string
    {
        if (!is_string($value)) {
            throw new \UnexpectedValueException('Invalid string in compiled-route cache payload.');
        }

        return $value;
    }

    /**
     * @return list<string>
     * @param mixed $value
     */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException('Invalid string list in compiled-route cache payload.');
        }

        $list = [];
        foreach ($value as $entry) {
            $list[] = self::string($entry);
        }

        return $list;
    }
}
