<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Range;

use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Constants\StatusEnum;
use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Body\FileBody;
use Infocyph\Webrick\Response\Conditional\ConditionalValidator;
use Infocyph\Webrick\Response\Headers\Range as SimpleRange;
use Infocyph\Webrick\Response\Internal\Utils;
use Infocyph\Webrick\Response\Response;

/** Build 200/206/416 responses for seekable resources. */
final readonly class RangeResponder
{
    /** @param array<string,string> $headers */
    public static function forFile(
        Request $req,
        string $absolutePath,
        string $mediaType = 'application/octet-stream',
        array $headers = [],
    ): Response {
        $metadata = self::fileMetadata($absolutePath, $headers);
        if ($metadata === null) {
            return new Response(StatusEnum::NOT_FOUND->value);
        }

        [$length, $headers] = $metadata;
        $rangeLine = self::fileRangeLine($req, $headers);
        $result = RangeParser::parse($rangeLine, $length);

        return self::buildFileResponse($absolutePath, $mediaType, $headers, $length, $result);
    }

    /** @param array<string,string> $headers */
    public static function fromSeekable(
        mixed $source,
        int $totalLength,
        RangeParseResult|SimpleRange|null $range,
        string $mediaType = 'application/octet-stream',
        array $headers = [],
        ?Request $req = null,
    ): Response {
        if ($totalLength < 0) {
            throw new \InvalidArgumentException('Total length cannot be negative.');
        }

        $result = self::normalizeResult($range, $req, $totalLength);
        if ($result->status === RangeParseStatus::UNSATISFIABLE) {
            unset($headers['Content-Type'], $headers['Content-Encoding'], $headers['Content-Language']);
            $headers['Content-Range'] = "bytes */{$totalLength}";
            $headers['Content-Length'] = '0';

            return new Response(StatusEnum::RANGE_NOT_SATISFIABLE->value, '', $headers);
        }

        if ($result->status !== RangeParseStatus::SATISFIABLE) {
            unset($headers['Content-Range']);
            $headers['Content-Type'] ??= $mediaType;
            $headers['Content-Length'] = (string) $totalLength;
            if (self::isSeekable($source)) {
                $headers['Accept-Ranges'] = 'bytes';
            }

            return new Response(StatusEnum::OK->value, self::wrapSeekable($source), $headers);
        }

        if (!self::isSeekable($source)) {
            throw new \RuntimeException('Partial content requires a seekable source.');
        }

        $resolved = $result->requireRange();
        $partialLength = $resolved->length();
        $headers['Content-Range'] = $resolved->contentRange();
        $headers['Content-Length'] = (string) $partialLength;
        $headers['Content-Type'] ??= $mediaType;
        $headers['Accept-Ranges'] = 'bytes';

        return new Response(
            StatusEnum::PARTIAL_CONTENT->value,
            self::wrapSeekable($source, $resolved->start, $partialLength),
            $headers,
        );
    }

    /** @param array<string,string> $headers */
    private static function buildFileResponse(
        string $absolutePath,
        string $mediaType,
        array $headers,
        int $length,
        RangeParseResult $result,
    ): Response {
        if ($result->status === RangeParseStatus::UNSATISFIABLE) {
            unset($headers['Content-Type'], $headers['Content-Encoding'], $headers['Content-Language']);
            $headers['Content-Range'] = "bytes */{$length}";
            $headers['Content-Length'] = '0';

            return new Response(StatusEnum::RANGE_NOT_SATISFIABLE->value, '', $headers);
        }

        if ($result->status !== RangeParseStatus::SATISFIABLE) {
            unset($headers['Content-Range']);
            $headers['Content-Type'] ??= $mediaType;
            $headers['Content-Length'] = (string) $length;
            $headers['Accept-Ranges'] = 'bytes';

            return new Response(StatusEnum::OK->value, new FileBody($absolutePath), $headers);
        }

        $resolved = $result->requireRange();
        $partialLength = $resolved->length();
        $headers['Content-Range'] = $resolved->contentRange();
        $headers['Content-Length'] = (string) $partialLength;
        $headers['Content-Type'] ??= $mediaType;
        $headers['Accept-Ranges'] = 'bytes';

        return new Response(
            StatusEnum::PARTIAL_CONTENT->value,
            new FileBody($absolutePath, $resolved->start, $partialLength),
            $headers,
        );
    }

    /** @param array<string,string> $headers @return array{0:int,1:array<string,string>}|null */
    private static function fileMetadata(string $absolutePath, array $headers): ?array
    {
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            return null;
        }

        $size = filesize($absolutePath);
        if ($size === false) {
            return null;
        }

        $mtime = filemtime($absolutePath);
        $lastModified = $mtime === false ? null : $mtime;
        $length = max(0, $size);
        $headers['Accept-Ranges'] = 'bytes';
        $headers['ETag'] ??= 'W/"' . dechex($length) . '-' . dechex($lastModified ?? 0) . '"';
        if ($lastModified !== null) {
            $headers['Last-Modified'] ??= Utils::httpDate($lastModified);
        }

        return [$length, $headers];
    }

    /** @param array<string,string> $headers */
    private static function fileRangeLine(Request $req, array $headers): string
    {
        if (HttpMethodEnum::normalize($req->getMethod()) !== HttpMethodEnum::GET->value) {
            return '';
        }

        $rangeLine = $req->getHeaderLine('Range');
        if ($rangeLine === '' || $req->getHeaderLine('If-Range') === '') {
            return $rangeLine;
        }

        $etag = $headers['ETag'] ?? null;
        $headerLastModified = isset($headers['Last-Modified']) ? strtotime($headers['Last-Modified']) : false;
        $validator = new ConditionalValidator(
            is_string($etag) && $etag !== '' ? $etag : null,
            $headerLastModified === false ? null : $headerLastModified,
            true,
        );

        return $validator->isRangeFresh($req) ? $rangeLine : '';
    }

    private static function isSeekable(mixed $source): bool
    {
        if ($source instanceof Stream) {
            return $source->isSeekable();
        }
        if (!is_resource($source)) {
            return false;
        }

        $metadata = stream_get_meta_data($source);

        return (bool) $metadata['seekable'];
    }

    private static function normalizeResult(
        RangeParseResult|SimpleRange|null $range,
        ?Request $req,
        int $totalLength,
    ): RangeParseResult {
        if ($range instanceof RangeParseResult) {
            return $range;
        }
        if ($range instanceof SimpleRange) {
            return RangeParseResult::satisfiable($range);
        }
        if (!$req instanceof Request || HttpMethodEnum::normalize($req->getMethod()) !== HttpMethodEnum::GET->value) {
            return RangeParseResult::none();
        }
        if ($req->getHeaderLine('If-Range') !== '') {
            return RangeParseResult::none();
        }

        return RangeParser::parse($req->getHeaderLine('Range'), $totalLength);
    }

    private static function wrapSeekable(mixed $source, ?int $start = null, ?int $length = null): ByteRangeStream|Stream
    {
        $base = $source instanceof Stream ? $source : new Stream($source);
        if ($start === null || $length === null) {
            if ($base->isSeekable()) {
                $base->rewind();
            }

            return $base;
        }

        return new ByteRangeStream($base, $start, $length);
    }
}
