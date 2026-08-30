<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Http;

use Infocyph\Webrick\Request\Psr7\ServerRequest;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Request\Support\HeaderBag;

/**
 * Lazy request-header facade. Only real HTTP headers are exposed; Basic
 * credentials are never materialized as pseudo PHP_AUTH_* header entries.
 *
 * @phpstan-type HeaderMap array<string, list<string>>
 * @phpstan-type AcceptMap array<string, list<string>>
 * @phpstan-type ContentMeta array{type:?string, charset:?string, length:?int, md5:string}
 * @phpstan-type DepRange array{unit:string, span:list<string>}
 * @phpstan-type DepMeta array{if_match:list<string>,if_none_match:list<string>,if_modified_since:?int,if_unmodified_since:?int,prefer_safe:bool,range:DepRange|null}
 */
final class RequestHeaders
{
    /** @var AcceptMap|null */
    private ?array $accept = null;

    private ?HeaderBag $all = null;

    /** @var ContentMeta|null */
    private ?array $content = null;

    /** @var DepMeta|null */
    private ?array $dep = null;

    public function __construct(private readonly Request|ServerRequest $req) {}

    /** @param array<string,mixed> $srv
     * @return HeaderMap
     */
    public static function extractFromServer(array $srv): array
    {
        $out = self::viaServerFallback($srv);
        self::backfillContentHeaders($srv, $out);
        self::backfillAuthorization($srv, $out);

        return $out;
    }

    /** @return ($key is null ? AcceptMap : list<string>) */
    public function accept(?string $key = null): array
    {
        if ($this->accept === null) {
            $map = [];
            foreach (['Accept', 'Accept-Charset', 'Accept-Encoding', 'Accept-Language'] as $header) {
                $raw = $this->req->getHeaderLine($header);
                if ($raw !== '') {
                    $map[$header] = $this->parseAccept($raw);
                }
            }
            $this->accept = $map;
        }

        return $key === null ? $this->accept : ($this->accept[$key] ?? []);
    }

    public function all(): HeaderBag
    {
        if ($this->all instanceof HeaderBag) {
            return $this->all;
        }

        $headers = $this->req->getHeaders();
        if ($headers === []) {
            $headers = self::extractFromServer($this->serverParams());
        } else {
            $this->injectAuthorisation($headers);
        }

        return $this->all = new HeaderBag($headers);
    }

    /** @phpstan-return ContentMeta */
    public function content(): array
    {
        if ($this->content !== null) {
            return $this->content;
        }

        $contentType = strtolower($this->req->getHeaderLine('Content-Type'));
        [$type] = explode(';', $contentType, 2);
        $charset = preg_match('/charset=([^;]+)/', $contentType, $matches) ? trim($matches[1]) : null;
        $length = $this->req->getHeaderLine('Content-Length');

        return $this->content = [
            'type' => $type ?: null,
            'charset' => $charset,
            'length' => $length !== '' ? (int) $length : null,
            'md5' => strtolower($this->req->getHeaderLine('Content-Md5')),
        ];
    }

    public function dependency(?string $key = null): mixed
    {
        if ($this->dep !== null) {
            return $key ? ($this->dep[$key] ?? []) : $this->dep;
        }

        $headers = $this->all();
        $rangeLine = $headers->getHeaderLine('Range');
        $dep = [
            'if_match' => $this->csv($headers->getHeaderLine('If-Match')),
            'if_none_match' => $this->csv($headers->getHeaderLine('If-None-Match')),
            'if_modified_since' => $this->httpDate($headers->getHeaderLine('If-Modified-Since')),
            'if_unmodified_since' => $this->httpDate($headers->getHeaderLine('If-Unmodified-Since')),
            'prefer_safe' => strtolower($headers->first('Prefer') ?? '') === 'safe' && $this->req->getUri()->getScheme() === 'https',
            'range' => null,
        ];

        if ($rangeLine !== '') {
            [$unit, $span] = array_pad(explode('=', str_replace(' ', '', $rangeLine), 2), 2, '');
            $dep['range'] = $unit !== '' ? ['unit' => $unit, 'span' => explode(',', $span)] : null;
        }

        $this->dep = $dep;

        return $key ? ($dep[$key] ?? []) : $dep;
    }

    /** @param array<string,mixed> $srv
     * @param HeaderMap $out
     */
    private static function backfillAuthorization(array $srv, array &$out): void
    {
        if (isset($out['Authorization'])) {
            return;
        }

        $authorization = $srv['HTTP_AUTHORIZATION'] ?? $srv['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        if (is_string($authorization) && $authorization !== '') {
            $out['Authorization'] = [$authorization];

            return;
        }

        $user = $srv['PHP_AUTH_USER'] ?? null;
        if (is_string($user) && $user !== '') {
            $password = $srv['PHP_AUTH_PW'] ?? '';
            $out['Authorization'] = ['Basic ' . base64_encode($user . ':' . (is_string($password) ? $password : ''))];

            return;
        }

        $digest = $srv['PHP_AUTH_DIGEST'] ?? null;
        if (is_string($digest) && $digest !== '') {
            $out['Authorization'] = [$digest];
        }
    }

    /** @param array<string,mixed> $srv
     * @param HeaderMap $out
     */
    private static function backfillContentHeaders(array $srv, array &$out): void
    {
        foreach (['CONTENT_TYPE' => 'Content-Type', 'CONTENT_LENGTH' => 'Content-Length', 'CONTENT_MD5' => 'Content-Md5'] as $serverKey => $header) {
            if (isset($srv[$serverKey]) && !isset($out[$header]) && is_string($srv[$serverKey])) {
                $out[$header] = [$srv[$serverKey]];
            }
        }
    }

    /** @param array<string,mixed> $srv
     * @return HeaderMap
     */
    private static function viaServerFallback(array $srv): array
    {
        $out = [];
        foreach ($srv as $key => $value) {
            if (!str_starts_with($key, 'HTTP_')) {
                continue;
            }

            $name = str_replace(' ', '-', ucwords(strtolower(strtr(substr($key, 5), '_', ' '))));
            if (is_array($value)) {
                $parts = array_values(array_filter($value, is_string(...)));
                $out[$name] = [implode(',', $parts)];
            } elseif (is_string($value)) {
                $out[$name] = [$value];
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function csv(string $value): array
    {
        if ($value === '') {
            return [];
        }

        $parts = preg_split('/\s*,\s*/', $value);

        return is_array($parts) ? $parts : [];
    }

    private function httpDate(string $value): ?int
    {
        return $value === '' ? null : (strtotime($value) ?: null);
    }

    /** @param HeaderMap $headers */
    private function injectAuthorisation(array &$headers): void
    {
        if (isset($headers['Authorization'])) {
            return;
        }

        self::backfillAuthorization($this->serverParams(), $headers);
    }

    /** @return list<string> */
    private function parseAccept(string $raw): array
    {
        $parsed = [];
        foreach (explode(',', $raw) as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }

            [$mime, $params] = array_pad(array_map(trim(...), explode(';', $segment, 2)), 2, '');
            $q = (float) (preg_match('/q=([\d.]+)/', $params, $matches) ? $matches[1] : 1);
            if ($q === 0.0) {
                continue;
            }
            $parsed[] = ['mime' => $mime, 'q' => $q, 'wild' => substr_count($mime, '*')];
        }

        usort($parsed, static fn(array $a, array $b): int => [$b['q'], $a['wild']] <=> [$a['q'], $b['wild']]);

        return array_column($parsed, 'mime');
    }

    /** @return array<string,mixed> */
    private function serverParams(): array
    {
        return array_filter(
            $this->req->getServerParams(),
            static fn(string $key): bool => $key !== '',
            ARRAY_FILTER_USE_KEY,
        );
    }
}
