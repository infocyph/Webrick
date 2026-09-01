<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Constants\StatusEnum;
use Infocyph\Webrick\Exceptions\HttpException;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Headers\CacheControl;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Runtime\Http\RuntimeCapabilities;
use Infocyph\Webrick\Support\HttpUtils;

/** Portable response compression fallback for runtimes that do not own compression. */
final readonly class CompressionMiddleware
{
    public const string ETAG_STRONG_DERIVE = 'derive-strong';

    public const string ETAG_STRONG_RECOMP = 'recompute-strong';

    public const string ETAG_WEAK_ON_ENCODE = 'weak-on-encode';

    private const array ALGO = [
        'br' => 'brotli_compress',
        'deflate' => 'gzdeflate',
        'gzip' => 'gzencode',
        'zstd' => 'zstd_compress',
    ];

    private const array NO_COMPRESS_PREFIXES = [
        'application/gzip',
        'application/octet-stream',
        'application/wasm',
        'application/x-gzip',
        'application/x-tar',
        'application/zip',
        'audio/',
        'image/',
        'text/event-stream',
        'video/',
    ];

    /** @var list<string> */
    private array $excludeTypes;

    /** @var list<string> */
    private array $onlyTypes;

    /** @var list<string> */
    private array $prefOrder;

    /**
     * @param array<array-key,mixed> $prefOrder
     * @param array<array-key,mixed> $excludeTypes
     * @param array<array-key,mixed> $onlyTypes
     */
    public function __construct(
        private int $minBytes = 1400,
        array $prefOrder = ['zstd', 'br', 'gzip'],
        private string $etagMode = self::ETAG_WEAK_ON_ENCODE,
        private int $gzipLevel = 6,
        private int $brotliQuality = 4,
        private int $zstdLevel = 3,
        private string $etagDeriveSalt = 'enc-v1',
        private int $maxBufferBytes = 8_388_608,
        array $excludeTypes = [],
        array $onlyTypes = [],
        private bool $forceAddVary = true,
    ) {
        if ($this->minBytes < 0 || $this->maxBufferBytes < 0 || $this->maxBufferBytes < $this->minBytes) {
            throw new \InvalidArgumentException('Compression byte limits must satisfy 0 <= minBytes <= maxBufferBytes.');
        }
        if (!in_array($this->etagMode, [
            self::ETAG_WEAK_ON_ENCODE,
            self::ETAG_STRONG_DERIVE,
            self::ETAG_STRONG_RECOMP,
        ], true)) {
            throw new \InvalidArgumentException('Unknown compression ETag mode.');
        }
        $this->prefOrder = self::normalizePreferenceOrder($prefOrder);
        $this->excludeTypes = self::normalizeStringList($excludeTypes, 'excludeTypes');
        $this->onlyTypes = self::normalizeStringList($onlyTypes, 'onlyTypes');
    }

    /** @param Closure(Request):Response $next */
    public function __invoke(Request $req, Closure $next): Response
    {
        $capabilities = $req->getAttribute(RuntimeCapabilities::ATTRIBUTE);
        if ($capabilities instanceof RuntimeCapabilities && $capabilities->transportCompression) {
            return $next($req);
        }

        $resp = $next($req);
        if (!$this->shouldCompress($req, $resp)) {
            return $resp;
        }

        $algorithm = $this->negotiate($req->getHeaderLine('Accept-Encoding'));
        if ($algorithm === false) {
            throw HttpException::notAcceptable(
                'No acceptable content coding is available.',
                ['Vary' => 'Accept-Encoding'],
            );
        }
        if ($algorithm === null) {
            return $this->forceAddVary ? $resp->withSmartHeader('Vary', 'Accept-Encoding') : $resp;
        }

        $raw = $resp->getStringBody();
        if ($raw === null) {
            return $resp;
        }
        $encoded = $this->encode($raw, $algorithm);
        if ($encoded === false) {
            return $this->forceAddVary ? $resp->withSmartHeader('Vary', 'Accept-Encoding') : $resp;
        }

        $encodedResponse = $this->applyEncoded($resp, $encoded, $algorithm);
        if ($this->forceAddVary) {
            $encodedResponse = $encodedResponse->withSmartHeader('Vary', 'Accept-Encoding');
        }

        return $this->adjustValidators($req, $encodedResponse, $encoded, $algorithm);
    }

    /** @return array<string,bool> */
    private static function availableAlgorithms(): array
    {
        /** @var array<string,bool>|null $available */
        static $available = null;
        if ($available !== null) {
            return $available;
        }

        $available = [];
        foreach (self::ALGO as $algorithm => $function) {
            $available[$algorithm] = function_exists($function);
        }

        return $available;
    }

    /** @param array<string,float> $quality */
    private static function identityQuality(array $quality, ?float $wildcard): float
    {
        if (array_key_exists('identity', $quality)) {
            return $quality['identity'];
        }

        return $wildcard === 0.0 ? 0.0 : 1.0;
    }

    /**
     * @param array<array-key,mixed> $algorithms
     * @return list<string>
     */
    private static function normalizePreferenceOrder(array $algorithms): array
    {
        $normalized = self::normalizeStringList($algorithms, 'prefOrder');
        foreach ($normalized as $algorithm) {
            if (!array_key_exists($algorithm, self::ALGO)) {
                throw new \InvalidArgumentException('Compression preference order contains an unsupported content coding.');
            }
        }

        return $normalized;
    }

    /**
     * @param array<array-key,mixed> $values
     * @return list<string>
     */
    private static function normalizeStringList(array $values, string $name): array
    {
        $normalized = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new \InvalidArgumentException("Compression {$name} must contain only strings.");
            }
            $normalized[] = $value;
        }

        return $normalized;
    }

    private function adjustValidators(Request $req, Response $resp, string $encodedBytes, string $algorithm): Response
    {
        $method = HttpMethodEnum::normalize($req->getMethod());
        if ($method !== HttpMethodEnum::GET->value && $method !== HttpMethodEnum::HEAD->value) {
            return $resp;
        }

        $etag = $resp->getHeaderLine('ETag');

        return match ($this->etagMode) {
            self::ETAG_WEAK_ON_ENCODE => $this->weakEncodedEtag($resp, $etag, $encodedBytes),
            self::ETAG_STRONG_DERIVE => $this->derivedEncodedEtag($resp, $etag, $encodedBytes, $algorithm),
            default => $resp->withSmartHeader('ETag', $this->strongFromBytes($encodedBytes)),
        };
    }

    private function applyEncoded(Response $resp, string $encoded, string $algorithm): Response
    {
        $resp = $resp
            ->withBody($encoded)
            ->withSmartHeader('Content-Encoding', $algorithm)
            ->withSmartHeader('Content-Length', (string) strlen($encoded));

        return $resp->hasHeader('Content-MD5') ? $resp->withoutHeader('Content-MD5') : $resp;
    }

    private function derivedEncodedEtag(Response $resp, string $etag, string $encodedBytes, string $algorithm): Response
    {
        [$base, $weak] = $this->parseEtag($etag);
        if ($base === '') {
            return $resp->withSmartHeader('ETag', $this->strongFromBytes($encodedBytes));
        }

        $level = $this->encodedLevelToken($algorithm);
        $derived = hash('xxh128', $base . '|' . $algorithm . '|' . $level . '|' . $this->etagDeriveSalt, false);
        if ($weak) {
            return $resp->withSmartHeader('ETag', 'W/"' . $derived . '"');
        }
        if ($this->isEncodingDeterministic($algorithm)) {
            return $resp->withSmartHeader('ETag', '"' . $derived . '"');
        }

        return $resp->withSmartHeader('ETag', $this->strongFromBytes($encodedBytes));
    }

    private function encode(string $raw, string $algorithm): string|false
    {
        if (!(self::availableAlgorithms()[$algorithm] ?? false)) {
            return false;
        }

        return match ($algorithm) {
            'gzip' => gzencode($raw, $this->gzipLevel, ZLIB_ENCODING_GZIP),
            'deflate' => gzdeflate($raw, $this->gzipLevel),
            'br' => brotli_compress($raw, $this->brotliQuality),
            'zstd' => zstd_compress($raw, $this->zstdLevel),
            default => false,
        };
    }

    private function encodedLevelToken(string $algorithm): string
    {
        return match ($algorithm) {
            'gzip', 'deflate' => (string) $this->gzipLevel,
            'br' => (string) $this->brotliQuality,
            'zstd' => (string) $this->zstdLevel,
            default => '0',
        };
    }

    private function hasNoTransform(Response $response): bool
    {
        return isset(CacheControl::directives($response->getHeaderLine('Cache-Control'))['no-transform']);
    }

    private function isAllowedByWhitelist(string $contentType): bool
    {
        if ($this->onlyTypes === []) {
            return true;
        }

        return array_any(
            $this->onlyTypes,
            fn(string $pattern): bool => $pattern !== '' && $this->mimeMatches($contentType, strtolower($pattern)),
        );
    }

    private function isEncodingDeterministic(string $algorithm): bool
    {
        return $algorithm !== 'gzip';
    }

    private function isNonCompressible(string $contentType): bool
    {
        foreach (self::NO_COMPRESS_PREFIXES as $prefix) {
            if (str_starts_with($contentType, $prefix)) {
                return true;
            }
        }

        return array_any(
            $this->excludeTypes,
            fn(string $pattern): bool => $pattern !== '' && $this->mimeMatches($contentType, strtolower($pattern)),
        );
    }

    private function mimeMatches(string $mime, string $pattern): bool
    {
        $mime = strtolower(trim(explode(';', $mime, 2)[0]));
        $pattern = trim($pattern);
        if ($pattern === '*' || $pattern === '*/*') {
            return true;
        }
        if (str_ends_with($pattern, '/*')) {
            return str_starts_with($mime, substr($pattern, 0, -1));
        }
        if (str_ends_with($pattern, '/')) {
            return str_starts_with($mime, $pattern);
        }

        return $mime === $pattern;
    }

    /** string=encoder, null=identity, false=not acceptable */
    private function negotiate(string $header): string|false|null
    {
        if (trim($header) === '') {
            return null;
        }

        $quality = $this->parseAcceptEncoding($header);
        $wildcard = $quality['*'] ?? null;
        $identityQ = self::identityQuality($quality, $wildcard);
        $best = null;
        $bestQ = 0.0;
        $available = self::availableAlgorithms();

        foreach ($this->prefOrder as $algorithm) {
            if (!($available[$algorithm] ?? false)) {
                continue;
            }
            $q = array_key_exists($algorithm, $quality)
                ? $quality[$algorithm]
                : ($wildcard ?? 0.0);
            if ($q > $bestQ) {
                $best = $algorithm;
                $bestQ = $q;
            }
        }

        if ($best !== null && $bestQ > 0.0 && $identityQ <= $bestQ) {
            return $best;
        }
        if ($identityQ > 0.0) {
            return null;
        }

        return false;
    }

    /** @return array<string,float> */
    private function parseAcceptEncoding(string $header): array
    {
        $quality = [];
        foreach (explode(',', $header) as $segment) {
            $parts = array_map(trim(...), explode(';', $segment));
            $token = strtolower(array_shift($parts));
            if ($token === '') {
                continue;
            }

            $q = 1.0;
            foreach ($parts as $parameter) {
                if (preg_match('/^q\s*=\s*(.*)$/i', $parameter, $match) !== 1) {
                    continue;
                }
                $q = HttpUtils::parseQValue($match[1]) ?? 0.0;

                break;
            }
            $quality[$token] = max($quality[$token] ?? 0.0, $q);
        }

        return $quality;
    }

    /** @return array{0:string,1:bool} */
    private function parseEtag(string $etag): array
    {
        $value = trim($etag);
        if ($value === '') {
            return ['', false];
        }

        $weak = str_starts_with($value, 'W/');
        if ($weak) {
            $value = substr($value, 2);
        }
        if (strlen($value) >= 2 && $value[0] === '"' && $value[-1] === '"') {
            $value = substr($value, 1, -1);
        }

        return [trim($value), $weak];
    }

    private function shouldCompress(Request $req, Response $resp): bool
    {
        if ($resp->isStreaming() || $resp->getStringBody() === null) {
            return false;
        }
        if (StatusEnum::isEmptyCode($resp->getStatusCode()) || $resp->getStatusCode() === StatusEnum::PARTIAL_CONTENT->value) {
            return false;
        }
        if (HttpMethodEnum::normalize($req->getMethod()) === HttpMethodEnum::HEAD->value) {
            return false;
        }
        if ($resp->hasHeader('Content-Encoding') || $resp->hasHeader('Content-Range') || $this->hasNoTransform($resp)) {
            return false;
        }

        $length = $resp->getBodySize() ?? 0;
        if ($length < $this->minBytes || $length > $this->maxBufferBytes) {
            return false;
        }

        $contentType = strtolower(trim($resp->getHeaderLine('Content-Type')));

        return $contentType === ''
            || (!$this->isNonCompressible($contentType) && $this->isAllowedByWhitelist($contentType));
    }

    private function strongFromBytes(string $bytes): string
    {
        return '"' . hash('xxh128', $bytes, false) . '"';
    }

    private function weakEncodedEtag(Response $resp, string $etag, string $encodedBytes): Response
    {
        if ($etag === '') {
            return $resp->withSmartHeader('ETag', 'W/' . $this->strongFromBytes($encodedBytes));
        }

        return str_starts_with($etag, 'W/') ? $resp : $resp->withSmartHeader('ETag', 'W/' . $etag);
    }
}
