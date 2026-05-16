<?php
declare(strict_types=1);

/**
 * Job Management Class
 * Handles lifecycle of background tasks and their associated events.
 */
class Job
{
    private int $jobId;
    private array $data;
    private array $logBuffer = [];
    private int $lastLogFlush = 0;
    private array $stepStartTimes = [];

    private const STATUS_PENDING = 'pending';
    private const STATUS_RUNNING = 'running';
    private const STATUS_CANCELLING = 'cancelling';
    private const STATUS_CANCELLED = 'cancelled';
    private const STATUS_SUCCESS = 'success';
    private const STATUS_ERROR = 'error';

    public function __construct(int $jobId)
    {
        $this->jobId = $jobId;
        $this->load();
    }

    private function load(): void
    {
        $pdo = DB::conn();
        $stmt = $pdo->prepare("SELECT * FROM jobs WHERE id = ?");
        $stmt->execute([$this->jobId]);
        $data = $stmt->fetch();
        if (!$data) {
            throw new Exception("Job #{$this->jobId} not found");
        }
        $this->data = $data;
    }

    /**
     * Create a new job record
     */
    public static function create(int $userId, string $type, ?int $serverId = null, array $payload = []): self
    {
        $pdo = DB::conn();
        $stmt = $pdo->prepare("
            INSERT INTO jobs (user_id, server_id, type, payload, status) 
            VALUES (?, ?, ?, ?, 'pending')
        ");
        $stmt->execute([$userId, $serverId, $type, json_encode($payload)]);
        
        return new self((int)$pdo->lastInsertId());
    }

    /**
     * Centralized state transition with validation
     */
    private function transitionTo(string $newStatus, ?array $result = null): bool
    {
        if ($this->data['status'] === $newStatus) {
            return true;
        }

        $currentStatus = $this->data['status'];
        
        $allowed = [
            self::STATUS_PENDING => [self::STATUS_RUNNING, self::STATUS_CANCELLED, self::STATUS_ERROR],
            self::STATUS_RUNNING => [self::STATUS_SUCCESS, self::STATUS_ERROR, self::STATUS_CANCELLING],
            self::STATUS_CANCELLING => [self::STATUS_CANCELLED, self::STATUS_ERROR],
            self::STATUS_SUCCESS => [],
            self::STATUS_ERROR => [self::STATUS_RUNNING],
            self::STATUS_CANCELLED => [],
        ];

        if (!in_array($newStatus, $allowed[$currentStatus] ?? [])) {
            Logger::warning("Illegal job transition: $currentStatus -> $newStatus", ['job_id' => $this->jobId]);
            return false;
        }

        $pdo = DB::conn();
        $sql = "UPDATE jobs SET status = ?, updated_at = NOW()";
        $params = [$newStatus];

        if ($newStatus === self::STATUS_RUNNING && !$this->data['started_at']) {
            $sql .= ", started_at = NOW()";
        }

        if (in_array($newStatus, [self::STATUS_SUCCESS, self::STATUS_ERROR, self::STATUS_CANCELLED])) {
            $sql .= ", completed_at = NOW()";
        }

        if ($result !== null) {
            $sql .= ", result = ?";
            $params[] = json_encode($result);
        }

        $sql .= " WHERE id = ?";
        $params[] = $this->jobId;

        $pdo->prepare($sql)->execute($params);
        $this->data['status'] = $newStatus;

        // Cleanup: If job is finished, detach it from the server record
        if (in_array($newStatus, [self::STATUS_SUCCESS, self::STATUS_ERROR, self::STATUS_CANCELLED]) && !empty($this->data['server_id'])) {
            $pdo->prepare("UPDATE vpn_servers SET current_job_id = NULL WHERE id = ? AND current_job_id = ?")
                ->execute([$this->data['server_id'], $this->jobId]);
        }
        
        return true;
    }

    /**
     * Mark job as running
     */
    public function start(): void
    {
        if ($this->transitionTo(self::STATUS_RUNNING)) {
            $this->emit('job.started', "Job '{$this->data['type']}' started", ['job_id' => $this->jobId]);
        }
    }

    /**
     * Mark job as finished successfully
     */
    public function success(array $result = []): void
    {
        if ($this->transitionTo(self::STATUS_SUCCESS, $result)) {
            $this->emit('job.success', "Job completed successfully", $result);
        }
    }

    /**
     * Mark job as failed
     */
    public function fail(string $error): void
    {
        if ($this->transitionTo(self::STATUS_ERROR, ['error' => $error])) {
            $this->emit('job.error', "Job failed: $error", ['error' => $error], 'error');
        }
    }

    /**
     * Request job cancellation (cooperative)
     */
    public function requestCancel(): void
    {
        if ($this->transitionTo(self::STATUS_CANCELLING)) {
            $this->emit('job.cancelling', "User requested cancellation...", [], 'warning');
        }
    }

    /**
     * Finalize cancellation
     */
    public function cancel(): void
    {
        if ($this->transitionTo(self::STATUS_CANCELLED)) {
            $this->emit('job.cancelled', "Job was cancelled", [], 'warning');
        }
    }

    /**
     * Check if job should stop (Cooperative Abort)
     */
    public function isCancelled(): bool
    {
        // Re-load status from DB to get the latest signal from the user/UI
        $pdo = DB::conn();
        $stmt = $pdo->prepare("SELECT status FROM jobs WHERE id = ?");
        $stmt->execute([$this->jobId]);
        $status = $stmt->fetchColumn();
        
        return in_array($status, [self::STATUS_CANCELLING, self::STATUS_CANCELLED]);
    }

    /**
     * Heartbeat: Touch the database to prove the worker is still alive
     * This prevents 'Zombie Jobs' if a worker crashes during a long step.
     */
    public function heartbeat(): void
    {
        // Only touch DB every 15 seconds to avoid write amplification
        if (time() - $this->lastLogFlush < 15) return;
        
        $pdo = DB::conn();
        $pdo->prepare("UPDATE jobs SET updated_at = NOW() WHERE id = ?")
            ->execute([$this->jobId]);
        
        $this->lastLogFlush = time();
    }

    /**
     * Track the start of a timed step
     */
    public function startStep(string $step): void
    {
        $this->stepStartTimes[$step] = microtime(true);
    }

    /**
     * Get duration of a step in milliseconds
     */
    public function endStep(string $step): int
    {
        if (!isset($this->stepStartTimes[$step])) {
            return 0;
        }
        $duration = (int)((microtime(true) - $this->stepStartTimes[$step]) * 1000);
        unset($this->stepStartTimes[$step]);
        return $duration;
    }

    /**
     * Emit a structured event for this job
     * Persistent: Saved to DB and broadcast to WS
     */
    public function emit(string $type, string $message, array $payload = [], string $level = 'info', bool $persist = true): void
    {
        $message = $this->sanitize($message);
        $pdo = DB::conn();

        if ($persist) {
            $stmt = $pdo->prepare("
                INSERT INTO job_events (job_id, type, level, message, payload) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $this->jobId,
                $type,
                $level,
                $message,
                json_encode($payload)
            ]);
            $eventId = $pdo->lastInsertId();
            
            // Every persistent event also acts as a heartbeat
            $pdo->prepare("UPDATE jobs SET updated_at = NOW() WHERE id = ?")
                ->execute([$this->jobId]);
        } else {
            $eventId = null;
        }

        // Broadcast to WebSockets via EventBus
        if (class_exists('EventBus')) {
            EventBus::publish("job:{$this->jobId}", [
                'id' => (int)$eventId,
                'job_id' => $this->jobId,
                'v' => 1, // Protocol Version
                'type' => $type,
                'level' => $level,
                'message' => $message,
                'payload' => $payload,
                'timestamp' => date('c'),
                'persistent' => $persist
            ]);
        }
    }

    /**
     * Log a raw command output line
     * Volatile: High-frequency logs (e.g. build output) should often skip DB persistence
     */
    public function log(string $message, array $ctx = [], bool $persist = false): void
    {
        // Throttling: If message is huge, chunk it or cap it
        if (strlen($message) > 10000) {
            $message = substr($message, 0, 10000) . "... [TRUNCATED]";
        }

        $this->emit('log', $message, $ctx, 'debug', $persist);
    }

    /**
     * Strip ANSI escape sequences, normalize line endings, and redact secrets
     */
    private function sanitize(string $text): string
    {
        // 1. Strip ANSI colors and cursor movements
        $pattern = '/\x1b\[[0-9;]*[mGJKHF]/';
        $text = preg_replace($pattern, '', $text);
        
        // 2. Redact sensitive patterns
        $secrets = [
            '/(sshpass\s+-p\s+[\'"]?)([^\s\'"]+)/i' => '$1[REDACTED]', // SSH Passwords
            '/(password\s*[:=]\s*[\'"]?)([^\s\'",]+)/i' => '$1[REDACTED]', // Generic passwords
            '/(-----BEGIN [A-Z ]+-----[\s\S]+?-----END [A-Z ]+-----)/' => '[PRIVATE KEY REDACTED]', // SSH Keys
            '/(token\s*[:=]\s*[\'"]?)([a-z0-9._-]{10,})/i' => '$1[REDACTED]' // Tokens
        ];
        $text = preg_replace(array_keys($secrets), array_values($secrets), $text);

        // 3. Redact server password if it appears
        if (!empty($this->data['payload'])) {
            $payload = json_decode($this->data['payload'], true);
            if (!empty($payload['password'])) {
                $text = str_replace($payload['password'], '[SERVER_PWD_REDACTED]', $text);
            }
        }

        // 4. Normalize line endings and strip non-printable chars
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[[:cntrl:]&&[^\n\t]]/', '', $text);
        
        return trim($text);
    }

    /**
     * Clean up old job data to prevent DB bloat
     * Recommended: Call from cron once a day
     */
    public static function prune(int $eventRetentionDays = 7, int $jobRetentionDays = 30): int
    {
        $pdo = DB::conn();
        
        // Remove old events
        $stmt1 = $pdo->prepare("DELETE FROM job_events WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
        $stmt1->execute([$eventRetentionDays]);
        $eventsRemoved = $stmt1->rowCount();

        // Remove old completed jobs (and their events via CASCADE)
        $stmt2 = $pdo->prepare("
            DELETE FROM jobs 
            WHERE status IN ('success', 'error', 'cancelled') 
            AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt2->execute([$jobRetentionDays]);
        $jobsRemoved = $stmt2->rowCount();

        return $jobsRemoved;
    }

    /**
     * Retrieve events for this job
     */
    public function getEvents(int $limit = 1000): array
    {
        $pdo = DB::conn();
        $stmt = $pdo->prepare("
            SELECT * FROM job_events 
            WHERE job_id = ? 
            ORDER BY id ASC 
            LIMIT ?
        ");
        $stmt->execute([$this->jobId, $limit]);
        return $stmt->fetchAll();
    }

    public function getId(): int
    {
        return $this->jobId;
    }

    public function getData(): array
    {
        return $this->data;
    }
}
