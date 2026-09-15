<?php

declare(strict_types=1);

namespace Tests\Unit;

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Body\FileBody;
use Infocyph\Webrick\Router\Runtime\PathwisePublicAssetResponder;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PathwisePublicAssetResponderTest extends TestCase
{
    private string $outsideFile;

    private string $root;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webrick-public-assets-' . bin2hex(random_bytes(8));
        $this->root = $base . '-root';
        $this->outsideFile = $base . '-outside.txt';
        if (!mkdir($this->root, 0700, true) && !is_dir($this->root)) {
            throw new RuntimeException('Unable to create public asset test root.');
        }

        file_put_contents($this->root . DIRECTORY_SEPARATOR . 'app.js', 'abcdef');
        file_put_contents($this->root . DIRECTORY_SEPARATOR . '.secret', 'hidden');
        file_put_contents($this->root . DIRECTORY_SEPARATOR . 'index.php', '<?php echo 1;');
        file_put_contents($this->outsideFile, 'outside');
    }

    protected function tearDown(): void
    {
        $link = $this->root . DIRECTORY_SEPARATOR . 'outside-link.txt';
        if (is_link($link) || file_exists($link)) {
            unlink($link);
        }
        foreach (['app.js', '.secret', 'index.php'] as $file) {
            $path = $this->root . DIRECTORY_SEPARATOR . $file;
            if (is_file($path)) {
                unlink($path);
            }
        }
        if (is_dir($this->root)) {
            rmdir($this->root);
        }
        if (is_file($this->outsideFile)) {
            unlink($this->outsideFile);
        }
    }

    public function testTrustedAssetUsesWebrickFileAndRangeSemantics(): void
    {
        $responder = new PathwisePublicAssetResponder(
            $this->root,
            responseHeaders: ['Cache-Control' => 'public, max-age=60'],
        );

        $response = $responder->respond(Request::fake(uri: '/assets/app.js'), 'app.js');
        self::assertSame(200, $response->getStatusCode());
        self::assertInstanceOf(FileBody::class, $response->getBody());
        self::assertSame('abcdef', (string) $response->getBody());
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('public, max-age=60', $response->getHeaderLine('Cache-Control'));
        self::assertNotSame('', $response->getHeaderLine('ETag'));
        self::assertNotSame('', $response->getHeaderLine('Last-Modified'));

        $range = $responder->respond(
            Request::fake(headers: ['Range' => 'bytes=1-3'], uri: '/assets/app.js'),
            'app.js',
        );
        self::assertSame(206, $range->getStatusCode());
        self::assertSame('bytes 1-3/6', $range->getHeaderLine('Content-Range'));
        self::assertSame('3', $range->getHeaderLine('Content-Length'));
        self::assertSame('bcd', (string) $range->getBody());
    }

    public function testUnsafeOrPrivatePathsNeverBecomeFilesystemAuthority(): void
    {
        $responder = new PathwisePublicAssetResponder($this->root);

        self::assertSame(404, $responder->respond(Request::fake(), '../' . basename($this->outsideFile))->getStatusCode());
        self::assertSame(404, $responder->respond(Request::fake(), '.secret')->getStatusCode());
        self::assertSame(404, $responder->respond(Request::fake(), 'index.php')->getStatusCode());
        self::assertSame(404, $responder->respond(Request::fake(), $this->outsideFile)->getStatusCode());
        self::assertSame(405, $responder->respond(Request::fake(method: 'POST'), 'app.js')->getStatusCode());

        $link = $this->root . DIRECTORY_SEPARATOR . 'outside-link.txt';
        if (@symlink($this->outsideFile, $link)) {
            self::assertSame(404, $responder->respond(Request::fake(), 'outside-link.txt')->getStatusCode());
        } else {
            self::assertFalse(is_link($link));
        }
    }
}
