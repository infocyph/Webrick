<?php

/**
 * Webrick - Input sanitizer middleware.
 *
 * Sanitizes incoming query parameters, form bodies, optional JSON bodies, and
 * optional uploaded file metadata (client name/type). Designed to be idempotent
 * using request attributes to avoid re-sanitization.
 */

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Constants\MediaTypeEnum;
use Infocyph\Webrick\Request\Core\UploadedFile;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Support\HttpUtils;
use Infocyph\Webrick\Support\InputSanitizer;

/**
 * Normalize and sanitize user input across query, body, and uploads.
 *
 * Behavior:
 * - Query params are sanitized once per request (flagged via ATTR_Q).
 * - Form/JSON bodies sanitized based on Content-Type and constructor flags (ATTR_F).
 * - Uploaded file metadata (client filename/media type) sanitized best-effort (ATTR_U).
 */
final readonly class InputSanitizerMiddleware
{
    /** Request attribute: form/json body sanitized. */
    public const string ATTR_F = '__sanitized.form';

    /** Request attribute: query sanitized. */
    public const string ATTR_Q = '__sanitized.query';

    /** Request attribute: uploads sanitized. */
    public const string ATTR_U = '__sanitized.uploads';

    private InputSanitizer $sanitizer;

    /**
     * @param InputSanitizer|null $sanitizer Custom sanitizer; defaults to InputSanitizer.
     * @param bool $touchFormBodies Sanitize application/x-www-form-urlencoded or multipart bodies.
     * @param bool $touchJsonBodies Sanitize JSON bodies (opt-in).
     * @param bool $touchUploadedNames Sanitize uploaded client filenames/media types (opt-in; requires setters).
     */
    public function __construct(
        ?InputSanitizer $sanitizer = null,
        private bool $touchFormBodies = true,
        private bool $touchJsonBodies = false,   // opt-in
        private bool $touchUploadedNames = false, // opt-in (best-effort; requires setters)
    ) {
        $this->sanitizer = $sanitizer ?? new InputSanitizer();
    }

    /**
     * Sanitize query/body/uploads as configured and proceed.
     *
     * @param Request $req Incoming request.
     * @param Closure(Request):Response $next
     * @return Response Downstream response.
     */
    public function __invoke(Request $req, Closure $next): Response
    {
        $req = $this->sanitizeQueryIfNeeded($req);
        $req = $this->sanitizeBodyIfNeeded($req);
        $req = $this->sanitizeUploadsIfNeeded($req);

        return $next($req);
    }

    /* ───────────────────────── body ────────────────────────── */
    /**
     * Sanitize form or JSON body according to content type and flags.
     *
     *
     * @return Request Possibly augmented request.
     */
    private function sanitizeBodyIfNeeded(Request $req): Request
    {
        if ($req->getAttribute(self::ATTR_F, false)) {
            return $req;
        }

        $ctype = strtolower($req->getHeaderLine('content-type'));
        $body = $req->getParsedBody();

        if (is_array($body) && $this->shouldTouchBody($ctype)) {
            $req = $req
                ->withParsedBody($this->stringKeyMap($this->sanitizer->sanitizeArray($body)))
                ->withAttribute(self::ATTR_F, true);
        }

        return $req;
    }

    /* ───────────────────────── query ───────────────────────── */
    /**
     * Sanitize query parameters if not already processed.
     *
     *
     * @return Request Possibly augmented request.
     */
    private function sanitizeQueryIfNeeded(Request $req): Request
    {
        if ($req->getAttribute(self::ATTR_Q, false)) {
            return $req;
        }

        $q = $req->getQueryParams();
        if ($q) {
            $req = $req
                ->withQueryParams($this->stringKeyMap($this->sanitizer->sanitizeArray($q)))
                ->withAttribute(self::ATTR_Q, true);
        }

        return $req;
    }

    private function sanitizeUploadedFileNode(mixed $node): mixed
    {
        if (is_array($node)) {
            foreach ($node as $key => $value) {
                $node[$key] = $this->sanitizeUploadedFileNode($value);
            }

            return $node;
        }

        if (!$node instanceof UploadedFile) {
            return $node;
        }

        $name = $node->getClientFilename();
        $type = $node->getClientMediaType();
        $name !== null ? $this->sanitizer->sanitizeString($name) : null;
        $type !== null ? $this->sanitizer->sanitizeString($type) : null;

        return $node;
    }

    /**
     * Recursively sanitize client filenames and media types of uploaded files.
     *
     * Best-effort: only applies when UploadedFile implementation exposes immutable
     * setters (withClientFilename / withClientMediaType).
     *
     * @param array<string, mixed> $files Uploaded files structure.
     * @return array<string, mixed> Sanitized files structure.
     */
    private function sanitizeUploadedFilesRecursive(array $files): array
    {
        foreach ($files as $k => $f) {
            $files[$k] = $this->sanitizeUploadedFileNode($f);
        }

        return $files;
    }

    /* ─────────────────────── uploads (opt-in) ─────────────────────── */
    /**
     * Sanitize uploaded file metadata (client filename/media type) best-effort.
     *
     * Applies only if enabled and not previously processed. Recurses to handle
     * nested uploaded files arrays.
     *
     *
     * @return Request Possibly augmented request.
     */
    private function sanitizeUploadsIfNeeded(Request $req): Request
    {
        if (!$this->touchUploadedNames || $req->getAttribute(self::ATTR_U, false)) {
            return $req;
        }

        $files = $req->getUploadedFiles();
        if ($files === []) {
            return $req;
        }

        $san = $this->sanitizeUploadedFilesRecursive($files);

        // Only replace when something actually changed
        if ($san !== $files) {
            $req = $req->withUploadedFiles($san);
        }

        return $req->withAttribute(self::ATTR_U, true);
    }

    /**
     * Determine if the body should be sanitized based on content type and flags.
     *
     * @param string $ctype Raw Content-Type header.
     * @return bool True when body sanitization is enabled for this request.
     */
    private function shouldTouchBody(string $ctype): bool
    {
        $mime = strtolower(strtok($ctype, ';') ?: '');
        $isForm = HttpUtils::isFormContentType($ctype);
        $isJson = str_starts_with($mime, MediaTypeEnum::JSON->base());

        return ($isForm && $this->touchFormBodies) || ($isJson && $this->touchJsonBodies);
    }

    /**
     * @param array<mixed> $input
     * @return array<string, mixed>
     */
    private function stringKeyMap(array $input): array
    {
        $result = [];
        foreach ($input as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $result[$key] = $value;
        }

        return $result;
    }
}
