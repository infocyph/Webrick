<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Cookies;

use Infocyph\Webrick\Response\Response;

/**
 * Aggregate Set-Cookie values using RFC identity (name + domain + path).
 */
final class CookieJar
{
    /** @var array<string,Cookie> */
    private array $cookies = [];

    /** @var list<string> */
    private array $raw = [];

    public function add(Cookie $cookie): self
    {
        $jar = clone $this;
        $jar->cookies[$cookie->identity()] = $cookie;

        return $jar;
    }

    public function apply(Response $response): Response
    {
        foreach ($this->raw as $line) {
            $response = $response->withAddedHeader('Set-Cookie', $line);
        }
        foreach ($this->cookies as $cookie) {
            $response = $response->withAddedHeader('Set-Cookie', (string) $cookie);
        }

        return $response;
    }

    public function raw(string $line): self
    {
        $jar = clone $this;
        $jar->raw[] = $line;

        return $jar;
    }

    public function remove(string $name, string $path = '/', ?string $domain = null): self
    {
        $cookie = Cookie::make($name)->path($path);
        if ($domain !== null) {
            $cookie = $cookie->domain($domain);
        }

        return $this->add($cookie->expire());
    }
}
