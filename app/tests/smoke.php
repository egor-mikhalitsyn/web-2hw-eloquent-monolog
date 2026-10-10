<?php

declare(strict_types=1);

// Integration check against a disposable SQLite database and a real HTTP server.
$base = dirname(__DIR__);
$temp = sys_get_temp_dir() . '/web-2hw-' . bin2hex(random_bytes(6));
mkdir($temp, 0775, true);
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=' . $temp . '/test.sqlite');
putenv('LOG_PATH=' . $temp . '/logs');
putenv('LOG_LEVEL=debug');
putenv('LOG_QUERIES=true');
putenv('LOG_BODY_MAX_BYTES=0');
putenv('APP_DEBUG=false');

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function command(array $args, int $expectedExit = 0): string
{
    $process = proc_open([PHP_BINARY, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    check(is_resource($process), 'Unable to start PHP');
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    check(proc_close($process) === $expectedExit, 'Command failed: ' . $output . $errors);
    return $output;
}

$server = null;
try {
    check(str_contains(command([$base . '/bin/migrate', 'status']), 'Pending'), 'Expected pending migrations');
    check(substr_count(command([$base . '/bin/migrate', 'migrate']), 'MIGRATED') === 2, 'Expected two migrations');
    check(str_contains(command([$base . '/bin/migrate', 'migrate']), 'Nothing to migrate'), 'Migrations must be idempotent');
    check(substr_count(command([$base . '/bin/migrate', 'rollback']), 'ROLLED BACK') === 2, 'Both migrations must roll back');
    command([$base . '/bin/migrate', 'migrate']);

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    check($socket !== false, 'Cannot select HTTP port');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $server = proc_open(
        [PHP_BINARY, '-S', $address, '-t', $base . '/public', $base . '/public/router.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $temp . '/server.log', 'a'], 2 => ['file', $temp . '/server.log', 'a']],
        $pipes,
    );
    check(is_resource($server), 'Cannot start HTTP server');
    fclose($pipes[0]);
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $probe = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if ($probe !== false) {
            fclose($probe);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    check($ready, 'HTTP server did not start');

    $responses = [];
    $request = static function (string $method, string $path, int $expected, string $body = '', string $type = 'application/json') use ($address, &$responses): string {
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => 'Content-Type: ' . $type . "\r\nAuthorization: Bearer test-secret\r\n",
            'content' => $body,
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 5,
        ]]);
        $response = file_get_contents('http://' . $address . $path, false, $context);
        check($response !== false, 'HTTP request failed: ' . $path);
        $headers = http_get_last_response_headers() ?? [];
        check(preg_match('/^HTTP\/\S+ (\d+)/', $headers[0] ?? '', $match) === 1 && (int) $match[1] === $expected, $method . ' ' . $path . ': expected ' . $expected . ', got ' . ($headers[0] ?? '') . ' ' . $response);
        $id = null;
        foreach ($headers as $header) {
            if (stripos($header, 'X-Request-Id:') === 0) {
                $id = trim(substr($header, 13));
            }
        }
        check($id !== null, 'Missing request ID');
        $responses[$id] = [$expected, $response, $method, $path];
        return $response;
    };

    check(str_contains($request('GET', '/', 200), 'Таблица demo'), 'Main page missing');
    check(json_decode($request('GET', '/health.php', 200), true)['status'] === 'ok', 'Health check failed');
    $request('HEAD', '/', 200);
    $request('POST', '/', 303, 'note=' . urlencode('<script>alert(1)</script>'), 'application/x-www-form-urlencoded');
    check(str_contains($request('GET', '/', 200), '&lt;script&gt;alert(1)&lt;/script&gt;'), 'Notes must be HTML escaped');
    $request('POST', '/', 422, 'note=', 'application/x-www-form-urlencoded');
    $request('GET', '/users', 200);
    $created = json_decode($request('POST', '/users', 201, '{"name":"Test User","email":"test@example.com","password":"test-password"}'), true);
    check($created['data']['name'] === 'Test User', 'Eloquent user creation failed');
    $request('GET', '/users/' . $created['data']['id'], 200);
    $request('POST', '/users', 422, '{"name":"Test User","email":"test@example.com"}');
    $request('POST', '/users', 400, '{broken');
    $request('POST', '/users', 422, '{"name":[],"email":[]}');
    $request('GET', '/users/999999', 404);
    $request('GET', '/missing', 404);
    $request('DELETE', '/users', 405);
    $request('PUT', '/', 405);

    // Remove application tables to exercise error responses and their HTTP logs.
    command([$base . '/bin/migrate', 'rollback']);
    $request('GET', '/', 503);
    $request('GET', '/users', 500);

    $records = [];
    foreach (glob($temp . '/logs/app-*.log') as $file) {
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if ($record['channel'] === 'http') {
                $records[$record['extra']['uid']][] = $record;
            }
        }
    }
    check(count($records) === count($responses), 'Every HTTP request must have one log entry');
    foreach ($responses as $id => [$status, $body, $method, $path]) {
        check(count($records[$id] ?? []) === 1, 'Missing or duplicate HTTP log');
        $record = $records[$id][0];
        check($record['context']['response']['status'] === $status, 'Logged status differs');
        $loggedBody = $record['context']['response']['body'];
        check(str_starts_with(ltrim($body), '{') ? json_decode($body, true) === json_decode($loggedBody, true) : $loggedBody === $body, 'Logged response body differs');
        check($record['context']['method'] === $method && $record['context']['uri'] === $path, 'Logged request differs');
        check($record['context']['request']['headers']['authorization'] === '***', 'Authorization must be masked');
        check(!str_contains($record['context']['request']['body'], 'test-password'), 'Passwords must be masked');
    }
    $bufferOutputs = [];
    foreach (['flush', 'clean', 'large', 'fatal'] as $mode) {
        $bufferOutputs['/' . $mode] = command([$base . '/tests/output-buffer.php', $mode], $mode === 'fatal' ? 255 : 0);
    }
    $bufferLogs = file($temp . '/logs/buffers.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    check(count($bufferLogs) === 4, 'Exactly one log per output buffer test is required');
    foreach ($bufferLogs as $line) {
        $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        check($record['context']['response']['body'] === $bufferOutputs[$record['context']['uri']], 'Flushed, cleaned or long response body differs');
        if ($record['context']['uri'] === '/fatal') {
            check($record['context']['response']['status'] === 500, 'Fatal errors must have status 500');
        }
    }
    echo 'PASS: migrations, rollback, Eloquent, forms, API, validation, errors and ' . count($responses) . " HTTP logs with response bodies; flush, clean, exit, long bodies and fatal errors.\n";
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($temp);
}
