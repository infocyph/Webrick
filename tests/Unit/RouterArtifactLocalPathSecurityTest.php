<?php

declare(strict_types=1);

use Infocyph\Webrick\Router\Build\RouterArtifactLoader;

test('router artifact loader rejects stream-wrapper paths on both trust paths', function (): void {
    $loader = new RouterArtifactLoader();
    foreach ([
        'php://filter/resource=/etc/passwd',
        'phar://untrusted.phar/routes.php',
        'https://example.invalid/compiled.php',
    ] as $path) {
        expect(fn() => $loader->load($path, 'production', 'fingerprint'))
            ->toThrow(UnexpectedValueException::class, 'local filesystem path');

        expect(fn() => $loader->loadPrevalidated(
            $path,
            str_repeat('a', 32),
            'production',
            'fingerprint',
        ))->toThrow(UnexpectedValueException::class, 'local filesystem path');
    }
});
