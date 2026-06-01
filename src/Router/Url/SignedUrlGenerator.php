<?php

/**
 * Webrick - Signed URL generator.
 *
 * Builds URL strings with an appended HMAC signature and optional expiry parameter.
 * The signature is computed deterministically over the final relative URL (path + sorted query).
 */

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Url;

use Infocyph\Webrick\Router\Route\Collection;
use InvalidArgumentException;

/**
 * Generate signed URLs for named routes.
 *
 * Usage example (verification side):
 *   if (!Signature::check($urlWithoutSig, $_GET['_sig'] ?? '', $secret)) {
 *       throw new \RuntimeException('URL signature mismatch.');
 *   }
 */
class SignedUrlGenerator extends UrlGenerator
{
    /**
     * Query parameter name used to carry the expiry timestamp (UNIX epoch).
     */
    public const string EXPIRES_PARAM = '_exp';

    /**
     * Query parameter name used to carry the URL signature.
     */
    public const string SIG_PARAM = '_sig';

    /**
     * Create a signed URL generator.
     *
     * @param string $baseUri Base URI used when generating absolute URLs.
     * @param Collection $routes Route collection for URL resolution.
     * @param string $secret Secret key used to compute HMAC signatures.
     */
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
     * Process:
     * 1) Ensure reserved parameters are not already present in $query.
     * 2) Optionally set an expiry timestamp.
     * 3) Sort query parameters for a deterministic signature.
     * 4) Generate the relative path (without query).
     * 5) Compute signature over the relative URL (path + sorted query).
     * 6) Return the final absolute or relative URL.
     *
     * @param string $name Route name.
     * @param array<string,bool|float|int|string|null> $params Path parameters for placeholder substitution.
     * @param array<string,array<int|string,mixed>|bool|float|int|string|null> $query Extra query parameters (will be sorted).
     * @param int|null $ttl TTL in seconds; null for no expiry.
     * @param bool $absolute Whether to return an absolute URL.
     * @return string The signed URL.
     *
     * @throws InvalidArgumentException If reserved parameters are present, or if TTL is invalid.
     */
    public function signed(
        string $name,
        array $params = [],
        array $query = [],
        ?int $ttl = null,
        bool $absolute = true,
    ): string {
        if ($name === '') {
            throw new InvalidArgumentException('Route name must not be empty.');
        }

        // 1) Disallow any pre-existing reserved params (signature or expiry)
        if (
            array_key_exists(self::SIG_PARAM, $query)
            || array_key_exists(self::EXPIRES_PARAM, $query)
        ) {
            throw new InvalidArgumentException(
                "Query may not contain reserved parameters '"
                . self::SIG_PARAM . "' or '" . self::EXPIRES_PARAM . "'.",
            );
        }

        // 2) Validate and set expiry (UNIX epoch)
        if ($ttl !== null) {
            if ($ttl < 1) {
                throw new InvalidArgumentException('TTL must be a positive integer.');
            }
            $query[self::EXPIRES_PARAM] = time() + $ttl;
        }

        // 3) Sort for deterministic signature
        ksort($query);

        // 4) Build the relative path (no query)
        $relativePath = parent::urlFor($name, $params, [], false);
        if ($relativePath === '') {
            throw new InvalidArgumentException('Resolved route path must not be empty.');
        }

        // 5) Compute HMAC and append it
        $query[self::SIG_PARAM] = Signature::make(parent::to($relativePath, $query, false), $this->secret);

        // 6) Build and return the final URL (absolute or relative)
        return parent::to($relativePath, $query, $absolute);
    }
}
