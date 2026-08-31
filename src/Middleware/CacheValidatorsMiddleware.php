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
     * @param null|Closure(Request):array{0:string|null,1:int|null} $metaProvider
     * @param bool $autoEtagWhenMissing
     * @param bool $includeQueryInEtag
     * @param int $autoEtagMinSize
     */
    public function __construct(
        private ?Closure $metaProvider = null,
        private bool $autoEtagWhenMissing = true,
        private bool $includeQueryInEtag = true,
        private int $autoEtagMinSize = 2048,
    ) {}

    /**
     * @param Closure(Request):Response $next
     * @param Request $req
     */
    public function __invoke(Request $req, Closure $next): Response
    {
        $getOrHead = $this->isGetOrHead($req);
        [$validator, $preconditions] = $this->evaluatePreconditions($req);
        $shortCircuit = $this->maybeShortCircuit($preconditions, $getOrHead);
        if ($shortCircuit !== null) {
            return $shortCircuit;
        }

        $req = $this->maybeDropStaleRangeHeader($req, $validator, $getOrHead);
        $resp = $next($req);
        if ($req->getAttribute('personalized') === true) {
            $resp = $resp->withCache(static fn(CacheControl $cache): CacheControl => $cache->private());
        }
        if (!$getOrHead) {
            return $resp;
        }

        $resp = $this->ensureValidatorHeaders($resp, $preconditions->headers);
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
        $result = new ConditionalValidator($etag === '' ? null : $etag, $lastModified)->evaluate($req);

        return $this->maybeShortCircuit($result, true) ?? $resp;
    }

    /**
     * @param array<string,string> $headers
     * @param Response $resp
     */
    private function ensureValidatorHeaders(Response $resp, array $headers): Response
    {
        foreach ($headers as $name => $value) {
            if (!$resp->hasHeader($name)) {
                $resp = $resp->withHeader($name, $value);
            }
        }

        return $resp;
    }

    /**
     * @return array{0:ConditionalValidator,1:Outcome}
     * @param Request $req
     */
    private function evaluatePreconditions(Request $req): array
    {
        [$etag, $lastModified] = $this->metaProvider instanceof Closure
            ? ($this->metaProvider)($req)
            : [null, null];
        $validator = new ConditionalValidator($etag, $lastModified);

        return [$validator, $validator->evaluate($req)];
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
