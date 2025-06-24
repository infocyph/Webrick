<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router\Middleware;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

/**
 * Validates a JWT bearer token and enforces required scopes.
 *
 * @param array<string> $requiredScopes
 */
final class OAuth2ScopeMiddleware implements MiddlewareInterface
{
    /** @param array<string> $requiredScopes */
    public function __construct(
        private array  $requiredScopes,
        private string $algo      = 'RS256',
        private string $publicKey = '',
        private string $secret    = ''
    ) {}

    public function process(ServerRequestInterface $r, RequestHandlerInterface $h): ResponseInterface
    {
        $hdr = $r->getHeaderLine('Authorization');
        if (!preg_match('/^Bearer\s+(\S+)/', $hdr, $m)) {
            throw new RuntimeException('Unauthorized', 401);
        }
        $payload = $this->decodeJwt($m[1]);

        $scopes = preg_split('/\s+/', $payload->scope ?? '') ?: [];
        foreach ($this->requiredScopes as $need) {
            if (!in_array($need, $scopes, true)) {
                throw new RuntimeException('Forbidden – missing scope ' . $need, 403);
            }
        }
        return $h->handle($r);
    }

    private function decodeJwt(string $jwt): object
    {
        try {
            return JWT::decode($jwt, $this->key());
        } catch (\Throwable) {
            throw new RuntimeException('Unauthorized – invalid token', 401);
        }
    }

    private function key(): Key
    {
        return $this->algo === 'HS256'
            ? new Key($this->secret, 'HS256')
            : new Key($this->publicKey, $this->algo);
    }
}
