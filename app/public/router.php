<?php

declare(strict_types=1);

// Router for php -S: keep API routes and health checks identical to nginx.
if (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) === '/health.php') {
    require __DIR__ . '/health.php';
} else {
    require __DIR__ . '/index.php';
}
