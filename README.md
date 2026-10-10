# Web: домашнее задание 2

Учебное приложение: nginx + PHP-FPM 8.5 + PostgreSQL 18. Записи читаются и сохраняются через Eloquent, схема управляется мигратором Illuminate, события и HTTP-запросы с телом ответа записываются через Monolog.

Структура адаптирована из предоставленного примера `zadanie_web_2_v2-main.zip`. Исходный стенд: [local_zoo_for_vyatgu](https://github.com/romicharroyo/local_zoo_for_vyatgu). Лицензия примера сохранена в [LICENSE](LICENSE).

## Запуск с Docker

Требуется Docker Desktop с Linux-контейнерами и Docker Compose. Команды выполняются из корня репозитория (`2HW`).

```powershell
Copy-Item .env.example .env
docker compose up -d --build
docker compose exec -u www-data php composer install
docker compose exec -u www-data php php bin/migrate migrate
```

Приложение: <http://127.0.0.1:8080/>. Главная страница показывает состояние БД, список `demo` и форму добавления заметки. PostgreSQL доступен на `127.0.0.1:5433`; параметры подключения задаются в `.env`. При повторном запуске сохраняйте существующий `.env`.

```powershell
docker compose ps
docker compose exec -u www-data php php bin/migrate status
docker compose exec -u www-data php php bin/migrate rollback
docker compose exec -u www-data php php bin/migrate make create_posts_table --create=posts
docker compose down
```

`rollback` откатывает последнюю группу миграций. `reset` откатывает все миграции, а `fresh` выполняет `reset` и повторное применение: обе команды удаляют данные в таблицах приложения. Команды также доступны через `Makefile`.

## Запуск без Docker

Нужны PHP 8.5+, Composer, расширения `mbstring`, `pdo_pgsql` и `pdo_sqlite`. SQLite позволяет проверить приложение без отдельного сервера БД; файл создаётся автоматически.

```powershell
composer install --working-dir=app
$env:DB_CONNECTION = "sqlite"
$env:DB_DATABASE = "database/database.sqlite"
php app/bin/migrate migrate
php -S 127.0.0.1:8080 -t app/public app/public/router.php
```

На компьютере подготовки PHP и Composer уже скачаны в `.tools` (исключена из Git). Если их нет в `PATH`, сначала выполните из `2HW`:

```powershell
$env:Path = "$PWD\.tools\php;$env:Path"
php .tools/composer.phar install --working-dir=app
```

Далее используйте команды с `DB_CONNECTION`, `DB_DATABASE` и `php` из предыдущего блока. В опубликованном репозитории папка `.tools` отсутствует; установите PHP и Composer самостоятельно или используйте Docker.

## Маршруты

| Метод | Путь | Результат |
| --- | --- | --- |
| GET | `/` | Список заметок `demo` |
| POST | `/` | Добавление заметки через Eloquent, затем редирект 303 |
| GET | `/health.php` | JSON-проверка соединения с БД |
| GET | `/users` | Последние 50 пользователей |
| POST | `/users` | Создание пользователя из JSON `name`, `email` |
| GET | `/users/{id}` | Пользователь по ID или ошибка 404 |

Пример в PowerShell:

```powershell
Invoke-RestMethod -Method Post -Uri http://127.0.0.1:8080/users -ContentType 'application/json' -Body '{"name":"Student","email":"student@example.com"}'
Invoke-RestMethod -Uri http://127.0.0.1:8080/users
```

Проверяются обязательные поля, длина имени, формат и уникальность email, длина заметки. Ответы об ошибках: 400 для некорректного JSON, 422 для неверных данных, 404 для отсутствующих ресурсов, 405 для неподдерживаемого метода. Ошибки БД и исключения также попадают в лог.

## Структура

```text
compose.yaml, .env.example, Makefile
docker/
  nginx/                   nginx и маршрутизация в public/index.php
  php/                     PHP-FPM, Composer и расширения
  postgres/                PostgreSQL и конфигурация сервера
app/
  composer.json, composer.lock
  bootstrap.php            общий запуск и HTTP-логирование
  config/                  настройки приложения, БД и Monolog
  bin/migrate              migrate, status, rollback, reset, fresh, make
  database/migrations/     тестовые миграции users и demo
  public/index.php         главная страница и API через Eloquent
  public/health.php         проверка БД
  public/router.php        маршрутизация для встроенного сервера PHP
  src/Database/            Capsule и обёртка над Illuminate Migrator
  src/Models/              модели Demo и User
  src/Logging/             фабрика Monolog
  src/Http/                лог запросов, JSON-ответы, API пользователей
  storage/logs/            ежедневные логи (исключены из Git)
  tests/                   интеграционная проверка
```

`Database::boot()` настраивает `Capsule`, диспетчер событий и Eloquent по `config/database.php`. В `index.php` используются `Demo::create()` и `Demo::query()`, в API — модель `User`. SQL для проверки версии сервера выполняется через соединение Capsule. Миграция `create_demo_table` создаёт таблицу и добавляет первую запись через Eloquent; `create_users_table` создаёт пользователей с уникальным email. Повторное применение выполненных миграций ничего не меняет.

## Monolog и HTTP-логи

`LoggerFactory` использует `RotatingFileHandler`, `JsonFormatter`, `UidProcessor` и `PsrLogMessageProcessor`. Каналы `app` и `http` пишут в `app/storage/logs/app-YYYY-MM-DD.log`. Каждая строка — JSON-объект; файл ротируется ежедневно, хранится 14 файлов.

HTTP-лог содержит метод, URI, IP, заголовки и тело запроса, код и заголовки ответа, **тело ответа**, длительность и ID запроса (`extra.uid`). Тот же ID возвращается в заголовке `X-Request-Id`. Запись создаётся при завершении буфера вывода, включая `exit()`, досрочный сброс буфера и фатальные ошибки. Удалённые через `ob_clean()` данные в тело ответа не попадают. Для HEAD тело пустое.

Заголовки авторизации и cookies, поля `password`, `token`, `secret` маскируются. JSON-тела сохраняются после разбора и маскирования, поэтому пробелы могут отличаться от исходного ответа. По умолчанию тело сохраняется целиком (`LOG_BODY_MAX_BYTES=0`); положительное значение включает ограничение. `LOG_QUERIES=true` добавляет SQL-запросы и время их выполнения. Статусы 2xx/3xx пишутся с уровнем INFO, 4xx — WARNING, 5xx — ERROR.

Чтение логов в PowerShell:

```powershell
Get-Content app\storage\logs\app-*.log -Tail 20 -Wait
Get-Content app\storage\logs\app-*.log | ForEach-Object { $_ | ConvertFrom-Json } | Where-Object channel -eq http
```

## Проверка

```powershell
composer validate --working-dir=app --strict
composer test --working-dir=app
```

При использовании локальных инструментов:

```powershell
php .tools/composer.phar test --working-dir=app
```

Тест запускает настоящий HTTP-сервер на свободном порту и временную SQLite-БД, проверяет миграции, откат, форму, API, ошибки и соответствие HTTP-логов ответам. Отдельно проверяются `exit()`, сброс и очистка буфера, длинные тела и фатальные ошибки. Временные файлы и сервер удаляются после проверки.

На компьютере подготовки пройдены проверки Composer, синтаксиса PHP и интеграционный тест. Docker не установлен, поэтому запуск контейнеров и работа с PostgreSQL здесь не проверялись.

Справка: [Illuminate Database](https://github.com/illuminate/database), [миграции Laravel](https://laravel.com/framework/docs/12.x/migrations), [Monolog](https://github.com/Seldaek/monolog/blob/main/doc/01-usage.md), [образ PostgreSQL](https://hub.docker.com/_/postgres).
