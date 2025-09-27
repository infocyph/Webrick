<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Core\Uri;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Conditional\ConditionalValidator;
use Infocyph\Webrick\Response\Conditional\Outcome;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Support\Etag;

final class CacheValidatorsMiddleware
{
    /** @var null|Closure(Request): array{0:string|null,1:int|null} */
    private static ?Closure $defaultProvider = null;

    public function __construct(
        private readonly ?Closure $metaProvider = null,
        private readonly bool $autoEtagWhenMissing = true,
        private readonly bool $includeQueryInEtag = true,
        private readonly int $autoEtagMinSize = 2048,
    ) {
    }

    public function __invoke(Request $req, Closure $next): Response
    {
        $isGetHead = $this->isGetOrHead($req);

        // 1) Preconditions: compute + evaluate
        [$validator, $result] = $this->evaluatePreconditionsWithProvider($req);

        // 2) Early exit on 304/412
        if ($resp = $this->maybeShortCircuit($result, $isGetHead)) {
            return $resp;
        }

        // 3) Drop stale Range for GET/HEAD
        $req = $this->maybeDropStaleRangeHeader($req, $validator, $isGetHead);

        // 4) Downstream
        $resp = $next($req);

        // 4.5) If upstream marked the request as personalized (e.g., locale from cookie),
        //      ensure the response is not publicly cacheable. Do NOT add Vary: Cookie.
        if ($req->getAttribute('personalized')) {
            $resp = $resp->withCache(fn ($cc) => $cc->private()->noTransform());
        }

        // 5) Post: ensure validators + maybe auto-ETag (GET/HEAD only)
        if ($isGetHead) {
            $resp = $this->ensureValidatorHeaders($resp, $result->headers ?? []);
            $resp = $this->maybeAttachAutoEtag($resp, $req);
        }

        return $resp;
    }

    public static function setDefaultMetaProvider(?Closure $provider): void
    {
        self::$defaultProvider = $provider;
    }

    private static function collapseDotSegments(string $p): string
    {
        $isAbs = str_starts_with($p, '/');
        $parts = [];
        foreach (explode('/', $p) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $seg;
        }
        $out = implode('/', $parts);
        return $isAbs ? '/' . $out : $out;
    }

    private static function docRoot(): ?string
    {
        $dr = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
        return $dr !== '' ? $dr : null;
    }

    private static function etagForFile(int $size, int $mtime, string $realPath): string
    {
        $seed = $size . '|' . $mtime . '|' . basename($realPath);
        return '"' . substr(hash('xxh3', $seed, false), 0, 16) . '"';
    }

    private static function fallbackMetaProvider(Request $r): array
    {
        $path = $r->getUri()->getPath() ?: '/';
        $docRoot = self::docRoot();
        $scriptMts = self::scriptMtime();

        if ($docRoot !== null) {
            if ($info = self::resolveFileUnderDocroot($docRoot, $path)) {
                [$real, $size, $mtime] = $info;
                $etag = self::etagForFile($size, $mtime, $real);
                return [$etag, $mtime];
            }
        }

        return [self::syntheticEtag($path, $scriptMts), $scriptMts];
    }

    private static function normPath(string $p): string
    {
        $p = str_replace('\\', '/', $p);
        if (PHP_OS_FAMILY === 'Windows') {
            $p = strtolower($p);
        }
        return rtrim($p, '/');
    }

    private static function resolveFileUnderDocroot(string $docRoot, string $path): ?array
    {
        $decoded = rawurldecode($path);
        $norm = self::collapseDotSegments($decoded);
        $rel = ltrim($norm, "/\\");
        $cand = $rel === '' ? $docRoot : ($docRoot . DIRECTORY_SEPARATOR . $rel);

        $real = realpath($cand);
        if ($real === false) {
            return null;
        }

        $rootNorm = self::normPath($docRoot) . '/';
        $realNorm = self::normPath($real) . '/';
        $isUnder = str_starts_with($realNorm, $rootNorm);

        if (!$isUnder || !is_file($real) || !is_readable($real)) {
            return null;
        }

        $size = filesize($real);
        $mtime = filemtime($real);
        if ($size === false || $mtime === false) {
            return null;
        }

        return [$real, (int)$size, (int)$mtime];
    }

    private static function scriptMtime(): ?int
    {
        $file = (string)($_SERVER['SCRIPT_FILENAME'] ?? __FILE__);
        if (!is_file($file)) {
            return null;
        }
        $mt = filemtime($file);
        return $mt === false ? null : $mt;
    }

    private static function syntheticEtag(string $path, ?int $scriptMtime): string
    {
        return '"' . substr(hash('xxh3', 'fallback|' . $path . '|' . (string)$scriptMtime, false), 0, 16) . '"';
    }

    private function ensureValidatorHeaders(Response $resp, array $headers): Response
    {
        foreach ($headers as $h => $v) {
            if ($v !== null && !$resp->hasHeader($h)) {
                $resp = $resp->withHeader($h, $v);
            }
        }
        return $resp;
    }

    private function evaluatePreconditionsWithProvider(Request $req): array
    {
        [$etag, $lm] = $this->resolveProvider()($req);
        $validator = new ConditionalValidator($etag, $lm);
        $result = $validator->evaluate($req);
        return [$validator, $result];
    }

    private function isAutoEtagEligible(Response $r): bool
    {
        if ($r->getStatusCode() !== 200) {
            return false;
        }
        $b = $r->getBody();
        if (!$b->isSeekable()) {
            return false;
        }
        $size = $b->getSize();
        return !($size !== null && $size < $this->autoEtagMinSize);
    }

    private function isGetOrHead(Request $req): bool
    {
        $m = strtoupper($req->getMethod());
        return $m === 'GET' || $m === 'HEAD';
    }

    private function maybeAttachAutoEtag(Response $resp, Request $req): Response
    {
        if (
            !$this->autoEtagWhenMissing
            || $resp->hasHeader('ETag')
            || !$this->isAutoEtagEligible($resp)
        ) {
            return $resp;
        }

        $qs = $this->includeQueryInEtag
            ? Uri::normalizeQueryString($req->getUri()->getQuery())
            : '';

        if (($computed = Etag::fromStream($resp->getBody(), $qs)) !== null) {
            $resp = $resp->withHeader('ETag', $computed);
        }
        return $resp;
    }

    private function maybeDropStaleRangeHeader(Request $req, ConditionalValidator $validator, bool $isGetHead): Request
    {
        if (!$isGetHead || !$req->hasHeader('Range')) {
            return $req;
        }
        return $validator->isRangeFresh($req)
            ? $req
            : $req->withoutHeader('Range')->withAttribute('range_dropped', true);
    }

    private function maybeShortCircuit(object $result, bool $isGetHead): ?Response
    {
        if (($result->state ?? null) === Outcome::PASS) {
            return null;
        }

        // RFC 7232: Non-GET/HEAD with If-None-Match → 412 instead of 304
        $status = (!$isGetHead && ($result->http ?? 0) === 304) ? 412 : ($result->http ?? 412);
        return Response::empty($status, $result->headers ?? []);
    }

    private function resolveProvider(): Closure
    {
        if ($this->metaProvider instanceof Closure) {
            return $this->metaProvider;
        }
        if (self::$defaultProvider instanceof Closure) {
            return self::$defaultProvider;
        }
        return self::fallbackMetaProvider(...);
    }
}
