# Getting Started

Start here to get a working app quickly, then explore guides and reference.

## Install

```bash
composer require infocyph/webrick
```

## Boot the kernel

```php
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;

$kernel = RouterKernel::bootWithRegistrar(
  ShardedMatcher::make(__DIR__.'/var/route-cache'),
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

Route::get('/', fn() => R::plaintext('Hello Webrick'))->name('home');
```

## Next steps
- Enable **signed URLs** and add the `verifySignedUrl` middleware for downloads/one‑time actions.
- Add **validators** + **compression** middleware for speed and correctness.
- Choose **Sharded** or **Fused** matcher based on your deployment style.
- Jump to **Deployments** to productionize.

```{toctree}
:maxdepth: 2
:hidden:
:caption: Getting Started

installation
quickstart
```
