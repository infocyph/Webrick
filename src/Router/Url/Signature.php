<?php

/**
 * Webrick - URL signature utilities.
 *
 * Provides helpers to compute and verify HMAC-based signatures for URL payloads,
 * using a modern cryptographic hash (sha3-256) and constant-time comparison.
 *
 * @package Infocyph\Webrick\Router\Url
 */

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Url;

/**
 * Generate and verify HMAC signatures for URL payloads.
 *
 * This utility offers:
 * - make(): compute HMAC (sha3-256) over a payload with a secret key.
 * - check(): verify a provided signature using constant-time comparison.
 *
 * The class is non-instantiable; use its static methods.
 */
final class Signature
{
    /**
     * Prevent instantiation; use static methods only.
     */
    private function __construct()
    {
    }

    /**
     * Verify that the provided signature matches the computed HMAC.
     *
     * Uses constant-time comparison to mitigate timing attacks.
     *
     * @param string $payload The original payload.
     * @param string $sig The expected hex-encoded signature.
     * @param string $key Secret key to recompute the HMAC.
     *
     * @return bool True if the signature matches; false otherwise.
     */
    public static function check(string $payload, string $sig, string $key): bool
    {
        return \hash_equals(self::make($payload, $key), $sig);
    }

    /**
     * Compute an HMAC for the given payload using sha3-256.
     *
     * @param string $payload Arbitrary string to sign (e.g., canonicalized query).
     * @param string $key Secret key used for HMAC.
     *
     * @return string Hex-encoded HMAC digest.
     */
    public static function make(string $payload, string $key): string
    {
        return hash_hmac('sha3-256', $payload, $key);
    }
}
