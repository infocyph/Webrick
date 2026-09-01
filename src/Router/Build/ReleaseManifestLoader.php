<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Build;

use RuntimeException;
use UnexpectedValueException;

/** Load release metadata from OPcache-friendly PHP with JSON fallback for compatibility. */
final class ReleaseManifestLoader
{
    /** @return array<string,mixed> */
    public function load(string $releaseManifestPath): array
    {
        $runtimePath = ReleaseCompiler::runtimeManifestPath($releaseManifestPath);
        if (is_file($runtimePath)) {
            $manifest = require $runtimePath;
            if (!is_array($manifest)) {
                throw new UnexpectedValueException('Webrick runtime release manifest must return an array.');
            }

            return $this->validate($manifest);
        }

        if (!is_file($releaseManifestPath)) {
            throw new RuntimeException('Webrick release manifest is missing.');
        }
        $json = file_get_contents($releaseManifestPath);
        if (!is_string($json)) {
            throw new RuntimeException('Unable to read Webrick release manifest.');
        }
        $manifest = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($manifest)) {
            throw new UnexpectedValueException('Malformed Webrick release manifest.');
        }

        return $this->validate($manifest);
    }

    /**
     * @param array<array-key,mixed> $manifest
     * @return array<string,mixed>
     */
    private function validate(array $manifest): array
    {
        if (
            ($manifest['format'] ?? null) !== 2
            || !is_string($manifest['environment'] ?? null)
            || $manifest['environment'] === ''
            || !is_string($manifest['config_fingerprint'] ?? null)
            || $manifest['config_fingerprint'] === ''
            || !is_array($manifest['intermix'] ?? null)
            || !is_array($manifest['webrick'] ?? null)
        ) {
            throw new UnexpectedValueException('Malformed Webrick release manifest.');
        }

        $intermix = $manifest['intermix'];
        if (
            !is_string($intermix['path'] ?? null)
            || $intermix['path'] === ''
            || !is_string($intermix['digest'] ?? null)
            || preg_match('/^[a-f0-9]{32}$/D', $intermix['digest']) !== 1
        ) {
            throw new UnexpectedValueException('Malformed InterMix release metadata.');
        }

        $webrick = $manifest['webrick'];
        if (
            !is_string($webrick['path'] ?? null)
            || $webrick['path'] === ''
            || !is_string($webrick['digest'] ?? null)
            || preg_match('/^[a-f0-9]{32}$/D', $webrick['digest']) !== 1
            || !is_string($webrick['fingerprint'] ?? null)
            || preg_match('/^[a-f0-9]{32}$/D', $webrick['fingerprint']) !== 1
        ) {
            throw new UnexpectedValueException('Malformed Webrick router release metadata.');
        }

        $normalized = [];
        foreach ($manifest as $key => $value) {
            if (!is_string($key)) {
                throw new UnexpectedValueException('Webrick release manifest must use string keys.');
            }
            $normalized[$key] = $value;
        }

        return $normalized;
    }
}
