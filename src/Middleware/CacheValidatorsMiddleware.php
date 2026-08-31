<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Constants\StatusEnum;
use Infocyph\Webrick\Request\Core\Uri;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Conditional\ConditionalValidator;
use Infocyph\Webrick\Response\Conditional\Outcome;
use Infocyph\Webrick\Response\Headers\CacheControl;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Support\Etag;

/** Conditional-request and validator middleware without implicit filesystem discovery. */
final readonly class CacheValidatorsMiddleware
{
    /**
     * Metadata provider returns [etag, lastModified, representationExists?].
     * Unsafe conditional requests require this authoritative pre-mutation state.
     *
     * @param null|Closure(Request):array{0:string|null,1:int|null,2?:bool} $metaProvider
     */
    public function __construct(
        private ?Closure $metaProvider = null,
        private bool $autoEtagWhenMissing = true,
        private bool $includeQueryInEtag = true,
        private int $autoEtagMinSize = 2048,
    ) {}

    /** @param Closure(Request):Response $next */
    public function __invoke(Request $req, Closure $next): Response
    {
        $getOrHead = $this->isGetOrHead($req);
        $preconditions = null;

        if ($this->metaProvider instanceof Closure) {
            [$validator, $preconditions] = $this->evaluatePreconditions($req);
            $shortCircuit = $this->maybeShortCircuit($preconditions, $getOrHead);
            if ($shortCircuit !== null) {
                return $shortCircuit;
            }
            $req = $this->maybeDropStaleRangeHeader($req, $validator, $getOrHead);
        } elseif (!$getOrHead && $this->hasUnsafePreconditions($req)) {
            throw new \LogicException(
                'Unsafe conditional requests require CacheValidatorsMiddleware metadata provider before mutation.',
            );
        }

        $resp = $next($req);
        if ($req->getAttribute('personalized') === true) {
            $resp = $resp->withCache(static fn(CacheControl $cache): CacheControl => $cache->private());
        }
        if (!$getOrHead) {
            return $resp;
        }

        if ($preconditions instanceof Outcome) {
            $resp = $this->ensureValidatorHeaders($resp, $preconditions->headers);
        }
        $resp = $this->maybeAttachAutoEtag($resp, $req);

        return $this->applyResponsePreconditions($req, $resp);
    }

    private static function parseLastModified(string $value): ?int
    {
        if ($value === '') {
            return null;
        }
        $timestamp = strtotime($value);

        return $timestamp === false ? null : $timestamp;
    }

    private function applyResponsePreconditions(Request $req, Response $resp): Response
    {
        $etag = trim($resp->getHeaderLine('ETag'));
        $lastModified = self::parseLastModified($resp->getHeaderLine('Last-Modified'));
        $status = $resp->getStatusCode();
        $exists = $status !== StatusEnum::NOT_FOUND->value && $status !== StatusEnum::GONE->value;
        $result = new ConditionalValidator(
            $etag === '' ? null : $etag,
            $lastModified,
            $exists,
        )->evaluate($req);

        return $this->maybeShortCircuit($result, true) ?? $resp;
    }

    /** @param array<string,string> $headers */
    private function ensureValidatorHeaders(Response $resp, array $headers): Response
    {
        foreach ($headers as $name => $value) {
            if (!$resp->hasHeader($name)) {
                $resp = $resp->withHeader($name, $value);
            }
        }

        return $resp;
    }

    /** @return array{0:ConditionalValidator,1:Outcome} */
    private function evaluatePreconditions(Request $req): array
    {
        $meta = ($this->metaProvider)($req);
        $etag = $meta[0] ?? null;
        $lastModified = $meta[1] ?? null;
        $exists = $meta[2] ?? null;
        $validator = new ConditionalValidator($etag, $lastModified, $exists);

        return [$validator, $validator->evaluate($req)];
    }

    private function hasUnsafePreconditions(Request $req): bool
    {
        return $req->getHeaderLine('If-Match') !== ''
            || $req->getHeaderLine('If-None-Match') !== ''
            || $req->getHeaderLine('If-Unmodified-Since') !== '';
    }

    private function isGetOrHead(Request $req): bool
    {
        $method = HttpMethodEnum::normalize($req->getMethod());

        return $method === HttpMethodEnum::GET->value || $method === HttpMethodEnum::HEAD->value;
    }

    private function maybeAttachAutoEtag(Response $resp, Request $req): Response
    {
        if (
            !$this->autoEtagWhenMissing
            || $resp->hasHeader('ETag')
            || $resp->getStatusCode() !== StatusEnum::OK->value
            || $resp->getFileBody() !== null
        ) {
            return $resp;
        }

        $size = $resp->getBodySize();
        if ($size !== null && $size < $this->autoEtagMinSize) {
            return $resp;
        }

        $salt = $this->includeQueryInEtag
            ? Uri::normalizeQueryString($req->getUri()->getQuery())
            : '';
        $string = $resp->getStringBody();
        $etag = $string !== null
            ? Etag::fromString($string, $salt)
            : Etag::fromStream($resp->getBody(), $salt);

        return $etag === null ? $resp : $resp->withHeader('ETag', $etag);
    }

    private function maybeDropStaleRangeHeader(Request $req, ConditionalValidator $validator, bool $getOrHead): Request
    {
        if (!$getOrHead || !$req->hasHeader('Range') || $validator->isRangeFresh($req)) {
            return $req;
        }

        return $req->withoutHeader('Range')->withAttribute('range_dropped', true);
    }

    private function maybeShortCircuit(Outcome $result, bool $getOrHead): ?Response
    {
        if ($result->state === Outcome::PASS) {
            return null;
        }

        $status = !$getOrHead && $result->http === StatusEnum::NOT_MODIFIED->value
            ? StatusEnum::PRECONDITION_FAILED->value
            : $result->http;

        return Response::empty($status, $result->headers);
    }
}
