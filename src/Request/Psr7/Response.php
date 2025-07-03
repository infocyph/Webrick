<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Request\Psr7;

use Infocyph\Webrick\Request\Core\Message;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Lean PSR-7 Response (speed-first)
 * ---------------------------------
 *  • Inherits header/body logic from Core\Message
 *  • Immutable (all mutators clone)
 *  • Reason phrase is auto-filled from a tiny static map; you can override it
 */
final class Response extends Message implements ResponseInterface
{
    private int    $status;
    private string $reason;

    /* -------- ctor -------- */
    public function __construct(
        int             $status  = 200,
        StreamInterface $body    = new \Infocyph\Webrick\Request\Core\Stream(),
        array           $headers = [],
        string          $protocol= '1.1',
        ?string         $reason  = null
    ) {
        parent::__construct($headers, $body, $protocol);

        $this->assertStatus($status);
        $this->status = $status;
        $this->reason = $reason ?? self::mapReason($status);
    }

    /* -------- PSR-7 getters -------- */
    public function getStatusCode(): int      { return $this->status; }
    public function getReasonPhrase(): string { return $this->reason; }

    /* -------- PSR-7 immutators -------- */
    public function withStatus($code, $reasonPhrase = ''): static
    {
        $this->assertStatus($code);
        if ($code === $this->status && $reasonPhrase === $this->reason) {
            return $this;
        }
        $c = clone $this;
        $c->status = $code;
        $c->reason = $reasonPhrase !== '' ? $reasonPhrase : self::mapReason($code);
        return $c;
    }

    /* -------- internals -------- */
    private static function assertStatus(int $s): void
    {
        if ($s < 100 || $s > 599) {
            throw new InvalidArgumentException("Invalid HTTP status: {$s}");
        }
    }

    /** Tiny RFC-9110 reason-phrase table (extend if you like). */
    private static function mapReason(int $s): string
    {
        static $map = [
            200=>'OK', 201=>'Created', 202=>'Accepted', 204=>'No Content',
            301=>'Moved Permanently', 302=>'Found', 304=>'Not Modified',
            400=>'Bad Request', 401=>'Unauthorized', 403=>'Forbidden',
            404=>'Not Found', 405=>'Method Not Allowed', 409=>'Conflict',
            415=>'Unsupported Media Type', 418=>'I\'m a teapot',
            422=>'Unprocessable Entity', 429=>'Too Many Requests',
            500=>'Internal Server Error', 502=>'Bad Gateway', 503=>'Service Unavailable',
        ];
        return $map[$s] ?? '';
    }
}
