<?php

declare(strict_types=1);

use App\Http\Json;
use App\Http\UserApi;
use App\Models\Demo;
use Illuminate\Database\Capsule\Manager as Capsule;

/** @var \App\Application $app */
$app = require __DIR__ . '/../bootstrap.php'; // Eloquent + Monolog + лог каждого запроса

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/') ?: '/';

try {
    // JSON API: /users...
    if ((new UserApi($app->log))->handle($method, $path)) {
        return;
    }

    if ($path !== '/') {
        Json::response(['error' => 'Not found'], 404);
        return;
    }

    if (!in_array($method, ['GET', 'POST', 'HEAD'], true)) {
        header('Allow: GET, POST, HEAD');
        Json::response(['error' => 'Method not allowed'], 405);
        return;
    }

    // Обработка формы добавления записи. Редиректим после POST (Post/Redirect/Get),
    // чтобы повторная отправка формы не происходила при обновлении страницы
    if ($method === 'POST') {
        $note = is_string($_POST['note'] ?? null) ? trim($_POST['note']) : '';
        if ($note === '' || mb_strlen($note) > 500) {
            Json::response(['error' => 'Введите заметку длиной от 1 до 500 символов'], 422);
            return;
        }
        Demo::create(['note' => $note]);
        $app->log->info('Demo note created');
        header('Location: /', true, 303);
        return;
    }

    $phpVersion = PHP_VERSION;
    $driver = $app->config['database']['default'];
    $hasPdoDriver = extension_loaded('pdo_' . $driver);
    $hostname = gethostname();

    $dbVersion = null;
    $dbError = null;
    $rows = [];

    try {
        $dbVersion = (string) Capsule::connection()->selectOne(
            $driver === 'sqlite' ? 'SELECT sqlite_version() AS v' : 'SELECT version() AS v',
        )->v;
        $rows = Demo::query()->orderByDesc('id')->get()->all(); // Eloquent вместо PDO SELECT
    } catch (\Throwable $e) {
        $dbError = $e->getMessage();
        http_response_code(503);
        $app->log->error('Database is unavailable: {message}', ['message' => $dbError, 'exception' => $e]);
    }
} catch (\Throwable $e) {
    // CRITICAL = неожиданное исключение (см. Monolog "Log Levels")
    $app->log->critical($e->getMessage(), ['exception' => $e]);
    Json::response(
        ['error' => 'Internal Server Error'] + ($app->config['app']['debug'] ? ['message' => $e->getMessage()] : []),
        500,
    );
    return;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>nginx + php-fpm + PostgreSQL</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 720px; margin: 2rem auto; padding: 0 1rem; color: #1c1c1c; }
        h1 { font-size: 1.4rem; }
        h2 { font-size: 1.1rem; margin-top: 2rem; }
        dl { display: grid; grid-template-columns: max-content 1fr; gap: 0.3rem 1rem; background: #f6f6f6; padding: 1rem; border-radius: 6px; }
        dt { font-weight: 600; }
        dd { margin: 0; }
        .ok { color: #0a7a2f; }
        .fail { color: #b00020; }
        table { border-collapse: collapse; width: 100%; margin: 0.5rem 0 1rem; }
        th, td { border: 1px solid #ddd; padding: 0.4rem 0.6rem; text-align: left; font-size: 0.9rem; }
        th { background: #f0f0f0; }
        form { display: flex; gap: 0.5rem; }
        input[type=text] { flex: 1; padding: 0.5rem; border: 1px solid #ccc; border-radius: 4px; }
        button { padding: 0.5rem 1.2rem; border: 0; border-radius: 4px; background: #2563eb; color: #fff; cursor: pointer; }
        button:hover { background: #1d4ed8; }
    </style>
</head>
<body>
    <h1>nginx + php-fpm + PostgreSQL</h1>

    <dl>
        <dt>Версия PHP</dt>
        <dd><?= h($phpVersion) ?></dd>

        <dt>Расширение <?= h('pdo_' . $driver) ?></dt>
        <dd class="<?= $hasPdoDriver ? 'ok' : 'fail' ?>"><?= $hasPdoDriver ? 'подключено' : 'отсутствует' ?></dd>

        <dt>Хост контейнера</dt>
        <dd><?= h((string) $hostname) ?></dd>

        <dt>Подключение к БД</dt>
        <dd class="<?= $dbError === null ? 'ok' : 'fail' ?>">
            <?= $dbError === null ? h($dbVersion ?? '') : 'БД недоступна. Проверьте подключение и выполните миграции.' ?>
        </dd>
    </dl>

    <h2>Таблица demo</h2>
    <?php if ($rows): ?>
        <table>
            <tr><th>id</th><th>создано</th><th>note</th></tr>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= h((string) $row->id) ?></td>
                    <td><?= h((string) $row->created_at) ?></td>
                    <td><?= h((string) $row->note) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <p>Нет данных (таблица пуста или БД недоступна).</p>
    <?php endif; ?>

    <form method="post" action="/">
        <input type="text" name="note" placeholder="Новая запись" required maxlength="500">
        <button type="submit">Добавить</button>
    </form>
</body>
</html>
