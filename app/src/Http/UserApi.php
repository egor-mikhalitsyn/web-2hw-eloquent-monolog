<?php

declare(strict_types=1);

namespace App\Http;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;

/**
 * Небольшой JSON API поверх Eloquent-модели User:
 *   GET /users, GET /users/{id}, POST /users
 */
final class UserApi
{
    public function __construct(private readonly LoggerInterface $log)
    {
    }

    /** @return bool true, если маршрут обработан */
    public function handle(string $method, string $path): bool
    {
        // GET /users - список
        if ($method === 'GET' && $path === '/users') {
            Json::response(['data' => User::query()->orderByDesc('id')->limit(50)->get()]);
            return true;
        }

        // GET /users/{id}
        if ($method === 'GET' && preg_match('#^/users/(\d+)$#', $path, $m) === 1) {
            $user = User::find((int) $m[1]);
            $user
                ? Json::response(['data' => $user])
                : Json::response(['error' => 'User not found'], 404);
            return true;
        }

        // POST /users {"name": "...", "email": "..."}
        if ($method === 'POST' && $path === '/users') {
            $this->create();
            return true;
        }

        if ($path === '/users' || preg_match('#^/users/\d+$#', $path) === 1) {
            header('Allow: ' . ($path === '/users' ? 'GET, POST' : 'GET'));
            Json::response(['error' => 'Method not allowed'], 405);
            return true;
        }

        return false;
    }

    private function create(): void
    {
        $input = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($input)) {
            Json::response([
                'error' => 'Invalid JSON body: ' . json_last_error_msg(),
                'hint' => 'The body must be valid UTF-8 JSON (on Windows send it from a file, use \uXXXX escapes or run `chcp 65001` first)',
            ], 400);
            return;
        }

        $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';
        $email = is_string($input['email'] ?? null) ? trim($input['email']) : '';

        $errors = [];
        if ($name === '' || mb_strlen($name) > 255) {
            $errors['name'] = 'A name between 1 and 255 characters is required';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'A valid email is required';
        } elseif (User::where('email', $email)->exists()) {
            $errors['email'] = 'Already taken';
        }
        if ($errors !== []) {
            Json::response(['errors' => $errors], 422);
            return;
        }

        try {
            $user = User::create(['name' => $name, 'email' => $email]);
        } catch (UniqueConstraintViolationException) {
            Json::response(['errors' => ['email' => 'Already taken']], 422);
            return;
        }
        $this->log->info('User created', ['id' => $user->id]);
        Json::response(['data' => $user], 201);
    }
}
