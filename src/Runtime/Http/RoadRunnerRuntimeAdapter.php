<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Runtime\Http;

use Closure;
use Generator;
use Infocyph\Webrick\Constants\StatusEnum;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Runtime\RoutingInput;
use RuntimeException;

/**
 * RoadRunner worker adapter.
 *
 * The host injects its already-created worker responder once at bootstrap.
 * Webrick never creates a competing worker/container and never discovers RR on
 * the request path.
 */
final readonly class RoadRunnerRuntimeAdapter implements RuntimeAdapterInterface
{
    /** @var Closure(int,string|Generator,array<string,list<string>>,bool):void */
    private Closure $respond;

    private RuntimeCapabilities $runtimeCapabilities;

    private bool $sendfileMiddleware;

    /**
     * @param callable(int,string|Generator,array<string,list<string>>,bool):void $respond
     */
    public function __construct(
        callable $respond,
        bool $sendfileMiddleware = false,
        bool $transportCompression = false,
    ) {
        $this->respond = Closure::fromCallable($respond);
        $this->runtimeCapabilities = new RuntimeCapabilities(
            name: 'roadrunner',
            persistent: true,
            concurrent: false,
            nativeStreaming: true,
            nativeFile: $sendfileMiddleware,
            transportCompression: $transportCompression,
        );
        $this->sendfileMiddleware = $sendfileMiddleware;
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
        if (!is_object($nativeRequest)) {
            throw new RuntimeException('RoadRunner runtime requires a PSR-style server request object.');
        }

        $server = PsrServerRequestData::server($nativeRequest);
        $parsedResolved = false;
        $parsed = null;
        $resolveParsed = static function () use ($nativeRequest, &$parsedResolved, &$parsed): array|object|null {
            if (!$parsedResolved) {
                $value = $nativeRequest->getParsedBody();
                $parsed = is_array($value) || is_object($value) ? $value : null;
                $parsedResolved = true;
            }

            return $parsed;
        };
        $form = RoutingFormInput::resolve(
            $server,
            static function () use ($resolveParsed): array {
                $value = $resolveParsed();

                return is_array($value) ? PsrServerRequestData::stringMapResult($value) : [];
            },
        );

        return new RuntimeRequestContext(
            RoutingInput::fromServer($server, $withHost, $form),
            static function () use ($nativeRequest, $server, $resolveParsed): Request {
                return TransportRequestFactory::fromParts(
                    $server,
                    PsrServerRequestData::headers($nativeRequest),
                    PsrServerRequestData::body($nativeRequest),
                    $resolveParsed(),
                    PsrUploadedFiles::normalize($nativeRequest->getUploadedFiles()),
                    PsrServerRequestData::stringMapResult($nativeRequest->getQueryParams()),
                    PsrServerRequestData::stringMapResult($nativeRequest->getCookieParams()),
                );
            },
            $this->runtimeCapabilities,
            $nativeRequest,
        );
    }

    public function write(Response $response, RuntimeRequestContext $context): void
    {
        $headers = $response->getHeaders();
        $size = ResponseWriterSupport::knownLength($response);
        if (
            !$response->hasHeader('Content-Length')
            && $size !== null
            && !in_array(
                $response->getStatusCode(),
                [StatusEnum::NO_CONTENT->value, StatusEnum::NOT_MODIFIED->value],
                true,
            )
        ) {
            $headers['Content-Length'] = [(string) $size];
        }

        if (!ResponseWriterSupport::allowsBody($response, $context)) {
            ($this->respond)($response->getStatusCode(), '', $headers, true);

            return;
        }

        $file = $response->getFileBody();
        if (
            $file !== null
            && $this->sendfileMiddleware
            && $file->offset() === 0
            && $file->length() === filesize($file->path())
        ) {
            $headers['X-Sendfile'] = [$file->path()];
            ($this->respond)($response->getStatusCode(), '', $headers, true);

            return;
        }

        $string = $response->getStringBody();
        if ($string !== null && !$response->isStreaming()) {
            ($this->respond)($response->getStatusCode(), $string, $headers, true);

            return;
        }

        ($this->respond)(
            $response->getStatusCode(),
            self::generator(ResponseWriterSupport::chunks($response)),
            $headers,
            true,
        );
    }

    /** @param iterable<string> $chunks */
    private static function generator(iterable $chunks): Generator
    {
        yield from $chunks;
    }
}
