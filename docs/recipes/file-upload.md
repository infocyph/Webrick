# File Upload Recipe

Handle file uploads securely with validation, storage, and serving.

---

## Problem

You need to accept file uploads, validate them, store securely, and serve them back.

---

## Solution

### 1. Upload Handler

```php
<?php
// src/Handler/FileUploadHandler.php

namespace App\Handler;

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Psr\Http\Message\UploadedFileInterface;

final class FileUploadHandler
{
    private string $uploadDir;
    private int $maxSize;
    private array $allowedTypes;

    public function __construct(
        string $uploadDir = '/var/www/uploads',
        int $maxSize = 10485760,  // 10MB
        array $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf']
    ) {
        $this->uploadDir = rtrim($uploadDir, '/');
        $this->maxSize = $maxSize;
        $this->allowedTypes = $allowedTypes;

        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
    }

    public function upload(Request $r): Response
    {
        $file = $r->file('file');

        if (!$file) {
            return Response::json([
                'error' => 'No file uploaded'
            ], 400);
        }

        // Validate
        $validation = $this->validate($file);
        if ($validation !== true) {
            return Response::json([
                'error' => $validation
            ], 400);
        }

        // Generate safe filename
        $extension = pathinfo($file->getClientFilename(), PATHINFO_EXTENSION);
        $filename = bin2hex(random_bytes(16)) . '.' . $extension;
        $path = $this->uploadDir . '/' . $filename;

        // Move uploaded file
        $file->moveTo($path);

        // Store metadata in database
        $fileId = $this->storeMetadata([
            'original_name' => $file->getClientFilename(),
            'stored_name' => $filename,
            'mime_type' => $file->getClientMediaType(),
            'size' => $file->getSize(),
            'uploaded_at' => date('Y-m-d H:i:s')
        ]);

        return Response::created([
            'id' => $fileId,
            'filename' => $filename,
            'size' => $file->getSize(),
            'url' => "/uploads/{$filename}"
        ], "/uploads/{$filename}");
    }

    private function validate(UploadedFileInterface $file): string|bool
    {
        // Check upload error
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return match($file->getError()) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File too large',
                UPLOAD_ERR_PARTIAL => 'File only partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No file uploaded',
                default => 'Upload error'
            };
        }

        // Check size
        if ($file->getSize() > $this->maxSize) {
            return "File exceeds maximum size of " . ($this->maxSize / 1048576) . "MB";
        }

        // Check MIME type
        $mimeType = $file->getClientMediaType();
        if (!in_array($mimeType, $this->allowedTypes)) {
            return "File type not allowed. Allowed types: " . implode(', ', $this->allowedTypes);
        }

        // Verify actual file type (not just extension)
        $tmpPath = $file->getStream()->getMetadata('uri');
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $actualMime = finfo_file($finfo, $tmpPath);
        finfo_close($finfo);

        if ($actualMime !== $mimeType) {
            return "File type mismatch";
        }

        return true;
    }

    private function storeMetadata(array $data): int
    {
        // Store in database
        return DB::insert('files', $data);
    }
}
```

### 2. Routes

```php
<?php
// routes/files.php

use Infocyph\Webrick\Router\Facade\Router as Route;
use App\Handler\FileUploadHandler;

$uploadHandler = new FileUploadHandler(
    uploadDir: __DIR__ . '/../storage/uploads',
    maxSize: 10 * 1024 * 1024,  // 10MB
    allowedTypes: ['image/jpeg', 'image/png', 'image/gif', 'application/pdf']
);

// Upload file
Route::post('/upload', [$uploadHandler, 'upload'])
    ->middleware(['auth', 'throttle:10,60']);

// Serve file
Route::get('/uploads/{filename:.*}', function(string $filename) {
    $path = __DIR__ . '/../storage/uploads/' . $filename;

    if (!file_exists($path)) {
        return Response::json(['error' => 'File not found'], 404);
    }

    // Get file metadata
    $file = DB::queryOne('SELECT * FROM files WHERE stored_name = ?', [$filename]);

    if (!$file) {
        return Response::json(['error' => 'File not found'], 404);
    }

    return Response::file($path, $file['original_name']);
});

// Download file
Route::get('/download/{id:int}', function(int $id) {
    $file = DB::queryOne('SELECT * FROM files WHERE id = ?', [$id]);

    if (!$file) {
        return Response::json(['error' => 'File not found'], 404);
    }

    $path = __DIR__ . '/../storage/uploads/' . $file['stored_name'];

    if (!file_exists($path)) {
        return Response::json(['error' => 'File not found'], 404);
    }

    return Response::download($path, $file['original_name']);
});
```

### 3. Multiple File Upload

```php
Route::post('/upload/multiple', function(Request $r) {
    $files = $r->file('files');  // Array of files

    if (!$files || !is_array($files)) {
        return Response::json([
            'error' => 'No files uploaded'
        ], 400);
    }

    $uploaded = [];
    $errors = [];

    foreach ($files as $index => $file) {
        try {
            // Validate
            if ($file->getError() !== UPLOAD_ERR_OK) {
                $errors[$index] = 'Upload failed';
                continue;
            }

            // Save file
            $extension = pathinfo($file->getClientFilename(), PATHINFO_EXTENSION);
            $filename = bin2hex(random_bytes(16)) . '.' . $extension;
            $path = __DIR__ . '/../storage/uploads/' . $filename;

            $file->moveTo($path);

            $uploaded[] = [
                'original' => $file->getClientFilename(),
                'stored' => $filename,
                'url' => "/uploads/{$filename}"
            ];
        } catch (\Exception $e) {
            $errors[$index] = $e->getMessage();
        }
    }

    return Response::json([
        'uploaded' => $uploaded,
        'errors' => $errors
    ], empty($errors) ? 201 : 207);  // 207 Multi-Status
});
```

### 4. Image Processing

```php
<?php
// src/Handler/ImageProcessor.php

namespace App\Handler;

use Intervention\Image\ImageManager;

final class ImageProcessor
{
    private ImageManager $manager;

    public function __construct()
    {
        $this->manager = new ImageManager(['driver' => 'gd']);
    }

    public function createThumbnail(string $path, int $width = 200, int $height = 200): string
    {
        $image = $this->manager->make($path);

        $image->fit($width, $height);

        $thumbPath = str_replace('.', '_thumb.', $path);
        $image->save($thumbPath);

        return $thumbPath;
    }

    public function optimize(string $path): void
    {
        $image = $this->manager->make($path);

        // Resize if too large
        if ($image->width() > 1920) {
            $image->resize(1920, null, function ($constraint) {
                $constraint->aspectRatio();
            });
        }

        // Compress
        $image->save($path, 85);  // 85% quality
    }
}

// Usage in upload handler
Route::post('/upload/image', function(Request $r) use ($uploadHandler, $imageProcessor) {
    $file = $r->file('image');

    // ... validate and save ...

    // Process image
    $imageProcessor->optimize($path);
    $thumbPath = $imageProcessor->createThumbnail($path);

    return Response::created([
        'url' => "/uploads/{$filename}",
        'thumbnail' => "/uploads/" . basename($thumbPath)
    ]);
});
```

---

## Testing

### Manual Test

```bash
# Single file
curl -X POST http://localhost:8000/upload \
  -H "Authorization: Bearer $TOKEN" \
  -F "file=@/path/to/image.jpg"

# Multiple files
curl -X POST http://localhost:8000/upload/multiple \
  -H "Authorization: Bearer $TOKEN" \
  -F "files[]=@image1.jpg" \
  -F "files[]=@image2.jpg" \
  -F "files[]=@document.pdf"

# Download
curl http://localhost:8000/download/1 \
  -H "Authorization: Bearer $TOKEN" \
  -O -J
```

### Unit Test

```php
<?php

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;

class FileUploadTest extends TestCase
{
    public function testValidateFileSize(): void
    {
        $file = $this->createMock(UploadedFileInterface::class);
        $file->method('getSize')->willReturn(20 * 1024 * 1024);  // 20MB
        $file->method('getError')->willReturn(UPLOAD_ERR_OK);

        $handler = new FileUploadHandler(maxSize: 10 * 1024 * 1024);
        $result = $handler->validate($file);

        $this->assertStringContainsString('exceeds maximum size', $result);
    }

    public function testValidateMimeType(): void
    {
        $file = $this->createMock(UploadedFileInterface::class);
        $file->method('getClientMediaType')->willReturn('application/x-executable');
        $file->method('getError')->willReturn(UPLOAD_ERR_OK);
        $file->method('getSize')->willReturn(1024);

        $handler = new FileUploadHandler(allowedTypes: ['image/jpeg', 'image/png']);
        $result = $handler->validate($file);

        $this->assertStringContainsString('not allowed', $result);
    }
}
```

---

## Variations

### Cloud Storage (S3)

```php
<?php

use Aws\S3\S3Client;

final class S3UploadHandler
{
    private S3Client $s3;
    private string $bucket;

    public function __construct(string $bucket)
    {
        $this->bucket = $bucket;
        $this->s3 = new S3Client([
            'version' => 'latest',
            'region' => $_ENV['AWS_REGION'],
            'credentials' => [
                'key' => $_ENV['AWS_ACCESS_KEY'],
                'secret' => $_ENV['AWS_SECRET_KEY']
            ]
        ]);
    }

    public function upload(UploadedFileInterface $file): array
    {
        $key = bin2hex(random_bytes(16)) . '/' . $file->getClientFilename();

        $result = $this->s3->putObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'Body' => $file->getStream(),
            'ContentType' => $file->getClientMediaType(),
            'ACL' => 'private'
        ]);

        return [
            'key' => $key,
            'url' => $result['ObjectURL']
        ];
    }

    public function getSignedUrl(string $key, int $expiration = 3600): string
    {
        $cmd = $this->s3->getCommand('GetObject', [
            'Bucket' => $this->bucket,
            'Key' => $key
        ]);

        $request = $this->s3->createPresignedRequest($cmd, "+{$expiration} seconds");

        return (string) $request->getUri();
    }
}
```

### Chunked Upload (Large Files)

```php
Route::post('/upload/chunked', function(Request $r) {
    $chunk = $r->file('chunk');
    $chunkIndex = (int) $r->input('chunkIndex');
    $totalChunks = (int) $r->input('totalChunks');
    $uploadId = $r->input('uploadId');

    // Store chunk
    $chunkPath = __DIR__ . "/../storage/chunks/{$uploadId}/{$chunkIndex}";
    if (!is_dir(dirname($chunkPath))) {
        mkdir(dirname($chunkPath), 0755, true);
    }
    $chunk->moveTo($chunkPath);

    // If all chunks received, combine them
    if ($chunkIndex === $totalChunks - 1) {
        $finalPath = __DIR__ . "/../storage/uploads/{$uploadId}";
        $finalFile = fopen($finalPath, 'wb');

        for ($i = 0; $i < $totalChunks; $i++) {
            $chunkData = file_get_contents(__DIR__ . "/../storage/chunks/{$uploadId}/{$i}");
            fwrite($finalFile, $chunkData);
            unlink(__DIR__ . "/../storage/chunks/{$uploadId}/{$i}");
        }

        fclose($finalFile);
        rmdir(__DIR__ . "/../storage/chunks/{$uploadId}");

        return Response::json([
            'complete' => true,
            'url' => "/uploads/{$uploadId}"
        ]);
    }

    return Response::json([
        'complete' => false,
        'received' => $chunkIndex + 1,
        'total' => $totalChunks
    ]);
});
```

### Download Manager

```php
Route::get('/download/{id:int}', function(Request $r, int $id) {
    $file = DB::queryOne('SELECT * FROM files WHERE id = ?', [$id]);

    if (!$file) {
        return Response::json(['error' => 'File not found'], 404);
    }

    $path = __DIR__ . '/../storage/uploads/' . $file['stored_name'];

    if (!file_exists($path)) {
        return Response::json(['error' => 'File not found'], 404);
    }

    // Support range requests (resumable downloads)
    $fileSize = filesize($path);
    $range = $r->getHeaderLine('Range');

    if ($range) {
        // Parse range header
        preg_match('/bytes=(\d+)-(\d+)?/', $range, $matches);
        $start = (int) $matches[1];
        $end = isset($matches[2]) ? (int) $matches[2] : $fileSize - 1;

        $length = $end - $start + 1;

        $handle = fopen($path, 'rb');
        fseek($handle, $start);
        $content = fread($handle, $length);
        fclose($handle);

        return Response::create($content, 206, [
            'Content-Type' => $file['mime_type'],
            'Content-Range' => "bytes {$start}-{$end}/{$fileSize}",
            'Content-Length' => (string) $length,
            'Accept-Ranges' => 'bytes'
        ]);
    }

    // Full download
    return Response::download($path, $file['original_name']);
});
```

---

## Security Best Practices

### 1. Validate File Type (Double Check)

```php
// ❌ Bad: Trust client mime type
$mimeType = $file->getClientMediaType();

// ✅ Good: Verify actual file type
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$actualMime = finfo_file($finfo, $tmpPath);
finfo_close($finfo);

if ($actualMime !== $expectedMime) {
    throw new \Exception('File type mismatch');
}
```

### 2. Store Outside Web Root

```php
// ❌ Bad: Public directory
$uploadDir = __DIR__ . '/../public/uploads';

// ✅ Good: Outside public
$uploadDir = __DIR__ . '/../storage/uploads';

// Serve through controller with auth check
Route::get('/uploads/{filename}', function($filename) {
    // Check permissions
    if (!$this->canAccess($filename)) {
        return Response::json(['error' => 'Forbidden'], 403);
    }

    return Response::file(__DIR__ . '/../storage/uploads/' . $filename);
});
```

### 3. Randomize Filenames

```php
// ❌ Bad: Keep original name
$filename = $file->getClientFilename();

// ✅ Good: Random name
$extension = pathinfo($file->getClientFilename(), PATHINFO_EXTENSION);
$filename = bin2hex(random_bytes(16)) . '.' . $extension;
```

### 4. Limit File Size

```php
// In php.ini
upload_max_filesize = 10M
post_max_size = 10M

// In middleware
Route::post('/upload', $handler)->middleware([
    new RequestLimitsMiddleware(maxBodyBytes: 10 * 1024 * 1024)
]);
```

### 5. Scan for Malware

```php
use Xenolope\Quahog\Client as ClamAVClient;

function scanFile(string $path): bool
{
    $clam = new ClamAVClient('unix:///var/run/clamav/clamd.sock');
    $result = $clam->scanLocalFile($path);

    return $result['status'] === 'OK';
}

if (!scanFile($path)) {
    unlink($path);
    return Response::json(['error' => 'Malware detected'], 400);
}
```

---

## Summary

**This recipe provides**:
- ✅ Secure file upload handling
- ✅ File validation (size, type, content)
- ✅ Safe filename generation
- ✅ Multiple file uploads
- ✅ Image processing
- ✅ Chunked uploads for large files
- ✅ Resumable downloads

**Production checklist**:
- [ ] Validate file types (double-check with finfo)
- [ ] Limit file sizes
- [ ] Store files outside web root
- [ ] Randomize filenames
- [ ] Implement virus scanning
- [ ] Rate limit upload endpoints
- [ ] Add authentication/authorization
- [ ] Set up proper permissions (0644 for files, 0755 for dirs)
- [ ] Use cloud storage for scalability
- [ ] Implement cleanup for orphaned files
- [ ] Log upload activity
