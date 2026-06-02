<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Http;

use Infocyph\Webrick\Request\Psr7\ServerRequest;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Request\Support\HeaderBag;

/**
 * Facade around a PSR-7 Request that
 *  • exposes an immutable HeaderBag (`all()`)
 *  • parses Accept*, Content-*, conditional & range headers
 *  • injects PHP_AUTH_* fallbacks so they behave like real headers
 *  • can extract headers directly from $_SERVER (portable, fast)
 *
 * ZERO allocations on hot path – heavy parsing happens lazily.
 *
 * @phpstan-type HeaderMap array<string, list<string>>
 * @phpstan-type AcceptMap array<string, list<string>>
 * @phpstan-type ContentMeta array{type:?string, charset:?string, length:?int, md5:string}
 * @phpstan-type DepRange array{unit:string, span:list<string>}
 * @phpstan-type DepMeta array{
 *   if_match:list<string>,
 *   if_none_match:list<string>,
 *   if_modified_since:?int,
 *   if_unmodified_since:?int,
 *   prefer_safe:bool,
 *   range:DepRange|null
 * }
 */
final class RequestHeaders
{
    /** @var AcceptMap|null parsed Accept* */
    private ?array $accept = null;

    private ?HeaderBag $all = null;   // raw + auth fallbacks

    /** @var ContentMeta|null Content-Type/Length/MD5 */
    private ?array $content = null;

    /** @var DepMeta|null If-*, Range, Prefer */
    private ?array $dep = null;

    public function __construct(private readonly Request|ServerRequest $req) {}

    /**
     * Extract headers from $_SERVER if getallheaders() is not available.
     *
     * This method is used as a fallback when the PSR-7 Request does not
     * provide headers (e.g. when created from globals).
     *
     * It first tries to use getallheaders() if available, otherwise
     * it falls back to parsing $_SERVER directly.
     *
     * It also backfills Content-* headers and Authorization header
     * from relevant $_SERVER keys.
     *
     * @param array<string, mixed> $srv Server parameters (e.g. $_SERVER)
     * @return HeaderMap Header bag in PSR-7 format: name => string[]
     */
    public static function extractFromServer(array $srv): array
    {
        $out = \function_exists('getallheaders')
            ? self::viaGetAllHeaders()
            : self::viaServerFallback($srv);

        self::backfillContentHeaders($srv, $out);
        self::backfillAuthorization($srv, $out);

        return $out;
    }

    /**
     * Returns an array of parsed Accept* headers.
     *
     * @param string $key [optional] The name of the Accept* header to retrieve.
     *                    If null, returns all parsed Accept* headers.
     * @return ($key is null ? AcceptMap : list<string>) An array of parsed Accept* headers.
     */
    public function accept(?string $key = null): array
    {
        if ($this->accept === null) {
            $map = [];
            foreach (['Accept', 'Accept-Charset', 'Accept-Encoding', 'Accept-Language'] as $h) {
                if ($raw = $this->req->getHeaderLine($h)) {
                    $map[$h] = $this->parseAccept($raw);
                }
            }
            $this->accept = $map;
        }

        if ($key === null) {
            return $this->accept;
        }

        return $this->accept[$key] ?? [];
    }

    /**
     * Get all headers as an immutable HeaderBag.
     *
     * First tries to get headers from the PSR-7 Request.
     * If that fails, it extracts headers from the SAPI/$_SERVER.
     *
     * Then it injects the Authorization header if it exists in $_SERVER.
     *
     * Finally, it returns a new HeaderBag instance.
     */
    public function all(): HeaderBag
    {
        if ($this->all) {
            return $this->all;
        }

        // Copy PSR-7 headers (values already arrays). Defensive fallback if empty.
        $hdr = $this->req->getHeaders();
        if ($hdr === []) {
            $hdr = self::extractFromServer($this->serverParams());
        }

        $this->injectAuthorisation($hdr);

        return $this->all = new HeaderBag($hdr);
    }

    /**
     * Content metadata.
     *
     * Returns an associative array with the following keys:
     *
     * - type: Content-Type header value (e.g. application/json)
     * - charset: Content-Type charset parameter value (e.g. utf-8)
     * - length: Content-Length header value as an integer
     * - md5: Content-Md5 header value as a lowercase string
     *
     * @return ContentMeta
     */
    public function content(): array
    {
        if ($this->content !== null) {
            return $this->content;
        }

        $ct = strtolower($this->req->getHeaderLine('Content-Type'));
        [$type] = explode(';', $ct, 2);
        $charset = preg_match('/charset=([^;]+)/', $ct, $m) ? trim($m[1]) : null;
        $lenLine = $this->req->getHeaderLine('Content-Length');

        return $this->content = [
            'type' => $type ?: null,
            'charset' => $charset,
            'length' => ($lenLine !== '' ? (int) $lenLine : null),
            'md5' => strtolower($this->req->getHeaderLine('Content-Md5')),
        ];
    }

    /**
     * Extracts dependency information from the request headers.
     *
     *     • If-Match: comma-separated list of ETags
     *     • If-None-Match: comma-separated list of ETags
     *     • If-Modified-Since: HTTP date
     *     • If-Unmodified-Since: HTTP date
     *     • Prefer: 'safe' if present and HTTPS
     *     • Range: ['unit' => 'bytes', 'span' => [int, int, …]]
     *
     * @param string|null $key If specified, returns the value for that key.
     * @return DepMeta|list<string>|int|bool|DepRange Dependency information
     */
    public function dependency(?string $key = null): mixed
    {
        if ($this->dep !== null) {
            return $key ? ($this->dep[$key] ?? []) : $this->dep;
        }

        $h = $this->all(); // HeaderBag
        $rangeLine = $h->getHeaderLine('Range');

        $dep = [
            'if_match' => $this->csv($h->getHeaderLine('If-Match')),
            'if_none_match' => $this->csv($h->getHeaderLine('If-None-Match')),
            'if_modified_since' => $this->httpDate($h->getHeaderLine('If-Modified-Since')),
            'if_unmodified_since' => $this->httpDate($h->getHeaderLine('If-Unmodified-Since')),
            'prefer_safe' => (strtolower($h->first('Prefer') ?? '') === 'safe')
                && $this->req->getUri()->getScheme() === 'https',
            'range' => null,
        ];

        if ($rangeLine !== '') {
            [$unit, $span] = array_pad(explode('=', str_replace(' ', '', $rangeLine), 2), 2, '');
            $dep['range'] = $unit !== '' ? ['unit' => $unit, 'span' => explode(',', $span)] : null;
        }

        $this->dep = $dep;

        return $key ? ($dep[$key] ?? []) : $dep;
    }

    /**
     * Inject Authorization header from PHP_AUTH_* or HTTP_AUTHORIZATION if
     * available (and related fallbacks).
     *
     * @param array<string, mixed> $srv Server parameters (e.g. $_SERVER)
     * @param HeaderMap $out
     */
    private static function backfillAuthorization(array $srv, array &$out): void
    {
        if (isset($out['Authorization'])) {
            return;
        }
        if (isset($srv['HTTP_AUTHORIZATION'])) {
            if (\is_string($srv['HTTP_AUTHORIZATION'])) {
                $out['Authorization'] = [$srv['HTTP_AUTHORIZATION']];
            }
        } elseif (isset($srv['REDIRECT_HTTP_AUTHORIZATION'])) {
            if (\is_string($srv['REDIRECT_HTTP_AUTHORIZATION'])) {
                $out['Authorization'] = [$srv['REDIRECT_HTTP_AUTHORIZATION']];
            }
        }
    }

    /**
     * Populate Content-* headers from $_SERVER if missing.
     *
     * @param array<string, mixed> $srv Server parameters (e.g. $_SERVER)
     * @param HeaderMap $out Header bag to populate
     */
    private static function backfillContentHeaders(array $srv, array &$out): void
    {
        foreach (
            [
                'CONTENT_TYPE' => 'Content-Type',
                'CONTENT_LENGTH' => 'Content-Length',
                'CONTENT_MD5' => 'Content-Md5',
            ] as $sk => $hn
        ) {
            if (isset($srv[$sk]) && !isset($out[$hn]) && \is_string($srv[$sk])) {
                $out[$hn] = [$srv[$sk]];
            }
        }
    }

    /**
     * Use getallheaders() if available (Apache, FastCGI, etc).
     * This function is used when getallheaders() is available.
     * It returns an array of headers in PSR-7 format: name => string[].
     * If the value is an array, it is converted to an array of strings.
     * If the value is a string, it is wrapped in an array.
     *
     * @return HeaderMap Header bag
     */
    private static function viaGetAllHeaders(): array
    {
        $out = [];
        foreach (\getallheaders() as $name => $value) {
            if (!\is_string($name)) {
                continue;
            }
            if (\is_array($value)) {
                $vals = [];
                foreach ($value as $item) {
                    if (\is_string($item)) {
                        $vals[] = $item;
                    }
                }
                $out[$name] = $vals;

                continue;
            }
            if (\is_string($value)) {
                $out[$name] = [$value];
            }
        }

        return $out;
    }

    /**
     * Fall back to $_SERVER when getallheaders() is not available.
     * This function is used when getallheaders() is not available.
     * It maps $_SERVER['HTTP_*'] to a PSR-7 header name.
     * For example, $_SERVER['HTTP_ACCEPT_ENCODING'] becomes 'Accept-Encoding'.
     * If the value is an array, it is imploded with commas.
     * If the value is a string, it is used as is.
     *
     * @param array<string, mixed> $srv Server parameters (e.g. $_SERVER)
     * @return HeaderMap Header bag
     */
    private static function viaServerFallback(array $srv): array
    {
        $out = [];
        foreach ($srv as $k => $v) {
            if (!str_starts_with($k, 'HTTP_')) {
                continue;
            }
            // HTTP_ACCEPT_ENCODING → Accept-Encoding
            $name = \str_replace(
                ' ',
                '-',
                \ucwords(\strtolower(\strtr(\substr($k, 5), '_', ' '))),
            );
            if (\is_array($v)) {
                $parts = [];
                foreach ($v as $item) {
                    if (\is_string($item)) {
                        $parts[] = $item;
                    }
                }
                $out[$name] = [\implode(',', $parts)];

                continue;
            }
            if (\is_string($v)) {
                $out[$name] = [$v];
            }
        }

        return $out;
    }

    /**
     * Fallback to populate PHP_AUTH_USER and PHP_AUTH_PW from an existing Authorization header
     * containing a Basic auth credential.
     *
     * @param string $line The Authorization header containing the Basic credential.
     * @param HeaderMap $hdr The header bag to populate with PHP_AUTH_* values.
     */
    private function backfillPhpAuthFromBasicHeader(string $line, array &$hdr): void
    {
        $cred = \base64_decode(\substr($line, 6), true);
        if ($cred === false || !\str_contains($cred, ':')) {
            return;
        }
        [$u, $p] = \explode(':', $cred, 2);
        $hdr['PHP_AUTH_USER'] = [$u];
        $hdr['PHP_AUTH_PW'] = [$p];
    }

    /**
     * Split a string into an array using a CSV-like syntax.
     *
     *   • Input string is trimmed before splitting.
     *   • Empty strings are ignored.
     *   • Whitespace is trimmed from both sides of the delimiters.
     *   • Delimiters are commas (",") or whitespace characters (one or more).
     *
     * @param string $v Input string to split
     * @return list<string> Resulting array of strings
     */
    private function csv(string $v): array
    {
        if ($v === '') {
            return [];
        }

        $parts = \preg_split('/\s*,\s*/', $v);
        if (!\is_array($parts)) {
            return [];
        }

        return $parts;
    }

    /**
     * Return a Unix epoch from an HTTP date string (RFC 7231).
     *
     * Returns null if the input string is empty or invalid.
     *
     * @param string $v HTTP date string (e.g. "Fri, 12 Jan 2018 08:00:00 GMT")
     * @return int|null Unix epoch or null if invalid
     */
    private function httpDate(string $v): ?int
    {
        return $v === '' ? null : (strtotime($v) ?: null);
    }

    /**
     * Inject Authorization header from PHP_AUTH_* or HTTP_AUTHORIZATION if
     * available (and related fallbacks).
     *
     * @param HeaderMap $hdr Header bag to populate
     */
    private function injectAuthorisation(array &$hdr): void
    {
        $srv = $this->serverParams();
        $added = false;

        $this->injectFromPhpAuth($srv, $hdr, $added);
        $this->injectFromExplicitAuthorization($srv, $hdr, $added);
    }

    /**
     * Populate Authorization header from HTTP_AUTHORIZATION or REDIRECT_HTTP_AUTHORIZATION if
     * available (and related fallbacks).
     *
     * @param array<string, mixed> $srv Server parameters (e.g. $_SERVER)
     * @param HeaderMap $hdr Header bag to populate
     * @param bool $alreadyAdded Whether an Authorization header has already been added
     */
    private function injectFromExplicitAuthorization(array $srv, array &$hdr, bool $alreadyAdded): void
    {
        if ($alreadyAdded) {
            return;
        }
        $line = $srv['HTTP_AUTHORIZATION']
            ?? $srv['REDIRECT_HTTP_AUTHORIZATION']
            ?? null;

        if (!\is_string($line) || $line === '') {
            return;
        }

        $hdr['Authorization'] ??= [$line];

        // also back-fill PHP_AUTH_* for Basic (helps downstream libs)
        if (\str_starts_with(\strtolower($line), 'basic ')) {
            $this->backfillPhpAuthFromBasicHeader($line, $hdr);
        }
    }

    /**
     * Populate Authorization header from PHP_AUTH_USER/PHP_AUTH_PW or PHP_AUTH_DIGEST if
     * available (and related fallbacks).
     *
     * @param array<string, mixed> $srv Server parameters (e.g. $_SERVER)
     * @param HeaderMap $hdr Header bag to populate
     * @param bool $added Whether an Authorization header has been added
     */
    private function injectFromPhpAuth(array $srv, array &$hdr, bool &$added): void
    {
        $user = $srv['PHP_AUTH_USER'] ?? null;
        if (\is_string($user) && $user !== '' && !isset($hdr['Authorization'])) {
            $pw = $srv['PHP_AUTH_PW'] ?? '';
            $pw = \is_string($pw) ? $pw : '';
            $hdr['Authorization'] = ['Basic ' . \base64_encode($user . ':' . $pw)];
            $added = true;

            return;
        }
        $digest = $srv['PHP_AUTH_DIGEST'] ?? null;
        if (\is_string($digest) && $digest !== '') {
            $hdr['Authorization'] = [$digest];
            $added = true;
        }
    }

    /**
     * Parse an Accept* header string into an array of acceptable MIME types.
     *
     * The returned array is sorted by the q parameter (highest first).
     * If two MIME types have the same q parameter, the one with the least number of wildcards is placed first.
     *
     * @param string $raw The Accept* header string
     * @return list<string> An array of acceptable MIME types
     */
    private function parseAccept(string $raw): array
    {
        $segments = explode(',', $raw); // faster than preg_split
        $parsed = [];

        foreach ($segments as $seg) {
            $seg = trim($seg);
            if ($seg === '') {
                continue;
            }
            [$mime, $q] = array_pad(array_map(trim(...), explode(';', $seg, 2)), 2, '');
            $qVal = (float) (preg_match('/q=([\d.]+)/', $q, $m) ? $m[1] : 1);
            if ($qVal == 0.0) {
                continue; // not acceptable
            }
            $wild = substr_count($mime, '*');
            $parsed[] = ['mime' => $mime, 'q' => $qVal, 'wild' => $wild];
        }

        usort(
            $parsed,
            static fn(array $a, array $b): int => [$b['q'], $a['wild']] <=> [$a['q'], $b['wild']],
        );

        return array_column($parsed, 'mime');
    }

    /**
     * @return array<string, mixed>
     */
    private function serverParams(): array
    {
        $params = $this->req->getServerParams();

        return \array_filter($params, static fn(string $key): bool => $key !== '', \ARRAY_FILTER_USE_KEY);
    }
}
