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

/** Gateway/front-door hardening with request-local proxy state. */
final readonly class GatewayHardeningMiddleware
{
    private const int DEFAULT_FORWARDED_HEADER_MASK = Request::HEADER_FORWARDED
        | Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_HOST
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO;

    private const array HOP_BY_HOP = [
        'connection', 'keep-alive', 'proxy-authenticate', 'proxy-authorization',
        'te', 'trailer', 'transfer-encoding', 'upgrade',
    ];

    private bool $allowAllHosts;

    /** @var list<IpCidr> */
    private array $denyIpNetworks;

    private int $forwardedHeaderMask;

    /** @var list<string> */
    private array $hostRegex;

    /** @var list<IpCidr> */
    private array $trustedProxyNetworks;

    /**
     * @param list<string> $trustedProxyCidrs
     * @param list<string> $denyIpCidrs
     * @param list<string> $trustedHosts
     * @param list<string> $redirectAllowedHosts
     * @param list<string> $trustedClientIpHeaders
     * @throws \InvalidArgumentException
     */
    public function __construct(
        array $trustedProxyCidrs = [],
        array $denyIpCidrs = [],
        private array $trustedHosts = [],
        ?int $forwardedHeaderMask = null,
        private bool $enforceHttps = true,
        private int $httpsPort = 443,
        private bool $stripHopByHop = true,
        private array $redirectAllowedHosts = [],
        private array $trustedClientIpHeaders = [],
        private ?string $trustedJa4Header = null,
        private ?string $trustedAsnHeader = null,
    ) {
        foreach ([$this->trustedJa4Header, $this->trustedAsnHeader] as $name) {
            if ($name !== null && (strlen($name) > 64 || preg_match('/^[A-Za-z0-9-]+$/D', $name) !== 1)) {
                throw new \InvalidArgumentException('Trusted risk header names must be valid HTTP field names.');
            }
        }
        if ($httpsPort < 1 || $httpsPort > 65535) {
            throw new \InvalidArgumentException('httpsPort must be between 1 and 65535.');
        }
        $this->forwardedHeaderMask = $forwardedHeaderMask ?? self::DEFAULT_FORWARDED_HEADER_MASK;
        $this->allowAllHosts = $this->trustedHosts === ['*'];
        $this->hostRegex = self::compileHostRegex($this->trustedHosts);
        $this->trustedProxyNetworks = self::compileNetworks($trustedProxyCidrs);
        $this->denyIpNetworks = self::compileNetworks($denyIpCidrs);
    }

    /**
     * @param Closure(Request):Response $next
     * @throws \Infocyph\Webrick\Exceptions\HttpException
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public function __invoke(Request $req, Closure $next): Response
    {
        $req = $this->normalizeProxyContext($req);
        $this->rejectIfUntrustedHost($req);

        $endUser = EndUser::from(
            $req,
            $this->trustedProxyNetworks,
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

    /**
     * @param list<string> $trustedHosts
     * @return list<string>
     * @throws \InvalidArgumentException
     */
    private static function compileHostRegex(array $trustedHosts): array
    {
        if ($trustedHosts === [] || $trustedHosts === ['*']) {
            return [];
        }

        $compiled = [];
        foreach ($trustedHosts as $pattern) {
            $pattern = trim($pattern);
            if ($pattern === '') {
                throw new \InvalidArgumentException('Trusted host patterns must not be empty.');
            }
            $compiled[] = '#^' . str_replace('\\*', '.*', preg_quote($pattern, '#')) . '$#iD';
        }

        return $compiled;
    }

    /**
     * @param list<string> $cidrs
     * @return list<IpCidr>
     * @throws \InvalidArgumentException
     */
    private static function compileNetworks(array $cidrs): array
    {
        $networks = [];
        foreach ($cidrs as $cidr) {
            $cidr = trim($cidr);
            if ($cidr === '') {
                throw new \InvalidArgumentException('CIDR entries must not be empty.');
            }
            $networks[] = IpCidr::from($cidr);
        }

        return $networks;
    }

    private static function equalsIgnoreCase(string $a, string $b): bool
    {
        return strcasecmp($a, $b) === 0;
    }

    /**
     * @throws \InvalidArgumentException
     * @throws \Infocyph\Webrick\Exceptions\HttpException
     */
    private function attachNetworkAttributes(Request $req, EndUser $endUser): Request
    {
        $clientIp = $endUser->ip();
        $peerIp = $endUser->ipNoProxy();

        $trusted = $this->cidrHit($peerIp, $this->trustedProxyNetworks);

        return $req->withAttributes([
            'client_ip' => $clientIp,
            'peer_ip' => $peerIp,
            'is_trusted_proxy' => $trusted,
            'risk_verified_ja4' => $trusted ? $this->trustedJa4($req) : null,
            'risk_verified_asn' => $trusted ? $this->trustedAsn($req) : null,
            'effective_scheme' => $req->getUri()->getScheme(),
            'effective_host' => $req->getUri()->getHost(),
            'effective_port' => $req->getUri()->getPort(),
        ]);
    }


    /** @param list<IpCidr> $networks */
    private function cidrHit(?string $ip, array $networks): bool
    {
        return $ip !== null
            && array_any($networks, static fn(IpCidr $network): bool => $network->matches($ip));
    }

    /**
     * @throws \Infocyph\Webrick\Exceptions\HttpException
     * @throws \InvalidArgumentException
     */
    private function denyIfBlockedEndUser(EndUser $endUser): void
    {
        $clientIp = $endUser->ip();
        if ($clientIp !== null && $this->cidrHit($clientIp, $this->denyIpNetworks)) {
            throw HttpException::forbidden("Forbidden – {$clientIp} is not allowed.");
        }
    }

    /**
     * @throws \Infocyph\Webrick\Exceptions\HttpException
     * @throws \InvalidArgumentException
     */
    private function guardRedirects(Request $req, Response $response): Response
    {
        if (!$response->hasHeader('Location')) {
            return $response;
        }

        $location = trim($response->getHeaderLine('Location'));
        $parts = $this->parseRedirectTarget($location);
        $host = $parts['host'] ?? null;
        if ($host !== null && $host !== '' && !$this->redirectHostAllowed($host, $req->getUri()->getHost())) {
            throw HttpException::badRequest('Open redirect blocked');
        }

        return $response;
    }

    private function matchesHost(string $host): bool
    {
        return array_any($this->hostRegex, static fn(string $regex): bool => $regex !== '' && preg_match($regex, $host) === 1);
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function normalizeProxyContext(Request $req): Request
    {
        $peer = $req->getServerParams()['REMOTE_ADDR'] ?? null;
        if (!is_string($peer) || !$this->cidrHit($peer, $this->trustedProxyNetworks)) {
            return $req;
        }

        return $req->withUri(Uri::fromServerParams($req->getServerParams(), $this->forwardedHeaderMask));
    }

    /** @return list<string> */
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

    /**
     * @return array{scheme?:string,host?:string,port?:int,user?:string,pass?:string,path?:string,query?:string,fragment?:string}
     * @throws \Infocyph\Webrick\Exceptions\HttpException
     */
    private function parseRedirectTarget(string $location): array
    {
        if ($location === ''
            || str_contains($location, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $location) === 1
        ) {
            throw HttpException::badRequest('Invalid redirect target');
        }

        try {
            $parts = parse_url($location);
        } catch (\ValueError) {
            throw HttpException::badRequest('Invalid redirect target');
        }
        if ($parts === false) {
            throw HttpException::badRequest('Invalid redirect target');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw HttpException::badRequest('Invalid redirect authority');
        }

        $scheme = $parts['scheme'] ?? null;
        if ($scheme !== null && !in_array(strtolower($scheme), ['http', 'https'], true)) {
            throw HttpException::badRequest('Invalid redirect scheme');
        }

        $host = $parts['host'] ?? null;
        $hasHost = is_string($host) && $host !== '';
        if (($scheme !== null || str_starts_with($location, '//')) && !$hasHost) {
            throw HttpException::badRequest('Invalid redirect authority');
        }

        return $parts;
    }

    private function redirectHostAllowed(string $targetHost, string $requestHost): bool
    {
        if ($this->redirectAllowedHosts !== []) {
            return array_any(
                $this->redirectAllowedHosts,
                static fn(string $allowed): bool => strcasecmp(trim($allowed), $targetHost) === 0,
            );
        }

        return !$this->allowAllHosts
            && $this->hostRegex !== []
            && $this->matchesHost($requestHost)
            && self::equalsIgnoreCase($targetHost, $requestHost);
    }

    /**
     * @throws \Infocyph\Webrick\Exceptions\HttpException
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    private function redirectIfHttpsEnforced(Request $req): ?Response
    {
        if (!$this->enforceHttps || $req->getUri()->getScheme() === 'https') {
            return null;
        }

        $host = $req->getUri()->getHost();
        if ($host === '' || !$this->redirectHostAllowed($host, $host)) {
            throw HttpException::badRequest('HTTPS redirect requires an explicitly trusted host.');
        }

        $port = $this->httpsPort === 443 ? null : $this->httpsPort;
        $target = $req->getUri()->withScheme('https')->withPort($port);

        return Response::redirect((string) $target, 308);
    }

    /**
     * @throws \Infocyph\Webrick\Exceptions\HttpException
     */
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

    /**
     * @throws \InvalidArgumentException
     */
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

    /**
     * @throws \InvalidArgumentException
     */
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

    /**
     * @throws \Infocyph\Webrick\Exceptions\HttpException
     * @throws \InvalidArgumentException
     */
    private function trustedAsn(Request $request): ?int
    {
        $value = $this->trustedRiskHeader($request, $this->trustedAsnHeader);
        if ($value === null) {
            return null;
        }
        if (preg_match('/^(?:AS)?([1-9][0-9]{0,9})$/D', $value, $matches) !== 1
            || (int) $matches[1] > 4_294_967_295
        ) {
            throw HttpException::badRequest('Malformed verified ASN signal.');
        }

        return (int) $matches[1];
    }

    /**
     * Only an explicitly configured, CIDR-trusted edge can attest a fingerprint.
     * A missing header is not a failed JA4 classification.
     *
     * @throws \Infocyph\Webrick\Exceptions\HttpException
     * @throws \InvalidArgumentException
     */
    private function trustedJa4(Request $request): ?string
    {
        $value = $this->trustedRiskHeader($request, $this->trustedJa4Header);
        if ($value === null) {
            return null;
        }
        if (strlen($value) > 128 || preg_match('/^[a-z0-9_]{15,128}$/D', $value) !== 1) {
            throw HttpException::badRequest('Malformed verified JA4 signal.');
        }

        return $value;
    }

    /**
     * Explicitly reject ambiguous/multiple values from a trusted hop.
     * The edge must overwrite client-supplied headers, never append to them.
     *
     * @throws \Infocyph\Webrick\Exceptions\HttpException
     * @throws \InvalidArgumentException
     */
    private function trustedRiskHeader(Request $request, ?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $values = $request->getHeader($name);
        if ($values === []) {
            return null;
        }

        if (count($values) !== 1) {
            throw HttpException::badRequest('Ambiguous verified risk signal.');
        }

        $value = $values[0];
        if ($value === '') {
            return null;
        }
        if (strlen($value) > 128 || str_contains($value, ',') || trim($value) !== $value) {
            throw HttpException::badRequest('Malformed verified risk signal.');
        }

        return $value;
    }
}
