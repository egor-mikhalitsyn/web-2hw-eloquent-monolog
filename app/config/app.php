<?php

declare(strict_types=1);

use Illuminate\Support\Env;

return [
    'name' => Env::get('APP_NAME', 'PHP Eloquent Skeleton'),
    'env' => Env::get('APP_ENV', 'production'),
    'debug' => (bool) Env::get('APP_DEBUG', false),
];
