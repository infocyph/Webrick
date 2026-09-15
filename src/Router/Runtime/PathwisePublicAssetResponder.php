<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Runtime;

use Infocyph\Pathwise\Exceptions\DownloadException;
use Infocyph\Pathwise\Exceptions\FileNotFoundException;
use Infocyph\Pathwise\Exceptions\FileSizeExceededException;
use Infocyph\Pathwise\StreamHandler\DownloadProcessor;
use Infocyph\Pathwise\Utils\PathHelper;
use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Constants\StatusEnum;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

/**
 * Optional Pathwise-backed public asset boundary.
 *
 * Applications opt into precedence by registering this responder on an
 * explicit public asset route. Pathwise authorizes the filesystem path;
 * Webrick remains authoritative for HTTP file/range/HEAD semantics.
 */
final readonly class PathwisePublicAssetResponder
{
    private DownloadProcessor $downloads;

    /** @var array<string,string> */
    private array $responseHeaders;

    private string $root;

    /** @param array<string,string> $responseHeaders */
    public function __construct(
        string $publicRoot,
        ?DownloadProcessor $downloads = null,
        array $responseHeaders = [],
    ) {
        $root = realpath($publicRoot);
        if (!is_string($root) || !is_dir($root) || !is_readable($root)) {
            throw new \InvalidArgumentException('Public asset root must be an existing readable directory.');
        }

        $this->root = PathHelper::normalize($root);
        $this->downloads = $downloads ?? new DownloadProcessor();
        $this->downloads->setAllowedRoots([$this->root]);
        $this->downloads->setBlockHiddenFiles();
        $this->downloads->setForceAttachment(false);
        $this->downloads->setRangeRequestsEnabled(false);
        $this->responseHeaders = ['X-Content-Type-Options' => 'nosniff', ...$responseHeaders];
    }

    public function respond(Request $request, string $relativePath): Response
    {
        $method = HttpMethodEnum::normalize($request->getMethod());
        if (!in_array($method, [HttpMethodEnum::GET->value, HttpMethodEnum::HEAD->value], true)) {
            return new Response(
                StatusEnum::METHOD_NOT_ALLOWED->value,
                '',
                ['Allow' => 'GET, HEAD'],
            );
        }

        $relativePath = self::normalizeRelativePath($relativePath);
        if ($relativePath === null) {
            return new Response(StatusEnum::NOT_FOUND->value);
        }

        try {
            $prepared = $this->downloads->prepareDownload(PathHelper::join($this->root, $relativePath));
        } catch (DownloadException|FileNotFoundException|FileSizeExceededException) {
            return new Response(StatusEnum::NOT_FOUND->value);
        }

        return Response::rangedFile(
            $request,
            $prepared->path,
            $prepared->mimeType,
            $this->responseHeaders,
        );
    }

    private static function normalizeRelativePath(string $relativePath): ?string
    {
        $relativePath = trim($relativePath);
        if (
            $relativePath === ''
            || str_contains($relativePath, "\0")
            || PathHelper::isAbsolute($relativePath)
            || PathHelper::hasScheme($relativePath)
        ) {
            return null;
        }

        return ltrim(str_replace('\\', '/', $relativePath), '/');
    }
}
