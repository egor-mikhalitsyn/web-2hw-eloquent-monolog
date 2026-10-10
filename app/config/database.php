<?php

declare(strict_types=1);

use Illuminate\Support\Env;

$basePath = dirname(__DIR__);

// SQLite - запасной вариант для запуска без Docker (DB_CONNECTION=sqlite)
$sqlitePath = (string) Env::get('DB_DATABASE', 'database/database.sqlite');
if ($sqlitePath !== ':memory:' && !str_starts_with($sqlitePath, '/') && preg_match('/^[A-Za-z]:[\\\\\/]/', $sqlitePath) !== 1) {
    $sqlitePath = $basePath . '/' . $sqlitePath;
}

return [
    // В Docker-окружении compose.yaml передаёт DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASSWORD
    'default' => Env::get('DB_CONNECTION', 'pgsql'),

    'connections' => [
        'pgsql' => [
            'driver' => 'pgsql',
            'host' => Env::get('DB_HOST', 'postgres'),
            'port' => Env::get('DB_PORT', '5432'),
            'database' => Env::get('DB_NAME', 'app'),
            'username' => Env::get('DB_USER', 'app'),
            'password' => Env::get('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => 'prefer',
            // Eloquent пишет даты без часового пояса (Y-m-d H:i:s) в часовом поясе PHP;
            // выравниваем сессию PostgreSQL по нему, чтобы timestamptz не "плыл"
            'timezone' => Env::get('DB_TIMEZONE', date_default_timezone_get()),
            // Не ждём дольше 3 секунд, если БД недоступна (как в исходном Database::connect())
            'options' => [PDO::ATTR_TIMEOUT => 3],
        ],

        'sqlite' => [
            'driver' => 'sqlite',
            'database' => $sqlitePath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ],

    'migrations' => [
        'table' => 'migrations',
        'path' => $basePath . '/database/migrations',
    ],
];
