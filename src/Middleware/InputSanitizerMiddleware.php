<?php

/**
 * Webrick - Input sanitizer middleware.
 *
 * Sanitizes incoming query parameters, form bodies, optional JSON bodies, and
 * optional uploaded file metadata (client name/type). Designed to be idempotent
 * using request attributes to avoid re-sanitization.
 *
 * @package Infocyph\Webrick\Middleware
 */

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
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
final class InputSanitizerMiddleware
{
    /** Request attribute: query sanitized. */
    public const ATTR_Q = '__sanitized.query';
    /** Request attribute: form/json body sanitized. */
    public const ATTR_F = '__sanitized.form';
    /** Request attribute: uploads sanitized. */
    public const ATTR_U = '__sanitized.uploads';

    /**
     * @param InputSanitizer|null $sanitizer           Custom sanitizer; defaults to InputSanitizer.
     * @param bool                $touchFormBodies     Sanitize application/x-www-form-urlencoded or multipart bodies.
     * @param bool                $touchJsonBodies     Sanitize JSON bodies (opt-in).
     * @param bool                $touchUploadedNames  Sanitize uploaded client filenames/media types (opt-in; requires setters).
     */
    public function __construct(
        private ?InputSanitizer $sanitizer = null,
        private readonly bool $touchFormBodies = true,
        private readonly bool $touchJsonBodies = false,   // opt-in
        private readonly bool $touchUploadedNames = false, // opt-in (best-effort; requires setters)
    ) {
        $this->sanitizer ??= new InputSanitizer();
    }

    /**
     * Sanitize query/body/uploads as configured and proceed.
     *
     * @param Request $req  Incoming request.
     * @param Closure $next Next handler.
     *
     * @return Response Downstream response.
     */
    public function __invoke(Request $req, Closure $next): Response
    {
        $req = $this->sanitizeQueryIfNeeded($req);
        $req = $this->sanitizeBodyIfNeeded($req);
        $req = $this->sanitizeUploadsIfNeeded($req);

        return $next($req);
    }

    /* ───────────────────────── query ───────────────────────── */

    /**
     * Sanitize query parameters if not already processed.
     *
     * @param Request $req
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
                ->withQueryParams($this->sanitizer->sanitizeArray($q))
                ->withAttribute(self::ATTR_Q, true);
        }

        return $req;
    }

    /* ───────────────────────── body ────────────────────────── */

    /**
     * Sanitize form or JSON body according to content type and flags.
     *
     * @param Request $req
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
                ->withParsedBody($this->sanitizer->sanitizeArray($body))
                ->withAttribute(self::ATTR_F, true);
        }

        return $req;
    }

    /**
     * Determine if the body should be sanitized based on content type and flags.
     *
     * @param string $ctype Raw Content-Type header.
     *
     * @return bool True when body sanitization is enabled for this request.
     */
    private function shouldTouchBody(string $ctype): bool
    {
        $mime = strtolower(strtok($ctype, ';') ?: '');
        $isForm = HttpUtils::isFormContentType($ctype);
        $isJson = str_starts_with($mime, 'application/json');

        return ($isForm && $this->touchFormBodies) || ($isJson && $this->touchJsonBodies);
    }

    /* ─────────────────────── uploads (opt-in) ─────────────────────── */

    /**
     * Sanitize uploaded file metadata (client filename/media type) best-effort.
     *
     * Applies only if enabled and not previously processed. Recurses to handle
     * nested uploaded files arrays.
     *
     * @param Request $req
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
     * Recursively sanitize client filenames and media types of uploaded files.
     *
     * Best-effort: only applies when UploadedFile implementation exposes immutable
     * setters (withClientFilename / withClientMediaType).
     *
     * @param array<int|string,mixed> $files Uploaded files structure.
     *
     * @return array<int|string,mixed> Sanitized files structure.
     */
    private function sanitizeUploadedFilesRecursive(array $files): array
    {
        foreach ($files as $k => $f) {
            if (is_array($f)) {
                $files[$k] = $this->sanitizeUploadedFilesRecursive($f);
                continue;
            }
            if (!$f instanceof UploadedFile) {
                // Unknown structure – leave as-is
                continue;
            }

            $name = $f->getClientFilename();
            $type = $f->getClientMediaType();

            $newName = $name !== null ? $this->sanitizer->sanitizeString($name) : null;
            $newType = $type !== null ? $this->sanitizer->sanitizeString($type) : null;

            // Only set if changed and the method exists on the implementation.
            if ($newName !== $name && method_exists($f, 'withClientFilename')) {
                $f = $f->withClientFilename($newName);
            }
            if ($newType !== $type && method_exists($f, 'withClientMediaType')) {
                $f = $f->withClientMediaType($newType);
            }

            $files[$k] = $f;
        }

        return $files;
    }
}