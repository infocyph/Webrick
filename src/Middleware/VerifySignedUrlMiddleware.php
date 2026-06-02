<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Constants\StatusEnum;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Url\SignedUrlConfig;
use Infocyph\Webrick\Router\Url\UrlGenerator;

final readonly class VerifySignedUrlMiddleware
{
    private SignedUrlConfig $config;

    /**
     * @param array<string,mixed>|list<string>|SignedUrlConfig|string $config
     * @param list<string> $ignoredQueryParams
     */
    public function __construct(
        array|SignedUrlConfig|string $config,
        int|string|null $leeway = null,
        array $ignoredQueryParams = [],
        ?string $payloadMode = null,
    ) {
        $this->config = $this->normalizeConfig($config, $leeway, $ignoredQueryParams, $payloadMode);
    }

    /**
     * @param Closure(Request):Response $next
     */
    public function __invoke(Request $request, Closure $next): Response
    {
        $query = $request->getQueryParams();
        $signatureParam = $this->config->signatureParam;
        $expiryParam = $this->config->expiryParam;
        $signature = $query[$signatureParam] ?? '';

        if (!\is_string($signature) || $signature === '') {
            return Response::plaintext('Missing signature', StatusEnum::BAD_REQUEST->value);
        }
        unset($query[$signatureParam]);

        if (isset($query[$expiryParam])) {
            $expiresAt = $query[$expiryParam];
            if (!\is_scalar($expiresAt) || !\is_numeric((string) $expiresAt)) {
                return Response::plaintext('Invalid expiration', StatusEnum::BAD_REQUEST->value);
            }

            if (\time() > ((int) $expiresAt + $this->config->leeway)) {
                return Response::plaintext('URL expired', StatusEnum::GONE->value);
            }
        }

        foreach ($this->config->ignoredQueryParams as $ignoredParam) {
            unset($query[$ignoredParam]);
        }

        \ksort($query);
        $payload = $this->buildPayload($request, $query);

        foreach ($this->config->verificationKeys as $key) {
            if (UrlGenerator::checkSignature($payload, $signature, $key, $this->config->algorithm)) {
                return $next($request);
            }
        }

        return Response::plaintext('Invalid signature', StatusEnum::FORBIDDEN->value);
    }

    /**
     * @param array<string,mixed> $query
     */
    private function buildPayload(Request $request, array $query): string
    {
        $target = $this->config->payloadMode === SignedUrlConfig::MODE_ABSOLUTE
            ? $this->requestAbsoluteTarget($request)
            : $this->requestRelativeTarget($request);

        if ($query === []) {
            return $target;
        }

        return $target . '?' . \http_build_query($query, '', '&', \PHP_QUERY_RFC3986);
    }

    /**
     * @param array<int|string,mixed> $values
     * @return list<string>
     */
    private function filterStringList(array $values): array
    {
        $filtered = [];
        foreach ($values as $value) {
            if (\is_string($value)) {
                $filtered[] = $value;
            }
        }

        return $filtered;
    }

    /**
     * @param array<string,mixed>|list<string>|SignedUrlConfig|string $config
     * @param list<string> $ignoredQueryParams
     */
    private function normalizeConfig(
        array|SignedUrlConfig|string $config,
        int|string|null $leeway,
        array $ignoredQueryParams,
        ?string $payloadMode,
    ): SignedUrlConfig {
        $baseConfig = match (true) {
            $config instanceof SignedUrlConfig => $config,
            \is_string($config) => new SignedUrlConfig(verificationKeys: [$config]),
            \array_is_list($config) => new SignedUrlConfig(
                verificationKeys: $this->filterStringList($config),
            ),
            default => SignedUrlConfig::fromArray($config),
        };

        return new SignedUrlConfig(
            generationKey: $baseConfig->generationKey,
            verificationKeys: $baseConfig->verificationKeys,
            defaultTtl: $baseConfig->defaultTtl,
            signatureParam: $baseConfig->signatureParam,
            expiryParam: $baseConfig->expiryParam,
            algorithm: $baseConfig->algorithm,
            payloadMode: $payloadMode ?? $baseConfig->payloadMode,
            ignoredQueryParams: $ignoredQueryParams !== []
                ? $this->filterStringList($ignoredQueryParams)
                : $baseConfig->ignoredQueryParams,
            leeway: $this->normalizeLeeway($leeway, $baseConfig->leeway),
        );
    }

    private function normalizeLeeway(int|string|null $leeway, int $default): int
    {
        if ($leeway === null) {
            return $default;
        }

        if (\is_int($leeway)) {
            return $leeway;
        }

        if ($leeway !== '' && \is_numeric($leeway)) {
            return (int) $leeway;
        }

        return $default;
    }

    private function requestAbsoluteTarget(Request $request): string
    {
        $uri = $request->getUri();
        $scheme = $uri->getScheme();
        $host = $uri->getHost();

        if ($scheme === '' || $host === '') {
            return $this->requestRelativeTarget($request);
        }

        return $scheme . '://' . $uri->getAuthority() . $this->requestRelativeTarget($request);
    }

    private function requestRelativeTarget(Request $request): string
    {
        $path = $request->getUri()->getPath();

        return $path === '' || $path[0] !== '/'
            ? '/' . \ltrim($path, '/')
            : $path;
    }
}
