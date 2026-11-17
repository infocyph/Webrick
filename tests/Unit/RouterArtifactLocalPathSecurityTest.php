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

test('prevalidated artifact rejects modified PHP before executing injected code', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'webrick-artifact-');
    if (!is_string($path)) {
        throw new RuntimeException('Unable to create router artifact fixture.');
    }

    $metaPath = $path . '.meta.json';
    $marker = $path . '.executed';
    $fingerprint = str_repeat('a', 32);

    try {
        $original = '<?php return ' . var_export(['artifact_fingerprint' => $fingerprint], true) . ';';
        file_put_contents($path, $original);
        file_put_contents($metaPath, json_encode([
            'format' => \Infocyph\Webrick\Router\Build\CompiledRouterArtifact::FORMAT_VERSION,
            'environment' => 'production',
            'config_fingerprint' => 'known-configuration',
            'artifact_fingerprint' => $fingerprint,
            'digest' => hash('xxh128', $original),
        ], JSON_THROW_ON_ERROR));

        $modified = '<?php file_put_contents(' . var_export($marker, true) . ', "executed");'
            . ' return ' . var_export(['artifact_fingerprint' => $fingerprint], true) . ';';
        file_put_contents($path, $modified);

        expect(fn () => new RouterArtifactLoader()->loadPrevalidated(
            $path,
            $fingerprint,
            'production',
            'known-configuration',
        ))->toThrow(RuntimeException::class, 'digest mismatch');
        expect(is_file($marker))->toBeFalse();
    } finally {
        foreach ([$marker, $metaPath, $path] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
});

test('router artifact loader rejects NUL-byte paths before metadata or PHP execution', function (): void {
    $loader = new RouterArtifactLoader();
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'compiled' . chr(0) . 'artifact.php';

    expect(fn () => $loader->load($path, 'production', 'fingerprint'))
        ->toThrow(UnexpectedValueException::class, 'local filesystem path');

    expect(fn () => $loader->loadPrevalidated($path, str_repeat('a', 32), 'production', 'fingerprint'))
        ->toThrow(UnexpectedValueException::class, 'local filesystem path');
});
