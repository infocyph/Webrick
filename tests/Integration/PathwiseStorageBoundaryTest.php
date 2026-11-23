<?php

declare(strict_types=1);

use Infocyph\Pathwise\Exceptions\DownloadException;
use Infocyph\Pathwise\Exceptions\UploadException;
use Infocyph\Pathwise\Storage\StorageContext;
use Infocyph\Pathwise\StreamHandler\DownloadProcessor;
use Infocyph\Pathwise\StreamHandler\MalwareScanMode;
use Infocyph\Pathwise\StreamHandler\PublicFileResolver;
use Infocyph\Pathwise\StreamHandler\UploadProcessor;
use Infocyph\Pathwise\StreamHandler\UploadSource;
use Infocyph\Pathwise\StreamHandler\UploadTrustProfile;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Request\Core\UploadedFile;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Body\FileBody;
use Infocyph\Webrick\Response\Response;

test('Webrick upload sources are persisted and streamed exclusively by Pathwise', function (): void {
    $root = sys_get_temp_dir() . '/webrick-pathwise-' . bin2hex(random_bytes(8));
    if (!mkdir($root, 0700)) {
        throw new RuntimeException('Unable to create isolated Pathwise test storage.');
    }

    try {
        $storage = new StorageContext([
            'private' => ['driver' => 'local', 'root' => $root],
        ], 'private');

        $uploader = new UploadProcessor();
        $uploader->setStorageContext($storage);
        $uploader->setTrustProfile(UploadTrustProfile::UNTRUSTED_DATA);
        $uploader->setDirectorySettings('private://documents', tempDir: sys_get_temp_dir());
        $uploader->setValidationSettings(['text/plain'], 4096);
        $uploader->setExtensionPolicy(['txt']);

        $upload = new UploadedFile(new Stream('0123456789'), 10, UPLOAD_ERR_OK, 'report.txt', 'text/plain');
        $source = UploadSource::fromMover(
            mover: static function (string $target) use ($upload): void {
                $upload->moveTo($target);
            },
            clientFilename: $upload->getClientFilename() ?? 'upload.bin',
            size: $upload->getSize(),
            clientMediaType: $upload->getClientMediaType(),
            error: $upload->getError(),
        );
        $stored = $uploader->ingestSource($source);
        expect($stored)->toStartWith('private://documents/')
            ->and(file_get_contents($storage->localPath($stored)))->toBe('0123456789')
            ->and($upload->getClientFilename())->toBe('report.txt');

        expect(fn() => $upload->moveTo($root . '/duplicate.txt'))
            ->toThrow(RuntimeException::class, 'Uploaded file has been moved');

        $downloads = new DownloadProcessor();
        $downloads->setStorageContext($storage);
        $downloads->setAllowedRoots(['private://documents']);
        $downloads->setExtensionPolicy(['txt']);

        $prepared = $downloads->prepareDownload($stored, 'safe-report.txt', 'bytes=2-5');
        $response = Response::stream(
            $downloads->streamChunks($prepared),
            $prepared->status,
            $prepared->headers,
        );

        expect($response->getStatusCode())->toBe(206)
            ->and($response->isStreaming())->toBeTrue()
            ->and($response->getHeaderLine('Content-Range'))->toBe('bytes 2-5/10')
            ->and($response->getHeaderLine('Content-Disposition'))->toContain('safe-report.txt')
            ->and($response->getHeaderLine('Content-Length'))->toBe('');

        $producer = $response->getProducer();
        if ($producer === null) {
            throw new RuntimeException('Expected a lazy Pathwise download producer.');
        }

        $body = '';
        foreach ($producer() as $chunk) {
            if (!is_string($chunk)) {
                throw new UnexpectedValueException('Pathwise emitted a non-string download chunk.');
            }

            $body .= $chunk;
        }

        expect($body)->toBe('2345');

        $downloads->setAllowedRoots(['private://other']);
        expect(fn() => $downloads->prepareDownload($stored))
            ->toThrow(DownloadException::class);
    } finally {
        FlysystemHelper::deleteDirectory($root);
    }
});

test('Pathwise refuses executable uploads before publishing them', function (): void {
    $root = sys_get_temp_dir() . '/webrick-pathwise-reject-' . bin2hex(random_bytes(8));
    if (!mkdir($root, 0700)) {
        throw new RuntimeException('Unable to create isolated upload-rejection storage.');
    }

    try {
        $uploader = new UploadProcessor();
        $uploader->setTrustProfile(UploadTrustProfile::UNTRUSTED_DATA);
        $uploader->setDirectorySettings($root);
        $uploader->setValidationSettings(['text/plain'], 4096);
        $uploader->setExtensionPolicy(['txt']);

        $upload = new UploadedFile(new Stream('<?php echo 1;'), null, UPLOAD_ERR_OK, 'payload.php');
        $source = UploadSource::fromMover(
            mover: static function (string $target) use ($upload): void {
                $upload->moveTo($target);
            },
            clientFilename: $upload->getClientFilename() ?? 'upload.bin',
            error: $upload->getError(),
        );

        expect(fn() => $uploader->ingestSource($source))
            ->toThrow(UploadException::class);

        expect(iterator_to_array(new FilesystemIterator($root, FilesystemIterator::SKIP_DOTS)))
            ->toBe([]);
    } finally {
        FlysystemHelper::deleteDirectory($root);
    }
});

test('Pathwise mandatory scanner fails closed when the host has not configured a scanner', function (): void {
    $root = sys_get_temp_dir() . '/webrick-pathwise-scan-' . bin2hex(random_bytes(8));
    if (!mkdir($root, 0700)) {
        throw new RuntimeException('Unable to create isolated scanner test storage.');
    }

    try {
        $uploader = new UploadProcessor();
        $uploader->setTrustProfile(UploadTrustProfile::UNTRUSTED_DATA);
        $uploader->setDirectorySettings($root);
        $uploader->setValidationSettings(['text/plain'], 4096);
        $uploader->setExtensionPolicy(['txt']);
        $uploader->setMalwareScanMode(MalwareScanMode::REQUIRED);

        $source = UploadSource::fromMover(
            mover: static function (string $target): void {
                file_put_contents($target, 'safe document');
            },
            clientFilename: 'document.txt',
        );
        expect(fn() => $uploader->ingestSource($source))
            ->toThrow(UploadException::class);
        expect(iterator_to_array(new FilesystemIterator($root, FilesystemIterator::SKIP_DOTS)))
            ->toBe([]);
    } finally {
        FlysystemHelper::deleteDirectory($root);
    }
});

test('Pathwise public-path containment keeps Webrick native ranged-file transport', function (): void {
    $root = sys_get_temp_dir() . '/webrick-pathwise-public-' . bin2hex(random_bytes(8));
    if (!mkdir($root, 0700)) {
        throw new RuntimeException('Unable to create isolated public-file storage.');
    }

    try {
        file_put_contents($root . '/asset.txt', 'abcdef');
        $resolver = new PublicFileResolver();
        $asset = $resolver->resolve($root, 'asset.txt');
        $response = Response::rangedFile(
            Request::fake(headers: ['Range' => 'bytes=1-3']),
            $asset->path,
            $asset->mimeType,
        );

        expect($response->getStatusCode())->toBe(206)
            ->and($response->getHeaderLine('Content-Range'))->toBe('bytes 1-3/6')
            ->and($response->getFileBody())->toBeInstanceOf(FileBody::class)
            ->and((string) $response->getBody())->toBe('bcd');

        expect(fn() => $resolver->resolve($root, '../outside.txt'))
            ->toThrow(DownloadException::class);
    } finally {
        FlysystemHelper::deleteDirectory($root);
    }
});
