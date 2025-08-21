<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use DateTimeImmutable;
use Infocyph\Webrick\Response\Cookies\Cookie;
use Infocyph\Webrick\Response\Cookies\CookieJar;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use InvalidArgumentException;
use LengthException;
use Psr\Cache\CacheItemPoolInterface;
use Random\RandomException;
use RuntimeException;

/**
 * Encrypted cookies (AES-256-GCM) with compression, chunking and key rotation.
 *
 * • Only cookies with names that start with $cookiePrefix are processed.
 * • AEAD AAD binds ciphertext to its logical cookie name + key id + compression mode.
 * • Ciphertext framing (base64 of raw bytes):
 *      v1: 0x31 '1' | MODE(1) | KID(1) | IV(12) | TAG(16) | CT(...)
 *      v0: MODE(1)  | IV(12)  | TAG(16) | CT(...)
 * • Compression mode byte: '0' none, 'z' zstd, 'b' brotli, 'g' gzip
 * • Optional server-side storage fallback pointer (“S:<id>”).
 *   Stored cache blob (v1): "C1:<8-hex-checksum>:<base64-ciphertext>"
 *   - checksum = first 8 hex chars of SHA-256 over the base64 ciphertext
 */
final class CookieEncryptionMiddleware
{
    /* compression flags (1-byte, ASCII) */
    private const MODE_NONE = '0';
    private const MODE_ZSTD = 'z';
    private const MODE_BROTLI = 'b';
    private const MODE_GZIP = 'g';

    private const V1_BYTE = "1";      // 0x31
    private const MODE_STORE = 'S:';  // server-side storage marker in cookie value
    private const CACHE_PREFIX = 'enc_cookie.';

    /** versioned cache blob prefix + simple integrity check (pre-b64 sanity) */
    private const STORE_BLOB_V1 = 'C1:';   // "C1:<chk>:<b64cipher>"
    private const STORE_SEP = ':';         // separator used in cache blob

    private static bool $hasZstd = false;
    private static bool $hasBrotli = false;
    private static bool $hasGzip = false;

    /** @var list<string> 32-byte raw keys (key ring) */
    private array $keys;
    /** current encryption key id (index into $keys) */
    private int $kid;

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

    /** Rotate the active encryption key by index (0..count-1). */
    public function rotateToKid(int $kid): void
    {
        if (!isset($this->keys[$kid])) {
            throw new InvalidArgumentException("Unknown key id $kid.");
        }
        $this->kid = $kid;
    }

    /* ───────────── pipeline ───────────── */

    public function __invoke(Request $req, Closure $next): Response
    {
        // decrypt incoming
        $req = $req->withCookieParams($this->decryptAll($req->getCookieParams()));

        // proceed
        $resp = $next($req);

        // encrypt outgoing Set-Cookie lines
        return $this->encryptAll($resp);
    }

    /* ───────────── decrypt path ───────────── */

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

    private function fromStore(string $id): mixed
    {
        if ($this->store === null) {
            return null;
        }
        $item = $this->store->getItem(self::CACHE_PREFIX . $id);
        return $item->isHit() ? $item->get() : null;
    }

    /**
     * Decode and validate a v1 cache blob ("C1:<chk>:<b64cipher>").
     * Returns the base64 ciphertext string on success, or null on failure.
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

        // quick integrity: first 8 hex chars of sha256(base64-cipher)
        $calc = substr(hash('sha256', $b64), 0, 8);
        if (!hash_equals($chk, $calc)) {
            return null;
        }

        // don't decode yet; just ensure it's plausible base64
        if (base64_decode($b64, true) === false) {
            return null;
        }

        return $b64;
    }

    private function decompress(string $mode, string $pt): mixed
    {
        return match ($mode) {
            self::MODE_ZSTD => self::$hasZstd ? @zstd_uncompress($pt) : null,
            self::MODE_BROTLI => self::$hasBrotli ? @brotli_uncompress($pt) : null,
            self::MODE_GZIP => @gzinflate($pt),
            default => $pt,
        };
    }

    /* ───────────── encrypt path ───────────── */

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
     * @return array<int,string> 1-based segments
     * @throws RandomException|LengthException|RuntimeException
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
     * Compress (best of zstd/brotli/gzip) → AES-256-GCM → base64.
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

    /** @return array{0:string,1:string} [payload, mode] */
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

    /** Additional Authenticated Data binding */
    private function aad(string $baseName, int $kid, string $mode): string
    {
        // Bind logical identity + key id + compression mode
        return $baseName . '|' . $kid . '|' . $mode;
    }

    /* ───────────── cache blob helpers ───────────── */

    /** Build v1 cache blob: "C1:<8-hex-chk>:<b64cipher>" */
    private function encodeCacheBlobV1(string $b64Cipher): string
    {
        $chk = substr(hash('sha256', $b64Cipher), 0, 8);
        return self::STORE_BLOB_V1 . $chk . self::STORE_SEP . $b64Cipher;
    }

    /* ───────────── helpers ───────────── */

    /**
     * Minimal Set-Cookie parser:
     *  returns [name, value, attrs[]] or null when unparseable.
     *  value is raw (still urlencoded per RFC).
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

    /**
     * Re-apply attributes (and enforce security defaults) to the Cookie builder.
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

    private function isSameSiteNone(array $attrs): bool
    {
        if (!isset($attrs['samesite'])) {
            return false;
        }
        return strcasecmp((string)$attrs['samesite'], 'none') === 0;
    }

    private function hasFlag(array $attrs, string $flag): bool
    {
        return array_any($attrs, fn ($v, $k) => $k === $flag && $v === true);
    }
}
