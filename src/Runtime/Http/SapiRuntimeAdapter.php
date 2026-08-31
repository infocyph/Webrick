<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Runtime\Http;

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Runtime\RoutingInput;
use RuntimeException;

/** Classic SAPI/FPM/FrankenPHP response runtime selected once at bootstrap. */
final readonly class SapiRuntimeAdapter implements RuntimeAdapterInterface
{
    private const string FINISH_FASTCGI = 'fastcgi';

    private const string FINISH_FRANKENPHP = 'frankenphp';

    private const string FINISH_LITESPEED = 'litespeed';

    private const string FINISH_NONE = 'none';

    private RuntimeCapabilities $runtimeCapabilities;

    private function __construct(
        private string $finishMode,
        string $name,
        bool $persistent,
        bool $transportCompression,
        bool $transportRequestLimits,
    ) {
        $this->runtimeCapabilities = new RuntimeCapabilities(
            name: $name,
            persistent: $persistent,
            concurrent: false,
            nativeStreaming: true,
            nativeFile: false,
            transportCompression: $transportCompression,
            transportRequestLimits: $transportRequestLimits,
        );
    }

    /** Resolve the synchronous SAPI once during application bootstrap. */
    public static function current(
        bool $transportCompression = false,
        bool $transportRequestLimits = false,
    ): self {
        if (function_exists('frankenphp_is_worker') && frankenphp_is_worker()) {
            return new self(self::FINISH_FRANKENPHP, 'frankenphp', true, $transportCompression, $transportRequestLimits);
        }
        if (PHP_SAPI === 'litespeed' || function_exists('litespeed_finish_request')) {
            return new self(self::FINISH_LITESPEED, 'litespeed', false, $transportCompression, $transportRequestLimits);
        }
        if (function_exists('fastcgi_finish_request')) {
            return new self(
                self::FINISH_FASTCGI,
                PHP_SAPI === 'fpm-fcgi' ? 'fpm' : 'fastcgi',
                false,
                $transportCompression,
                $transportRequestLimits,
            );
        }

        return new self(self::FINISH_NONE, PHP_SAPI, false, $transportCompression, $transportRequestLimits);
    }

    public function capabilities(): RuntimeCapabilities
    {
        return $this->runtimeCapabilities;
    }

    public function context(
        mixed $nativeRequest = null,
        mixed $nativeResponse = null,
        bool $withHost = false,
    ): RuntimeRequestContext {
        unset($nativeRequest, $nativeResponse);

        return new RuntimeRequestContext(
            RoutingInput::fromGlobals($withHost),
            static fn(): Request => Request::fromGlobals(),
            $this->runtimeCapabilities,
        );
    }

    public function write(Response $response, RuntimeRequestContext $context): void
    {
        $allowsBody = ResponseWriterSupport::allowsBody($response, $context);
        $size = ResponseWriterSupport::knownLength($response);

        if (!headers_sent()) {
            http_response_code($response->getStatusCode());
            header_remove('X-Powered-By');

            $protocol = $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1';
            $http2 = is_string($protocol) && str_starts_with($protocol, 'HTTP/2');
            foreach (ResponseWriterSupport::headers($response, $http2) as [$name, $value]) {
                header("{$name}: {$value}", false);
            }

            if (!$response->hasHeader('Content-Length') && $size !== null) {
                header('Content-Length: ' . $size, false);
            }
        }

        if ($allowsBody) {
            $output = fopen('php://output', 'wb');
            if (!is_resource($output)) {
                throw new RuntimeException('Unable to open the SAPI output stream.');
            }

            try {
                $string = $response->getStringBody();
                if ($string !== null) {
                    self::writeChunk($output, $string);
                } else {
                    foreach (ResponseWriterSupport::chunks($response) as $chunk) {
                        self::writeChunk($output, $chunk);
                        if ($response->isStreaming()) {
                            flush();
                        }
                    }
                }
            } finally {
                fclose($output);
            }
        }

        $this->finish();
    }

    /** @param resource $output */
    private static function writeChunk(mixed $output, string $chunk): void
    {
        $length = strlen($chunk);
        $offset = 0;
        while ($offset < $length) {
            $written = fwrite($output, substr($chunk, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Unable to write the complete SAPI response body.');
            }
            $offset += $written;
        }
    }

    private function finish(): void
    {
        match ($this->finishMode) {
            self::FINISH_FASTCGI => function_exists('fastcgi_finish_request') ? fastcgi_finish_request() : null,
            self::FINISH_FRANKENPHP => function_exists('frankenphp_finish_request') ? frankenphp_finish_request() : null,
            self::FINISH_LITESPEED => function_exists('litespeed_finish_request') ? litespeed_finish_request() : null,
            default => null,
        };
    }
}
