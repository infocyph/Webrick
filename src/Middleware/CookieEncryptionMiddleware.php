<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Cookies\Cookie;
use Infocyph\Webrick\Response\Cookies\CookieJar;
use Infocyph\Webrick\Response\Response;
use InvalidArgumentException;
use LengthException;
use Psr\Cache\CacheItemPoolInterface;
use Random\RandomException;
use RuntimeException;

/** Authenticated cookie encryption with immutable key selection. */
final class CookieEncryptionMiddleware
{
    private const string CACHE_PREFIX = 'enc_cookie.';
    private const string MODE_BROTLI = 'b';
    private const string MODE_GZIP = 'g';
    private const string MODE_NONE = '0';
    private const string MODE_STORE = 'S:';
    private const string MODE_ZSTD = 'z';
    private const string STORE_BLOB_V1 = 'C1:';
    private const string STORE_SEP = ':';
    private const string V1_BYTE = '1';

    private static bool $hasBrotli = false;
    private static bool $hasGzip = false;
    private static bool $hasZstd = false;

    private readonly CookieAttributeApplier $cookieAttributeApplier;
    /** @var list<string> */
    private readonly array $keys;
    private readonly int $kid;

    /** @param string|array<int,string> $keyOrKeys */
    public function __construct(
        string|array $keyOrKeys,
        private readonly string $cookiePrefix = 'enc_',
        private readonly int $maxBytes = 3_800,
        private readonly ?CacheItemPoolInterface $store = null,
        private readonly int $storeTtl = 86_400,
        private readonly bool $dropOnDecryptFailure = true,
        private readonly bool $forceSecure = true,
        private readonly bool $forceHttpOnly = true,
        private readonly ?string $defaultSameSite = 'Lax',
        int $activeKid = 0,
    ) {
        $keys = is_array($keyOrKeys) ? array_values($keyOrKeys) : [$keyOrKeys];
        if ($keys === []) {
            throw new InvalidArgumentException('At least one key is required.');
        }
        foreach ($keys as $i => $key) {
            if (strlen($key) !== 32) {
                throw new InvalidArgumentException("Key #{$i} must be 32 bytes (AES-256).");
            }
        }
        if (count($keys) > 256) {
            throw new InvalidArgumentException('At most 256 keys are supported.');
        }
        if (!isset($keys[$activeKid])) {
            throw new InvalidArgumentException("Unknown key id {$activeKid}.");
        }
        if ($this->maxBytes < 256 || $this->maxBytes > 4096) {
            throw new InvalidArgumentException('maxBytes must be 256–4096.');
        }

        $this->keys = $keys;
        $this->kid = $activeKid;

        if (!self::$hasZstd && !self::$hasBrotli && !self::$hasGzip) {
            self::$hasZstd = function_exists('zstd_compress');
            self::$hasBrotli = function_exists('brotli_compress');
            self::$hasGzip = function_exists('gzdeflate');
        }

        $this->cookieAttributeApplier = new CookieAttributeApplier(
            forceSecure: $this->forceSecure,
            forceHttpOnly: $this->forceHttpOnly,
            defaultSameSite: $this->defaultSameSite,
        );
    }

    /** @param Closure(Request):Response $next */
    public function __invoke(Request $req, Closure $next): Response
    {
        $req = $req->withCookieParams($this->decryptAll($req->getCookieParams()));

        return $this->encryptAll($next($req));
    }

    /** Return a new middleware instance with a different active encryption key. */
    public function rotateToKid(int $kid): self
    {
        if (!isset($this->keys[$kid])) {
            throw new InvalidArgumentException("Unknown key id {$kid}.");
        }
        if ($kid === $this->kid) {
            return $this;
        }

        return new self(
            keyOrKeys: $this->keys,
            cookiePrefix: $this->cookiePrefix,
            maxBytes: $this->maxBytes,
            store: $this->store,
            storeTtl: $this->storeTtl,
            dropOnDecryptFailure: $this->dropOnDecryptFailure,
            forceSecure: $this->forceSecure,
            forceHttpOnly: $this->forceHttpOnly,
            defaultSameSite: $this->defaultSameSite,
            activeKid: $kid,
        );
    }

    private function aad(string $baseName, int $kid, string $mode): string
    {
        return $baseName . '|' . $kid . '|' . $mode;
    }

    /** @return array{0:string,1:string} */
    private function bestCompress(string $plaintext): array
    {
        $best = $plaintext;
        $mode = self::MODE_NONE;
        if (self::$hasZstd && ($compressed = zstd_compress($plaintext, 3)) !== false && strlen($compressed) < strlen($best)) {
            $best = $compressed;
            $mode = self::MODE_ZSTD;
        }
        if (self::$hasBrotli && ($compressed = brotli_compress($plaintext, 4)) !== false && strlen($compressed) < strlen($best)) {
            $best = $compressed;
            $mode = self::MODE_BROTLI;
        }
        if (self::$hasGzip && ($compressed = gzdeflate($plaintext, 4)) !== false && strlen($compressed) < strlen($best)) {
            $best = $compressed;
            $mode = self::MODE_GZIP;
        }

        return [$best, $mode];
    }

    private function decodeCacheBlobV1(string $blob): ?string
    {
        $body = substr($blob, strlen(self::STORE_BLOB_V1));
        $position = strpos($body, self::STORE_SEP);
        if ($position === false) {
            return null;
        }
        $checksum = substr($body, 0, $position);
        $cipher = substr($body, $position + 1);
        if ($checksum === '' || $cipher === '' || !hash_equals($checksum, substr(hash('sha3-256', $cipher), 0, 8))) {
            return null;
        }

        return base64_decode($cipher, true) === false ? null : $cipher;
    }

    private function decompress(string $mode, string $payload): mixed
    {
        return match ($mode) {
            self::MODE_ZSTD => self::$hasZstd ? zstd_uncompress($payload) : null,
            self::MODE_BROTLI => self::$hasBrotli ? brotli_uncompress($payload) : null,
            self::MODE_GZIP => gzinflate($payload),
            default => $payload,
        };
    }

    private function decrypt(string $baseName, string $cipher): mixed
    {
        $resolved = $this->resolveCipherInput($cipher);
        if ($resolved['hasPlain']) {
            return $resolved['plain'];
        }
        if (!is_string($resolved['cipher'])) {
            return null;
        }
        $frame = $this->parseCipherFrame($resolved['cipher']);

        return $frame === null ? null : $this->decryptFrame($baseName, $frame);
    }

    /** @param array<string,mixed> $cookies @return array<string,mixed> */
    private function decryptAll(array $cookies): array
    {
        $pattern = '/^(' . preg_quote($this->cookiePrefix, '/') . '[^.]+)(?:\.p(\d+))?$/';
        $out = [];
        $assemblies = [];
        foreach ($cookies as $name => $value) {
            if (!is_string($value)) {
                continue;
            }
            if (!preg_match($pattern, $name, $matches)) {
                $out[$name] = $value;
                continue;
            }
            $assemblies[$matches[1]][(int) ($matches[2] ?? 1)] = $value;
        }

        foreach ($assemblies as $base => $parts) {
            ksort($parts);
            $plain = $this->decrypt($base, implode('', $parts));
            if (($plain === null || $plain === false) && $this->dropOnDecryptFailure) {
                continue;
            }
            $out[$base] = $plain === false ? null : $plain;
        }

        return $out;
    }

    private function decryptAndDecompress(
        string $baseName,
        string $mode,
        int $kid,
        string $key,
        string $iv,
        string $tag,
        string $ciphertext,
    ): mixed {
        $plain = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $this->aad($baseName, $kid, $mode),
        );
        if ($plain === false) {
            return null;
        }
        $decoded = $this->decompress($mode, $plain);

        return $decoded === false || $decoded === null ? null : $decoded;
    }

    /** @param array{mode:string,kid:?int,iv:string,tag:string,ct:string} $frame */
    private function decryptFrame(string $baseName, array $frame): mixed
    {
        foreach ($this->keysToTry($frame['kid']) as [$kid, $key]) {
            $decoded = $this->decryptAndDecompress(
                $baseName,
                $frame['mode'],
                $kid,
                $key,
                $frame['iv'],
                $frame['tag'],
                $frame['ct'],
            );
            if ($decoded !== null) {
                return $decoded;
            }
        }

        return null;
    }

    private function encodeCacheBlobV1(string $cipher): string
    {
        return self::STORE_BLOB_V1 . substr(hash('sha3-256', $cipher), 0, 8) . self::STORE_SEP . $cipher;
    }

    private function encryptAll(Response $response): Response
    {
        $setCookies = $response->getHeader('Set-Cookie');
        if ($setCookies === []) {
            return $response;
        }

        $jar = new CookieJar();
        foreach ($setCookies as $line) {
            $parts = $this->parseSetCookie($line);
            if ($parts === null || !str_starts_with($parts['name'], $this->cookiePrefix)) {
                $jar = $jar->raw($line);
                continue;
            }

            foreach ($this->encryptSegments($parts['name'], rawurldecode($parts['value'])) as $index => $segment) {
                $name = $index === 1 ? $parts['name'] : $parts['name'] . '.p' . $index;
                $cookie = $this->cookieAttributeApplier->apply(Cookie::make($name, $segment), $parts['attrs']);
                $jar = $jar->add($cookie);
            }
        }

        return $jar->apply($response->withoutHeader('Set-Cookie'));
    }

    /** @throws RandomException|RuntimeException */
    private function encryptBlob(string $baseName, string $plaintext): string
    {
        [$payload, $mode] = $this->bestCompress($plaintext);
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $payload,
            'aes-256-gcm',
            $this->keys[$this->kid],
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $this->aad($baseName, $this->kid, $mode),
        );
        if ($ciphertext === false) {
            throw new RuntimeException('Cookie encryption failed.');
        }

        return base64_encode(self::V1_BYTE . $mode . chr($this->kidByte()) . $iv . $tag . $ciphertext);
    }

    /** @return array<int,string> @throws RandomException|LengthException|RuntimeException */
    private function encryptSegments(string $baseName, string $plaintext): array
    {
        $cipher = $this->encryptBlob($baseName, $plaintext);
        if (strlen($cipher) <= $this->maxBytes) {
            return [1 => $cipher];
        }

        $parts = str_split($cipher, $this->maxBytes);
        if (count($parts) <= 10) {
            return array_combine(range(1, count($parts)), $parts);
        }
        if ($this->store !== null) {
            $id = bin2hex(random_bytes(16));
            $item = $this->store->getItem(self::CACHE_PREFIX . $id);
            $item->set($this->encodeCacheBlobV1($cipher))->expiresAfter($this->storeTtl);
            $this->store->save($item);

            return [1 => self::MODE_STORE . $id];
        }

        throw new LengthException('Encrypted cookie exceeds safe size even after chunking; enable server-side storage or reduce payload.');
    }

    private function fromStore(string $id): mixed
    {
        if ($this->store === null) {
            return null;
        }
        $item = $this->store->getItem(self::CACHE_PREFIX . $id);

        return $item->isHit() ? $item->get() : null;
    }

    /** @return list<array{0:int,1:string}> */
    private function keysToTry(?int $kid): array
    {
        if ($kid !== null) {
            return isset($this->keys[$kid]) ? [[$kid, $this->keys[$kid]]] : [];
        }

        $keys = [];
        foreach ($this->keys as $index => $key) {
            $keys[] = [$index, $key];
        }

        return $keys;
    }

    /** @return int<0,255> */
    private function kidByte(): int
    {
        if ($this->kid < 0 || $this->kid > 255) {
            throw new RuntimeException('Key id must be within byte range.');
        }

        return $this->kid;
    }

    /** @return array{mode:string,kid:?int,iv:string,tag:string,ct:string}|null */
    private function parseCipherFrame(string $cipher): ?array
    {
        $raw = base64_decode($cipher, true);
        if ($raw === false) {
            return null;
        }

        $offset = 0;
        $v1 = isset($raw[0]) && $raw[0] === self::V1_BYTE;
        if ($v1) {
            $offset = 1;
        }
        $mode = $raw[$offset] ?? null;
        if (!is_string($mode)) {
            return null;
        }
        $offset++;

        $kid = null;
        if ($v1) {
            $kid = ord($raw[$offset] ?? "\x00");
            $offset++;
        }
        $iv = substr($raw, $offset, 12);
        $offset += 12;
        $tag = substr($raw, $offset, 16);
        $offset += 16;
        if (strlen($iv) !== 12 || strlen($tag) !== 16) {
            return null;
        }

        return ['mode' => $mode, 'kid' => $kid, 'iv' => $iv, 'tag' => $tag, 'ct' => substr($raw, $offset)];
    }

    /** @return array{name:string,value:string,attrs:array<string,bool|string>}|null */
    private function parseSetCookie(string $line): ?array
    {
        $chunks = array_map(trim(...), explode(';', $line));
        if ($chunks === [] || !str_contains($chunks[0], '=')) {
            return null;
        }
        [$name, $value] = explode('=', $chunks[0], 2);
        $attrs = [];
        for ($i = 1, $count = count($chunks); $i < $count; $i++) {
            if ($chunks[$i] === '') {
                continue;
            }
            $pair = explode('=', $chunks[$i], 2);
            $attrs[strtolower(trim($pair[0]))] = isset($pair[1]) ? trim($pair[1]) : true;
        }

        return ['name' => trim($name), 'value' => $value, 'attrs' => $attrs];
    }

    /** @return array{cipher:?string,plain:mixed,hasPlain:bool} */
    private function resolveCipherInput(string $cipher): array
    {
        if (!str_starts_with($cipher, self::MODE_STORE)) {
            return ['cipher' => $cipher, 'plain' => null, 'hasPlain' => false];
        }
        $stored = $this->fromStore(substr($cipher, 2));
        if (!is_string($stored)) {
            return ['cipher' => null, 'plain' => null, 'hasPlain' => false];
        }
        if (str_starts_with($stored, self::STORE_BLOB_V1)) {
            return ['cipher' => $this->decodeCacheBlobV1($stored), 'plain' => null, 'hasPlain' => false];
        }
        if (base64_decode($stored, true) === false) {
            return ['cipher' => null, 'plain' => $stored, 'hasPlain' => true];
        }

        return ['cipher' => $stored, 'plain' => null, 'hasPlain' => false];
    }
}
