<?php

declare(strict_types=1);

use Infocyph\Webrick\Constants\MediaTypeEnum;
use Infocyph\Webrick\Constants\StatusEnum;
use Infocyph\Webrick\Exceptions\HttpException;
use Infocyph\Webrick\Middleware\ThrottleMiddleware;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Facade\Router as Route;
use Infocyph\Webrick\Router\Url\SignedUrlConfig;

/**
 * This file is included inside the $register closure in index.php,
 * so the variable $registrar (Registrar) is available.
 *
 * NOTE: DemoController and UsersController are declared in index.php (global namespace),
 * so we can reference them directly here.
 */

/* ---- homepage with links ---- */
Route::get('/', function (): Response {
    $links = [
        '/ping' => 'Static text',
        '/hello/Alice' => 'Dynamic placeholder',
        '/json' => 'JSON payload (named: json)',
        '/download' => 'Download (attachment)',
        '/redirect' => 'Redirect 302 → /',
        '/color/ff00ff' => 'Regex-constrained placeholder',
        '/class/Bob' => 'Class-based handler',

        // Extra
        '/post/echo' => 'POST echo',
        '/user/42 (PUT)' => 'Update user (PUT)',
        '/stream' => 'Streaming response',
        '/locale' => 'Show negotiated locale',
        '/xml' => 'XML payload (charset-aware)',
        '/status/418' => 'Status echo (I’m a teapot)',
        '/json/slow' => 'Lazy JSON via JsonSerializable',

        // Resource & alias-redirect demos
        '/users' => 'Resource: users.index',
        '/users/create' => 'Resource: users.create',
        '/users/42' => 'Resource: users.show',
        '/users/42/edit' => 'Resource: users.edit',
        '/to-json' => 'Redirect to route alias: json',
        '/to-user-42' => 'Redirect to route alias: users.show (id=42)',
        '/signed-demo' => 'Signed Demo',
        '/make-signed-absolute/42' => 'Signed Demo: absolute payload redirect',
        '/api/error-demo' => 'JSON API error rendering demo',
        '/auto-demo' => 'Auto Demo',
        '/auto-hello' => 'Auto Hello',
        '/xml-demo' => 'XML Demo',

        // Attribute-based demo (registered via AttributeRouteLoader)
        '/attr/hello/Alice' => 'Attribute routes: AttrDemoController::hello',

        // Encrypted cookie demo
        '/cookie/set' => 'Set encrypted cookie',
        '/cookie/read' => 'Read encrypted cookie',

        // Group demo links (prefix-based, same host)
        '/blog' => 'Group: blog.index',
        '/blog/hello-world' => 'Group: blog.show (slug)',
        '/admin/dashboard' => 'Group: admin.dashboard (throttled)',

        // Multi-domain demo (requires hostnames to resolve)
        'http://api.localhost/v1/ping' => 'Domain: api.localhost → api.ping',
        'http://api.localhost/v1/users/7' => 'Domain: api.localhost → api.users.show',
        'http://admin.localhost/dashboard' => 'Domain: admin.localhost → admin.dashboard',
    ];

    $html = '<h1>Webrick demo</h1><ul>';
    foreach ($links as $href => $title) {
        $html .= "<li><a href=\"{$href}\">{$title}</a></li>";
    }
    $html .= '</ul>';

    return Response::create(
        $html,
        headers: ['Content-Type' => MediaTypeEnum::HTML->base() . '; charset=utf-8'],
    );
});

/* ---- simple routes ---- */
Route::get('/ping', fn() => 'pong', 'ping');

Route::get('/hello/{name}', fn($name): Response => Response::json(['hello' => $name]));
Route::get('/json', fn() => Response::json(['memory' => memory_get_usage(true)]), 'json');
Route::get('/redirect', fn() => Response::redirect('/', StatusEnum::FOUND->value));
Route::get('/download', fn(Request $r) => Response::rangedDownload($r, __FILE__, 'index.php'));

Route::get('/color/{color:hex}', fn($hex): Response => Response::json(['you sent hex' => $hex]));

/* ---- class-based routes ---- */
Route::get('/class/test/{name}', [DemoController::class, 'hello'], 'test');
Route::get('/class/rest/{name}', [DemoController::class, 'hello']);
Route::get('/plus/{name}/mine', [DemoController::class, 'hello']);

/* ---- extra variety routes ---- */
Route::post('/post/echo', fn(Request $r): Response => Response::json(['method' => $r->getMethod(), 'payload' => $r->all(), 'time' => \date(DATE_ATOM)]));

Route::put('/user/{id:int}', fn(Request $r, $id): Response => Response::json(['updated' => $id, 'input' => $r->all()]));

Route::get('/stream', fn(): Response => Response::stream(function () {
    for ($i = 1; $i <= 10; $i++) {
        yield "chunk {$i}\n";
        usleep(100_000);
    }

    return '';
}));

Route::get('/locale', fn(Request $r) => Response::json(['locale' => $r->getAttribute('locale') ?? 'unknown']));

Route::get('/xml', fn() => Response::create(
    '<note><to>You</to><from>Me</from><msg>Hello</msg></note>',
    StatusEnum::OK->value,
    ['Content-Type' => MediaTypeEnum::XML->value],
));
Route::get('/xml-demo', fn() => Response::create(
    '<note><to>You</to><from>Me</from><msg>Hello</msg></note>',
    StatusEnum::OK->value,
    ['Content-Type' => MediaTypeEnum::XML->value],
));

Route::get('/status/{code}', fn(string $code): Response => Response::plaintext("Status: $code", (int) $code));

Route::get('/json/slow', fn(): Response => Response::json(new class implements JsonSerializable {
    /**
     * @return array{now:int,items:list<array{n:int,v:string}>}
     */
    public function jsonSerialize(): array
    {
        return [
            'now' => time(),
            'items' => array_map(fn($i) => ['n' => $i, 'v' => bin2hex(random_bytes(4))], range(1, 100)),
        ];
    }
}));

/* ---- resource routes (Laravel-ish) ---- */
Route::resource('users', '/users', UsersController::class);

/* ---- redirects using aliases ---- */
Route::get('/to-json', fn() => Response::redirect(Route::urlFor('json'), StatusEnum::FOUND->value));
Route::get('/to-user-42', fn() => Response::redirect(
    Route::urlFor('users.show', ['id' => 42], absolute: true),
    StatusEnum::FOUND->value,
));

Route::get('/signed-demo', fn() => Response::json([
    'rel' => Route::signedUrlFor('users.show', ['id' => 42]),
    'abs' => Route::signedUrlFor('users.show', ['id' => 42], absolute: true),
    'abs_payload' => Route::signedUrlFor(
        'secure.absolute',
        ['id' => 42],
        ['dl' => 1],
        absolute: true,
        payloadMode: SignedUrlConfig::MODE_ABSOLUTE,
    ),
    'expires_at' => Route::temporaryUrlUntil(
        'secure.absolute',
        new \DateTimeImmutable('+10 minutes'),
        ['id' => 42],
        ['dl' => 1],
        absolute: true,
        payloadMode: SignedUrlConfig::MODE_ABSOLUTE,
    ),
]));

Route::get('/api/error-demo', static function (): Response {
    throw HttpException::forbidden('API token missing');
});

// 1) Generate a signed URL (relative) and redirect to it
Route::get('/make-signed/{id:int}', function ($id) {
    $signed = Route::temporaryUrlFor(
        name: 'secure.show',
        params: ['id' => $id],
        query: ['dl' => 1],
        absolute: false,
    );

    return Response::redirect($signed, StatusEnum::FOUND->value);
}, [
    'as' => 'make.signed',
    'middleware' => ['throttle:2,1'],
]);

Route::get('/make-signed-absolute/{id:int}', function ($id) {
    $signed = Route::temporaryUrlUntil(
        name: 'secure.absolute',
        expiresAt: new \DateTimeImmutable('+5 minutes'),
        params: ['id' => $id],
        query: ['dl' => 1],
        absolute: true,
        payloadMode: SignedUrlConfig::MODE_ABSOLUTE,
    );

    return Response::redirect($signed . '&preview=1', StatusEnum::FOUND->value);
}, [
    'as' => 'make.signed.absolute',
    'middleware' => ['throttle:2,1'],
]);

// 2) Protected endpoint (verified by middleware)
Route::get('/secure/{id:int}', fn($id): Response => Response::json(['ok' => true, 'id' => $id, 'time' => \date(DATE_ATOM)]), [
    'as' => 'secure.show',
    'middleware' => ['verifySignedUrl', 'throttle:2,1'],
]);

Route::get('/secure-absolute/{id:int}', fn(Request $r, $id): Response => Response::json([
    'ok' => true,
    'id' => $id,
    'preview' => $r->getQueryParams()['preview'] ?? null,
    'time' => \date(DATE_ATOM),
]), [
    'as' => 'secure.absolute',
    'middleware' => [
        'verifySignedUrlAbsolute',
        'throttle:2,1',
    ],
]);

Route::get('/auto-demo', fn(Request $r) => Response::auto($r, ['now' => time(), 'msg' => 'hello']));
Route::get('/auto-hello', fn(Request $r) => Response::auto($r, 'Hello world!'));

// Set an encrypted cookie (middleware will encrypt the value transparently)
Route::get('/cookie/set', function (): Response {
    $resp = Response::json(['ok' => true, 'note' => 'cookie set (encrypted)']);
    // If Response has a helper, prefer it; fallback shown below:
    $cookie = rawurlencode('demo') . '=' . rawurlencode('secret-value')
        . '; Path=/; HttpOnly; SameSite=Lax';

    return $resp->withAddedHeader('Set-Cookie', $cookie);
});

// Read it back (middleware should have decrypted into Request cookies)
Route::get('/cookie/read', fn(Request $r): Response => Response::json([
    'cookie_demo' => $r->cookie('demo'),
]));

/* ------------------------------------------------------------------
 * GROUP EXAMPLES
 * ----------------------------------------------------------------*/

// A) Simple prefix group with name prefix (same host)
Route::group(
    prefix: '/blog',
    namePrefix: 'blog.',
    callback: function (Registrar $blog): void {
        $blog->get('/', fn() => Response::json(['section' => 'blog', 'action' => 'index']));                 // blog.index
        Route::get('/{slug}', fn($slug) => Response::json(['section' => 'blog', 'slug' => $slug]), 'show');   // blog.show
    },
);

// B) Nested group with extra middleware (same host)
Route::group(
    prefix: '/admin',
    middleware: [ThrottleMiddleware::class],
    namePrefix: 'admin.',
    callback: function (Registrar $admin): void {
        $admin->get('/dashboard', fn() => Response::json(['admin' => true, 'action' => 'dashboard']), 'dashboard'); // admin.dashboard
        $admin->get('/stats', fn() => Response::json(['admin' => true, 'action' => 'stats']), 'stats');             // admin.stats
    },
);

/* ------------------------------------------------------------------
 * MULTI-DOMAIN EXAMPLES
 * (map these hosts to your dev server — e.g. /etc/hosts)
 *   127.0.0.1 api.localhost
 *   127.0.0.1 admin.localhost
 * ----------------------------------------------------------------*/

// C) API domain group
Route::group(
    prefix: '/v1',
    domain: 'api.localhost',
    namePrefix: 'api.',
    callback: function (): void {
        Route::get('/ping', fn() => Response::json(['domain' => 'api.localhost', 'ok' => true]), 'ping');              // api.ping
        Route::get('/users/{id:int}', fn(string $id) => Response::json(['domain' => 'api.localhost', 'user' => (int) $id]), 'users.show'); // api.users.show
    },
);

// D) Admin domain group
Route::group(
    domain: 'admin.localhost',
    namePrefix: 'admin.',
    callback: function (): void {
        Route::get('/dashboard', fn() => Response::json(['domain' => 'admin.localhost', 'page' => 'dashboard'])); // admin.dashboard
    },
);
