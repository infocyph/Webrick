<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Response\Macros;

use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Response\Stream;
use Psr\Http\Message\StreamInterface;

/**
 * **One-shot registration** of handy fluent helpers:
 *
 * ```php
 * ResponseMacros::boot();
 *
 * return Response::json(['ok'=>true]);
 * ```
 */
final class ResponseMacros
{
    public static function boot(): void
    {
        // ---- JSON ------------------------------------------------
        Response::macro('json', /**
         * @param mixed  $data
         * @param int    $status
         * @param array  $hdr   extra headers
         */
            function (
                mixed $data,
                int   $status  = 200,
                array $hdr     = []
            ): Response {
                $json  = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                $body  = new Stream($json);
                $hdr  += ['Content-Type' => 'application/json; charset=utf-8'];
                return (new Response($status, $body, $hdr));
            });

        // ---- redirect --------------------------------------------
        Response::macro('redirect',
            function (
                string $url,
                int    $status  = 302,
                array  $hdr     = []
            ): Response {
                $hdr['Location'] = $url;
                return new Response($status, null, $hdr);
            });

        // ---- attachment / download -------------------------------
        Response::macro('attachment',
            /**
             * @param string|StreamInterface $file
             */
            function (
                string|StreamInterface $file,
                string $name,
                string $mime = 'application/octet-stream',
                array  $hdr  = []
            ): Response {
                if (is_string($file)) {
                    $stream = new Stream(fopen($file, 'rb'));
                    $len    = filesize($file) ?: null;
                } else {
                    $stream = $file;
                    $len    = $stream->getSize();
                }

                $hdr += [
                    'Content-Type'        => $mime,
                    'Content-Disposition' => 'attachment; filename="' . addcslashes($name, '"\\') . '"',
                ];
                if ($len !== null) { $hdr['Content-Length'] = (string) $len; }

                return new Response(200, $stream, $hdr);
            });
    }
}
