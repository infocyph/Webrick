<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use DateTimeImmutable;
use Infocyph\Webrick\Response\Cookies\Cookie;

final readonly class CookieAttributeApplier
{
    public function __construct(
        private bool $forceSecure,
        private bool $forceHttpOnly,
        private ?string $defaultSameSite,
    ) {}

    /**
     * @param array<string,bool|string> $attrs
     * @param Cookie $cookie
     */
    public function apply(Cookie $cookie, array $attrs): Cookie
    {
        $cookie = $this->applyPathDomainAndExpiryAttrs($cookie, $attrs);
        $cookie = $this->applySameSiteAttr($cookie, $attrs);
        $cookie = $this->applySecureAttr($cookie, $attrs);

        return $this->applyHttpOnlyAttr($cookie, $attrs);
    }

    /**
     * @param array<string,bool|string> $attrs
     * @param Cookie $cookie
     */
    private function applyHttpOnlyAttr(Cookie $cookie, array $attrs): Cookie
    {
        $hasHttpOnly = (isset($attrs['httponly']) && $attrs['httponly'] === true) || $this->hasFlag($attrs, 'httponly');
        if ($this->forceHttpOnly || $hasHttpOnly) {
            return $cookie->httpOnly();
        }

        return $cookie;
    }

    /**
     * @param array<string,bool|string> $attrs
     * @param Cookie $cookie
     */
    private function applyPathDomainAndExpiryAttrs(Cookie $cookie, array $attrs): Cookie
    {
        if (isset($attrs['path']) && \is_string($attrs['path'])) {
            $cookie = $cookie->path($attrs['path']);
        }
        if (isset($attrs['domain']) && \is_string($attrs['domain'])) {
            $cookie = $cookie->domain($attrs['domain']);
        }
        if (isset($attrs['max-age']) && \is_string($attrs['max-age']) && ctype_digit($attrs['max-age'])) {
            $cookie = $cookie->maxAge((int) $attrs['max-age']);
        }
        if (isset($attrs['expires']) && \is_string($attrs['expires'])) {
            $ts = strtotime($attrs['expires']);
            if ($ts !== false) {
                $cookie = $cookie->expires(new DateTimeImmutable("@{$ts}"));
            }
        }

        return $cookie;
    }

    /**
     * @param array<string,bool|string> $attrs
     * @param Cookie $cookie
     */
    private function applySameSiteAttr(Cookie $cookie, array $attrs): Cookie
    {
        if (isset($attrs['samesite']) && \is_string($attrs['samesite'])) {
            return $cookie->sameSite($attrs['samesite']);
        }

        return $this->defaultSameSite !== null
            ? $cookie->sameSite($this->defaultSameSite)
            : $cookie;
    }

    /**
     * @param array<string,bool|string> $attrs
     * @param Cookie $cookie
     */
    private function applySecureAttr(Cookie $cookie, array $attrs): Cookie
    {
        $hasSecure = (isset($attrs['secure']) && $attrs['secure'] === true) || $this->hasFlag($attrs, 'secure');
        if ($this->forceSecure || $this->isSameSiteNone($attrs) || $hasSecure) {
            return $cookie->secure();
        }

        return $cookie;
    }

    /**
     * @param array<string,bool|string> $attrs
     * @param string $flag
     */
    private function hasFlag(array $attrs, string $flag): bool
    {
        return array_any($attrs, fn($v, $k) => $k === $flag && $v === true);
    }

    /**
     * @param array<string,bool|string> $attrs
     */
    private function isSameSiteNone(array $attrs): bool
    {
        if (!isset($attrs['samesite'])) {
            return false;
        }

        return strcasecmp((string) $attrs['samesite'], 'none') === 0;
    }
}
