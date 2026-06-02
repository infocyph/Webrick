<?php

/**
 * route_cache_examples.php (project root)
 *
 * Run: php route_cache_examples.php
 */
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Infocyph\Webrick\Constants\MatcherModeEnum;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Facade\Router;
use Infocyph\Webrick\Router\Route\Collection;
use Infocyph\Webrick\Support\RouteCache;
use Psr\Log\NullLogger;

function out(string $line = ''): void
{
    file_put_contents('php://stdout', $line, FILE_APPEND);
}

// ----------------------------------------------------------------------------------
// Common inputs (tweak as you like)
// ----------------------------------------------------------------------------------
$routesFile = __DIR__ . '/routes.php';

// Sharded cache: a directory that holds many small PHP shards + sentinels
$shardedDir = __DIR__ . '/.route-cache';

// Fused cache: a single PHP file
$fusedFile = __DIR__ . '/.route-cache/__routes.php';

// Optional signed URL secret + ttl
$signKey = getenv('WEBRICK_SIGN_KEY') ?: 'dev-sign-key';
$ttl900 = 900;

// Optional logger (or pass none)
$logger = new NullLogger();

// ----------------------------------------------------------------------------------
// 1) SHARDED: build using routes.php (matcher auto from path; alias-fallback = true)
// ----------------------------------------------------------------------------------
out("1) SHARDED build (routes.php, alias-fallback=TRUE)\n");
$sentinel1 = RouteCache::build([
    // matcher omitted → auto-detected as SHARDED because cache path is a dir
    'cache' => $shardedDir,
    'routes' => $routesFile,
    'signKey' => $signKey,
    'signedDefaultTtl' => $ttl900,
    'fallbackAliasesFromRegistrar' => true,
    'logger' => $logger,
    // optional knobs:
    'registrarOptions' => [
        'autoSlashRedirect' => false,
        'exposeUrlServices' => true,
    ],
]);
out("   -> sentinel: {$sentinel1}\n\n");

// ----------------------------------------------------------------------------------
// 2) SHARDED: build using a registration CLOSURE (no routes.php file), alias-fallback = false
// ----------------------------------------------------------------------------------
out("2) SHARDED build (closure registrar, alias-fallback=FALSE, ttl=300)\n");
$sentinel2 = RouteCache::build([
    'matcher' => MatcherModeEnum::SHARDED->value,   // explicit
    'cache' => $shardedDir,
    // Supply your own registration logic instead of routes.php:
    'register' => static function (Registrar $r): void {
        // Minimal demo routes
        $r->get('/ping', fn() => 'pong', 'ping');
        $r->get('/hello/{name}', function ($req, $name): Response {
            unset($req);

            return Response::json(['hello' => $name]);
        }, 'hello');
    },
    'signKey' => $signKey,
    'signedDefaultTtl' => 300,
    'fallbackAliasesFromRegistrar' => false, // trust cache’s __aliases.php only
    'logger' => $logger,
]);
out("   -> sentinel: {$sentinel2}\n\n");

// ----------------------------------------------------------------------------------
// 3) FUSED: build using routes.php (matcher auto from .php path)
// ----------------------------------------------------------------------------------
out("3) FUSED build (routes.php, auto-detect matcher)\n");
$sentinel3 = RouteCache::build([
    // matcher omitted → auto-detected as FUSED because cache ends with .php
    'cache' => $fusedFile,
    'routes' => $routesFile,
    'signKey' => $signKey,
    'signedDefaultTtl' => 1200, // different TTL to show it’s configurable
    'fallbackAliasesFromRegistrar' => true,
    'logger' => $logger,
]);
out("   -> sentinel: {$sentinel3}\n\n");

// ----------------------------------------------------------------------------------
// 4) FUSED: build with explicit matcher + custom binder for URL services
// ----------------------------------------------------------------------------------
out("4) FUSED build (explicit matcher, custom bindUrlServices)\n");
$sentinel4 = RouteCache::build([
    'matcher' => MatcherModeEnum::FUSED->value,
    'cache' => $fusedFile,
    'routes' => $routesFile,
    'signKey' => $signKey,
    'signedDefaultTtl' => 600,
    // If you want to override the default binder:
    'bindUrlServices' => static function (Collection $routes) use ($signKey): void {
        // You can customize how URL services are bound for signed/temporary URLs:
        // (This mirrors Router::bindUrlServices but could inject different defaults.)
        Router::bindUrlServices($routes, $signKey, 600);
    },
    'logger' => $logger,
    'fallbackAliasesFromRegistrar' => true,
]);
out("   -> sentinel: {$sentinel4}\n\n");

// ----------------------------------------------------------------------------------
// 5) SHARDED: CLEAR (SAFE / non-aggressive) → keeps the directory & dotfiles (.gitignore)
// ----------------------------------------------------------------------------------
out("5) SHARDED clear (safe; non-aggressive)\n");
$removed5 = RouteCache::clear([
    // matcher omitted → auto-detected as SHARDED by directory path
    'cache' => $shardedDir,
    'aggressive' => false, // default; shown here explicitly
]);
out('   -> removed something? ' . ($removed5 ? 'yes' : 'no') . "\n");
out('   -> directory still there? ' . (is_dir($shardedDir) ? 'yes' : 'no') . "\n\n");

// ----------------------------------------------------------------------------------
// 6) SHARDED: CLEAR (AGGRESSIVE) → removes cache artifacts recursively, keeps root .gitignore
// ----------------------------------------------------------------------------------
out("6) SHARDED clear (AGGRESSIVE) – recursive purge, preserves root .gitignore\n");
$removed6 = RouteCache::clear([
    'matcher' => MatcherModeEnum::SHARDED->value,
    'cache' => $shardedDir,
    'aggressive' => true,
]);
out('   -> removed artifacts? ' . ($removed6 ? 'yes' : 'no') . "\n");
out('   -> directory exists? ' . (is_dir($shardedDir) ? 'yes' : 'no') . "\n\n");

// ----------------------------------------------------------------------------------
// 7) FUSED: CLEAR → deletes the single fused cache file
// ----------------------------------------------------------------------------------
out("7) FUSED clear (single file)\n");
// ensure there’s a file to delete (rebuild quickly)
RouteCache::build([
    'cache' => $fusedFile,
    'routes' => $routesFile,
    'signKey' => $signKey,
]);
$removed7 = RouteCache::clear([
    'matcher' => MatcherModeEnum::FUSED->value,   // explicit, but would be auto-detected by .php suffix
    'cache' => $fusedFile,
]);
out('   -> removed file? ' . ($removed7 ? 'yes' : 'no') . "\n");
out('   -> file exists? ' . (is_file($fusedFile) ? 'yes' : 'no') . "\n\n");

// ----------------------------------------------------------------------------------
// 8) SHARDED: build again with full option set (kitchen sink)
// ----------------------------------------------------------------------------------
out("8) SHARDED build (kitchen sink)\n");
$sentinel8 = RouteCache::build([
    'matcher' => MatcherModeEnum::SHARDED->value,
    'cache' => $shardedDir,
    'routes' => $routesFile,
    'signKey' => $signKey,
    'signedDefaultTtl' => 1800,
    'fallbackAliasesFromRegistrar' => true,
    'logger' => $logger,
    'registrarOptions' => [
        'autoSlashRedirect' => false,
        'exposeUrlServices' => true,
    ],
    // You can also pass middleware lists if you want them wired during warmup
    'preGlobal' => [
        // \Infocyph\Webrick\Middleware\GatewayHardeningMiddleware::class,
        // \Infocyph\Webrick\Middleware\TelemetryMiddleware::class,
    ],
    'postGlobal' => [
        // \Infocyph\Webrick\Middleware\CompressionMiddleware::class,
        // \Infocyph\Webrick\Middleware\VaryAccumulatorMiddleware::class,
    ],
]);
out("   -> sentinel: {$sentinel8}\n\n");

out("All examples done.\n");
