<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Constants;

enum MatcherModeEnum: string
{
    case FUSED = 'fused';

    case GENERATED = 'generated';

    case SHARDED = 'sharded';

    public static function fromInput(?string $value, string $cachePath): self
    {
        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            self::FUSED->value => self::FUSED,
            self::GENERATED->value => self::GENERATED,
            self::SHARDED->value => self::SHARDED,
            default => str_ends_with($cachePath, '.php') ? self::FUSED : self::SHARDED,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $m): string => $m->value, self::cases());
    }
}
