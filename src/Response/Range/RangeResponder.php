<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Range;

use Infocyph\Webrick\Constants\StatusEnum;
use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Headers\Range as SimpleRange;
use Infocyph\Webrick\Response\Internal\Utils;
use Infocyph\Webrick\Response\Response;

/**
 * Build 200/206/416 responses for seekable resources.
 */
final readonly class RangeResponder
{
    /** @param array<string,string> $headers */
    public static function forFile(
        Request $req,
        string $absolutePath,
        string $mediaType = 'application/octet-stream',
        array $headers = [],
    ): Response {
        $resource = fopen($absolutePath, 'rb');
        if ($resource === false) {
            return new Response(StatusEnum::NOT_FOUND->value);
        }

        $stat = fstat($resource) ?: [];
        $length = max(0, (int) ($stat['size'] ?? 0));
        $mtime = (int) ($stat['mtime'] ?? time());

        $headers += [
            'Accept-Ranges' => 'bytes',
            'ETag' => 'W/"' . dechex($length) . '-' . dechex($mtime) . '"',
            'Last-Modified' => Utils::httpDate($mtime),
        ];

        return self::fromSeekable(
            $resource,
            $length,
            RangeParser::parse($req->getHeaderLine('Range'), $length),
            $mediaType,
            $headers,
            $req,
        );
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
            unset(
                $headers['Content-Type'],
                $headers['Content-Encoding'],
                $headers['Content-Language'],
                $headers['Content-Length'],
            );
            $headers['Content-Range'] = "bytes */{$totalLength}";
            $headers['Content-Length'] = '0';

            return new Response(StatusEnum::RANGE_NOT_SATISFIABLE->value, new Stream(''), $headers);
        }

        if ($result->status !== RangeParseStatus::SATISFIABLE) {
            $headers += [
                'Content-Type' => $mediaType,
                'Content-Length' => (string) $totalLength,
            ];
            if (self::isSeekable($source)) {
                $headers += ['Accept-Ranges' => 'bytes'];
            }

            return new Response(StatusEnum::OK->value, self::wrapSeekable($source), $headers);
        }

        if (!self::isSeekable($source)) {
            throw new \RuntimeException('Partial content requires a seekable source.');
        }

        $resolved = $result->requireRange();
        $partialLength = $resolved->length();
        $headers += [
            'Content-Range' => $resolved->contentRange(),
            'Content-Length' => (string) $partialLength,
            'Content-Type' => $mediaType,
            'Accept-Ranges' => 'bytes',
        ];

        return new Response(
            StatusEnum::PARTIAL_CONTENT->value,
            self::wrapSeekable($source, $resolved->start, $partialLength),
            $headers,
        );
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

        return $metadata['seekable'] ?? false;
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

        return RangeParser::parse($req?->getHeaderLine('Range') ?? '', $totalLength);
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
