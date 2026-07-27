<?php

declare(strict_types=1);

use Infocyph\Webrick\Support\RouteCache;

it('distinguishes removed and missing route cache files', function (): void {
    $directory = sys_get_temp_dir() . '/webrick-route-cache-' . bin2hex(random_bytes(6));
    $cache = $directory . '/fused.php';
    mkdir($directory, 0775, true);
    file_put_contents($cache, '<?php return [];');

    try {
        expect(RouteCache::clear([
            'matcher' => 'fused',
            'cache' => $cache,
        ]))->toBeTrue()
            ->and(is_file($cache))->toBeFalse()
            ->and(RouteCache::clear([
                'matcher' => 'fused',
                'cache' => $cache,
            ]))->toBeFalse();
    } finally {
        if (is_file($cache)) {
            unlink($cache);
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
});

it('fails closed when a route cache directory is not writable', function (): void {
    $directory = sys_get_temp_dir() . '/webrick-route-cache-' . bin2hex(random_bytes(6));
    $cache = $directory . '/fused.php';
    mkdir($directory, 0775, true);
    file_put_contents($cache, '<?php return [];');
    chmod($directory, 0555);

    try {
        if (is_writable($directory)) {
            test()->markTestSkipped('The current user can write read-only directories.');
        }

        expect(fn() => RouteCache::clear([
            'matcher' => 'fused',
            'cache' => $cache,
        ]))->toThrow(RuntimeException::class, 'Route cache directory is not writable');
    } finally {
        chmod($directory, 0775);
        if (is_file($cache)) {
            unlink($cache);
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
});
