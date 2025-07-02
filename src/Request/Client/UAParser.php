<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Client;

use Infocyph\ArrayKit\Collection\Collection;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Ultra-light UA & Client-Hint parser.
 *
 * No giant regex soup, no external deps; fine for logging,
 * feature flag toggles, etc.
 */
final readonly class UAParser
{
    private string $ua;
    private string $uaLower;
    private array $hints;

    /* ---------------------------------------------------------- */
    public function __construct(ServerRequestInterface|string|null $source = null)
    {
        if ($source instanceof ServerRequestInterface) {
            $this->ua = $source->getHeaderLine('User-Agent');
            $this->hints = $this->parseSecCh($source);
        } else {
            $this->ua = (string)($source ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''));
            $this->hints = [];
        }
        $this->uaLower = \strtolower($this->ua);
    }

    /* ---------------------------------------------------------- */

    /** @return Collection{browser:string,version:string,platform:string,engine:string} */
    public function parse(): Collection
    {
        [$browser, $version] = $this->browser();
        $platform = $this->platform();
        $engine = $this->engine($browser);

        return Collection::from(compact('browser', 'version', 'platform', 'engine'));
    }

    /* ================= 1. Client-Hints ======================== */

    private function parseSecCh(ServerRequestInterface $req): array
    {
        $bag = [];

        if ($ua = $req->getHeaderLine('Sec-CH-UA')) {
            \preg_match_all('/"([^"]+?)";v="([^"]+)"/', $ua, $m, \PREG_SET_ORDER);
            foreach ($m as [, $brand, $ver]) {
                $bag['brands'][$brand] = $ver;
            }
        }
        $bag['mobile'] = $req->getHeaderLine('Sec-CH-UA-Mobile') === '?1';
        $bag['platform'] = $req->getHeaderLine('Sec-CH-UA-Platform');
        $bag['platformVersion'] = $req->getHeaderLine('Sec-CH-UA-Platform-Version');
        $bag['fullVersion'] = $req->getHeaderLine('Sec-CH-UA-Full-Version');

        return $bag;
    }

    /* ================= 2. Browser + version =================== */

    private function browser(): array
    {
        /* --- Prefer Client-Hints (Chromium derivates) ---------- */
        if (!empty($this->hints['brands'])) {
            foreach (\array_reverse($this->hints['brands'], true) as $brand => $ver) {
                if (!\preg_match('/^(Chromium|Not.?A.?Brand)$/i', $brand)) {
                    return [$brand, $this->hints['fullVersion'] ?: $ver];
                }
            }
            if (isset($this->hints['brands']['Chromium'])) {
                return ['Chromium', $this->hints['brands']['Chromium']];
            }
        }

        /* --- Fallback UA sniff -------------------------------- */
        $table = [
            'edg' => 'Edge',
            'opr' => 'Opera',
            'vivaldi' => 'Vivaldi',
            'brave' => 'Brave',
            'samsungbrowser' => 'Samsung Internet',
            'yabrowser' => 'Yandex Browser',
            'firefox' => 'Firefox',
            'chrome' => 'Chrome',
            'safari' => 'Safari',
            'msie' => 'Internet Explorer',
            'trident/7' => 'Internet Explorer',
        ];

        foreach ($table as $needle => $label) {
            if (\str_contains($this->uaLower, $needle)) {
                return [$label, $this->extractVersion($needle)];
            }
        }
        return ['Unknown', ''];
    }

    private function extractVersion(string $token): string
    {
        $rx = [
            'edg' => '/edg[e|a]?[ /]([\d.]+)/i',
            'opr' => '/(?:opr|opera)[ /]([\d.]+)/i',
            'vivaldi' => '/vivaldi[ /]([\d.]+)/i',
            'brave' => '/brave\/([\d.]+)/i',
            'samsungbrowser' => '/samsungbrowser\/([\d.]+)/i',
            'yabrowser' => '/yabrowser\/([\d.]+)/i',
            'firefox' => '/firefox\/([\d.]+)/i',
            'chrome' => '/chrome\/([\d.]+)/i',
            'safari' => '/version\/([\d.]+)/i',
            'msie' => '/msie ([\d.]+)/i',
            'trident/7' => '/rv:([\d.]+)/i',
        ];

        return isset($rx[$token]) && \preg_match($rx[$token], $this->ua, $m)
            ? $m[1]
            : '';
    }

    /* ================= 3. Platform =========================== */

    private function platform(): string
    {
        /* --- Client-Hints first --- */
        if ($this->hints) {
            $plat = $this->hints['platform'] ?: 'Unknown';
            $ver = $this->hints['platformVersion'];
            $plat .= $ver !== '' ? ' ' . $ver : '';
            if ($this->hints['mobile'] && !\str_contains(\strtolower($plat), 'android')) {
                $plat .= ' Mobile';
            }
            return \trim($plat);
        }

        /* --- UA fallback -------------------------------------- */
        $rx = [
            '/Windows NT 10\.0.*Windows[/\s]?11/i' => 'Windows 11',
            '/Windows NT 10\.0/i' => 'Windows 10',
            '/Windows NT 6\.3/i' => 'Windows 8.1',
            '/Windows NT 6\.2/i' => 'Windows 8',
            '/Windows NT 6\.1/i' => 'Windows 7',
            '/Mac OS X ([\d_]+)/i' => fn($v) => 'macOS ' . \str_replace('_', '.', $v),
            '/Android ([\d.]+)/i' => fn($v) => 'Android ' . $v,
            '/iPhone OS ([\d_]+)/i' => fn($v) => 'iOS ' . \str_replace('_', '.', $v),
            '/iPad; CPU OS ([\d_]+)/i' => fn($v) => 'iPadOS ' . \str_replace('_', '.', $v),
            '/Linux/i' => 'Linux',
        ];

        foreach ($rx as $pat => $label) {
            if (\preg_match($pat, $this->ua, $m)) {
                return \is_callable($label) ? $label($m[1]) : $label;
            }
        }
        return 'Unknown';
    }

    /* ================= 4. Engine ============================= */

    private function engine(string $browser): string
    {
        if (\in_array($browser, ['Chrome', 'Edge', 'Brave', 'Vivaldi', 'Yandex Browser', 'Samsung Internet'], true)) {
            return 'Blink';
        }
        return match (true) {
            \str_contains($this->uaLower, 'trident') => 'Trident',
            \str_contains($this->uaLower, 'gecko') && \str_contains($this->uaLower, 'firefox') => 'Gecko',
            \str_contains($this->uaLower, 'applewebkit') => 'WebKit',
            \str_contains($this->uaLower, 'presto') => 'Presto',
            default => 'Unknown',
        };
    }
}
