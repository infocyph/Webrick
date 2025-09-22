<?php

/**
 * Webrick - Cookie encryption middleware.
 *
 * Provides authenticated encryption (AES-256-GCM) for selected cookies with:
 * - Optional compression (zstd/brotli/gzip) chosen adaptively per value.
 * - Safe chunking for large ciphertexts, with server-side storage fallback.
 * - Key rotation using a key ring (by index, KID in framing).
 * - Security hardening for Set-Cookie attributes (Secure/HttpOnly/SameSite).
 *
 * Ciphertext framing (base64 of raw bytes):
 * - v1: 0x31 '1' | MODE(1) | KID(1) | IV(12) | TAG(16) | CT(...)
 * - v0: MODE(1)  | IV(12)  | TAG(16) | CT(...)
 *
 * Notes:
 * - Only cookies whose names start with $cookiePrefix are encrypted/decrypted.
 * - AAD binds ciphertext to its logical cookie name + KID + compression mode.
 * - Compression mode byte: '0' none, 'z' zstd, 'b' brotli, 'g' gzip.
 * - Optional server-side storage pointer uses the cookie value "S:<id>".
 *   The cache blob format (v1) is "C1:<8-hex-chk>:<base64-ciphertext>" where
 *   chk = first 8 hex chars of sha3-256 over the base64 ciphertext.
 *
 * @package Infocyph\Webrick\Middleware
 */

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use DateTimeImmutable;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Cookies\Cookie;
use Infocyph\Webrick\Response\Cookies\CookieJar;
use Infocyph\Webrick\Response\Response;
use InvalidArgumentException;
use LengthException;
use Psr\Cache\CacheItemPoolInterface;
use Random\RandomException;
use RuntimeException;

/**
 * Encrypt and authenticate outbound cookies; decrypt inbound cookies.
 */
final class CookieEncryptionMiddleware
{
    private const CACHE_PREFIX = 'enc_cookie.';
    private const MODE_BROTLI = 'b';
    private const MODE_GZIP = 'g';
    /* compression flags (1-byte, ASCII) */
    private const MODE_NONE = '0';
    private const MODE_STORE = 'S:';  // server-side storage marker in cookie value
    private const MODE_ZSTD = 'z';

    /** versioned cache blob prefix + simple integrity check (pre-b64 sanity) */
    private const STORE_BLOB_V1 = 'C1:';   // "C1:<chk>:<b64cipher>"
    private const STORE_SEP = ':';         // separator used in cache blob

    private const V1_BYTE = "1";      // 0x31
    private static bool $hasBrotli = false;
    private static bool $hasGzip = false;

    private static bool $hasZstd = false;

    /** @var list<string> 32-byte raw keys (key ring) */
    private array $keys;
    /** current encryption key id (index into $keys) */
    private int $kid;

    /**
     * Configure cookie encryption and storage settings.
     *
     * @param string|array<int,string>    $keyOrKeys     32B key or list of 32B keys; index 0 is default KID.
     * @param string                      $cookiePrefix  Cookie name prefix to process (others pass through).
     * @param int                         $maxBytes      Max bytes per cookie part (safe headroom under ~4k).
     * @param CacheItemPoolInterface|null $store         Optional PSR-6 cache for storage fallback.
     * @param int                         $storeTtl      TTL for server-side stored ciphertext (seconds).
     * @param bool                        $dropOnDecryptFailure Omit cookie when decrypt/auth fails.
     * @param bool                        $forceSecure   Enforce Secure attribute on encrypted cookies.
     * @param bool                        $forceHttpOnly Enforce HttpOnly attribute on encrypted cookies.
     * @param string|null                 $defaultSameSite Default SameSite value; null to leave unset.
     *
     * @throws InvalidArgumentException If keys are missing/invalid or maxBytes out of range.
     */
    public function __construct(
        string|array $keyOrKeys,                  // 32B key or list of 32B keys (key[0] is default)
        private string $cookiePrefix = 'enc_',
        private int $maxBytes = 3_800,            // per cookie part, safe headroom under ~4k
        private ?CacheItemPoolInterface $store = null,
        private int $storeTtl = 86_400,           // seconds
        // hardening / ergonomics
        private bool $dropOnDecryptFailure = true,
        private bool $forceSecure = true,
        private bool $forceHttpOnly = true,
        private ?string $defaultSameSite = 'Lax', // null = don’t set; 'None' requires Secure (we’ll enforce)
    ) {
        // normalize keys
        $this->keys = is_array($keyOrKeys) ? array_values($keyOrKeys) : [$keyOrKeys];
        if ($this->keys === []) {
            throw new InvalidArgumentException('At least one key is required.');
        }
        foreach ($this->keys as $i => $k) {
            if (strlen($k) !== 32) {
                throw new InvalidArgumentException("Key #$i must be 32 bytes (AES-256).");
            }
        }
        $this->kid = 0;

        if ($this->maxBytes < 256 || $this->maxBytes > 4096) {
            throw new InvalidArgumentException('maxBytes must be 256–4096.');
        }

        // probe compression once per worker
        if (self::$hasZstd === false && self::$hasBrotli === false && self::$hasGzip === false) {
            self::$hasZstd = function_exists('zstd_compress');
            self::$hasBrotli = function_exists('brotli_compress');
            self::$hasGzip = function_exists('gzdeflate');
        }
    }

    /* ───────────── pipeline ───────────── */

    /**
     * Decrypt inbound cookies before handling; encrypt outbound Set-Cookie headers after.
     *
     * @param Request $req
     * @param Closure $next
     *
     * @return Response Response with encrypted Set-Cookie headers.
     */
    public function __invoke(Request $req, Closure $next): Response
    {
        // decrypt incoming
        $req = $req->withCookieParams($this->decryptAll($req->getCookieParams()));

        // proceed
        $resp = $next($req);

        // encrypt outgoing Set-Cookie lines
        return $this->encryptAll($resp);
    }

    /**
     * Rotate the active encryption key by index.
     *
     * @param int $kid Key index (0..count-1).
     *
     * @return void
     *
     * @throws InvalidArgumentException When the index is unknown.
     */
    public function rotateToKid(int $kid): void
    {
        if (!isset($this->keys[$kid])) {
            throw new InvalidArgumentException("Unknown key id $kid.");
        }
        $this->kid = $kid;
    }

    /**
     * Build Additional Authenticated Data (AAD) for AES-GCM.
     *
     * @param string $baseName Logical cookie name.
     * @param int    $kid      Key id.
     * @param string $mode     Compression mode flag.
     *
     * @return string AAD string used for encryption/decryption.
     */
    private function aad(string $baseName, int $kid, string $mode): string
    {
        // Bind logical identity + key id + compression mode
        return $baseName . '|' . $kid . '|' . $mode;
    }

    /**
     * Re-apply (and enforce) cookie attributes to the builder.
     *
     * @param Cookie               $cookie Cookie builder.
     * @param array<string,bool|string> $attrs  Parsed attributes from original Set-Cookie.
     *
     * @return Cookie Cookie with attributes applied and security defaults enforced.
     */
    private function applyAttrs(Cookie $cookie, array $attrs): Cookie
    {
        // original attrs (best effort)
        if (isset($attrs['path']) && method_exists($cookie, 'path')) {
            $cookie = $cookie->path($attrs['path']);
        }
        if (isset($attrs['domain']) && method_exists($cookie, 'domain')) {
            $cookie = $cookie->domain($attrs['domain']);
        }
        if (isset($attrs['max-age']) && method_exists($cookie, 'maxAge')) {
            $cookie = $cookie->maxAge((int)$attrs['max-age']);
        }
        if (isset($attrs['expires']) && method_exists($cookie, 'expires')) {
            $ts = strtotime($attrs['expires']);
            if ($ts !== false) {
                $cookie = $cookie->expires(new DateTimeImmutable("@$ts"));
            }
        }
        if (isset($attrs['samesite']) && method_exists($cookie, 'sameSite')) {
            $cookie = $cookie->sameSite($attrs['samesite']);
        } elseif ($this->defaultSameSite !== null && method_exists($cookie, 'sameSite')) {
            $cookie = $cookie->sameSite($this->defaultSameSite);
        }

        $hasSecure = (isset($attrs['secure']) && $attrs['secure'] === true) || $this->hasFlag($attrs, 'secure');
        $hasHttpOnly = (isset($attrs['httponly']) && $attrs['httponly'] === true) || $this->hasFlag($attrs, 'httponly');

        if (($this->forceSecure || $this->isSameSiteNone($attrs)) && method_exists($cookie, 'secure')) {
            $cookie = $cookie->secure();
        } elseif ($hasSecure && method_exists($cookie, 'secure')) {
            $cookie = $cookie->secure();
        }

        if ($this->forceHttpOnly && method_exists($cookie, 'httpOnly')) {
            $cookie = $cookie->httpOnly();
        } elseif ($hasHttpOnly && method_exists($cookie, 'httpOnly')) {
            $cookie = $cookie->httpOnly();
        }

        return $cookie;
    }

    /**
     * Choose best compression among zstd, brotli, gzip, or none.
     *
     * @param string $pt Plaintext.
     *
     * @return array{0:string,1:string} [payload, mode]
     */
    private function bestCompress(string $pt): array
    {
        $best = $pt;
        $mode = self::MODE_NONE;

        if (self::$hasZstd && ($c = zstd_compress($pt, 3)) !== false && strlen($c) < strlen($best)) {
            $best = $c;
            $mode = self::MODE_ZSTD;
        }
        if (self::$hasBrotli && ($c = brotli_compress($pt, 4)) !== false && strlen($c) < strlen($best)) {
            $best = $c;
            $mode = self::MODE_BROTLI;
        }
        if (self::$hasGzip && ($c = gzdeflate($pt, 4)) !== false && strlen($c) < strlen($best)) {
            $best = $c;
            $mode = self::MODE_GZIP;
        }

        return [$best, $mode];
    }

    /**
     * Decode and validate a v1 cache blob ("C1:<chk>:<b64cipher>").
     *
     * @param string $blob The cache blob.
     *
     * @return string|null The base64 ciphertext string on success; null on failure.
     */
    private function decodeCacheBlobV1(string $blob): ?string
    {
        // Expect: "C1:<chk>:<b64cipher>"
        $prefixLen = strlen(self::STORE_BLOB_V1); // "C1:"
        $body = substr($blob, $prefixLen);
        $pos = strpos($body, self::STORE_SEP);
        if ($pos === false) {
            return null;
        }
        $chk = substr($body, 0, $pos);
        $b64 = substr($body, $pos + 1);

        if ($chk === '' || $b64 === '') {
            return null;
        }

        // quick integrity: first 8 hex chars of sha3-256(base64-cipher)
        $calc = substr(hash('sha3-256', $b64), 0, 8);
        if (!hash_equals($chk, $calc)) {
            return null;
        }

        // don't decode yet; just ensure it's plausible base64
        if (base64_decode($b64, true) === false) {
            return null;
        }

        return $b64;
    }

    /**
     * Decompress plaintext according to mode.
     *
     * @param string $mode Compression mode flag.
     * @param string $pt   Raw plaintext (possibly compressed).
     *
     * @return string|null Decompressed or original plaintext; null for unsupported mode.
     */
    private function decompress(string $mode, string $pt): mixed
    {
        return match ($mode) {
            self::MODE_ZSTD => self::$hasZstd ? @zstd_uncompress($pt) : null,
            self::MODE_BROTLI => self::$hasBrotli ? @brotli_uncompress($pt) : null,
            self::MODE_GZIP => @gzinflate($pt),
            default => $pt,
        };
    }

    /**
     * Decrypt a single cookie ciphertext (base64 string).
     *
     * @param string $baseName Logical cookie name (without .pN suffix).
     * @param string $cipher   Base64 ciphertext or "S:<id>" pointer.
     *
     * @return mixed|null Decrypted value or null when authentication fails.
     */
    private function decrypt(string $baseName, string $cipher): mixed
    {
        // server-side store indirection
        if (str_starts_with($cipher, self::MODE_STORE)) {
            $stored = $this->fromStore(substr($cipher, 2));
            if (!is_string($stored)) {
                return null;
            }

            // New format: "C1:<chk>:<b64cipher>"
            if (str_starts_with($stored, self::STORE_BLOB_V1)) {
                $decoded = $this->decodeCacheBlobV1($stored);
                if ($decoded === null) {
                    return null; // checksum/prefix invalid or malformed
                }
                $cipher = $decoded; // validated base64 ciphertext string
            } else {
                // Back-compat: pre-V1 cache could contain either ciphertext (base64) or plaintext.
                $maybeRaw = base64_decode($stored, true);
                if ($maybeRaw === false) {
                    // Legacy plaintext path — return as-is.
                    return $stored;
                }
                $cipher = $stored; // legacy cached ciphertext (no version/checksum)
            }
        }

        $raw = base64_decode($cipher, true);
        if ($raw === false) {
            return null;
        }

        // v1 or legacy?
        $off = 0;
        $isV1 = (isset($raw[0]) && $raw[0] === self::V1_BYTE);
        if ($isV1) {
            $off = 1;
        }

        $mode = $raw[$off] ?? null;
        $off += 1;
        $kid = $isV1 ? (ord($raw[$off] ?? "\x00")) : null;
        $off += $isV1 ? 1 : 0;

        $iv = substr($raw, $off, 12);
        $off += 12;
        $tag = substr($raw, $off, 16);
        $off += 16;
        $ct = substr($raw, $off);

        if ($mode === null || strlen($iv) !== 12 || strlen($tag) !== 16) {
            return null;
        }

        // pick key by KID (v1) or try ring (legacy/no kid)
        $keysToTry = [];
        if ($kid !== null && isset($this->keys[$kid])) {
            $keysToTry[] = [$kid, $this->keys[$kid]];
        } else {
            foreach ($this->keys as $i => $k) {
                $keysToTry[] = [$i, $k];
            }
        }

        foreach ($keysToTry as [$useKid, $key]) {
            $pt = openssl_decrypt(
                $ct,
                'aes-256-gcm',
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                $this->aad($baseName, (int)$useKid, $mode),   // AAD binds name+kid+mode
            );
            if ($pt !== false) {
                return $this->decompress($mode, $pt);
            }
        }

        return null; // auth failed with all keys
    }

    /* ───────────── decrypt path ───────────── */

    /**
     * Decrypt all cookies with the configured prefix and reassemble chunked values.
     *
     * @param array<string,string> $cookies Incoming cookie map.
     *
     * @return array<string,mixed> Cookie map with decrypted values (others pass through).
     */
    private function decryptAll(array $cookies): array
    {
        $rx = '/^(' . preg_quote($this->cookiePrefix, '/') . '[^.]+)(?:\.p(\d+))?$/';
        $out = $assemblies = [];

        foreach ($cookies as $name => $val) {
            if (!preg_match($rx, $name, $m)) {
                $out[$name] = $val; // not an encrypted cookie
                continue;
            }
            $base = $m[1];                     // e.g., enc_session
            $idx = (int)($m[2] ?? 1);
            $assemblies[$base][$idx] = $val;
        }

        foreach ($assemblies as $base => $parts) {
            ksort($parts);
            $cipher = implode('', $parts);
            $plain = $this->decrypt($base, $cipher);
            if ($plain === null && $this->dropOnDecryptFailure) {
                // fail closed: omit the cookie entirely
                continue;
            }
            $out[$base] = $plain;
        }

        return $out;
    }

    /* ───────────── cache blob helpers ───────────── */

    /**
     * Build v1 cache blob "C1:<8-hex-chk>:<b64cipher>".
     *
     * @param string $b64Cipher Base64 ciphertext.
     *
     * @return string Encoded cache blob.
     */
    private function encodeCacheBlobV1(string $b64Cipher): string
    {
        $chk = substr(hash('sha3-256', $b64Cipher), 0, 8);
        return self::STORE_BLOB_V1 . $chk . self::STORE_SEP . $b64Cipher;
    }

    /* ───────────── encrypt path ───────────── */

    /**
     * Replace Set-Cookie headers with encrypted equivalents for matching names.
     *
     * @param Response $resp Outgoing response.
     *
     * @return Response Response with updated Set-Cookie headers.
     */
    private function encryptAll(Response $resp): Response
    {
        $set = $resp->getHeader('Set-Cookie');
        if ($set === []) {
            return $resp;
        }

        $jar = new CookieJar();

        foreach ($set as $line) {
            $parts = $this->parseSetCookie($line);
            if ($parts === null) {
                // keep verbatim if we can’t parse
                $jar = $jar->raw($line);
                continue;
            }

            [$name, $value, $attrs] = $parts;

            // Only encrypt our prefix
            if (!str_starts_with($name, $this->cookiePrefix)) {
                $jar = $jar->raw($line);
                continue;
            }

            foreach ($this->encryptSegments($name, rawurldecode((string)$value)) as $i => $seg) {
                $cname = $i === 1 ? $name : "{$name}.p{$i}";
                $cookie = Cookie::make($cname, $seg);
                $cookie = $this->applyAttrs($cookie, $attrs);
                $jar = $jar->add($cookie);
            }
        }

        // Replace Set-Cookie with encrypted ones
        return $jar->apply($resp->withoutHeader('Set-Cookie'));
    }

    /**
     * Compress (best of zstd/brotli/gzip) → AES-256-GCM → base64.
     *
     * @param string $baseName Logical cookie name.
     * @param string $pt       Plaintext to encrypt.
     *
     * @return string Base64 ciphertext.
     *
     * @throws RandomException
     * @throws RuntimeException
     */
    private function encryptBlob(string $baseName, string $pt): string
    {
        [$best, $mode] = $this->bestCompress($pt);

        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt(
            $best,
            'aes-256-gcm',
            $this->keys[$this->kid],
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $this->aad($baseName, $this->kid, $mode),
        );

        if ($ct === false) {
            throw new RuntimeException('Cookie encryption failed.');
        }

        // v1 framing: '1' | MODE | KID | IV | TAG | CT
        $raw = self::V1_BYTE . $mode . chr($this->kid) . $iv . $tag . $ct;
        return base64_encode($raw);
    }

    /**
     * Split ciphertext into cookie-sized segments or store server-side if too large.
     *
     * @param string $baseName  Logical cookie name.
     * @param string $plaintext Plaintext payload.
     *
     * @return array<int,string> 1-based segments.
     *
     * @throws RandomException
     * @throws LengthException
     * @throws RuntimeException
     */
    private function encryptSegments(string $baseName, string $plaintext): array
    {
        $cipher = $this->encryptBlob($baseName, $plaintext);

        // fits?
        if (strlen($cipher) <= $this->maxBytes) {
            return [1 => $cipher];
        }

        // chunk (≤10)
        $parts = str_split($cipher, $this->maxBytes);
        if (count($parts) <= 10) {
            return array_combine(range(1, count($parts)), $parts);
        }

        // server-side fallback: **store ciphertext, wrapped with version + checksum**
        if ($this->store !== null) {
            $id = bin2hex(random_bytes(16));
            $item = $this->store->getItem(self::CACHE_PREFIX . $id);
            $item->set($this->encodeCacheBlobV1($cipher))->expiresAfter($this->storeTtl);
            $this->store->save($item);
            return [1 => self::MODE_STORE . $id];
        }

        throw new LengthException(
            'Encrypted cookie exceeds safe size even after chunking; ' .
            'enable server-side storage or reduce payload.',
        );
    }

    /**
     * Fetch a stored ciphertext/cache blob by id.
     *
     * @param string $id Storage key id (hex).
     *
     * @return mixed|null Stored payload or null when not found.
     */
    private function fromStore(string $id): mixed
    {
        if ($this->store === null) {
            return null;
        }
        $item = $this->store->getItem(self::CACHE_PREFIX . $id);
        return $item->isHit() ? $item->get() : null;
    }

    /**
     * Whether a flag-like attribute is present (e.g., Secure/HttpOnly).
     *
     * @param array<string,bool|string> $attrs
     * @param string                    $flag
     *
      * @return bool True when the attribute exists as a valueless flag.
     */
    private function hasFlag(array $attrs, string $flag): bool
    {
        return array_any($attrs, fn ($v, $k) => $k === $flag && $v === true);
    }

    /**
     * Whether attributes indicate SameSite=None.
     *
     * @param array<string,bool|string> $attrs
     *
     * @return bool True when SameSite=None is configured.
     */
    private function isSameSiteNone(array $attrs): bool
    {
        if (!isset($attrs['samesite'])) {
            return false;
        }
        return strcasecmp((string)$attrs['samesite'], 'none') === 0;
    }

    /* ───────────── helpers ───────────── */

    /**
     * Minimal Set-Cookie parser.
     *
     * @param string $line Raw Set-Cookie line.
     *
     * @return array{name:string, value:string, attrs:array<string,bool|string>}|null
     *               Returns [name, value, attrs[]] or null when unparseable.
     *               Value is raw (still urlencoded per RFC).
     */
    private function parseSetCookie(string $line): ?array
    {
        $chunks = array_map('trim', explode(';', $line));
        if ($chunks === [] || !str_contains($chunks[0], '=')) {
            return null;
        }
        [$name, $value] = explode('=', $chunks[0], 2);
        $attrs = [];
        for ($i = 1; $i < count($chunks); $i++) {
            if ($chunks[$i] === '') {
                continue;
            }
            $kv = explode('=', $chunks[$i], 2);
            $k = strtolower(trim($kv[0]));
            $v = isset($kv[1]) ? trim($kv[1]) : true;
            $attrs[$k] = $v;
        }
        return [trim($name), $value, $attrs];
    }
}
