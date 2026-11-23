<?php

declare(strict_types=1);

use function Infocyph\Webrick\Router\Matching\matcher_write_validated_atomic_php_file;

test('generated PHP cache staging is private to the destination directory and safely published', function (): void {
    $directory = sys_get_temp_dir() . '/webrick-cache-stage-' . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0700)) {
        throw new RuntimeException('Unable to allocate matcher cache test directory.');
    }

    $path = $directory . '/matcher.php';
    $php = "<?php return ['status' => 'valid'];\n";

    try {
        matcher_write_validated_atomic_php_file(
            $path,
            $php,
            static function (array $payload): void {
                expect($payload['status'] ?? null)->toBe('valid');
            },
        );

        expect(file_get_contents($path))->toBe($php);
        $permissions = fileperms($path);
        if (!is_int($permissions)) {
            throw new RuntimeException('Unable to inspect generated matcher cache permissions.');
        }
        expect($permissions & 0o022)->toBe(0);

        expect(static function () use ($path): void {
            matcher_write_validated_atomic_php_file(
                $path,
                '<?php return false;',
                static function (array $payload): void {
                    expect($payload)->toBe([]);
                },
            );
        })->toThrow(RuntimeException::class, 'validation failed before publication');

        expect(file_get_contents($path))->toBe($php);
        expect(glob($directory . '/.webrick-cache-*'))->toBe([]);
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
        rmdir($directory);
    }
});
