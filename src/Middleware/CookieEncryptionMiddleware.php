<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use LengthException;
use Psr\Cache\CacheItemPoolInterface;
use Infocyph\Webrick\Response\Cookies\Cookie;
use Infocyph\Webrick\Response\Cookies\CookieJar;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

/**
 * AES-256-GCM cookie encryption with
 *   • optional **Zstd / Brotli / Gzip** compression (best-size pick)
 *   • automatic **chunking** (`.p2` …) under browser limits
 *   • PSR-6 **server-side fallback** when still too large
 */
final readonly class CookieEncryptionMiddleware
{
    /* compression flags (1-byte, ASCII) */
    private const MODE_NONE   = '0';
    private const MODE_ZSTD   = 'z';
    private const MODE_BROTLI = 'b';
    private const MODE_GZIP   = 'g';

    /* server-side sentinel */
    private const MODE_STORE  = 'S:';
    private const CACHE_PREFIX = 'enc_cookie.';

    public function __construct(
        private string                  $key,          // 32-byte raw key
        private string                  $cookiePrefix = 'enc_',
        private int                     $maxBytes     = 3_800,
        private ?CacheItemPoolInterface $store        = null,
        private int                     $storeTtl     = 86_400,
    ) {
        if (strlen($this->key) !== 32) {
            throw new \InvalidArgumentException('Key must be 32 bytes (AES-256).');
        }
        if ($this->maxBytes < 256 || $this->maxBytes > 4096) {
            throw new \InvalidArgumentException('maxBytes must be 256–4096.');
        }
    }

    /* ───────────── middleware entry ───────────── */

    public function __invoke(Request $req, Closure $next): Response
    {
        $req  = $req->withCookieParams($this->decryptAll($req->getCookieParams()));
        $resp = $next($req);
        return $this->encryptAll($resp);
    }

    /* ───────────── decrypt path ───────────── */

    private function decryptAll(array $cookies): array
    {
        $rx  = '/^(' . preg_quote($this->cookiePrefix, '/') . '[^\.]+)(?:\.p(\d+))?$/';
        $out = $assemblies = [];

        foreach ($cookies as $name => $val) {
            if (!preg_match($rx, $name, $m)) {               // not encrypted
                $out[$name] = $val;
                continue;
            }
            $base = $m[1];
            $idx  = (int)($m[2] ?? 1);
            $assemblies[$base][$idx] = $val;
        }

        foreach ($assemblies as $base => $parts) {
            ksort($parts);
            $cipher          = implode('', $parts);
            $out[$base] = $this->decrypt($cipher);
        }
        return $out;
    }

    private function decrypt(string $cipher): mixed
    {
        /* server-side cached? ------------------------------------ */
        if (str_starts_with($cipher, self::MODE_STORE)) {
            if ($this->store === null) {
                return null;
            }
            $id   = substr($cipher, 2);
            $item = $this->store->getItem(self::CACHE_PREFIX . $id);
            return $item->isHit() ? $item->get() : null;
        }

        /* AES-GCM ------------------------------------------------- */
        $raw = base64_decode($cipher, true);
        if ($raw === false || strlen($raw) < 29) {
            return null;
        }

        $mode = $raw[0];
        $iv   = substr($raw, 1, 12);
        $tag  = substr($raw, 13, 16);
        $ct   = substr($raw, 29);

        $pt = openssl_decrypt(
            $ct,
            'aes-256-gcm',
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        if ($pt === false) {
            return null;
        }

        return match ($mode) {
            self::MODE_ZSTD   => \function_exists('zstd_uncompress')
                ? zstd_uncompress($pt) : null,
            self::MODE_BROTLI => \function_exists('brotli_uncompress')
                ? brotli_uncompress($pt) : null,
            self::MODE_GZIP   => gzinflate($pt),
            default           => $pt,
        };
    }

    /* ───────────── encrypt path ───────────── */

    private function encryptAll(Response $resp): Response
    {
        $jar = new CookieJar();

        foreach ($resp->getHeader('Set-Cookie') as $line) {
            [$name, $payload] = explode('=', $line, 2);
            if (!str_starts_with($name, $this->cookiePrefix)) {
                continue;
            }

            foreach ($this->encryptSegments(rawurldecode($payload)) as $i => $seg) {
                $cname = $i === 1 ? $name : "{$name}.p{$i}";
                $jar   = $jar->add(
                    Cookie::make($cname, $seg)->httpOnly()->secure()
                );
            }
        }
        return $jar->apply($resp->withoutHeader('Set-Cookie'));
    }

    /**
     * @return array<int,string> 1-based segment list
     * @throws LengthException
     */
    private function encryptSegments(string $plaintext): array
    {
        $cipher = $this->encryptBlob($plaintext);

        /* fits in one? */
        if (strlen($cipher) <= $this->maxBytes) {
            return [1 => $cipher];
        }

        /* chunk into ≤10 parts */
        $parts = str_split($cipher, $this->maxBytes);
        if (\count($parts) <= 10) {
            return array_combine(range(1, \count($parts)), $parts);
        }

        /* cache fallback */
        if ($this->store !== null) {
            $id   = bin2hex(random_bytes(16));
            $item = $this->store->getItem(self::CACHE_PREFIX . $id);
            $item->set($plaintext)->expiresAfter($this->storeTtl);
            $this->store->save($item);
            return [1 => self::MODE_STORE . $id];
        }

        throw new LengthException(
            'Encrypted cookie exceeds safe size even after chunking; '
            . 'consider server-side storage or reduce payload.'
        );
    }

    /**
     * Compress (best of zstd / brotli / gzip) → encrypt → base64.
     */
    private function encryptBlob(string $pt): string
    {
        $best     = $pt;
        $bestMode = self::MODE_NONE;

        /* Zstd (fast, good ratio) */
        if (\function_exists('zstd_compress')) {
            $c = zstd_compress($pt, 3);
            if ($c !== false && strlen($c) + 1 < strlen($best)) {
                $best = $c;
                $bestMode = self::MODE_ZSTD;
            }
        }

        /* Brotli (excellent ratio, slower) */
        if (\function_exists('brotli_compress')) {
            $c = brotli_compress($pt, 4);              // quality 4 ≈ gzip-level-4
            if ($c !== false && strlen($c) + 1 < strlen($best)) {
                $best = $c;
                $bestMode = self::MODE_BROTLI;
            }
        }

        /* Gzip deflate */
        if (\function_exists('gzdeflate')) {
            $c = gzdeflate($pt, 4);
            if ($c !== false && strlen($c) + 1 < strlen($best)) {
                $best = $c;
                $bestMode = self::MODE_GZIP;
            }
        }

        /* AES-256-GCM */
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt(
            $best,
            'aes-256-gcm',
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        return base64_encode($bestMode . $iv . $tag . $ct);
    }
}
