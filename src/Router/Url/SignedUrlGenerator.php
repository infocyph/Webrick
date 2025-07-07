<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Url;

use DateTimeImmutable;
use Infocyph\Webrick\Router\Route\Collection;
use InvalidArgumentException;

class SignedUrlGenerator extends UrlGenerator
{
    public const SIG_PARAM      = '_sig';
    public const EXPIRES_PARAM  = '_exp';

    public function __construct(
        string      $baseUri,
        Collection $routes,
        private readonly string $secret,
    ) {
        parent::__construct($baseUri, $routes);
    }

    /**
     * Build a **signed** URL (optionally expiring).
     *
     * @param non-empty-string $name Route name
     * @param array<string,mixed> $params Path params
     * @param array $query
     * @param int|null $ttl Seconds from now; null = no expiry
     * @param bool $absolute
     * @return string
     * @throws \DateMalformedStringException
     */
    public function signed(
        string $name,
        array  $params = [],
        array  $query  = [],
        ?int   $ttl    = null,
        bool   $absolute = true,
    ): string {
        if (isset($query[self::SIG_PARAM], $query[self::EXPIRES_PARAM])) {
            throw new InvalidArgumentException('Reserved signature params present in $query.');
        }

        if ($ttl !== null && $ttl < 1) {
            throw new InvalidArgumentException('TTL must be positive integer seconds.');
        }

        if ($ttl !== null) {
            $expires           = (new DateTimeImmutable())->modify("+{$ttl} seconds")->getTimestamp();
            $query[self::EXPIRES_PARAM] = $expires;
        }

        // Build *unsigned* URL first (relative, to keep scheme/host out of sig)
        $unsigned = parent::urlFor($name, $params, $query, false);

        // Signature: HMAC of path+qs (no baseUri) + secret
        $sig = hash_hmac('sha256', $unsigned, $this->secret);
        $query[self::SIG_PARAM] = $sig;

        // Re-build including signature
        return parent::urlFor($name, $params, $query, $absolute);
    }
}
