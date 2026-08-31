<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Constants\MediaTypeEnum;
use Infocyph\Webrick\Constants\StatusEnum;
use Infocyph\Webrick\Exceptions\HttpException;
use Infocyph\Webrick\Request\Http\ContentNegotiator;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Attribute\Produces;

/** Negotiate content type, charset and locale for a request. */
final readonly class NegotiationMiddleware
{
    /** @var list<string> */
    private array $produces;

    /**
     * @param list<string> $produces
     * @param list<string> $charsets
     * @param list<string> $locales
     */
    public function __construct(
        array $produces = [],
        private array $charsets = ['utf-8'],
        private array $locales = ['en'],
        private string $localeFallback = 'en',
    ) {
        $this->produces = $produces !== []
            ? $produces
            : ['+json', MediaTypeEnum::JSON->value, MediaTypeEnum::HTML->base()];
    }

    /**
     * @param Closure(Request):Response $next
     */
    public function __invoke(Request $req, Closure $next): Response
    {
        [$produces, $charsets] = $this->resolveRouteOverrides($req);
        [$req, $type, $charset] = $this->negotiateTypeAndCharset($req, $produces, $charsets);
        [$req, $locale] = $this->negotiateLocale($req);

        $req = $req->withAttributes([
            'negotiated.type' => $type,
            'negotiated.charset' => $charset,
            'locale' => $locale,
        ]);

        return $this->ensureContentType($next($req), $type, $charset)
            ->withSmartHeader('Content-Language', $locale);
    }

    private function charsetMattersFor(string $type): bool
    {
        if (MediaTypeEnum::isJsonLike($type)) {
            return false;
        }

        return str_starts_with($type, 'text/')
            || str_contains($type, 'xml')
            || $type === 'application/javascript'
            || $type === 'text/javascript';
    }

    /** @param list<string> $types */
    private function charsetMattersForAny(array $types): bool
    {
        return array_any(
            $types,
            fn(string $type): bool => $this->charsetMattersFor(strtolower($type)),
        );
    }

    private function composeContentType(string $type, ?string $charset): string
    {
        $lower = strtolower($type);
        if (MediaTypeEnum::isJsonLike($lower) || str_contains($type, ';')) {
            return $type;
        }

        return $charset !== null && $this->charsetMattersFor($lower)
            ? $type . '; charset=' . $charset
            : $type;
    }

    private function ensureContentType(Response $response, string $type, ?string $charset): Response
    {
        if (in_array($response->getStatusCode(), [StatusEnum::NO_CONTENT->value, StatusEnum::NOT_MODIFIED->value], true)) {
            return $response;
        }

        $existing = $response->getHeaderLine('Content-Type');
        if ($existing === '') {
            return $response->withSmartHeader('Content-Type', $this->composeContentType($type, $charset));
        }
        if ($charset === null || stripos($existing, 'charset=') !== false) {
            return $response;
        }

        $semicolon = strpos($existing, ';');
        $base = strtolower($semicolon === false ? $existing : substr($existing, 0, $semicolon));
        if (!$this->charsetMattersFor($base) || MediaTypeEnum::isJsonLike($base)) {
            return $response;
        }

        return $response->withSmartHeader('Content-Type', rtrim($existing) . '; charset=' . $charset);
    }

    /**
     * @return array{0:Request,1:string}
     */
    private function negotiateLocale(Request $req): array
    {
        [$locale, $source] = $req->detectLocale(
            $this->locales !== [] ? $this->locales : [$this->localeFallback],
            $this->localeFallback,
        );
        $locale = $locale !== '' ? $locale : $this->localeFallback;

        if ($source === 'header') {
            $req = VaryAccumulatorMiddleware::add($req, 'Accept-Language');
        } elseif ($source === 'cookie') {
            $req = $req->withAttribute('personalized', true);
        }

        return [$req, $locale];
    }

    /**
     * @param list<string> $produces
     * @param list<string> $charsets
     * @return array{0:Request,1:string,2:?string}
     */
    private function negotiateTypeAndCharset(Request $req, array $produces, array $charsets): array
    {
        $negotiator = new ContentNegotiator($req->headers());
        $type = $negotiator->preferred($produces);
        if ($type === null) {
            $req = VaryAccumulatorMiddleware::add($req, 'Accept');
            $headers = [
                'Content-Type' => MediaTypeEnum::PLAIN->value,
                'Vary' => 'Accept',
            ];

            if ($req->getHeaderLine('Accept-Charset') !== '' && $this->charsetMattersForAny($produces)) {
                VaryAccumulatorMiddleware::add($req, 'Accept-Charset');
                $headers['Vary'] = 'Accept, Accept-Charset';
            }

            throw HttpException::notAcceptable('Not acceptable.', $headers);
        }

        $req = VaryAccumulatorMiddleware::add($req, 'Accept');
        $lowerType = strtolower($type);
        $charset = null;

        if ($req->getHeaderLine('Accept-Charset') !== '' && $this->charsetMattersFor($lowerType)) {
            $charset = $this->pickCharset($negotiator, $charsets);
            if ($charset !== null) {
                $req = VaryAccumulatorMiddleware::add($req, 'Accept-Charset');
            }
        }

        if ($charset === null && $this->charsetMattersFor($lowerType)) {
            $lowerCharsets = array_map(strtolower(...), $charsets);
            $utf8 = array_search('utf-8', $lowerCharsets, true);
            $charset = $utf8 !== false ? $charsets[$utf8] : ($charsets[0] ?? null);
        }

        return [$req, $type, $charset];
    }

    /**
     * @param list<string> $candidates
     */
    private function pickCharset(ContentNegotiator $negotiator, array $candidates): ?string
    {
        foreach ($candidates as $charset) {
            if ($negotiator->supportsCharset($charset)) {
                return $charset;
            }
        }

        return null;
    }

    /**
     * @return array{0:list<string>,1:list<string>}
     */
    private function resolveRouteOverrides(Request $req): array
    {
        $produces = $this->produces;
        $charsets = $this->charsets;
        $attribute = $req->getAttribute('produces');

        if ($attribute instanceof Produces) {
            $produces = $attribute->types !== [] ? $attribute->types : $produces;
            $charsets = $attribute->charsets !== [] ? $attribute->charsets : $charsets;
        }

        return [$produces, $charsets ?? []];
    }
}
