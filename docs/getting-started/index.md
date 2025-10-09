
# Getting Started

Start here to get a working app quickly, then explore guides and reference.

## Install

```bash
composer require infocyph/webrick
```

## Boot the kernel

```php
use Infocyph\Webrick\Router\RouterKernel;
use Infocyph\Webrick\Router\Matcher\ShardedMatcher;

$kernel = RouterKernel::bootWithRegistrar(
  new ShardedMatcher(__DIR__.'/var/route-cache'),
  require __DIR__.'/routes.php',
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

Route::get('/', fn() => R::text('Hello Webrick'))->name('home');
```

## Next steps
- Enable **signed URLs** and add the `verifySignedUrl` middleware for downloads/one‑time actions.
- Add **validators** + **compression** middleware for speed and correctness.
- Choose **Sharded** or **Fused** matcher based on your deployment style.
- Jump to **Deployments** to productionize.
