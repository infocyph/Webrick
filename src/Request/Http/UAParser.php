<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Http;

use Infocyph\Webrick\Request\Request;

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
    private static array $tokenMap = [
        'crios' => 'Chrome iOS',
        'fxios' => 'Firefox iOS',
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

    private array $hint = [];
    private string $ua;
    private string $uaLower;

    /**
     * Initializes a new UAParser instance.
     *
     * If `$source` is an instance of `Request`, it will be used to extract the User-Agent header.
     * Otherwise, the value of `$source` will be used as the User-Agent string, or if it is null,
     * the value of `$_SERVER['HTTP_USER_AGENT']` will be used.
     *
     * @param Request|string|null $source The source of the User-Agent string.
     */
    public function __construct(Request|string|null $source = null)
    {
        if ($source instanceof Request) {
            $this->ua = $source->getHeaderLine('User-Agent');
            $this->hint = $this->parseSecCh($source);
        } else {
            $this->ua = (string)($source ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        }
        $this->uaLower = strtolower($this->ua);
    }


    /**
     * Returns the User-Agent header of the current request.
     *
     * @return string the User-Agent header value
     */
    public function getUserAgent(): string
    {
        return $this->ua;
    }

    /**
     * Coarsely parses the User-Agent string and returns an associative array
     * with the following keys:
     * - browser: the browser name
     * - version: the browser version
     * - platform: the platform name
     * - engine: the rendering engine name
     *
     * @return array
     */
    public function parse(): array
    {
        [$browser, $version] = $this->browser();   // ← rename $ver → $version
        $platform = $this->platform();
        $engine = $this->engine($browser);

        return compact('browser', 'version', 'platform', 'engine');
    }


    /**
     * Determine the browser name and version from the given headers.
     * Client Hints: prefer full-version list brands, real brand last.
     * UA fallback: detect iOS tokens and extract version.
     * Returns an array [$browser, $version].
     * @return array<string,string> [$browser, $version]
     */
    private function browser(): array
    {
        // Client Hints: prefer full-version list brands, real brand last
        $brands = $this->hint['brands_full'] ?? $this->hint['brands'] ?? null;
        if ($brands) {
            $preferred = array_reverse($brands, true);
            foreach ($preferred as $brand => $ver) {
                if (!preg_match('/^(?:Chromium|Not\s?)?A?Brand$/i', $brand)) {
                    return [$brand, (string)$ver];
                }
            }
            if (isset($brands['Chromium'])) {
                return ['Chromium', (string)$brands['Chromium']];
            }
        }

        // UA fallback (add iOS tokens)
        foreach (self::$tokenMap as $token => $label) {
            if (str_contains($this->uaLower, $token)) {
                return [$label, $this->extractVersion($token)];
            }
        }
        return ['Unknown', ''];
    }


    /**
     * Maps a browser to its rendering engine.
     *
     * @param string $browser Browser name (case-insensitive)
     * @return string Engine name (case-sensitive)
     * @example
     * <code>
     * $ua = new UAParser('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/90.0.4430.212 Safari/537.36');
     * $engine = $ua->engine($ua->browser());
     * var_dump($engine); // Blink
     * </code>
     */
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

    /**
     * Extracts a version from the UA string given a token.
     * Tokens are matched against regex patterns in the static $rx array.
     * If a match is found, the matched version is returned.
     * Otherwise, an empty string is returned.
     *
     * @param string $token The token to search for in the UA string.
     * @return string The extracted version or an empty string if no match is found.
     */
    private function extractVersion(string $token): string
    {
        static $rx = [
            'crios' => '/crios\/([\d.]+)/i',
            'fxios' => '/fxios\/([\d.]+)/i',
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



    /**
     * Parses the Sec-CH-UA* headers from the request.
     *
     * Returns an array with the following keys:
     * - brands_full: an associative array of brand names to their full version
     * - brands: an associative array of brand names to their version
     * - mobile: a boolean indicating whether the client is a mobile device
     * - platform: the platform name
     * - platformVersion: the platform version
     * - fullVersion: the full version string
     *
     * @return array
     */
    private function parseSecCh(Request $req): array
    {
        $bag = [];

        // Prefer the full-version list when present
        if ($full = $req->getHeaderLine('Sec-CH-UA-Full-Version-List')) {
            preg_match_all('/"([^"]+?)";v="([^"]+)"/', $full, $m, PREG_SET_ORDER);
            foreach ($m as [, $brand, $ver]) {
                $bag['brands_full'][$brand] = $ver; // full dotted version
            }
        }

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


    /**
     * Detects the client's platform (OS, browser, etc.) and
     * returns a human-readable string.
     *
     * Client-Hints are prioritized, falling back to UA sniffing
     * if no hints are provided.
     *
     * @return string
     */
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
}
