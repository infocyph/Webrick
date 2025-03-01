<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Http;

/**
 * A user-agent parser focusing on minimal if statements.
 * We detect:
 *   - browser
 *   - version
 *   - platform (with version if possible)
 *   - engine
 */
class UAParser
{
    protected string $userAgent;
    protected string $lowerUA;

    public function __construct(?string $userAgent = null)
    {
        $ua = $userAgent ?? ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown');
        $this->userAgent = $ua;
        $this->lowerUA   = strtolower((string) $ua);
    }

    /**
     * Return an array with:
     * [
     *   'browser'  => string,
     *   'version'  => string,
     *   'platform' => string,
     *   'engine'   => string
     * ]
     */
    public function parse(): array
    {
        return [
            'browser'  => $this->detectBrowser(),
            'version'  => $this->detectBrowserVersion(),
            'platform' => $this->detectPlatformVersion(),
            'engine'   => $this->detectEngine(),
        ];
    }

    // -----------------------------------------------------------
    // 1) Detect Browser Name
    // -----------------------------------------------------------
    protected function detectBrowser(): string
    {
        // We store patterns => names in an array:
        $browsers = [
            'edg'        => 'Edge',              // includes "edge" or "edg"
            'msie'       => 'Internet Explorer',
            'trident/7'  => 'Internet Explorer 11',
            'firefox'    => 'Firefox',
            'chrome'     => 'Chrome',
            'safari'     => 'Safari',
            'opera'      => 'Opera',
            'opr'        => 'Opera'              // Opera often uses "OPR"
        ];

        // We loop once, no large if chain
        foreach ($browsers as $key => $name) {
            if (str_contains($this->lowerUA, $key)) {
                return $name;
            }
        }
        return 'Unknown';
    }

    // -----------------------------------------------------------
    // 2) Detect Browser Version
    // -----------------------------------------------------------
    protected function detectBrowserVersion(): string
    {
        $browser = $this->detectBrowser();

        // We'll hold patterns for each known browser:
        $patterns = [
            'Edge'                => '/(edge|edg)\/([\d.]+)/i',
            'Internet Explorer'   => '/msie\s([\d.]+)/i',
            'Internet Explorer 11' => '/rv:([\d.]+)/i',
            'Firefox'             => '/firefox\/([\d.]+)/i',
            'Chrome'              => '/chrome\/([\d.]+)/i',
            'Safari'              => '/version\/([\d.]+)/i',
            'Opera'               => '/(?:opera|opr)\/([\d.]+)/i'
        ];

        if (isset($patterns[$browser])) {
            if (preg_match($patterns[$browser], $this->userAgent, $match)) {
                // some patterns capture in group 2, others in group 1
                return $match[2] ?? $match[1] ?? '';
            }
        }

        return '';
    }

    // -----------------------------------------------------------
    // 3) Detect Platform + (Version)
    // -----------------------------------------------------------
    protected function detectPlatformVersion(): string
    {
        $ua = $this->userAgent;

        // Instead of multiple ifs, we have an ordered array of [regex, callback].
        // The first match wins.
        $regexes = [
            // Windows: "Windows NT X.Y"
            [
                'pattern' => '/Windows NT\s?([\d.]+)/i',
                'replace' => fn ($v) => "Windows $v"
            ],
            // iPhone OS e.g. "iPhone OS 14_5"
            [
                'pattern' => '/iPhone\sOS\s([0-9_]+)/i',
                'replace' => fn ($v) => "iPhone iOS " . str_replace('_', '.', $v)
            ],
            // iPad OS e.g. "iPad OS 15_0"
            [
                'pattern' => '/iPad\sOS\s([0-9_]+)/i',
                'replace' => fn ($v) => "iPad iOS " . str_replace('_', '.', $v)
            ],
            // CPU OS e.g. "CPU OS 14_2 like Mac OS X"
            [
                'pattern' => '/CPU\sOS\s([0-9_]+)\slike\sMac\sOS\sX/i',
                'replace' => fn ($v) => "iOS " . str_replace('_', '.', $v)
            ],
            // Mac OS X e.g. "Mac OS X 10_15_7"
            [
                'pattern' => '/Mac\sOS\sX\s([0-9_]+)/i',
                'replace' => fn ($v) => "macOS " . str_replace('_', '.', $v)
            ],
            // Android e.g. "Android 12"
            [
                'pattern' => '/Android\s?([\d.]+)/i',
                'replace' => fn ($v) => "Android {$v}"
            ]
        ];

        foreach ($regexes as $entry) {
            if (preg_match($entry['pattern'], $ua, $m)) {
                // If matched, call the callback on group 1
                return $entry['replace']($m[1]);
            }
        }

        // If we get here, no matches => fallback checks
        $lowUA = strtolower($ua);
        // We can do a "match" approach for smaller checks:
        return match (true) {
            str_contains($lowUA, 'linux')           => 'Linux',
            str_contains($lowUA, 'iphone')          => 'iPhone (iOS)',
            str_contains($lowUA, 'ipad')            => 'iPad (iOS)',
            str_contains($lowUA, 'macintosh')       => 'macOS',
            str_contains($lowUA, 'mac os x')        => 'macOS',
            str_contains($lowUA, 'windows phone')   => 'Windows Phone',
            default                                 => 'Unknown'
        };
    }

    // -----------------------------------------------------------
    // 4) Detect Engine
    // -----------------------------------------------------------
    protected function detectEngine(): string
    {
        $ua = $this->lowerUA;

        // We'll do a quick approach: if "trident", it's IE, if "gecko + firefox" => Gecko, etc.
        // Then fallback to see if it's Chrome-based => Blink or old WebKit => WebKit.
        return match (true) {
            str_contains($ua, 'trident')                            => 'Trident',
            (str_contains($ua, 'gecko') && str_contains($ua, 'firefox')) => 'Gecko',
            (str_contains($ua, 'chrome') || str_contains($ua, 'edg') || str_contains($ua, 'chromium')) => 'Blink',
            str_contains($ua, 'applewebkit') || str_contains($ua, 'safari') => 'WebKit',
            str_contains($ua, 'presto')                             => 'Presto',
            default                                                 => 'Unknown'
        };
    }

    public function getUserAgent(): string
    {
        return $this->userAgent;
    }
}
