<?php

declare(strict_types=1);

namespace App;

use App\Database\Database;
use App\Logging\LoggerFactory;
use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Env;
use Monolog\Logger;

/**
 * Application bootstrap: .env -> config -> Monolog -> Eloquent.
 * Used by public/index.php, bin/migrate and tests.
 */
final class Application
{
    /**
     * @param array{app:array, database:array, logging:array} $config
     */
    private function __construct(
        public readonly string $basePath,
        public readonly array $config,
        public readonly LoggerFactory $loggers,
        public readonly Logger $log,
        public readonly Logger $httpLog,
        public readonly Capsule $db,
    ) {
    }

    public static function boot(string $basePath): self
    {
        Dotenv::create(Env::getRepository(), $basePath)->safeLoad();

        $config = [
            'app' => require $basePath . '/config/app.php',
            'database' => require $basePath . '/config/database.php',
            'logging' => require $basePath . '/config/logging.php',
        ];

        $loggers = new LoggerFactory($config['logging']);
        $log = $loggers->make('app');
        $httpLog = $log->withName('http'); // same handler and processors, different channel

        $db = Database::boot(
            $config['database'],
            $config['logging']['log_queries'] ? $log : null,
        );

        return new self($basePath, $config, $loggers, $log, $httpLog, $db);
    }
}
