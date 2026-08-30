<?php

declare(strict_types=1);

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Body\FileBody;
use Infocyph\Webrick\Response\Response;

test('file responses preserve native file identity', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'webrick-file-');
    expect($path)->toBeString();
    file_put_contents($path, 'abcdef');

    try {
        $response = Response::download($path);
        $file = $response->getFileBody();

        expect($file)->toBeInstanceOf(FileBody::class)
            ->and($file?->path())->toBe($path)
            ->and($file?->offset())->toBe(0)
            ->and($file?->length())->toBe(6)
            ->and((string) $file)->toBe('abcdef');
    } finally {
        @unlink($path);
    }
});

test('range responses preserve native offset and length', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'webrick-range-');
    expect($path)->toBeString();
    file_put_contents($path, 'abcdef');

    try {
        $request = Request::fake(headers: ['Range' => 'bytes=2-4']);
        $response = Response::rangedFile($request, $path, 'text/plain');
        $file = $response->getFileBody();

        expect($response->getStatusCode())->toBe(206)
            ->and($response->getHeaderLine('Content-Range'))->toBe('bytes 2-4/6')
            ->and($file)->toBeInstanceOf(FileBody::class)
            ->and($file?->offset())->toBe(2)
            ->and($file?->length())->toBe(3)
            ->and((string) $file)->toBe('cde');
    } finally {
        @unlink($path);
    }
});
