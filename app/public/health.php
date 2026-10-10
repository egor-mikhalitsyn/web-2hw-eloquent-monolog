<?php

declare(strict_types=1);

use App\Http\Json;
use Illuminate\Database\Capsule\Manager as Capsule;

/** @var \App\Application $app */
$app = require __DIR__ . '/../bootstrap.php'; // этот запрос тоже попадает в лог

$checks = [
    'php' => 'ok',
    'database' => 'ok',
];
$httpStatus = 200;

try {
    Capsule::connection()->select('SELECT 1');
} catch (\Throwable $e) {
    $checks['database'] = 'fail';
    $httpStatus = 503;
    $app->log->error('Health check: database is unavailable: {message}', ['message' => $e->getMessage()]);
}

Json::response([
    'status' => $httpStatus === 200 ? 'ok' : 'fail',
    'checks' => $checks,
], $httpStatus);
