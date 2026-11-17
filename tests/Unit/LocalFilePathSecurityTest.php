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
