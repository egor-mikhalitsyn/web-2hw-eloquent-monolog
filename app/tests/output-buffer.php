<?php

declare(strict_types=1);

use App\Http\HttpLogger;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

require dirname(__DIR__) . '/vendor/autoload.php';

$handler = new StreamHandler(getenv('LOG_PATH') . '/buffers.log');
$handler->setFormatter(new JsonFormatter());
$logger = new Logger('buffer-test', [$handler]);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/' . ($argv[1] ?? 'flush');
HttpLogger::register($logger, ['body_max_bytes' => 0, 'masked_headers' => [], 'masked_fields' => []]);

switch ($argv[1] ?? 'flush') {
    case 'flush':
        echo 'first';
        ob_flush();
        echo 'second';
        exit;
    case 'clean':
        echo 'discarded';
        ob_clean();
        echo 'sent';
        break;
    case 'large':
        echo str_repeat('Тест', 5000);
        break;
    case 'fatal':
        ini_set('display_errors', '0');
        echo 'before fatal';
        trigger_error('Test fatal error', E_USER_ERROR);
        break;
}
