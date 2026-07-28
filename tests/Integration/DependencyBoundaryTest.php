<?php

declare(strict_types=1);

it('keeps CacheLayer optional in production requirements', function (): void {
    $composer = json_decode(
        file_get_contents(dirname(__DIR__, 2) . '/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect(array_keys($composer['require']))->toBe([
        'php',
        'infocyph/arraykit',
        'infocyph/intermix',
        'psr/cache',
        'psr/log',
    ])->and($composer['require-dev']['infocyph/cachelayer'] ?? null)->toBe('^2.0')
        ->and($composer['suggest']['infocyph/cachelayer'] ?? null)->toBeString()
        ->and($composer['suggest'])->not->toHaveKeys([
            'psr/http-factory',
            'psr/http-server-middleware',
        ]);
});
