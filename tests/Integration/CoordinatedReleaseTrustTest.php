<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Build\ReleaseArtifactLoader;
use Infocyph\Webrick\Router\Build\ReleaseCompiler;
use Infocyph\Webrick\Router\Definition\Registrar;

test('coordinated release detects tampered executable router bytes before requiring PHP', function (): void {
    $directory = sys_get_temp_dir() . '/webrick-release-trust-' . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0700)) {
        throw new RuntimeException('Unable to create release trust fixture.');
    }

    $intermixPath = $directory . '/intermix.php';
    $routerPath = $directory . '/router.php';
    $manifestPath = $directory . '/release.json';
    $marker = $directory . '/executed';
    $builder = ContainerBuilder::create('webrick.release.trust.' . bin2hex(random_bytes(4)));
    $builder->input(Request::class);

    try {
        $manifest = new ReleaseCompiler()->compile(
            builder: $builder,
            register: static function (Registrar $registrar): void {
                $registrar->get('/safe', static fn(): Response => Response::plaintext('safe', 200));
            },
            environment: 'production',
            configFingerprint: 'coordinated-release-trust',
            intermixPath: $intermixPath,
            routerPath: $routerPath,
            releaseManifestPath: $manifestPath,
        );

        $intermix = $manifest['intermix'] ?? null;
        $webrick = $manifest['webrick'] ?? null;
        if (!is_array($intermix) || !is_array($webrick)) {
            throw new UnexpectedValueException('Release compiler did not emit artifact metadata.');
        }
        $intermixHash = $intermix['source_sha256'] ?? null;
        $webrickHash = $webrick['source_sha256'] ?? null;
        expect($intermixHash)->toBeString()
            ->and($webrickHash)->toBeString()
            ->and($webrickHash)->toBe(hash_file('sha256', $routerPath));

        // Even rehashing the less-trusted sidecar cannot bypass manifest SHA-256.
        $modified = '<?php file_put_contents(' . var_export($marker, true) . ', "executed"); return [];';
        if (file_put_contents($routerPath, $modified) === false) {
            throw new RuntimeException('Unable to mutate router fixture.');
        }
        $metaPath = $routerPath . '.meta.json';
        $metaJson = file_get_contents($metaPath);
        if (!is_string($metaJson)) {
            throw new RuntimeException('Router metadata fixture is missing.');
        }
        $meta = json_decode($metaJson, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($meta)) {
            throw new UnexpectedValueException('Invalid router fixture metadata.');
        }
        $meta['digest'] = hash('xxh128', $modified);
        file_put_contents($metaPath, json_encode($meta, JSON_THROW_ON_ERROR));

        expect(fn() => new ReleaseArtifactLoader()->load(
            $builder,
            $manifestPath,
            'production',
            'coordinated-release-trust',
        ))->toThrow(RuntimeException::class, 'router artifact digest');
        expect(is_file($marker))->toBeFalse();
    } finally {
        cleanTestCache($directory);
    }
});
