<?php

declare(strict_types=1);

namespace App\Database;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Psr\Log\LoggerInterface;

/**
 * Boots standalone Eloquent (illuminate/database "Capsule") from config/database.php.
 * After boot() all Eloquent models work statically: User::create(), User::find()...
 */
final class Database
{
    /**
     * @param array<string, mixed> $config config/database.php
     */
    public static function boot(array $config, ?LoggerInterface $queryLogger = null): Capsule
    {
        self::ensureSqliteFile($config['connections'][$config['default']] ?? []);

        $container = new Container();
        $capsule = new Capsule($container);

        foreach ($config['connections'] as $name => $connection) {
            $capsule->addConnection($connection, $name);
        }
        $capsule->getDatabaseManager()->setDefaultConnection($config['default']);

        $events = new Dispatcher($container);
        if ($queryLogger !== null) {
            $events->listen(QueryExecuted::class, static function (QueryExecuted $query) use ($queryLogger): void {
                $queryLogger->info('SQL: {sql}', [
                    'sql' => $query->sql,
                    'bindings' => $query->bindings,
                    'time_ms' => $query->time,
                    'connection' => $query->connectionName,
                ]);
            });
        }
        $capsule->setEventDispatcher($events);

        $capsule->setAsGlobal();   // Capsule::connection() / Capsule::schema() anywhere
        $capsule->bootEloquent();  // enable the static Eloquent Model API

        return $capsule;
    }

    /** @param array<string, mixed> $connection */
    private static function ensureSqliteFile(array $connection): void
    {
        if (($connection['driver'] ?? null) !== 'sqlite' || $connection['database'] === ':memory:') {
            return;
        }
        $file = $connection['database'];
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0775, true);
        }
        if (!file_exists($file)) {
            touch($file);
        }
    }
}
