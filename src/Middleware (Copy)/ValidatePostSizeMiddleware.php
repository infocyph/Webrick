<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Request\Request;

/**
 * Aborts the request with **413 Payload Too Large** when the incoming
 * body size exceeds `post_max_size` (or a custom limit you supply).
 */
final readonly class ValidatePostSizeMiddleware
{
    public function __construct(private ?int $bytes = null) {}

    public function __invoke(Request $req, Closure $next): Response
    {
        $limit = $this->bytes ?? self::phpIniBytes(ini_get('post_max_size'));
        $length = (int)$req->getHeaderLine('Content-Length');

        if ($limit > 0 && $length > $limit) {
            return new Response(
                status: 413,
                headers: ['Content-Type' => 'text/plain; charset=utf-8'],
                body: new Stream('Payload exceeds maximum allowed size.'),
            );
        }

        return $next($req);
    }

    private static function phpIniBytes(string|false $val): int
    {
        if ($val === false) {
            return 0;
        }
        $val = trim($val);
        $unit = strtolower(substr($val, -1));
        $num = (int)$val;
        return match ($unit) {
            'g' => $num * 1024 * 1024 * 1024,
            'm' => $num * 1024 * 1024,
            'k' => $num * 1024,
            default => $num,
        };
    }
}
