<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Exceptions\HttpException;
use Infocyph\Webrick\Request\Core\Uri;
use Infocyph\Webrick\Request\Http\EndUser;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Request\Support\IpCidr;
use Infocyph\Webrick\Response\Response;

/**
 * Gateway/front-door hardening with request-local proxy state.
 */
final class GatewayHardeningMiddleware
{
    private const int DEFAULT_FORWARDED_HEADER_MASK = Request::HEADER_FORWARDED
        | Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_HOST
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO;

    private const array HOP_BY_HOP = [
        'connection',
        'keep-alive',
        'proxy-authenticate',
        'proxy-authorization',
        'te',
        'trailer',
        'transfer-encoding',
        'upgrade',
    ];

    private const int HOST_REGEX_CACHE_LIMIT = 64;

    /** @var array<string,list<string>> */
    private static array $hostRegexCache = [];

    private readonly bool $allowAllHosts;

    private readonly int $forwardedHeaderMask;

    /** @var list<string> */
    private readonly array $hostRegex;

    /**
     * @param list<string> $trustedProxyCidrs
     * @param list<string> $denyIpCidrs
     * @param list<string> $trustedHosts
     * @param list<string> $redirectAllowedHosts
     * @param list<string> $trustedClientIpHeaders Explicit vendor client-IP header names.
     */
    public function __construct(
        private readonly array $trustedProxyCidrs = [],
        private readonly array $denyIpCidrs = [],
        private readonly array $trustedHosts = [],
        ?int $forwardedHeaderMask = null,
        private readonly bool $enforceHttps = true,
        private readonly int $httpsPort = 443,
        private readonly bool $stripHopByHop = true,
        private readonly array $redirectAllowedHosts = [],
        private readonly array $trustedClientIpHeaders = [],
    ) {
        $this->forwardedHeaderMask = $forwardedHeaderMask ?? self::DEFAULT_FORWARDED_HEADER_MASK;
        $this->allowAllHosts = $this->trustedHosts === ['*'];
        $this->hostRegex = self::compileHostRegex($this->trustedHosts);
    }

    /**
     * @param Closure(Request):Response $next
     */
    public function __invoke(Request $req, Closure $next): Response
    {
        $req = $this->normalizeProxyContext($req);
        $this->rejectIfUntrustedHost($req);

        $endUser = EndUser::from(
            $req,
            $this->trustedProxyCidrs,
            $this->forwardedHeaderMask,
            $this->trustedClientIpHeaders,
        );
        $this->denyIfBlockedEndUser($endUser);
        $req = $this->attachNetworkAttributes($req, $endUser);

        $redirect = $this->redirectIfHttpsEnforced($req);
        if ($redirect instanceof Response) {
            return $redirect;
        }

        if ($this->stripHopByHop) {
            $req = $this->stripHopByHopFromRequest($req);
        }

        $response = $next($req);
        if ($this->stripHopByHop) {
            $response = $this->stripHopByHopFromResponse($response);
        }

        return $this->guardRedirects($req, $response);
    }

    /** @param array<string> $trustedHosts
     * @return list<string>
     */
    private static function compileHostRegex(array $trustedHosts): array
    {
        if ($trustedHosts === [] || $trustedHosts === ['*']) {
            return [];
        }

        $trustedHosts = array_values($trustedHosts);
        $key = hash('xxh128', json_encode($trustedHosts, JSON_THROW_ON_ERROR));
        if (isset(self::$hostRegexCache[$key])) {
            return self::$hostRegexCache[$key];
        }

        $compiled = [];
        foreach ($trustedHosts as $pattern) {
            $compiled[] = '#^' . str_replace('\\*', '.*', preg_quote($pattern, '#')) . '$#i';
        }

        if (count(self::$hostRegexCache) < self::HOST_REGEX_CACHE_LIMIT) {
            self::$hostRegexCache[$key] = $compiled;
        }

        return $compiled;
    }

    private static function equalsIgnoreCase(string $a, string $b): bool
    {
        return strcasecmp($a, $b) === 0;
    }

    private function attachNetworkAttributes(Request $req, EndUser $endUser): Request
    {
        $clientIp = $endUser->ip();
        $peerIp = $endUser->ipNoProxy();

        return $req->withAttributes([
            'client_ip' => $clientIp,
            'peer_ip' => $peerIp,
            'is_trusted_proxy' => $this->cidrHit($peerIp, $this->trustedProxyCidrs),
            'effective_scheme' => $req->getUri()->getScheme(),
            'effective_host' => $req->getUri()->getHost(),
            'effective_port' => $req->getUri()->getPort(),
        ]);
    }

    /**
     * @param array<string> $cidrs
     */
    private function cidrHit(?string $ip, array $cidrs): bool
    {
        return $ip !== null
            && $cidrs !== []
            && array_any($cidrs, static fn(string $cidr): bool => IpCidr::match($ip, $cidr));
    }

    private function denyIfBlockedEndUser(EndUser $endUser): void
    {
        $clientIp = $endUser->ip();
        if ($clientIp !== null && $this->cidrHit($clientIp, $this->denyIpCidrs)) {
            throw HttpException::forbidden("Forbidden – {$clientIp} is not allowed.");
        }
    }

    private function guardRedirects(Request $req, Response $response): Response
    {
        if (!$response->hasHeader('Location')) {
            return $response;
        }

        $location = trim($response->getHeaderLine('Location'));
        $scheme = parse_url($location, PHP_URL_SCHEME);
        if ($scheme !== null && $scheme !== '') {
            if (!is_string($scheme) || !in_array(strtolower($scheme), ['http', 'https'], true)) {
                throw HttpException::badRequest('Invalid redirect scheme');
            }
        }

        $host = parse_url($location, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return $response;
        }

        if ($this->redirectAllowedHosts === []) {
            if (!self::equalsIgnoreCase($host, $req->getUri()->getHost())) {
                throw HttpException::badRequest('Open redirect blocked');
            }
        } elseif (!array_any($this->redirectAllowedHosts, static fn(string $allowed): bool => strcasecmp($allowed, $host) === 0)) {
            throw HttpException::badRequest('Open redirect blocked');
        }

        return $response;
    }

    private function matchesHost(string $host): bool
    {
        return array_any($this->hostRegex, static fn(string $regex): bool => preg_match($regex, $host) === 1);
    }

    private function normalizeProxyContext(Request $req): Request
    {
        $peer = $req->getServerParams()['REMOTE_ADDR'] ?? null;
        if (!is_string($peer) || !$this->cidrHit($peer, $this->trustedProxyCidrs)) {
            return $req;
        }

        return $req->withUri(Uri::fromServerParams($req->getServerParams(), $this->forwardedHeaderMask));
    }

    /**
     * @return list<string>
     */
    private function parseConnectionTokens(string $line): array
    {
        if ($line === '') {
            return [];
        }

        $out = [];
        foreach (explode(',', $line) as $token) {
            $token = strtolower(trim($token));
            if ($token !== '') {
                $out[] = $token;
            }
        }

        return $out;
    }

    private function redirectIfHttpsEnforced(Request $req): ?Response
    {
        if (!$this->enforceHttps || $req->getUri()->getScheme() === 'https') {
            return null;
        }

        $port = $this->httpsPort === 443 ? null : $this->httpsPort;
        $target = $req->getUri()->withScheme('https')->withPort($port);

        return Response::redirect((string) $target, 308);
    }

    private function rejectIfUntrustedHost(Request $req): void
    {
        if ($this->allowAllHosts || $this->trustedHosts === []) {
            return;
        }

        $host = trim($req->getUri()->getHost());
        if ($host === '') {
            throw HttpException::badRequest('Missing or empty Host header.');
        }
        if (!$this->matchesHost($host)) {
            throw HttpException::badRequest('Untrusted Host header.');
        }
    }

    private function stripHopByHopFromRequest(Request $request): Request
    {
        $tokens = $this->parseConnectionTokens($request->getHeaderLine('Connection'));
        foreach (array_unique([...self::HOP_BY_HOP, ...$tokens]) as $header) {
            if ($request->hasHeader($header)) {
                $request = $request->withoutHeader($header);
            }
        }

        return $request;
    }

    private function stripHopByHopFromResponse(Response $response): Response
    {
        $tokens = $this->parseConnectionTokens($response->getHeaderLine('Connection'));
        foreach (array_unique([...self::HOP_BY_HOP, ...$tokens]) as $header) {
            if ($response->hasHeader($header)) {
                $response = $response->withoutHeader($header);
            }
        }

        return $response;
    }
}
