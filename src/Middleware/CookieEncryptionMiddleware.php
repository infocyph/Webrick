<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Response\Cookies\Cookie;
use Infocyph\Webrick\Response\Cookies\CookieJar;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

/**
 * Transparently encrypts + decrypts cookie values using AES-256-GCM.
 *
 * ⚠ Requires the `openssl` extension.  *Perf:* a single -O(1) call per
 * request/response; negligible for typical payloads.
 */
final readonly class CookieEncryptionMiddleware
{
    public function __construct(
        private string $key,                        // 32-byte raw key
        private string $cookiePrefix = 'enc_',      // only encrypt these
    ) {
        if (strlen($this->key) !== 32) {
            throw new \InvalidArgumentException('Key must be 32 bytes (AES-256)');
        }
    }

    public function __invoke(Request $req, Closure $next): Response
    {
        /* -- decrypt incoming ------------------------------------- */
        $cookies = $req->getCookieParams();
        foreach ($cookies as $name => &$val) {
            if (str_starts_with($name, $this->cookiePrefix)) {
                $val = $this->decrypt($val);
            }
        }
        $req = $req->withCookieParams($cookies);

        /* -- downstream stack ------------------------------------- */
        $resp = $next($req);

        /* -- encrypt outgoing ------------------------------------- */
        $jar = new CookieJar();
        foreach ($resp->getHeader('Set-Cookie') as $line) {
            $cookie = Cookie::make('dummy')->expire(); // placeholder
            [$name, $payload] = explode('=', $line, 2);
            if (!str_starts_with($name, $this->cookiePrefix)) {
                continue;
            }
            $enc = $this->encrypt(rawurldecode($payload));
            $jar = $jar->add(
                Cookie::make($name, $enc)->httpOnly()->secure()
            );
        }
        return $jar->apply($resp->withoutHeader('Set-Cookie'));
    }

    private function encrypt(string $plaintext): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt($plaintext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        return base64_encode($iv . $tag . $ct);
    }

    private function decrypt(string $cipher): ?string
    {
        $raw = base64_decode($cipher, true);
        if ($raw === false || strlen($raw) < 28) {                    // too short → clearly invalid
            return null;
        }

        $iv  = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ct  = substr($raw, 28);

        $pt = openssl_decrypt(
            $ct,
            'aes-256-gcm',
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        return $pt === false ? null : $pt;
    }
}
