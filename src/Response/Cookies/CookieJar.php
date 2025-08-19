<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Cookies;

use Infocyph\Webrick\Response\Response;

/**
 * Aggregate multiple `Set-Cookie` lines and attach to a Response.
 */
final class CookieJar
{
    /** @var array<string,Cookie> */
    private array $cookies = [];
    /** @var string[] raw Set-Cookie lines to pass through unchanged */
    private array $raw = [];

    public function add(Cookie $c): self
    {
        $x = clone $this;
        $x->cookies[$c->name] = $c;
        return $x;
    }

    /** Keep an original Set-Cookie line verbatim */
    public function raw(string $line): self
    {
        $x = clone $this;
        $x->raw[] = $line;
        return $x;
    }

    public function remove(string $name): self
    {
        return $this->add(Cookie::make($name)->expire());
    }

    public function apply(Response $r): Response
    {
        foreach ($this->raw as $line) {
            $r = $r->withAddedHeader('Set-Cookie', $line);
        }
        foreach ($this->cookies as $c) {
            $r = $r->withAddedHeader('Set-Cookie', (string)$c);
        }
        return $r;
    }
}
