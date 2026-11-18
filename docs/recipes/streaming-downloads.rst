Streaming downloads
===================

Webrick FileBody keeps native file identity and range metadata, allowing a
runtime to serve local files efficiently. Do not rely on basename() alone to
authorize a path: it cannot enforce a trusted root, symlink policy or tenant
ownership.

For host-selected **public** local assets, optional Pathwise 4
PublicFileResolver can check canonical root containment before Webrick handles
the request's HTTP range semantics:

.. code-block:: php

   use Infocyph\Pathwise\Exceptions\PathwiseException;
   use Infocyph\Pathwise\StreamHandler\PublicFileResolver;
   use Infocyph\Webrick\Request\Request;
   use Infocyph\Webrick\Response\Response;

   // Host-configured root: never select it from the request.
   $root = '/srv/app/public-assets';
   $resolver = new PublicFileResolver();

   $assetHandler = static function (Request $request, string $name) use (
       $root,
       $resolver,
   ): Response {
       try {
           $asset = $resolver->resolve($root, $name);
       } catch (PathwiseException) {
           return Response::plaintext('Not found', 404);
       }

       return Response::rangedFile($request, $asset->path, $asset->mimeType, [
           'Cache-Control' => 'public, max-age=300',
       ]);
   };

The host registers the handler and chooses which files are eligible for
public access. The resolver rejects symlinks by default and must never be used
as a substitute for per-user authorization of private files.

For protected documents or remote Flysystem storage, consult the File upload
and protected download recipe. Use Pathwise DownloadProcessor for allowed
storage roots and prepared streaming, and Webrick Response::stream for the
HTTP response. Do not parse the range twice or transform request paths into
raw filesystem paths. Verify HEAD, conditional requests and disconnects.
