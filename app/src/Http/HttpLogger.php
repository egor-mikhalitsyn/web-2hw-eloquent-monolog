<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Logs every incoming HTTP request together with the response that was sent:
 * method, URI, client IP, headers, request body, status code, response headers,
 * response body and duration.
 *
 * Usage (once, at the very top of public/index.php):
 *
 *     HttpLogger::register($logger, $config['http']);
 *
 * The output handler records all flushed chunks and logs once when the buffer
 * closes, including normal shutdown, exit() and fatal errors.
 */
final class HttpLogger
{
    private const MASK = '***';

    private int $startedAt;
    private string $responseBody = '';
    private string $requestBody;
    private bool $logged = false;

    /** @param array{body_max_bytes:int, masked_headers:list<string>, masked_fields:list<string>} $options */
    private function __construct(
        private readonly LoggerInterface $logger,
        private readonly array $options,
    ) {
    }

    /** @param array{body_max_bytes:int, masked_headers:list<string>, masked_fields:list<string>} $options */
    public static function register(LoggerInterface $logger, array $options): self
    {
        $self = new self($logger, $options);
        $self->startedAt = hrtime(true);
        $self->requestBody = (string) file_get_contents('php://input');

        ob_start(static function (string $chunk, int $phase) use ($self): string {
            $isHead = ($_SERVER['REQUEST_METHOD'] ?? '') === 'HEAD';
            if (($phase & PHP_OUTPUT_HANDLER_CLEAN) === 0 && !$isHead) {
                $self->responseBody .= $chunk;
            }
            if (($phase & PHP_OUTPUT_HANDLER_FINAL) !== 0) {
                $self->log();
            }
            return $isHead ? '' : $chunk;
        });
        register_shutdown_function(static function (): void {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                http_response_code(500);
            }
        });

        return $self;
    }

    private function log(): void
    {
        if ($this->logged) {
            return;
        }
        $this->logged = true;

        $status = http_response_code() ?: 200;
        $durationMs = round((hrtime(true) - $this->startedAt) / 1e6, 2);

        $method = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
        $uri = $_SERVER['REQUEST_URI'] ?? '';

        $requestHeaders = $this->requestHeaders();
        $responseHeaders = $this->responseHeaders();

        $context = [
            'request' => [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'headers' => $this->maskHeaders($requestHeaders),
                'body' => $this->prepareBody(
                    $this->requestBody,
                    $requestHeaders['content-type'] ?? '',
                    maskFields: true,
                ),
            ],
            'response' => [
                'status' => $status,
                'headers' => $this->maskHeaders($responseHeaders),
                'body' => $this->prepareBody(
                    $this->responseBody,
                    $responseHeaders['content-type'] ?? '',
                    maskFields: true,
                ),
            ],
            'duration_ms' => $durationMs,
        ];

        $level = match (true) {
            $status >= 500 => LogLevel::ERROR,
            $status >= 400 => LogLevel::WARNING,
            default => LogLevel::INFO,
        };

        $this->logger->log($level, '{method} {uri} -> {status}', [
            'method' => $method,
            'uri' => $uri,
            'status' => $status,
        ] + $context);
    }

    /** @return array<string, string> lower-cased header name => value */
    private function requestHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }
        foreach (['CONTENT_TYPE', 'CONTENT_LENGTH'] as $key) {
            if (!empty($_SERVER[$key])) {
                $headers[strtolower(str_replace('_', '-', $key))] = (string) $_SERVER[$key];
            }
        }

        return $headers;
    }

    /** @return array<string, string> */
    private function responseHeaders(): array
    {
        $headers = [];
        foreach (headers_list() as $line) {
            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
            $headers[strtolower(trim($name))] = trim($value);
        }

        return $headers;
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private function maskHeaders(array $headers): array
    {
        foreach ($this->options['masked_headers'] as $name) {
            if (isset($headers[$name])) {
                $headers[$name] = self::MASK;
            }
        }

        return $headers;
    }

    private function prepareBody(string $body, string $contentType, bool $maskFields): string
    {
        if ($body === '') {
            return '';
        }

        $length = strlen($body);

        // Do not dump binary payloads into the log.
        if (!mb_check_encoding($body, 'UTF-8') && !str_contains($contentType, 'json')) {
            return sprintf('[binary data, %d bytes]', $length);
        }

        if ($maskFields && str_contains(strtolower($contentType), 'json')) {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $body = (string) json_encode(
                    $this->maskFields($decoded),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                );
            }
        } elseif ($maskFields && str_contains(strtolower($contentType), 'application/x-www-form-urlencoded')) {
            parse_str($body, $fields);
            $body = http_build_query($this->maskFields($fields));
        }

        $max = $this->options['body_max_bytes'];
        if ($max > 0 && strlen($body) > $max) {
            $body = mb_strcut($body, 0, $max, 'UTF-8')
                . sprintf('... [truncated, %d bytes total]', $length);
        }

        return $body;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private function maskFields(array $data): array
    {
        $masked = array_map('strtolower', $this->options['masked_fields']);

        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $masked, true)) {
                $data[$key] = self::MASK;
            } elseif (is_array($value)) {
                $data[$key] = $this->maskFields($value);
            }
        }

        return $data;
    }
}
