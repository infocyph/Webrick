Streaming downloads
===================

Use ``Response::streamDownload(...)`` when you want a streamed attachment, or ``Response::attachment(...)`` when a normal file response is enough.

.. code:: php

   use Infocyph\Webrick\Response\Response;
   use Infocyph\Webrick\Router\Facade\Router as Route;

   Route::get('/download/{name}', function (string $name) {
       $safe = basename($name);
       $path = __DIR__ . '/files/' . $safe;

       if (! is_file($path)) {
           return Response::plaintext('Not found', 404);
       }

       return Response::streamDownload($path, $safe, headers: [
           'X-Accel-Buffering' => 'no',
           'Cache-Control' => 'private, no-transform',
       ]);
   }, 'download.file');
