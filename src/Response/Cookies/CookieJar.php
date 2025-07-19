<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Cookies;

use Infocyph\Webrick\Response\Response;

/**
 * Aggregate multiple `Set-Cookie` lines and attach to a Response.
 */
final class CookieJar
{
    /** @var array<string,Cookie> keyed by name */
    private array $cookies = [];

    public function add(Cookie $c): self
    {
        $x = clone $this;
        $x->cookies[$c->name] = $c;
        return $x;
    }

    public function remove(string $name): self
    {
        return $this->add(Cookie::make($name)->expire());
    }

    /** Idempotently attach Set-Cookie headers */
    public function apply(Response $r): Response
    {
        foreach ($this->cookies as $c) {
            $r = $r->withAddedHeader('Set-Cookie', (string)$c);
        }
        return $r;
    }
}
