<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Monolog\Logger as MonologLogger;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\JsonFormatter;

/**
 * Control-Plane Logger Service
 */
class Logger
{
    private static array $instances = [];
    private static string $logDir = '/var/log/nk-panel';

    /**
     * Get a channel-specific logger instance
     */
    public static function channel(string $channel = 'system'): MonologLogger
    {
        if (!isset(self::$instances[$channel])) {
            self::$instances[$channel] = self::createLogger($channel);
        }
        return self::$instances[$channel];
    }

    /**
     * Create a new logger instance with rotating JSON file handler
     */
    private static function createLogger(string $channel): MonologLogger
    {
        if (!is_dir(self::$logDir)) {
            @mkdir(self::$logDir, 0755, true);
        }

        $logger = new MonologLogger($channel);
        $filename = self::getFilenameForChannel($channel);
        
        // Keep 14 days of logs
        $fileHandler = new RotatingFileHandler(self::$logDir . '/' . $filename, 14, MonologLogger::DEBUG);
        $fileHandler->setFormatter(new JsonFormatter());
        
        // Also log to stdout for Docker
        $streamHandler = new StreamHandler('php://stdout', MonologLogger::DEBUG);
        $streamHandler->setFormatter(new JsonFormatter());
        
        $logger->pushHandler($fileHandler);
        $logger->pushHandler($streamHandler);
        
        return $logger;
    }

    private static function getFilenameForChannel(string $channel): string
    {
        return match ($channel) {
            'ssh' => 'ssh.log',
            'deployments' => 'deployments.log',
            'control-plane' => 'control-plane.log',
            'error' => 'error.log',
            default => $channel . '.log',
        };
    }

    /**
     * Convenience method for general system info
     */
    public static function info(string $message, array $context = []): void
    {
        self::channel('system')->info($message, $context);
    }

    /**
     * Convenience method for general system errors
     */
    public static function error(string $message, array $context = []): void
    {
        self::channel('error')->error($message, $context);
    }

    /**
     * Convenience method for general system warnings
     */
    public static function warning(string $message, array $context = []): void
    {
        self::channel('system')->warning($message, $context);
    }
}
