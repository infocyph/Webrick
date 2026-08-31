<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Support\VaryContext;

/** Merge request-local Vary requirements once after downstream execution. */
final class VaryAccumulatorMiddleware
{
    /**
     * @param Closure(Request):Response $next
     */
    public function __invoke(Request $req, Closure $next): Response
    {
        $req = self::ensureContext($req);
        $resp = $next($req);
        $current = $resp->getHeaderLine('Vary');
        if (self::hasStar($current)) {
            return $resp;
        }

        $tokens = self::normalize(self::splitTokens($current));
        $context = self::context($req);
        if ($context !== null) {
            $tokens = self::merge($tokens, $context->all());
        }
        $tokens = self::merge($tokens, $this->inferAutoTokens($req, $resp));

        if (in_array('*', $tokens, true)) {
            return $current === '*' ? $resp : $resp->withHeader('Vary', '*');
        }
        if ($tokens === []) {
            return $current === '' ? $resp : $resp->withoutHeader('Vary');
        }

        $final = implode(', ', $tokens);

        return $final === $current ? $resp : $resp->withHeader('Vary', $final);
    }

    public static function add(Request $r, string ...$headers): Request
    {
        $r = self::ensureContext($r);
        self::context($r)?->add(...$headers);

        return $r;
    }

    public static function addIf(Request $r, bool $when, string ...$headers): Request
    {
        return $when ? self::add($r, ...$headers) : $r;
    }

    public static function clear(Request $r): Request
    {
        $r = self::ensureContext($r);
        self::context($r)?->clear();

        return $r;
    }

    /**
     * @return list<string>
     */
    public static function peek(Request $r, bool $normalized = true): array
    {
        $tokens = self::context($r)?->all() ?? [];

        return $normalized ? self::normalize($tokens) : $tokens;
    }

    private static function canonical(string $token): string
    {
        $token = trim($token);
        if ($token === '') {
            return '';
        }

        return implode('-', array_map(
            static fn(string $part): string => $part === '' ? '' : ucfirst(strtolower($part)),
            explode('-', $token),
        ));
    }

    private static function context(Request $request): ?VaryContext
    {
        $context = $request->getAttribute(VaryContext::ATTRIBUTE);

        return $context instanceof VaryContext ? $context : null;
    }

    private static function ensureContext(Request $request): Request
    {
        return self::context($request) instanceof VaryContext
            ? $request
            : $request->withAttribute(VaryContext::ATTRIBUTE, new VaryContext());
    }

    private static function hasStar(string $line): bool
    {
        return in_array('*', self::splitTokens($line), true);
    }

    /**
     * @param list<string> $base
     * @param list<string> $extra
     * @return list<string>
     */
    private static function merge(array $base, array $extra): array
    {
        $seen = array_fill_keys(array_map(strtolower(...), $base), true);
        foreach ($extra as $token) {
            $canonical = self::canonical($token);
            $key = strtolower($canonical);
            if ($canonical !== '' && !isset($seen[$key])) {
                $seen[$key] = true;
                $base[] = $canonical;
            }
        }

        return $base;
    }

    /**
     * @param list<string> $tokens
     * @return list<string>
     */
    private static function normalize(array $tokens): array
    {
        return self::merge([], $tokens);
    }

    /**
     * @return list<string>
     */
    private static function splitTokens(string $line): array
    {
        if ($line === '') {
            return [];
        }

        $tokens = [];
        foreach (explode(',', $line) as $token) {
            $token = trim($token);
            if ($token !== '') {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    /**
     * @return list<string>
     */
    private function inferAutoTokens(Request $req, Response $resp): array
    {
        $tokens = [];
        if ($resp->hasHeader('Content-Encoding')) {
            $tokens[] = 'Accept-Encoding';
        }
        if ($resp->hasHeader('Content-Language')) {
            $tokens[] = 'Accept-Language';
        }

        $allowOrigin = trim($resp->getHeaderLine('Access-Control-Allow-Origin'));
        if ($allowOrigin === '' || $allowOrigin === '*') {
            return $tokens;
        }

        $tokens[] = 'Origin';
        if (HttpMethodEnum::normalize($req->getMethod()) !== HttpMethodEnum::OPTIONS->value) {
            return $tokens;
        }
        if ($req->getHeaderLine('Access-Control-Request-Method') !== '') {
            $tokens[] = 'Access-Control-Request-Method';
        }
        if ($req->getHeaderLine('Access-Control-Request-Headers') !== '') {
            $tokens[] = 'Access-Control-Request-Headers';
        }

        return $tokens;
    }
}
