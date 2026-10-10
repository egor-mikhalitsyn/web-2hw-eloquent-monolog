<?php

declare(strict_types=1);

namespace App\Http;

final class Json
{
    /** Отправляет JSON-ответ с нужным статусом. */
    public static function response(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }
}
