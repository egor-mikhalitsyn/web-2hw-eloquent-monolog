<?php

declare(strict_types=1);

use Illuminate\Support\Env;

$basePath = dirname(__DIR__);

$path = (string) Env::get('LOG_PATH', 'storage/logs');
if (!str_starts_with($path, '/') && preg_match('/^[A-Za-z]:[\\\\\/]/', $path) !== 1) {
    $path = $basePath . '/' . $path;
}

return [
    'path' => rtrim($path, '/'),
    'level' => strtolower((string) Env::get('LOG_LEVEL', 'debug')),
    'max_files' => (int) Env::get('LOG_MAX_FILES', 14),
    'log_queries' => (bool) Env::get('LOG_QUERIES', false),

    'http' => [
        // 0 records the complete body; a positive value enables an optional limit.
        'body_max_bytes' => max(0, (int) Env::get('LOG_BODY_MAX_BYTES', 0)),
        // Header names (case-insensitive) whose values are replaced with ***
        'masked_headers' => ['authorization', 'cookie', 'set-cookie', 'x-api-key'],
        // JSON body keys (case-insensitive) whose values are replaced with ***
        'masked_fields' => ['password', 'password_confirmation', 'token', 'secret', 'api_key'],
    ],
];
