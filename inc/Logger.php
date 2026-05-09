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
        try {
            if (!is_dir(self::$logDir)) {
                @mkdir(self::$logDir, 0777, true);
            }

            $logger = new MonologLogger($channel);
            $filename = self::getFilenameForChannel($channel);
            $logPath = self::$logDir . '/' . $filename;
            
            // Keep 14 days of logs. We'll rely on the 777 directory for new files.
            // We pass null for permission to let the OS/start.sh handle it,
            // or 0666 if we are sure we won't trigger a 'chmod' error on an existing file.
            $fileHandler = new RotatingFileHandler($logPath, 14, MonologLogger::DEBUG, true, 0666);
            $fileHandler->setFormatter(new JsonFormatter());
            
            // Also log to stdout for Docker
            $streamHandler = new StreamHandler('php://stdout', MonologLogger::DEBUG);
            $streamHandler->setFormatter(new JsonFormatter());
            
            $logger->pushHandler($fileHandler);
            $logger->pushHandler($streamHandler);

            return $logger;
        } catch (\Throwable $e) {
            // Fallback to error_log to prevent 500 errors
            error_log("Logger failure for channel {$channel}: " . $e->getMessage());
            $logger = new MonologLogger($channel);
            $logger->pushHandler(new \Monolog\Handler\ErrorLogHandler());
            return $logger;
        }
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

    /**
     * Get logs for a specific channel, filtered by context
     */
    public static function getLogs(string $channel, array $filter = [], int $limit = 50): array
    {
        $filename = self::getFilenameForChannel($channel);
        $logFile = self::$logDir . '/' . $filename;
        
        if (!file_exists($logFile)) {
            // Check for rotated files too (simplified)
            $logFile = self::$logDir . '/' . $filename . '-1';
            if (!file_exists($logFile)) return [];
        }

        $lines = [];
        $fp = fopen($logFile, 'r');
        if (!$fp) return [];

        // Seek to end and read backwards is better, but for simplicity we read last $limit lines
        // Monolog JSON format: {"message":"...","context":{...},"level":200,"level_name":"INFO","channel":"...","datetime":"...","extra":{}}
        
        while (($line = fgets($fp)) !== false) {
            $data = json_decode($line, true);
            if (!$data) continue;

            $match = true;
            foreach ($filter as $key => $value) {
                if (!isset($data['context'][$key]) || $data['context'][$key] != $value) {
                    $match = false;
                    break;
                }
            }

            if ($match) {
                $lines[] = $data;
            }
        }
        fclose($fp);

        return array_slice($lines, -$limit);
    }
}
