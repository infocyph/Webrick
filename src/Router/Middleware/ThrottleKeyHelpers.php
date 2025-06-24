<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router\Middleware;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Psr\Http\Message\ServerRequestInterface;

trait ThrottleKeyHelpers
{
    /**
     * Builds a resolver from string spec:
     *   • 'ip'                – client IP
     *   • 'header:X-API-Key'  – header value
     *   • 'jwt.sub'           – "sub" claim from JWT
     */
    private static function makeKeyResolver(string $spec): callable
    {
        if ($spec === 'ip') {
            return static fn (ServerRequestInterface $r) =>
                $r->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0';
        }
        if (str_starts_with($spec, 'header:')) {
            $hdr = substr($spec, 7);
            return static fn (ServerRequestInterface $r) => $r->getHeaderLine($hdr);
        }
        if ($spec === 'jwt.sub') {
            return static function (ServerRequestInterface $r): string {
                if (!preg_match('/Bearer\s+(\S+)/', $r->getHeaderLine('Authorization'), $m)) {
                    return '';
                }
                try {
                    $pl = JWT::decode($m[1], new Key('unused', 'none'));
                    return (string) ($pl->sub ?? '');
                } catch (\Throwable) {
                    return '';
                }
            };
        }
        return static fn (): string => 'global';
    }
}
