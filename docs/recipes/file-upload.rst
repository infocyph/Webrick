File upload and protected download with Pathwise
================================================

Webrick owns HTTP routing, request parsing and responses; the host owns
authentication, storage, authorizations, malware scanning, persistence and
quotas. Use optional Pathwise 4 for hardened upload/download storage workflows,
without introducing a mandatory dependency into Webrick:

.. code-block:: bash

   composer require infocyph/pathwise:^4.0

Create the private directory outside the web root and grant it safe ownership
and permissions at deployment time. Set up instance-scoped storage rather than
process-global storage mounts:

.. code-block:: php

   use Infocyph\Pathwise\Storage\StorageContext;

   $storage = new StorageContext([
       'private' => ['driver' => 'local', 'root' => '/srv/app/private'],
   ], 'private');

Upload
------

A host-authenticated route can use Webrick's native UploadedFile as a mover.
The host supplies $scanner (MalwareScannerInterface), a storage context, and
$saveAuthorizedDocument, an authorized metadata-persistence callback that
returns an opaque document ID. Never use client filenames or MIME headers as
authoritative paths or security decisions.

.. code-block:: php

   use Infocyph\Pathwise\StreamHandler\MalwareScanMode;
   use Infocyph\Pathwise\StreamHandler\UploadProcessor;
   use Infocyph\Pathwise\StreamHandler\UploadSource;
   use Infocyph\Pathwise\StreamHandler\UploadTrustProfile;
   use Infocyph\Webrick\Request\Core\UploadedFile;
   use Infocyph\Webrick\Request\Request;
   use Infocyph\Webrick\Response\Response;

   $upload = static function (Request $request) use (
       $storage,
       $scanner,
       $saveAuthorizedDocument,
   ): Response {
       $file = $request->file('document');
       if (!$file instanceof UploadedFile || $file->getClientFilename() === null) {
           return Response::json(['error' => 'invalid_upload'], 400);
       }

       $pipeline = new UploadProcessor();
       $pipeline->setStorageContext($storage);
       $pipeline->setTrustProfile(UploadTrustProfile::UNTRUSTED_DATA);
       $pipeline->setDirectorySettings('private://documents', tempDir: sys_get_temp_dir());
       $pipeline->setValidationProfile('document');
       $pipeline->setExtensionPolicy(['pdf']);
       $pipeline->setMalwareScanner($scanner);
       $pipeline->setMalwareScanMode(MalwareScanMode::REQUIRED);

       $source = UploadSource::fromMover(
           mover: static function (string $path) use ($file): void {
               $file->moveTo($path);
           },
           clientFilename: $file->getClientFilename(),
           size: $file->getSize(),
           clientMediaType: $file->getClientMediaType(),
           error: $file->getError(),
       );

       $storedKey = $pipeline->ingestSource($source);
       $id = $saveAuthorizedDocument($request, $storedKey);

       return Response::json(['id' => $id], 201)
           ->withHeader('Cache-Control', 'no-store');
   };

Apply host authentication and authorization, body-size limits, CSRF/CORS,
admission budgets and error mapping to the route. Use a fresh mutable
UploadProcessor per request in persistent workers. Required scanner outages
must fail closed. Clean up orphaned published objects if persistence fails.
Do not return internal storage paths, client-supplied filenames or scanner
diagnostics to callers.

Download
--------

The host supplies $findAuthorizedDocument: it checks the caller's identity and
tenant and returns null for unauthorized/unknown IDs or a safe record with
path (Pathwise key) and name (display filename). Do not build file paths from
the route variable.

.. code-block:: php

   use Infocyph\Pathwise\StreamHandler\DownloadProcessor;
   use Infocyph\Webrick\Request\Request;
   use Infocyph\Webrick\Response\Response;

   $download = static function (Request $request, string $id) use (
       $storage,
       $findAuthorizedDocument,
   ): Response {
       $record = $findAuthorizedDocument($request, $id);
       if ($record === null) {
           return Response::json(['error' => 'not_found'], 404);
       }

       $pipeline = new DownloadProcessor();
       $pipeline->setStorageContext($storage);
       $pipeline->setAllowedRoots(['private://documents']);
       $pipeline->setExtensionPolicy(['pdf']);
       $prepared = $pipeline->prepareDownload(
           $record['path'],
           $record['name'],
           $request->getHeaderLine('Range') ?: null,
       );

       return Response::stream(
           $pipeline->streamChunks($prepared),
           $prepared->status,
           $prepared->headers,
       );
   };

Pathwise validates the allowed root and byte range, then revalidates before
streaming. Webrick builds the HTTP response and owns HEAD and cancellation.
Response::stream removes Content-Length, retaining the status and range headers
for partial streams. Do not manually parse byte ranges or materialize large
files in memory. The host still owns conditional request validation and
cancellation policy. For prevalidated canonical local paths, prefer Webrick's
native Response::rangedFile/Response::rangedAttachment fast paths.

Test size, MIME/signature mismatch, scanner failure, symlink/root escape,
concurrent uploads, failed metadata persistence, cross-tenant access, invalid
ranges, HEAD, missing files and early disconnects. Remote Flysystem adapters
must be qualified for their actual atomicity and consistency guarantees.
