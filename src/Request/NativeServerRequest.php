<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request;

use Infocyph\Webrick\Request\Psr7\ServerRequest;
use Infocyph\Webrick\Request\Support\PayloadParseState;
use InvalidArgumentException;

/**
 * Native Webrick request surface layered over the compatibility ServerRequest.
 *
 * Core input access returns arrays/scalars directly. JSON/XML parsing carries an
 * explicit state so valid empty payloads are never confused with "not parsed"
 * and invalid payloads never silently fall through to form data.
 */
class NativeServerRequest extends ServerRequest
{
    private mixed $jsonPayload = null;

    private ?PayloadParseState $jsonState = null;

    private mixed $xmlPayload = null;

    private ?PayloadParseState $xmlState = null;

    public function cookie(?string $key = null): mixed
    {
        return self::valueFromMap($this->getCookieParams(), $key);
    }

    public function jsonParseState(): PayloadParseState
    {
        return $this->jsonState ?? ($this->isJsonContentType()
            ? PayloadParseState::NOT_PARSED
            : PayloadParseState::NOT_APPLICABLE);
    }

    public function parsedJson(?string $key = null): mixed
    {
        $this->parseJson();
        if ($this->jsonState === PayloadParseState::NOT_APPLICABLE) {
            return $this->post($key);
        }
        if ($this->jsonState === PayloadParseState::INVALID) {
            throw new InvalidArgumentException('Invalid JSON request body.');
        }

        return self::valueFromPayload($this->jsonPayload, $key);
    }

    public function parsedXml(?string $key = null): mixed
    {
        $this->parseXml();
        if ($this->xmlState === PayloadParseState::NOT_APPLICABLE) {
            return $this->post($key);
        }
        if ($this->xmlState === PayloadParseState::INVALID) {
            throw new InvalidArgumentException('Invalid XML request body.');
        }

        return self::valueFromPayload($this->xmlPayload, $key);
    }

    public function post(?string $key = null): mixed
    {
        $parsed = $this->getParsedBody();
        $body = is_array($parsed) ? self::stringMap($parsed) : [];

        return self::valueFromMap($body, $key);
    }

    public function query(?string $key = null): mixed
    {
        return self::valueFromMap($this->getQueryParams(), $key);
    }

    public function server(?string $key = null): mixed
    {
        return self::valueFromMap($this->getServerParams(), $key);
    }

    public function withParsedBody(object|array|null $data): static
    {
        $request = parent::withParsedBody($data);
        $request->jsonPayload = null;
        $request->jsonState = null;
        $request->xmlPayload = null;
        $request->xmlState = null;

        return $request;
    }

    public function xmlParseState(): PayloadParseState
    {
        return $this->xmlState ?? ($this->isXmlContentType()
            ? PayloadParseState::NOT_PARSED
            : PayloadParseState::NOT_APPLICABLE);
    }

    private function isJsonContentType(): bool
    {
        return preg_match('#(?:application|text)/(?:[^\s;]+\+)?json(?:\s*;|$)#i', $this->getHeaderLine('Content-Type')) === 1;
    }

    private function isXmlContentType(): bool
    {
        return preg_match('#(?:application|text)/(?:[^\s;]+\+)?xml(?:\s*;|$)#i', $this->getHeaderLine('Content-Type')) === 1;
    }

    private function parseJson(): void
    {
        if ($this->jsonState !== null) {
            return;
        }
        if (!$this->isJsonContentType()) {
            $this->jsonState = PayloadParseState::NOT_APPLICABLE;

            return;
        }

        try {
            $this->jsonPayload = json_decode($this->raw(), true, 512, JSON_THROW_ON_ERROR);
            $this->jsonState = PayloadParseState::PARSED;
        } catch (\JsonException) {
            $this->jsonPayload = null;
            $this->jsonState = PayloadParseState::INVALID;
        }
    }

    private function parseXml(): void
    {
        if ($this->xmlState !== null) {
            return;
        }
        if (!$this->isXmlContentType()) {
            $this->xmlState = PayloadParseState::NOT_APPLICABLE;

            return;
        }
        if (!function_exists('simplexml_load_string')) {
            $this->xmlState = PayloadParseState::INVALID;

            return;
        }

        $xml = simplexml_load_string(
            $this->raw(),
            'SimpleXMLElement',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        if ($xml === false) {
            $this->xmlState = PayloadParseState::INVALID;

            return;
        }

        $encoded = json_encode($xml, JSON_THROW_ON_ERROR);
        $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        $this->xmlPayload = $decoded ?? [];
        $this->xmlState = PayloadParseState::PARSED;
    }

    /** @param array<mixed> $value @return array<string,mixed> */
    private static function stringMap(array $value): array
    {
        $map = [];
        foreach ($value as $key => $entry) {
            if (is_string($key)) {
                $map[$key] = $entry;
            }
        }

        return $map;
    }

    /** @param array<string,mixed> $map */
    private static function valueFromMap(array $map, ?string $key): mixed
    {
        return $key === null ? $map : ($map[$key] ?? null);
    }

    private static function valueFromPayload(mixed $payload, ?string $key): mixed
    {
        if ($key === null) {
            return $payload;
        }

        return is_array($payload) ? ($payload[$key] ?? null) : null;
    }
}
