<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Http;

use Infocyph\Webrick\Request\Core\ServerRequest;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Request\Support\HeaderBag;
use Infocyph\Webrick\Support\HttpUtils;

/** Lazy request-header facade. */
final class RequestHeaders
{
    /** @var array<string,list<string>>|null */
    private ?array $accept = null;

    private ?HeaderBag $all = null;

    /** @var array{type:?string,charset:?string,length:?int,md5:string}|null */
    private ?array $content = null;

    /** @var array<string,mixed>|null */
    private ?array $dep = null;

    public function __construct(private readonly Request|ServerRequest $req) {}

    /** @param array<string,mixed> $srv @return array<string,list<string>> */
    public static function extractFromServer(array $srv): array
    {
        $out = self::viaServerFallback($srv);
        self::backfillContentHeaders($srv, $out);
        self::backfillAuthorization($srv, $out);

        return $out;
    }

    /** @return ($key is null ? array<string,list<string>> : list<string>) */
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

    /** @return array{type:?string,charset:?string,length:?int,md5:string} */
    public function content(): array
    {
        if ($this->content !== null) {
            return $this->content;
        }

        $contentType = $this->req->getHeaderLine('Content-Type');
        $lengthRaw = trim($this->req->getHeaderLine('Content-Length'));

        return $this->content = [
            'type' => ($type = HttpUtils::baseMediaType($contentType)) !== '' ? $type : null,
            'charset' => HttpUtils::contentTypeCharset($contentType),
            'length' => $lengthRaw === '' ? null : HttpUtils::parseUnsignedDecimal($lengthRaw),
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
            'if_match' => $this->etagList($headers->getHeaderLine('If-Match')),
            'if_none_match' => $this->etagList($headers->getHeaderLine('If-None-Match')),
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

    public function raw(string $name): string
    {
        return $this->req->getHeaderLine($name);
    }

    /** @param array<string,mixed> $srv @param array<string,list<string>> $out @param-out array<string,list<string>> $out */
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

    /** @param array<string,mixed> $srv @param array<string,list<string>> $out @param-out array<string,list<string>> $out */
    private static function backfillContentHeaders(array $srv, array &$out): void
    {
        foreach (['CONTENT_TYPE' => 'Content-Type', 'CONTENT_LENGTH' => 'Content-Length', 'CONTENT_MD5' => 'Content-Md5'] as $serverKey => $header) {
            if (isset($srv[$serverKey]) && !isset($out[$header]) && is_string($srv[$serverKey])) {
                $out[$header] = [$srv[$serverKey]];
            }
        }
    }

    /** @param array<string,mixed> $srv @return array<string,list<string>> */
    private static function viaServerFallback(array $srv): array
    {
        $out = [];
        foreach ($srv as $key => $value) {
            if (!str_starts_with($key, 'HTTP_')) {
                continue;
            }
            $name = str_replace(' ', '-', ucwords(strtolower(strtr(substr($key, 5), '_', ' '))));
            if (is_array($value)) {
                $parts = [];
                foreach ($value as $item) {
                    if (is_string($item)) {
                        $parts[] = $item;
                    }
                }
                $out[$name] = [implode(',', $parts)];
            } elseif (is_string($value)) {
                $out[$name] = [$value];
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function etagList(string $value): array
    {
        if ($value === '') {
            return [];
        }

        $tokens = [];
        $token = '';
        $quoted = false;
        for ($i = 0, $length = strlen($value); $i < $length; ++$i) {
            $char = $value[$i];
            if ($char === '"') {
                $quoted = !$quoted;
                $token .= $char;

                continue;
            }
            if ($char === ',' && !$quoted) {
                $trimmed = trim($token);
                if ($trimmed !== '') {
                    $tokens[] = $trimmed;
                }
                $token = '';

                continue;
            }
            $token .= $char;
        }

        $trimmed = trim($token);
        if ($trimmed !== '') {
            $tokens[] = $trimmed;
        }

        return $tokens;
    }

    private function httpDate(string $value): ?int
    {
        return HttpUtils::parseHttpDate($value);
    }

    /** @param array<string,list<string>> $headers @param-out array<string,list<string>> $headers */
    private function injectAuthorisation(array &$headers): void
    {
        if (!isset($headers['Authorization'])) {
            self::backfillAuthorization($this->serverParams(), $headers);
        }
    }

    /** @return list<string> */
    private function parseAccept(string $raw): array
    {
        $parsed = [];
        foreach (explode(',', $raw) as $segment) {
            $parts = array_map(trim(...), explode(';', $segment));
            $value = array_shift($parts);
            if ($value === null || $value === '') {
                continue;
            }

            $q = 1.0;
            foreach ($parts as $parameter) {
                if (preg_match('/^q\s*=\s*(.*)$/i', $parameter, $matches) !== 1) {
                    continue;
                }
                $q = HttpUtils::parseQValue($matches[1]) ?? 0.0;

                break;
            }
            if ($q <= 0.0) {
                continue;
            }
            $parsed[] = ['value' => $value, 'q' => $q, 'wild' => substr_count($value, '*')];
        }

        usort($parsed, static fn(array $a, array $b): int => [$b['q'], $a['wild']] <=> [$a['q'], $b['wild']]);

        /** @var list<string> $values */
        $values = array_column($parsed, 'value');

        return $values;
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
