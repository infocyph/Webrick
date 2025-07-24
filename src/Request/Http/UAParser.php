<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Http;

use Infocyph\Webrick\Request\Psr7\ServerRequest;

/**
 * UAParser – coarse browser / platform / engine detector
 * ------------------------------------------------------
 * 1.  Prefers Chromium-style Client-Hints (Sec-CH-UA*) – zero regex cost
 * 2.  Falls back to frozen User-Agent header with a **small** lookup table
 *
 *      $ua = new UAParser($request);
 *      $info = $ua->parse();   // ['browser'=>'Chrome','version'=>'117', …]
 *
 * Good for logging, feature flags. **Not** a capability database.
 */
final class UAParser
{
    /* ---- raw UA + lowercase cache ---- */
    private string $ua;
    private string $uaLower;

    /* ---- parsed Sec-CH bag (if any) ---- */
    private array $hint = [];

    private static array $tokenMap = [          // longest tokens first
        'edg'             => 'Edge',
        'opr'             => 'Opera',
        'vivaldi'         => 'Vivaldi',
        'brave'           => 'Brave',
        'samsungbrowser'  => 'Samsung Internet',
        'yabrowser'       => 'Yandex Browser',
        'firefox'         => 'Firefox',
        'chrome'          => 'Chrome',
        'safari'          => 'Safari',
        'msie'            => 'Internet Explorer',
        'trident/7'       => 'Internet Explorer',
    ];

    /* --------------------------------------------------------------------- */

    public function __construct(ServerRequest|string|null $source = null)
    {
        if ($source instanceof ServerRequest) {
            $this->ua = $source->getHeaderLine('User-Agent');
            $this->hint = $this->parseSecCh($source);
        } else {
            $this->ua = (string)($source ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        }
        $this->uaLower = strtolower($this->ua);
    }

    public function parse(): array
    {
        [$browser, $version] = $this->browser();   // ← rename $ver → $version
        $platform = $this->platform();
        $engine   = $this->engine($browser);

        return compact('browser', 'version', 'platform', 'engine');
    }


    /* =====================================================================
       Section 1 – Client-Hints
       ===================================================================== */
    private function parseSecCh(ServerRequest $req): array
    {
        $bag = [];

        // "Sec-CH-UA" → brand list
        if ($uaHint = $req->getHeaderLine('Sec-CH-UA')) {
            preg_match_all('/"([^"]+?)";v="([^"]+)"/', $uaHint, $m, PREG_SET_ORDER);
            foreach ($m as [, $brand, $ver]) {
                $bag['brands'][$brand] = $ver;
            }
        }

        $bag['mobile'] = $req->getHeaderLine('Sec-CH-UA-Mobile') === '?1';
        $bag['platform'] = $req->getHeaderLine('Sec-CH-UA-Platform');
        $bag['platformVersion'] = $req->getHeaderLine('Sec-CH-UA-Platform-Version');
        $bag['fullVersion'] = $req->getHeaderLine('Sec-CH-UA-Full-Version');

        return array_filter($bag);
    }

    /* =====================================================================
       Section 2 – Browser + version
       ===================================================================== */
    private function browser(): array
    {
        /* ----- BEST: Client-Hints ------------------------------------------------ */
        if ($brands = ($this->hint['brands'] ?? [])) {
            $preferred = array_reverse($brands, true);       // Chromium sends real brand last
            foreach ($preferred as $brand => $ver) {
                if (!preg_match('/^(?:Chromium|Not\s?)?A?Brand$/i', $brand)) {
                    return [$brand, $this->hint['fullVersion'] ?: $ver];
                }
            }
            if (isset($brands['Chromium'])) {
                return ['Chromium', $brands['Chromium']];
            }
        }
        foreach (self::$tokenMap as $token => $label) {
            if (str_contains($this->uaLower, $token)) {
                return [$label, $this->extractVersion($token)];
            }
        }
        return ['Unknown', ''];
    }

    private function extractVersion(string $token): string
    {
        static $rx = [
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
        return isset($rx[$token]) && preg_match($rx[$token], $this->ua, $m)
            ? $m[1]
            : '';
    }

    /* =====================================================================
       Section 3 – Platform
       ===================================================================== */
    private function platform(): string
    {
        /* Client-Hints first */
        if ($this->hint) {
            $plat = $this->hint['platform'] ?: 'Unknown';
            $ver = $this->hint['platformVersion'];
            if ($ver !== '') {
                $plat .= ' ' . $ver;
            }
            if ($this->hint['mobile'] && !str_contains(strtolower($plat), 'android')) {
                $plat .= ' Mobile';
            }
            return trim($plat);
        }

        /* UA sniff fallback */
        static $rx = [
            '/Windows NT 10\.0.*Windows[/\s]?11/i' => 'Windows 11',
            '/Windows NT 10\.0/i' => 'Windows 10',
            '/Windows NT 6\.3/i' => 'Windows 8.1',
            '/Windows NT 6\.2/i' => 'Windows 8',
            '/Windows NT 6\.1/i' => 'Windows 7',
            '/Mac OS X ([\d_]+)/i' => fn ($v) => 'macOS ' . str_replace('_', '.', $v),
            '/Android ([\d.]+)/i' => fn ($v) => 'Android ' . $v,
            '/iPhone OS ([\d_]+)/i' => fn ($v) => 'iOS ' . str_replace('_', '.', $v),
            '/iPad; CPU OS ([\d_]+)/i' => fn ($v) => 'iPadOS ' . str_replace('_', '.', $v),
            '/Linux/i' => 'Linux',
        ];
        foreach ($rx as $pat => $label) {
            if (preg_match($pat, $this->ua, $m)) {
                return \is_callable($label) ? $label($m[1] ?? '') : $label;
            }
        }
        return 'Unknown';
    }

    /* =====================================================================
       Section 4 – Rendering / JS engine
       ===================================================================== */
    private function engine(string $browser): string
    {
        if (in_array($browser, ['Chrome', 'Edge', 'Brave', 'Vivaldi', 'Yandex Browser', 'Samsung Internet'], true)) {
            return 'Blink';
        }
        return match (true) {
            str_contains($this->uaLower, 'trident') => 'Trident',
            str_contains($this->uaLower, 'gecko') && str_contains($this->uaLower, 'firefox') => 'Gecko',
            str_contains($this->uaLower, 'applewebkit') => 'WebKit',
            str_contains($this->uaLower, 'presto') => 'Presto',
            default => 'Unknown',
        };
    }

    /* Public helper */
    public function getUserAgent(): string
    {
        return $this->ua;
    }
}
