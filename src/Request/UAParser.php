<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Ultra-light UA / Client-Hint parser.
 *
 * 1.  If a PSR-7 request is passed we first look at
 *     ▸ Sec-CH-UA, Sec-CH-UA-Version
 *     ▸ Sec-CH-UA-Platform, -Platform-Version, -Mobile
 * 2.  Otherwise (or as fallback) we fall back to the frozen
 *     User-Agent header and a big ordered lookup table.
 *
 * No external dependencies, no gigantic regex soup.
 * Ideal for logging and coarse feature-flags, not for
 * fine-grained capability detection.
 */
final class UAParser
{
    private string $ua;       // full raw UA header
    private readonly string $uaLower;  // lowercase ua (for speed)
    private array  $hintBag;  // parsed client-hints (if any)

    /* -------------------------------------------------------- */

    public function __construct(
        ServerRequestInterface|string|null $source = null
    ) {
        if ($source instanceof ServerRequestInterface) {
            $this->ua = $source->getHeaderLine('User-Agent');
            $this->hintBag = $this->parseSecCh($source);
        } else {
            $this->ua      = (string) ($source ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''));
            $this->hintBag = [];                       // no Client-Hints
        }
        $this->uaLower = strtolower($this->ua);
    }

    /* -------------------------------------------------------- */

    /** Return the four canonical fields. */
    public function parse(): array
    {
        // --- browser + version ------------------------------------
        [$browser, $version] = $this->browserAndVersion();

        // --- platform ---------------------------------------------
        $platform = $this->platform();

        // --- engine -----------------------------------------------
        $engine = $this->engine($browser);

        return compact('browser', 'version', 'platform', 'engine');
    }

    /* ============================================================
       1)  Client-Hints parsing helper
       ============================================================ */
    private function parseSecCh(ServerRequestInterface $req): array
    {
        $bag = [];

        $uaHint = $req->getHeaderLine('Sec-CH-UA');
        if ($uaHint !== '') {
            // example:  "Chromium";v="117", "Not(A:Brand";v="24", "Brave";v="117"
            preg_match_all('/"([^"]+?)";v="([^"]+)"/', $uaHint, $m, PREG_SET_ORDER);
            foreach ($m as [, $brand, $ver]) {
                $bag['brands'][$brand] = $ver; // Brave => 117
            }
        }

        $bag['mobile']   = $req->getHeaderLine('Sec-CH-UA-Mobile') === '?1';
        $bag['platform'] = $req->getHeaderLine('Sec-CH-UA-Platform');
        $bag['platformVersion'] = $req->getHeaderLine('Sec-CH-UA-Platform-Version');
        $bag['fullVersion']     = $req->getHeaderLine('Sec-CH-UA-Full-Version');
        return $bag;
    }

    /* ============================================================
       2)  Browser  +  Version
       ============================================================ */
    private function browserAndVersion(): array
    {
        /* ---------- 2-a) BEST CASE – Chromium-style Client-Hints ---- */
        if (!empty($this->hintBag['brands'])) {
            // Brands are sorted by weight in Chromium, but
            // the real browser is the *last* non-"Chromium"/"Not?Brand"
            $preferred = array_reverse($this->hintBag['brands'], true);
            foreach ($preferred as $brand => $ver) {
                if (!preg_match('/^(?:Chromium|Not\s?)?A?Brand$/i', $brand)) {
                    return [$brand, $this->hintBag['fullVersion'] ?: $ver];
                }
            }
            // Fallback: pick Chromium
            if (isset($this->hintBag['brands']['Chromium'])) {
                return ['Chromium', $this->hintBag['brands']['Chromium']];
            }
        }

        /* ---------- 2-b) NORMAL UA sniff --------------------------- */
        // ordered longest-match-first to avoid Chrome matching before Edge etc.
        $table = [
            'edg'            => 'Edge',
            'opr'            => 'Opera',
            'vivaldi'        => 'Vivaldi',
            'brave'          => 'Brave',
            'samsungbrowser' => 'Samsung Internet',
            'yabrowser'      => 'Yandex Browser',
            'firefox'        => 'Firefox',
            'chrome'         => 'Chrome',
            'safari'         => 'Safari',
            'msie'           => 'Internet Explorer',
            'trident/7'      => 'Internet Explorer'
        ];

        foreach ($table as $needle => $label) {
            if (str_contains($this->uaLower, $needle)) {
                $ver = $this->extractVersion($needle);
                return [$label, $ver];
            }
        }
        return ['Unknown', ''];
    }

    private function extractVersion(string $token): string
    {
        $patternMap = [
            'edg'            => '/edg[e|a]?[ /]([\d.]+)/i',
            'opr'            => '/(?:opr|opera)[ /]([\d.]+)/i',
            'vivaldi'        => '/vivaldi[ /]([\d.]+)/i',
            'brave'          => '/brave\/([\d.]+)/i',
            'samsungbrowser' => '/samsungbrowser\/([\d.]+)/i',
            'yabrowser'      => '/yabrowser\/([\d.]+)/i',
            'firefox'        => '/firefox\/([\d.]+)/i',
            'chrome'         => '/chrome\/([\d.]+)/i',
            'safari'         => '/version\/([\d.]+)/i',
            'msie'           => '/msie ([\d.]+)/i',
            'trident/7'      => '/rv:([\d.]+)/i'
        ];

        if (isset($patternMap[$token]) && preg_match($patternMap[$token], $this->ua, $m)) {
            return $m[1];
        }
        return '';
    }

    /* ============================================================
       3)  Platform  (with version when easy)
       ============================================================ */
    private function platform(): string
    {
        /* --- Client-Hints preferred --- */
        if ($this->hintBag) {
            $plat   = $this->hintBag['platform'] ?: 'Unknown';
            $pv     = $this->hintBag['platformVersion'];
            if ($pv !== '') {
                $plat .= ' ' . $pv;
            }
            if ($this->hintBag['mobile'] && !str_contains(strtolower((string) $plat), 'android')) {
                $plat .= ' Mobile';
            }
            return trim((string) $plat);
        }

        /* --- fallback UA sniff ----------------------------------- */
        $rx = [
            '/Windows NT 10\.0/i'    => 'Windows 10',
            '/Windows NT 10\.0.*Windows[/\s]?11/i' => 'Windows 11',
            '/Windows NT 6\.3/i'     => 'Windows 8.1',
            '/Windows NT 6\.2/i'     => 'Windows 8',
            '/Windows NT 6\.1/i'     => 'Windows 7',
            '/Mac OS X ([\d_]+)/i'   => fn ($v) => 'macOS ' . str_replace('_', '.', $v),
            '/Android ([\d.]+)/i'    => fn ($v) => 'Android ' . $v,
            '/iPhone OS ([\d_]+)/i'  => fn ($v) => 'iOS ' . str_replace('_', '.', $v),
            '/iPad; CPU OS ([\d_]+)/i' => fn ($v) => 'iPadOS ' . str_replace('_', '.', $v),
            '/Linux/i'               => 'Linux'
        ];

        foreach ($rx as $pat => $label) {
            if (preg_match($pat, $this->ua, $m)) {
                return \is_callable($label) ? $label($m[1]) : $label;
            }
        }
        return 'Unknown';
    }

    /* ============================================================
       4)  Rendering / JS engine
       ============================================================ */
    private function engine(string $browser): string
    {
        // If we already know it's a Chromium-family browser, it’s Blink
        if (\in_array($browser, ['Chrome','Edge','Brave','Vivaldi','Yandex Browser','Samsung Internet'], true)) {
            return 'Blink';
        }

        return match (true) {
            str_contains($this->uaLower, 'trident')                => 'Trident',
            str_contains($this->uaLower, 'gecko') && str_contains($this->uaLower, 'firefox') => 'Gecko',
            str_contains($this->uaLower, 'applewebkit')            => 'WebKit',
            str_contains($this->uaLower, 'presto')                 => 'Presto',
            default                                                => 'Unknown'
        };
    }

    /* Debug helper */
    public function getUserAgent(): string
    {
        return $this->ua;
    }
}
