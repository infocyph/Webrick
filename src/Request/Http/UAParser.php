<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Http;

use Infocyph\Webrick\Request\Request;

/**
 * @phpstan-type HintMap array{
 *   brands_full?: array<string, string>,
 *   brands?: array<string, string>,
 *   mobile?: bool,
 *   platform?: string,
 *   platformVersion?: string,
 *   fullVersion?: string
 * }
 */
final class UAParser
{
    /** @var array<string,string> */
    private const array TOKEN_MAP = [
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

    private readonly string $uaLower;

    /** @var HintMap */
    private array $hint = [];

    private string $ua;

    public function __construct(Request|string|null $source = null)
    {
        if ($source instanceof Request) {
            $this->ua = $source->getHeaderLine('User-Agent');
            $this->hint = $this->parseSecCh($source);
        } else {
            $this->ua = $source ?? $this->serverUserAgent();
        }

        $this->uaLower = strtolower($this->ua);
    }

    public function getUserAgent(): string
    {
        return $this->ua;
    }

    /** @return array{browser:string,version:string,platform:string,engine:string} */
    public function parse(): array
    {
        [$browser, $version] = $this->browser();
        $platform = $this->platform();
        $engine = $this->engine($browser);

        return compact('browser', 'version', 'platform', 'engine');
    }

    /** @return array{0:string,1:string} */
    private function browser(): array
    {
        $brands = $this->hint['brands_full'] ?? $this->hint['brands'] ?? null;
        if (is_array($brands) && $brands !== []) {
            $preferred = array_reverse($brands, true);
            foreach ($preferred as $brand => $version) {
                if (!preg_match('/^(?:Chromium|Not\s?)?A?Brand$/i', $brand)) {
                    return [$brand, $version];
                }
            }

            if (isset($brands['Chromium'])) {
                return ['Chromium', $brands['Chromium']];
            }
        }

        foreach (self::TOKEN_MAP as $token => $label) {
            if (str_contains($this->uaLower, $token)) {
                return [$label, $this->extractVersion($token)];
            }
        }

        return ['Unknown', ''];
    }

    private function clientHintValue(string $value): string
    {
        $value = trim($value);
        if (strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
            $value = substr($value, 1, -1);
            $value = str_replace(['\\"', '\\\\'], ['"', '\\'], $value);
        }

        return trim($value);
    }

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

    private function extractVersion(string $token): string
    {
        /** @var array<string,string> $patterns */
        static $patterns = [
            'crios' => '/crios\/([\d.]+)/i',
            'fxios' => '/fxios\/([\d.]+)/i',
            'edg' => '/(?:edg|edga|edgios|edge)[\/]([\d.]+)/i',
            'opr' => '/(?:opr|opera)[ \/]([\d.]+)/i',
            'vivaldi' => '/vivaldi[ \/]([\d.]+)/i',
            'brave' => '/brave\/([\d.]+)/i',
            'samsungbrowser' => '/samsungbrowser\/([\d.]+)/i',
            'yabrowser' => '/yabrowser\/([\d.]+)/i',
            'firefox' => '/firefox\/([\d.]+)/i',
            'chrome' => '/chrome\/([\d.]+)/i',
            'safari' => '/version\/([\d.]+)/i',
            'msie' => '/msie ([\d.]+)/i',
            'trident/7' => '/rv:([\d.]+)/i',
        ];

        $pattern = $patterns[$token] ?? null;
        if ($pattern === null || preg_match($pattern, $this->ua, $matches) !== 1) {
            return '';
        }

        return $matches[1] ?? '';
    }

    /** @return array<string,string> */
    private function parseBrandVersions(string $header): array
    {
        if ($header === '') {
            return [];
        }

        preg_match_all('/"([^"]+?)";v="([^"]+)"/', $header, $matches, PREG_SET_ORDER);

        $brands = [];
        foreach ($matches as $match) {
            $brands[$match[1]] = $match[2];
        }

        return $brands;
    }

    /** @phpstan-return HintMap */
    private function parseSecCh(Request $req): array
    {
        $hint = [];

        $fullBrands = $this->parseBrandVersions($req->getHeaderLine('Sec-CH-UA-Full-Version-List'));
        if ($fullBrands !== []) {
            $hint['brands_full'] = $fullBrands;
        }

        $brands = $this->parseBrandVersions($req->getHeaderLine('Sec-CH-UA'));
        if ($brands !== []) {
            $hint['brands'] = $brands;
        }

        $hint['mobile'] = $req->getHeaderLine('Sec-CH-UA-Mobile') === '?1';
        $hint['platform'] = $this->clientHintValue($req->getHeaderLine('Sec-CH-UA-Platform'));
        $hint['platformVersion'] = $this->clientHintValue($req->getHeaderLine('Sec-CH-UA-Platform-Version'));
        $hint['fullVersion'] = $this->clientHintValue($req->getHeaderLine('Sec-CH-UA-Full-Version'));

        return $hint;
    }

    private function platform(): string
    {
        if ($this->hint !== []) {
            $platform = $this->hint['platform'] ?? '';
            $platform = $platform !== '' ? $platform : 'Unknown';

            $version = $this->hint['platformVersion'] ?? '';
            if ($version !== '') {
                $platform .= ' ' . $version;
            }

            $mobile = $this->hint['mobile'] ?? false;
            if ($mobile && !str_contains(strtolower($platform), 'android')) {
                $platform .= ' Mobile';
            }

            return trim($platform);
        }

        return $this->platformFromUserAgent();
    }

    private function platformFromUserAgent(): string
    {
        if (preg_match('/iPad; CPU OS ([\d_]+)/i', $this->ua, $matches) === 1) {
            return 'iPadOS ' . str_replace('_', '.', $matches[1]);
        }
        if (preg_match('/iPhone OS ([\d_]+)/i', $this->ua, $matches) === 1) {
            return 'iOS ' . str_replace('_', '.', $matches[1]);
        }
        if (preg_match('/Android ([\d.]+)/i', $this->ua, $matches) === 1) {
            return 'Android ' . $matches[1];
        }
        if (preg_match('/Mac OS X ([\d_]+)/i', $this->ua, $matches) === 1) {
            return 'macOS ' . str_replace('_', '.', $matches[1]);
        }

        /** @var array<string,string> $patterns */
        $patterns = [
            '/Windows NT 10\.0.*Windows[\/\s]?11/i' => 'Windows 11',
            '/Windows NT 10\.0/i' => 'Windows 10',
            '/Windows NT 6\.3/i' => 'Windows 8.1',
            '/Windows NT 6\.2/i' => 'Windows 8',
            '/Windows NT 6\.1/i' => 'Windows 7',
            '/Linux/i' => 'Linux',
        ];

        foreach ($patterns as $pattern => $label) {
            if (preg_match($pattern, $this->ua) === 1) {
                return $label;
            }
        }

        return 'Unknown';
    }

    private function serverUserAgent(): string
    {
        $header = $_SERVER['HTTP_USER_AGENT'] ?? null;

        return is_string($header) ? $header : '';
    }
}
