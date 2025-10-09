# Attribute Routes

Webrick can discover routes from PHP attributes so you can colocate route metadata with handlers.

## Enabling discovery

Point the registrar to scan your source directory (shown here conceptually—keep your existing registrar wiring):

```php
// in routes.php (or your registrar file)
use Infocyph\Webrick\Router\Definition\Attribute as A;
use Infocyph\Webrick\Router\Route;

// Existing explicit routes…
Route::get('/', [HomeController::class, 'index'])->name('home');

// And/or rely on attribute discovery
// e.g., return an AttributeRegistrar::scan(__DIR__.'/src/Http/Controller');
```

## Defining routes with attributes

```php
namespace App\Http\Controller;

use Infocyph\Webrick\Router\Definition\Attribute\Get;
use Infocyph\Webrick\Router\Definition\Attribute\Post;
use Infocyph\Webrick\Response\Response as R;

final class UserController
{
    #[Get('/users', name: 'users.index')]
    public function index(): R { return R::json(['ok' => true]); }

    #[Post('/users', name: 'users.store', middleware: ['throttle:60,60'])]
    public function store(): R { return R::json(['created' => true], 201); }
}
```

### Supported verb attributes

`Get`, `Post`, `Put`, `Patch`, `Delete`, `Head`, `Options` (and extended verbs if enabled).
Group-level prefix/name/middleware attributes can also be used to reduce repetition.

## Troubleshooting

- Ensure the attribute namespace matches exactly: `Infocyph\Webrick\Router\Attributes`.
- If a route isn’t found, confirm your scan paths include the file and that the class is autoloadable.
- Name collisions: duplicate `name:` across routes will throw; keep names unique.
- Throttle alias parameters use `throttle:<limit>,<seconds>`.
