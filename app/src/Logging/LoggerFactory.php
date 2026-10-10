<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Monolog\Processor\UidProcessor;

/**
 * Monolog setup following https://seldaek.github.io/monolog/doc/01-usage.html
 *
 *  - one Logger with a handler stack (Core Concepts / Configuring a logger);
 *  - a Formatter attached to the handler (Customizing the log format);
 *  - Processors pushed on the logger to enrich every record (Using processors);
 *  - extra channels created with Logger::withName() - they share the handler,
 *    so all channels are written to one file and can be filtered with grep
 *    (Leveraging channels).
 */
final class LoggerFactory
{
    private readonly UidProcessor $uid;

    /** @param array<string, mixed> $config config/logging.php */
    public function __construct(private readonly array $config)
    {
        $this->uid = new UidProcessor(16);
    }

    /** Unique id of the current request; it is added to every record as extra.uid. */
    public function requestId(): string
    {
        return $this->uid->getUid();
    }

    /** Creates the main logger (channel $channel) writing to {path}/app-YYYY-MM-DD.log */
    public function make(string $channel = 'app'): Logger
    {
        // Handler: daily rotation, keeps the last max_files files.
        $handler = new RotatingFileHandler(
            filename: $this->config['path'] . '/app.log',
            maxFiles: (int) $this->config['max_files'],
            level: Level::fromName($this->config['level']),
            filePermission: 0664,
            useLocking: true,
        );

        // Formatter: attached to the handler.
        $handler->setFormatter(new JsonFormatter());

        $logger = new Logger($channel);
        $logger->pushHandler($handler);

        // Processors: add extra data to every record, of every channel derived via withName().
        $logger->pushProcessor(new PsrLogMessageProcessor()); // {placeholders}
        $logger->pushProcessor($this->uid);                                                 // extra.uid

        return $logger;
    }
}
