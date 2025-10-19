<?php

declare(strict_types=1);

use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Response\Cookies\CookieJar;
use Infocyph\Webrick\Response\Cookies\Cookie;

/**
 * Helper to create response with cookies (since Response::withCookie doesn't exist)
 */
function responseWithCookie(
    string $name,
    string $value,
    int $maxAge = 0,
    string $path = '/',
    ?string $domain = null
): Response {
    $jar = new CookieJar();
    $cookie = Cookie::make($name, $value);

    if ($maxAge > 0) {
        $cookie = $cookie->maxAge($maxAge);
    }

    if ($path !== '/') {
        $cookie = $cookie->path($path);
    }

    if ($domain !== null) {
        $cookie = $cookie->domain($domain);
    }

    $jar = $jar->add($cookie);
    return $jar->apply(Response::create(''));
}

/**
 * Helper to create HTML response (since Response::html doesn't exist)
 */
function htmlResponse(string $html, int $status = 200): Response {
    return Response::create($html, $status, [
        'Content-Type' => 'text/html; charset=utf-8'
    ]);
}
