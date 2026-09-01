<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Interop\Psr7;

use Infocyph\Webrick\Interfaces\BodyStream;
use Infocyph\Webrick\Request\Core\UploadedFile;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

/**
 * Optional PSR-7/PSR-17 interoperability boundary.
 *
 * Webrick remains native internally. This class is used only when a consuming
 * application installs psr/http-message plus psr/http-factory.
 */
final readonly class PsrBridge
{
    public function __construct(
        private StreamFactoryInterface $streams,
        private ?UploadedFileFactoryInterface $uploadedFiles = null,
    ) {}

    public function response(Response $source, ResponseFactoryInterface $factory): ResponseInterface
    {
        $target = $factory->createResponse($source->getStatusCode(), $source->getReasonPhrase())
            ->withProtocolVersion($source->getProtocolVersion());

        foreach ($source->getHeaders() as $name => $values) {
            $target = $target->withHeader($name, $values);
        }

        $body = $source->getStringBody();
        if ($source->isStreaming()) {
            $body = $this->bufferProducer($source);
        } elseif ($body === null) {
            $body = $this->readStreamPreservingPosition($source->getBody());
        }

        return $target->withBody($this->streams->createStream($body));
    }

    public function serverRequest(Request $source, ServerRequestFactoryInterface $factory): ServerRequestInterface
    {
        $target = $factory->createServerRequest(
            $source->getMethod(),
            (string) $source->getUri(),
            $source->getServerParams(),
        )->withProtocolVersion($source->getProtocolVersion())
            ->withQueryParams($source->getQueryParams())
            ->withCookieParams($source->getCookieParams())
            ->withParsedBody($source->getParsedBody());

        foreach ($source->getHeaders() as $name => $values) {
            $target = $target->withHeader($name, $values);
        }
        foreach ($source->getAttributes() as $name => $value) {
            $target = $target->withAttribute($name, $value);
        }

        $target = $target->withBody(
            $this->streams->createStream($this->readStreamPreservingPosition($source->getBody())),
        );

        $files = $source->getUploadedFiles();
        if ($files !== []) {
            if (!$this->uploadedFiles instanceof UploadedFileFactoryInterface) {
                throw new RuntimeException(
                    'PSR uploaded-file conversion requires an UploadedFileFactoryInterface.',
                );
            }
            $target = $target->withUploadedFiles($this->convertUploadedFiles($files));
        }

        return $target;
    }

    private function bufferProducer(Response $source): string
    {
        $producer = $source->getProducer();
        if ($producer === null) {
            return '';
        }

        $body = '';
        foreach ($producer() as $chunk) {
            $body .= $chunk;
        }

        return $body;
    }

    private function convertUploadedFile(UploadedFile $file): UploadedFileInterface
    {
        $factory = $this->uploadedFiles
            ?? throw new RuntimeException('PSR uploaded-file conversion requires an UploadedFileFactoryInterface.');

        $error = $file->getError();
        $stream = $error === UPLOAD_ERR_OK
            ? $this->streams->createStream($this->readStreamPreservingPosition($file->getStream()))
            : $this->streams->createStream('');

        return $factory->createUploadedFile(
            $stream,
            $file->getSize(),
            $error,
            $file->getClientFilename(),
            $file->getClientMediaType(),
        );
    }

    /**
     * @param array<array-key,mixed> $files
     * @return array<array-key,mixed>
     */
    private function convertUploadedFiles(array $files): array
    {
        $converted = [];
        foreach ($files as $key => $file) {
            if ($file instanceof UploadedFile) {
                $converted[$key] = $this->convertUploadedFile($file);
            } elseif (is_array($file)) {
                $converted[$key] = $this->convertUploadedFiles($file);
            }
        }

        return $converted;
    }

    private function readStreamPreservingPosition(BodyStream $stream): string
    {
        if (!$stream->isSeekable()) {
            return $stream->getContents();
        }

        $position = $stream->tell();

        try {
            $stream->rewind();

            return $stream->getContents();
        } finally {
            $stream->seek($position);
        }
    }
}
