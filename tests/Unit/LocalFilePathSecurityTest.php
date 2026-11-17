<?php

declare(strict_types=1);

use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Request\Core\UploadedFile;
use Infocyph\Webrick\Response\Body\FileBody;

test('uploaded files refuse stream-wrapper source and destination paths', function (): void {
    expect(fn(): UploadedFile => new UploadedFile('php://filter/resource=/etc/passwd'))
        ->toThrow(InvalidArgumentException::class);

    expect(fn(): UploadedFile => UploadedFile::fromSpec([
        'tmp_name' => 'https://example.invalid/upload',
        'error' => UPLOAD_ERR_OK,
    ]))->toThrow(InvalidArgumentException::class);

    $upload = new UploadedFile(new Stream('safe upload'));
    expect(fn() => $upload->moveTo('phar://archive.phar/entry'))
        ->toThrow(InvalidArgumentException::class);
});

test('native file responses do not open stream-wrapper paths', function (): void {
    expect(fn(): FileBody => new FileBody('file:///etc/passwd'))
        ->toThrow(RuntimeException::class);
});

test('failed path move preserves an existing destination and leaves no copy debris', function (): void {
    $target = tempnam(sys_get_temp_dir(), 'webrick-upload-target-');
    if (!is_string($target)) {
        throw new RuntimeException('Unable to create upload destination fixture.');
    }

    try {
        file_put_contents($target, 'keep-existing-content');
        $source = dirname($target) . DIRECTORY_SEPARATOR . 'missing-upload-' . bin2hex(random_bytes(8));
        $upload = new UploadedFile($source);
        expect(fn () => $upload->moveTo($target))
            ->toThrow(RuntimeException::class, 'Failed to copy uploaded file');
        expect(file_get_contents($target))->toBe('keep-existing-content');
    } finally {
        unlink($target);
    }
});

test('path-backed file moves preserve contents and invalidate the moved upload', function (): void {
    $source = tempnam(sys_get_temp_dir(), 'webrick-upload-source-');
    $target = tempnam(sys_get_temp_dir(), 'webrick-upload-moved-');
    if (!is_string($source) || !is_string($target)) {
        throw new RuntimeException('Unable to create upload move fixtures.');
    }

    try {
        file_put_contents($source, 'upload-data');
        $upload = new UploadedFile($source);
        $upload->moveTo($target);

        expect(file_exists($source))->toBeFalse()
            ->and(file_get_contents($target))->toBe('upload-data');
        expect(fn () => $upload->moveTo($target))
            ->toThrow(RuntimeException::class, 'Uploaded file has been moved');
    } finally {
        if (is_file($source)) {
            unlink($source);
        }
        if (is_file($target)) {
            unlink($target);
        }
    }
});

test('NUL bytes are rejected at uploaded-file and file-response path boundaries', function (): void {
    $invalid = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webrick' . chr(0) . 'invalid';

    expect(fn (): UploadedFile => new UploadedFile($invalid))
        ->toThrow(InvalidArgumentException::class, 'local filesystem path');

    $upload = new UploadedFile(new Stream('safe upload'));
    expect(fn () => $upload->moveTo($invalid))
        ->toThrow(InvalidArgumentException::class, 'local filesystem path');

    expect(fn (): FileBody => new FileBody($invalid))
        ->toThrow(RuntimeException::class, 'Unreadable response file');
});

test('UNC/network-share paths cannot become upload sources, targets or file responses', function (string $path): void {
    expect(fn(): UploadedFile => new UploadedFile($path))
        ->toThrow(InvalidArgumentException::class, 'local filesystem path');

    $upload = new UploadedFile(new Stream('safe upload'));
    expect(fn() => $upload->moveTo($path))
        ->toThrow(InvalidArgumentException::class, 'local filesystem path');

    expect(fn(): FileBody => new FileBody($path))
        ->toThrow(RuntimeException::class, 'Unreadable response file');
})->with([
    '//untrusted.invalid/share/upload.dat',
    chr(92) . chr(92) . 'untrusted.invalid' . chr(92) . 'share' . chr(92) . 'upload.dat',
]);
