<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Core\UploadedFile;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Support\HttpUtils;
use Infocyph\Webrick\Support\InputSanitizer;

final class InputSanitizerMiddleware
{
    public const ATTR_Q = '__sanitized.query';
    public const ATTR_F = '__sanitized.form';
    public const ATTR_U = '__sanitized.uploads';

    public function __construct(
        private ?InputSanitizer $sanitizer = null,
        private readonly bool $touchFormBodies = true,
        private readonly bool $touchJsonBodies = false,   // opt-in
        private readonly bool $touchUploadedNames = false // opt-in (best-effort; requires setters)
    ) {
        $this->sanitizer ??= new InputSanitizer();
    }

    public function __invoke(Request $req, Closure $next): Response
    {
        $req = $this->sanitizeQueryIfNeeded($req);
        $req = $this->sanitizeBodyIfNeeded($req);
        $req = $this->sanitizeUploadsIfNeeded($req);

        return $next($req);
    }

    /* ───────────────────────── query ───────────────────────── */

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

    private function sanitizeBodyIfNeeded(Request $req): Request
    {
        if ($req->getAttribute(self::ATTR_F, false)) {
            return $req;
        }

        $ctype = strtolower($req->getHeaderLine('content-type'));
        $body  = $req->getParsedBody();

        if (is_array($body) && $this->shouldTouchBody($ctype)) {
            $req = $req
                ->withParsedBody($this->sanitizer->sanitizeArray($body))
                ->withAttribute(self::ATTR_F, true);
        }

        return $req;
    }

    private function shouldTouchBody(string $ctype): bool
    {
        $mime   = strtolower(strtok($ctype, ';') ?: '');
        $isForm = HttpUtils::isFormContentType($ctype);
        $isJson = str_starts_with($mime, 'application/json');

        return ($isForm && $this->touchFormBodies) || ($isJson && $this->touchJsonBodies);
    }

    /* ─────────────────────── uploads (opt-in) ─────────────────────── */

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
     * Recursively sanitize client filenames and media types.
     * Best-effort: only applies when UploadedFile implementation exposes
     * immutable setters (withClientFilename / withClientMediaType).
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
