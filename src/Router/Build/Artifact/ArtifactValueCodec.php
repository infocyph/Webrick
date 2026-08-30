<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Build\Artifact;

use UnexpectedValueException;

use function Opis\Closure\serialize as opis_serialize;
use function Opis\Closure\unserialize as opis_unserialize;

/**
 * Build-time/runtime-boundary codec for handler and middleware descriptors.
 */
final class ArtifactValueCodec
{
    private const string SERIALIZED = 'serialized';
    private const string VALUE = 'value';

    private function __construct() {}

    /** @return array{kind:string,value:mixed} */
    public static function encode(mixed $value): array
    {
        if (is_string($value)) {
            return ['kind' => self::VALUE, 'value' => $value];
        }

        if (
            is_array($value)
            && array_is_list($value)
            && count($value) === 2
            && isset($value[0], $value[1])
            && is_string($value[0])
            && is_string($value[1])
        ) {
            return ['kind' => self::VALUE, 'value' => [$value[0], $value[1]]];
        }

        return [
            'kind' => self::SERIALIZED,
            'value' => base64_encode(opis_serialize($value)),
        ];
    }

    public static function decode(mixed $payload): mixed
    {
        if (!is_array($payload) || !is_string($payload['kind'] ?? null)) {
            throw new UnexpectedValueException('Invalid Webrick artifact value descriptor.');
        }

        if ($payload['kind'] === self::VALUE) {
            $value = $payload['value'] ?? null;
            if (is_string($value)) {
                return $value;
            }
            if (
                is_array($value)
                && array_is_list($value)
                && count($value) === 2
                && isset($value[0], $value[1])
                && is_string($value[0])
                && is_string($value[1])
            ) {
                return [$value[0], $value[1]];
            }

            throw new UnexpectedValueException('Invalid scalar Webrick artifact value.');
        }

        if ($payload['kind'] !== self::SERIALIZED || !is_string($payload['value'] ?? null)) {
            throw new UnexpectedValueException('Unknown Webrick artifact value descriptor.');
        }

        $serialized = base64_decode($payload['value'], true);
        if ($serialized === false || $serialized === '') {
            throw new UnexpectedValueException('Invalid serialized Webrick artifact value.');
        }

        try {
            return opis_unserialize($serialized);
        } catch (\Throwable $exception) {
            throw new UnexpectedValueException('Unable to restore Webrick artifact value.', 0, $exception);
        }
    }
}
