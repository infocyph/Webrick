<?php

declare(strict_types=1);

use function Infocyph\Webrick\Router\Matching\sharded_matcher_sanitize_for_filename;
use function Infocyph\Webrick\Router\Matching\sharded_matcher_shard_file_path;

test('shard filename sanitization cannot escape its trusted cache directory', function (string $untrusted): void {
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webrick-owned-cache';
    $safe = sharded_matcher_sanitize_for_filename($untrusted, ['CON', 'PRN', 'AUX']);
    $path = sharded_matcher_shard_file_path($root, '*', $untrusted, ['CON', 'PRN', 'AUX']);

    expect($safe)->not->toBe('')
        ->and($safe[0])->not->toBe('.')
        ->and(str_contains($safe, '/'))->toBeFalse()
        ->and(str_contains($safe, '\\'))->toBeFalse()
        ->and(str_contains($safe, ':'))->toBeFalse()
        ->and(str_contains($safe, chr(0)))->toBeFalse()
        ->and(preg_match('/^[A-Za-z0-9_.-]+$/D', $safe))->toBe(1)
        ->and(dirname($path))->toBe($root)
        ->and(basename($path))->toBe($safe . '.php');
})->with([
    '../secret.php',
    '..\\..\\secret.php',
    'php://filter/resource=/etc/passwd',
    'phar://malicious.phar/exec.php',
    'https://example.invalid/payload.php',
    '..' . chr(0) . '/../escape',
    '/../../../../',
    'CON',
    '',
]);
