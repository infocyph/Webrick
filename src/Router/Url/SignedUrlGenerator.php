<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Url;

use Infocyph\Webrick\Router\Route\Collection;
use InvalidArgumentException;

class SignedUrlGenerator extends UrlGenerator
{
    public const string SIG_PARAM = '_sig';
    public const string EXPIRES_PARAM = '_exp';

    public function __construct(
        string $baseUri,
        Collection $routes,
        private readonly string $secret,
    ) {
        parent::__construct($baseUri, $routes);
    }

    /**
     * Build a signed URL, optionally expiring after $ttl seconds.
     *
     * @param non-empty-string $name Route name
     * @param array<string,mixed> $params Path parameters
     * @param array<string,mixed> $query Extra query parameters
     * @param int|null $ttl TTL in seconds, null = no expiry
     * @param bool $absolute Prepend baseUri?
     *
     * @return string
     */
    public function signed(
        string $name,
        array $params = [],
        array $query = [],
        ?int $ttl = null,
        bool $absolute = true,
    ): string {
        // 1) Disallow any pre-existing reserved params
        if (
            array_key_exists(self::SIG_PARAM, $query)
            || array_key_exists(self::EXPIRES_PARAM, $query)
        ) {
            throw new InvalidArgumentException(
                "Query may not contain reserved parameters '"
                . self::SIG_PARAM . "' or '" . self::EXPIRES_PARAM . "'.",
            );
        }

        // 2) Validate and set expiry
        if ($ttl !== null) {
            if ($ttl < 1) {
                throw new InvalidArgumentException('TTL must be a positive integer.');
            }
            $query[self::EXPIRES_PARAM] = time() + $ttl;
        }

        // 3) Sort for deterministic signature
        ksort($query);

        // 4) Build the *relative* path (no query)
        $relativePath = parent::urlFor($name, $params, [], false);

        // 5) Compute HMAC and append it
        $query[self::SIG_PARAM] = hash_hmac(
            'sha256',
            parent::to($relativePath, $query, false),
            $this->secret,
        );

        // 6) Build and return the final URL (absolute or relative)
        return parent::to($relativePath, $query, $absolute);
    }
}
