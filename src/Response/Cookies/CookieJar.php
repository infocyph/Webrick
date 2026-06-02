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

    /**
     * Adds a Cookie to the jar.
     *
     * Returns a new instance of CookieJar with the added cookie.
     *
     * @param Cookie $c The cookie to add.
     * @return self A new instance of CookieJar with the added cookie.
     */
    public function add(Cookie $c): self
    {
        $x = clone $this;
        $x->cookies[$c->name] = $c;

        return $x;
    }

    /**
     * Attach all cookies to a Response.
     *
     * This method is typically used once you've added all desired cookies to the jar.
     *
     * It will attach the raw Set-Cookie lines first, followed by the {@see Cookie} objects.
     *
     * @param Response $r The response to attach cookies to
     * @return Response The response with attached cookies
     */
    public function apply(Response $r): Response
    {
        foreach ($this->raw as $line) {
            $r = $r->withAddedHeader('Set-Cookie', $line);
        }
        foreach ($this->cookies as $c) {
            $r = $r->withAddedHeader('Set-Cookie', (string) $c);
        }

        return $r;
    }

    /**
     * Add a raw Set-Cookie line to the jar.
     *
     * This is useful for adding cookies that are not represented by the {@see Cookie} class,
     * or for adding cookies with attributes not supported by the {@see Cookie} class.
     *
     * Note that raw lines are added before any {@see Cookie} objects when applying to a Response.
     *
     * @param string $line The raw Set-Cookie line to add.
     */
    public function raw(string $line): self
    {
        $x = clone $this;
        $x->raw[] = $line;

        return $x;
    }

    /**
     * Remove a cookie by adding a new Set-Cookie header with an expired cookie.
     * This method is a shortcut for adding a cookie with an expired timestamp.
     *
     * @param string $name The name of the cookie to remove.
     */
    public function remove(string $name): self
    {
        return $this->add(Cookie::make($name)->expire());
    }
}
