<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Exceptions\HttpException;
use Infocyph\Webrick\Interfaces\BodyStream;
use Infocyph\Webrick\Request\Core\StringBody;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Runtime\Http\RuntimeCapabilities;
use InvalidArgumentException;

/** Portable request-limit fallback when the transport has not already enforced limits. */
final readonly class RequestLimitsMiddleware
{
    /** @var array<string,true> */
    private array $bodyVerbSet;

    private int $resolvedBodyLimit;

    /** @param list<string> $bodyLimitVerbs */
    public function __construct(
        private int $maxHeaderBytes = 8192,
        private int $maxHeaderCount = 100,
        ?int $maxBodyBytes = null,
        array $bodyLimitVerbs = [
            HttpMethodEnum::POST->value,
            HttpMethodEnum::PUT->value,
            HttpMethodEnum::PATCH->value,
            HttpMethodEnum::DELETE->value,
        ],
    ) {
        if ($maxHeaderBytes < 0 || $maxHeaderCount < 0) {
            throw new InvalidArgumentException('Header limits must be >= 0.');
        }
        if ($maxBodyBytes !== null && $maxBodyBytes < 0) {
            throw new InvalidArgumentException('maxBodyBytes must be >= 0 when provided.');
        }

        $verbs = [];
        foreach ($bodyLimitVerbs as $verb) {
            $normalized = HttpMethodEnum::normalize($verb);
            if ($normalized !== '') {
                $verbs[$normalized] = true;
            }
        }
        $this->bodyVerbSet = $verbs;
        $this->resolvedBodyLimit = $maxBodyBytes ?? self::phpIniBytes(ini_get('post_max_size'));
    }

    /** @param Closure(Request):Response $next */
    public function __invoke(Request $req, Closure $next): Response
    {
        $capabilities = $req->getAttribute(RuntimeCapabilities::ATTRIBUTE);
        if ($capabilities instanceof RuntimeCapabilities && $capabilities->transportRequestLimits) {
            return $next($req);
        }

        $this->rejectForHeaderLimits($req);
        $req = $this->enforceBodyLimit($req);

        return $next($req);
    }

    private static function phpIniBytes(string|false $value): int
    {
        if ($value === false || trim($value) === '') {
            return 0;
        }

        $value = trim($value);
        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /** @return array<string,string> */
    private function connectionCloseHeaders(Request $req): array
    {
        $protocol = $req->getServerParams()['SERVER_PROTOCOL'] ?? 'HTTP/1.1';
        $protocol = is_string($protocol) ? strtoupper($protocol) : 'HTTP/1.1';

        return str_starts_with($protocol, 'HTTP/1.') ? ['Connection' => 'close'] : [];
    }

    private function enforceBodyLimit(Request $req): Request
    {
        if (
            $this->resolvedBodyLimit <= 0
            || !isset($this->bodyVerbSet[HttpMethodEnum::normalize($req->getMethod())])
        ) {
            return $req;
        }

        $transferCoded = $this->hasTransferCoding($req);
        $contentLength = trim($req->getHeaderLine('Content-Length'));
        if ($transferCoded && $contentLength !== '') {
            throw HttpException::badRequest(
                'Transfer-Encoding and Content-Length must not be combined.',
                $this->connectionCloseHeaders($req),
            );
        }

        if ($contentLength !== '') {
            $length = $this->parseContentLength($contentLength);
            if ($length === null) {
                throw HttpException::badRequest(
                    'Invalid Content-Length header.',
                    $this->connectionCloseHeaders($req),
                );
            }
            if ($length > $this->resolvedBodyLimit) {
                throw $this->payloadTooLargeException($req);
            }
        }

        $body = $req->getBody();
        $actual = $body->getSize();
        if ($actual !== null) {
            if ($actual > $this->resolvedBodyLimit) {
                throw $this->payloadTooLargeException($req);
            }

            return $req;
        }

        [$actual, $buffer] = $this->measureUnknownBody($req, $body);
        if ($actual > $this->resolvedBodyLimit) {
            throw $this->payloadTooLargeException($req);
        }

        return $buffer === null ? $req : $req->withBody(new StringBody($buffer));
    }

    private function hasTransferCoding(Request $req): bool
    {
        $line = strtolower($req->getHeaderLine('Transfer-Encoding'));
        if ($line === '') {
            return false;
        }

        foreach (explode(',', $line) as $token) {
            $token = trim($token);
            if ($token !== '' && $token !== 'identity' && $token !== 'trailers') {
                return true;
            }
        }

        return false;
    }

    /** @return array{0:int,1:?string} */
    private function measureUnknownBody(Request $req, BodyStream $body): array
    {
        $seekable = $body->isSeekable();
        $position = null;
        if ($seekable) {
            $position = $body->tell();
            $body->rewind();
        }

        $buffer = '';
        try {
            while (!$body->eof()) {
                $chunk = $body->read(8192);
                if ($chunk === '') {
                    if ($body->eof()) {
                        break;
                    }
                    throw HttpException::badRequest(
                        'Unable to read request body while enforcing limits.',
                        $this->connectionCloseHeaders($req),
                    );
                }
                $buffer .= $chunk;
                if (strlen($buffer) > $this->resolvedBodyLimit) {
                    throw $this->payloadTooLargeException($req);
                }
            }
        } finally {
            if ($seekable && $position !== null) {
                $body->seek($position);
            }
        }

        return [strlen($buffer), $seekable ? null : $buffer];
    }

    private function parseContentLength(string $raw): ?int
    {
        if ($raw === '' || preg_match('/^[0-9]+$/D', $raw) !== 1) {
            return null;
        }

        $value = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        return $value === false ? PHP_INT_MAX : (int) $value;
    }

    private function payloadTooLargeException(Request $req): HttpException
    {
        return HttpException::payloadTooLarge(
            'Payload exceeds maximum allowed size.',
            $this->connectionCloseHeaders($req),
        );
    }

    private function rejectForHeaderLimits(Request $req): void
    {
        if ($this->maxHeaderCount <= 0 && $this->maxHeaderBytes <= 0) {
            return;
        }

        $bytes = 0;
        $fields = 0;
        foreach ($req->getHeaders() as $name => $values) {
            $nameBytes = strlen($name) + 2;
            $fields += count($values);
            foreach ($values as $value) {
                $bytes += $nameBytes + strlen($value);
            }
        }

        $this->rejectIfLimitExceeded($req, $this->maxHeaderCount, $fields, 'Too many header fields');
        $this->rejectIfLimitExceeded($req, $this->maxHeaderBytes, $bytes, 'Request headers too large');
    }

    private function rejectIfLimitExceeded(Request $req, int $limit, int $current, string $message): void
    {
        if ($limit > 0 && $current > $limit) {
            throw HttpException::requestHeaderFieldsTooLarge($message, $this->connectionCloseHeaders($req));
        }
    }
}
