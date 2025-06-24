<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router\OpenApi;

use Infocyph\InterMix\Cache\Cache;
use ReflectionClass;

/**
 * Stores generated schemas (DTOs & Enums) between CLI runs.
 * Auto-invalidates when the source file’s mtime changes.
 */
final class DTORegistry
{
    private const CACHE_KEY = 'dto.schemas';
    private static Cache $cache;
    /** @var array<string,array{schema:object,mtime:int}> */
    private static array $store;
    private static bool  $dirty = false;

    /* boot once */
    private static function init(): void
    {
        if (isset(self::$store)) {
            return;
        }
        self::$cache = Cache::file('webrick_dto');
        self::$store = self::$cache->getItem(self::CACHE_KEY)->get() ?: [];
        register_shutdown_function([self::class, 'flush']);
    }

    public static function add(string $class, object $schema): void
    {
        self::init();
        $file = (new ReflectionClass($class))->getFileName() ?: '';
        $mt   = is_file($file) ? filemtime($file) : time();

        self::$store[$class] = ['schema' => $schema, 'mtime' => $mt];
        self::$dirty         = true;
    }

    /** @return array<string,object> Fresh, valid schemas */
    public static function dump(): array
    {
        self::init();

        foreach (self::$store as $cls => $pair) {
            $file = (new ReflectionClass($cls))->getFileName() ?: '';
            if (is_file($file) && filemtime($file) > $pair['mtime']) {
                unset(self::$store[$cls]);
                self::$dirty = true;
            }
        }
        return array_column(self::$store, 'schema', '0');
    }

    /* persist to cache */
    public static function flush(): void
    {
        if (!self::$dirty) {
            return;
        }
        self::$cache->getItem(self::CACHE_KEY)->set(self::$store);
        self::$cache->save();
        self::$dirty = false;
    }
}
