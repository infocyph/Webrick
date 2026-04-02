# Getting Started

Start here to get a working app quickly, then explore guides and reference.

---

## What You'll Learn

By the end of this section, you'll be able to:

1. ✅ **Install** Webrick and its dependencies
2. ✅ **Boot** a RouterKernel with proper configuration
3. ✅ **Register** routes with closures and controllers
4. ✅ **Generate** signed and temporary URLs
5. ✅ **Configure** middleware (pre-global and post-global)
6. ✅ **Build** and ship route caches for production
7. ✅ **Troubleshoot** common installation issues

**Time to Complete**: ~15 minutes

---

## Prerequisites

Before you begin:

- **PHP 8.4+** installed and working
- **Composer 2.x** available in your PATH
- Basic understanding of:
  - HTTP request/response cycle
  - PHP namespaces and autoloading
  - Command-line basics

**Verify Prerequisites**:
```bash
# Check PHP version (must be 8.4+)
php -v

# Check Composer
composer --version

# Check required extensions
php -m | grep -E '(mbstring|json|zlib)'
```

---

## Learning Path
```
1. Installation
   └─> Install via Composer
   └─> Set up directory structure
   └─> Configure environment keys

2. Quick Start
   └─> Boot the kernel
   └─> Define first routes
   └─> Register middleware
   └─> Generate signed URLs

3. First Deployment
   └─> Build route cache
   └─> Configure web server
   └─> Enable OPcache
   └─> Ship to production
```


## Install

```bash
composer require infocyph/webrick
```

## Boot the kernel

```php
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;
use Psr\Log\NullLogger;

$kernel = RouterKernel::bootWithRegistrar(
  log: new NullLogger(),
  matcher: ShardedMatcher::make(__DIR__.'/.route-cache'),
  register: require __DIR__.'/routes.php',
  registrarOptions: [
    'autoSlashRedirect' => true,
    'exposeUrlServices' => true,
    'signKey'           => getenv('WEBRICK_SIGN_KEY') ?: 'dev-key-change-me',
    'signedDefaultTtl'  => 300,
    'fallbackAliasesFromRegistrar' => true,
  ]
);
```

## First routes

```php
use Infocyph\Webrick\Router\Route;
use Infocyph\Webrick\Response\Response as R;

Route::get('/', fn() => R::plaintext('Hello Webrick'))->name('home');
```

## Next steps
- Enable **signed URLs** and add the `verifySignedUrl` middleware for downloads/one‑time actions.
- Add **validators** + **compression** middleware for speed and correctness.
- Choose **Sharded**, **Fused**, or **Generated** matcher based on your deployment style.
- Jump to **Deployments** to productionize.

```{toctree}
:maxdepth: 2
:hidden:
:caption: Getting Started

installation
quickstart
```
