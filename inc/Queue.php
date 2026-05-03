<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/Logger.php';

use Pheanstalk\Pheanstalk;
use Pheanstalk\Values\TubeName;

/**
 * Control-Plane Queue Service
 */
class Queue
{
    private static ?Pheanstalk $instance = null;

    private static function getConnection(): Pheanstalk
    {
        if (self::$instance === null) {
            $host = getenv('QUEUE_HOST') ?: 'beanstalkd';
            $port = (int)(getenv('QUEUE_PORT') ?: 11300);
            
            self::$instance = Pheanstalk::create($host, $port);
        }
        return self::$instance;
    }

    /**
     * Push a job to a specific tube
     * 
     * @param string $tube The tube name (e.g. 'deployments')
     * @param array $payload Structured payload (must contain 'type')
     */
    public static function push(string $tube, array $payload): void
    {
        if (empty($payload['type'])) {
            throw new InvalidArgumentException("Job payload must contain a 'type'");
        }

        try {
            $pheanstalk = self::getConnection();
            $pheanstalk->useTube(new TubeName($tube));
            $pheanstalk->put(json_encode($payload));
            
            Logger::channel('control-plane')->info('Job queued', [
                'tube' => $tube,
                'payload' => $payload
            ]);
        } catch (\Throwable $e) {
            Logger::error('Failed to queue job', [
                'tube' => $tube,
                'payload' => $payload,
                'error' => $e->getMessage()
            ]);
            throw new Exception("Queue service unavailable: " . $e->getMessage());
        }
    }
}
