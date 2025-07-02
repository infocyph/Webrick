<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Client;

use Infocyph\ArrayKit\Collection\Collection;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Resolve public IP, handle trusted proxies, anonymise addresses,
 * and expose lightweight UA helpers.
 *
 * Immutable, PHP 8.4 readonly.
 */
final class EndUser
{
    /* -----------------------------------------------------------------
     * Constructor / factory
     * ---------------------------------------------------------------- */
    public function __construct(
        private ServerRequestInterface $req,
        private array $extraTrusted = []   // CIDR strings
    ) {}

    public static function from(ServerRequestInterface $req, array $trusted = []): self
    {
        return new self($req, $trusted);
    }

    /* -----------------------------------------------------------------
     * Global trusted-proxy list
     * ---------------------------------------------------------------- */
    private static array $trustedGlobal = [];

    public static function setTrustedProxies(array $cidrs): void
    {
        self::$trustedGlobal = $cidrs;
    }

    /* -----------------------------------------------------------------
     * Cached hooks
     * ---------------------------------------------------------------- */
    private ?string $cacheNoProxy  = null;
    private ?string $cacheViaProxy = null;
    private array   $cidrMemo      = [];

    /* -----------------------------------------------------------------
     * Public helpers
     * ---------------------------------------------------------------- */

    /** Preferred public IP (proxy-aware). */
    public function ip(): ?string
    {
        $hop = $this->ipNoProxy();

        return ($hop && $this->isTrusted($hop))
            ? $this->ipViaProxy()
            : $hop;
    }

    /** REMOTE_ADDR (CLI-safe). */
    public function ipNoProxy(): ?string
    {
        if ($this->cacheNoProxy !== null) {
            return $this->cacheNoProxy;
        }

        $ip = \PHP_SAPI === 'cli'
            ? \gethostbyname(\gethostname())
            : ($this->req->getServerParams()['REMOTE_ADDR'] ?? null);

        return $this->cacheNoProxy = \filter_var($ip, \FILTER_VALIDATE_IP) ?: null;
    }

    /** First *public* IP in Forwarded / X-Forwarded-For chain. */
    public function ipViaProxy(): ?string
    {
        if ($this->cacheViaProxy !== null) {
            return $this->cacheViaProxy;
        }

        $chain = $this->parseForwarded()
            ?: $this->parseLegacyForwarded();

        $chain[] = $this->ipNoProxy();

        foreach ($chain as $ip) {
            if (!$ip) {
                continue;
            }
            if (!$this->isPrivate($ip)) {
                return $this->cacheViaProxy = $ip;
            }
            if ($this->isTrusted($ip)) {
                continue;            // skip trusted internal hop
            }
            break;                      // hit un-trusted private hop
        }

        return $this->cacheViaProxy = $this->ipNoProxy();
    }

    /** Anonymise IPv4 (/24) or IPv6 (/64). */
    public function anonymise(string $ip): string
    {
        $wrapped = $ip[0] === '[' && $ip[-1] === ']';
        $ip      = $wrapped ? \substr($ip, 1, -1) : $ip;

        $bin = \inet_pton($ip);
        if ($bin === false) {
            return $ip;
        }
        $mask = \strlen($bin) === 4
            ? \inet_pton('255.255.255.0')                 // /24
            : \inet_pton('ffff:ffff:ffff:ffff:0:0:0:0');  // /64

        $masked = '';
        for ($i = 0, $len = \strlen($bin); $i < $len; ++$i) {
            $masked .= $bin[$i] & $mask[$i];
        }

        $out = \inet_ntop($masked) ?: $ip;
        return $wrapped ? '[' . $out . ']' : $out;
    }

    /* ---- User-Agent ------------------------------------------------ */

    public function userAgent(): ?string
    {
        return $this->req->getHeaderLine('User-Agent') ?: null;
    }

    public function parseUserAgent(): Collection
    {
        $ua = $this->userAgent() ?? 'Unknown';

        // Prefer WhichBrowser if installed
        if (\class_exists(\WhichBrowser\Parser::class)) {
            $wb = new \WhichBrowser\Parser($ua);
            return Collection::from([
                'raw'      => $ua,
                'browser'  => $wb->browser->name,
                'version'  => $wb->browser->version->value,
                'platform' => $wb->os->toString(),
                'engine'   => $wb->engine->name,
            ]);
        }

        // Fallback to ultra-light parser
        return Collection::from(
            ['raw' => $ua] + (new UAParser($this->req))->parse()->toArray()
        );
    }

    /* -----------------------------------------------------------------
     * Internal helpers
     * ---------------------------------------------------------------- */
    private function isPrivate(string $ip): bool
    {
        return \filter_var(
                $ip,
                \FILTER_VALIDATE_IP,
                \FILTER_FLAG_NO_RES_RANGE | \FILTER_FLAG_NO_PRIV_RANGE
            ) === false;
    }

    private function isTrusted(string $ip): bool
    {
        $all = \array_merge(self::$trustedGlobal, $this->extraTrusted);

        foreach ($all as $cidr) {
            $v6 = \str_contains($cidr, ':');
            $key = ($v6 ? '6' : '4') . ":$ip|$cidr";

            if (isset($this->cidrMemo[$key])) {
                if ($this->cidrMemo[$key]) {
                    return true;
                }
                continue;
            }

            $match = $v6 ? $this->cidrV6($ip, $cidr) : $this->cidrV4($ip, $cidr);
            $this->cidrMemo[$key] = $match;
            if ($match) {
                return true;
            }
        }
        return false;
    }

    /** RFC 7239 “Forwarded” header → list L→R */
    private function parseForwarded(): array
    {
        $h = $this->req->getHeaderLine('Forwarded');
        if ($h === '') {
            return [];
        }

        $out = [];
        foreach (\explode(',', $h) as $seg) {
            if (\preg_match('/for=(?:"?\[?)([A-F0-9:.]+)/i', $seg, $m)) {
                $out[] = $m[1];
            }
        }
        return $out;
    }

    /** Legacy X-Forwarded-For & friends. */
    private function parseLegacyForwarded(): array
    {
        $s = $this->req->getServerParams();

        $legacy = [
            'HTTP_X_FORWARDED_FOR',
            'HTTP_CLIENT_IP',
            'HTTP_X_REAL_IP',
        ];

        foreach ($legacy as $hdr) {
            if (empty($s[$hdr])) {
                continue;
            }
            return $hdr === 'HTTP_X_FORWARDED_FOR'
                ? \array_map('trim', \explode(',', (string) $s[$hdr]))
                : [\trim((string) $s[$hdr])];
        }
        return [];
    }

    /* -------- CIDR check helpers -------- */

    private function cidrV4(string $ip, string $cidr): bool
    {
        if (\str_contains($ip, ':')) {
            return false;
        }
        [$net, $mask] = \strpos($cidr, '/') ? \explode('/', $cidr, 2) : [$cidr, 32];
        $mask = (int) $mask;

        return (\ip2long($ip) & ~((1 << (32 - $mask)) - 1))
            === (\ip2long($net) & ~((1 << (32 - $mask)) - 1));
    }

    private function cidrV6(string $ip, string $cidr): bool
    {
        [$net, $mask] = \strpos($cidr, '/') ? \explode('/', $cidr, 2) : [$cidr, 128];
        $mask = (int) $mask;

        $ipBin  = \inet_pton($ip);
        $netBin = \inet_pton($net);
        if ($ipBin === false || $netBin === false) {
            return false;
        }

        $bytes = intdiv($mask, 8);
        $same  = \substr_compare($ipBin, $netBin, 0, $bytes) === 0;

        if ($same && $mask % 8) {
            $bitmask = 0xFF << (8 - ($mask % 8));
            $same = (\ord($ipBin[$bytes]) & $bitmask) === (\ord($netBin[$bytes]) & $bitmask);
        }

        return $same;
    }
}
