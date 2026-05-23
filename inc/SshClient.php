<?php
declare(strict_types=1);

require_once __DIR__ . '/SshConnectionConfig.php';
require_once __DIR__ . '/SshException.php';

class SshClient
{
    /** @var string|null Path to the active ControlMaster socket */
    private ?string $muxSocket = null;

    /** @var bool Whether this instance owns the master connection */
    private bool $muxOwner = false;

    /** @var Job|null Current active background job for tracking */
    private ?Job $currentJob = null;

    private readonly SshConnectionConfig $config;
    private readonly ?int $serverId;

    public function __construct(SshConnectionConfig $config, ?int $serverId = null, ?Job $job = null)
    {
        $this->config = $config;
        $this->serverId = $serverId;
        $this->currentJob = $job;
    }

    /**
     * Destructor — tear down the SSH ControlMaster if we own it.
     */
    public function __destruct()
    {
        $this->closeMux();
    }

    public function setJob(?Job $job): void
    {
        $this->currentJob = $job;
    }

    /**
     * Close the SSH multiplexed master connection if active.
     */
    public function closeMux(): void
    {
        if ($this->muxSocket && $this->muxOwner && file_exists($this->muxSocket)) {
            // Ask the master to exit gracefully
            $exitCmd = sprintf(
                'ssh -o ControlPath=%s -O exit dummy 2>/dev/null',
                escapeshellarg($this->muxSocket)
            );
            @shell_exec($exitCmd);
            @unlink($this->muxSocket);
            $this->muxSocket = null;
            $this->muxOwner = false;
        }
    }

    /**
     * Build common SSH options array.
     */
    private function buildSshOptions(): array
    {
        $port = $this->config->port > 0 ? $this->config->port : 22;

        $opts = [
            '-p ' . $port,
            '-o ConnectTimeout=15',
            '-o ServerAliveInterval=15',
            '-o ServerAliveCountMax=3',
            '-o StrictHostKeyChecking=no',
            '-o UserKnownHostsFile=/dev/null',
            '-o Ciphers=aes128-ctr,aes256-ctr,aes128-gcm@openssh.com,aes256-gcm@openssh.com,chacha20-poly1305@openssh.com',
            '-o MACs=hmac-sha2-256-etm@openssh.com,hmac-sha2-512-etm@openssh.com,umac-128-etm@openssh.com',
            '-o HostKeyAlgorithms=ssh-ed25519,ecdsa-sha2-nistp256,rsa-sha2-512,rsa-sha2-256',
            '-o KexAlgorithms=curve25519-sha256,curve25519-sha256@libssh.org,ecdh-sha2-nistp256,ecdh-sha2-nistp384,ecdh-sha2-nistp521'
        ];

        return $opts;
    }

    /**
     * Build the SSH auth prefix (sshpass or key-based).
     * Returns [prefix_string, extra_ssh_options[], keyPath].
     */
    private function buildSshAuth(): array
    {
        $prefix = '';
        $extra = ['-o LogLevel=ERROR'];
        $keyPath = null;

        // 1. Prepare SSH Key if available
        if (!empty($this->config->privateKey)) {
            $keyPath = tempnam(sys_get_temp_dir(), 'nk_ssh_');
            file_put_contents($keyPath, $this->config->privateKey);
            chmod($keyPath, 0600);
            $extra[] = '-i ' . escapeshellarg($keyPath);
            $extra[] = '-o PubkeyAuthentication=yes';
        } else {
            $extra[] = '-o PubkeyAuthentication=no';
        }

        // 2. Prepare Password (sshpass) if available
        if (!empty($this->config->password)) {
            $prefix = sprintf(
                "SSHPASS='%s' sshpass -e",
                str_replace("'", "'\\''", $this->config->password)
            );
            $extra[] = '-o BatchMode=no';
            
            if (!empty($this->config->privateKey)) {
                $extra[] = '-o PreferredAuthentications=publickey,password';
            } else {
                $extra[] = '-o PreferredAuthentications=password';
            }
        } else {
            $extra[] = '-o BatchMode=yes';
            $extra[] = '-o PreferredAuthentications=publickey';
        }

        return [$prefix, $extra, $keyPath];
    }

    /**
     * Ensure an SSH ControlMaster connection is open.
     * Subsequent commands reuse this connection for near-zero latency.
     */
    private function ensureMux(): void
    {
        $muxDir = '/tmp/ssh_mux';
        if (!is_dir($muxDir)) {
            @mkdir($muxDir, 0700, true);
        }

        // Calculate socket path based on host and port.
        // We remove getmypid() to allow multiple PHP processes (like queue workers)
        // to share the same background master connection.
        $socketPath = $muxDir . '/nk_' . md5($this->config->host . ':' . $this->config->port);

        // If we already have a socket path set but it doesn't match current PID (shouldn't happen with getmypid() but for safety)
        // or if we have a stale file on disk from a previous crashed process with the same PID.
        if (file_exists($socketPath)) {
            // Check if the master is actually alive and responsive
            $checkCmd = sprintf(
                'ssh -o ControlPath=%s -O check dummy 2>&1',
                escapeshellarg($socketPath)
            );
            $checkOut = (string)shell_exec($checkCmd);
            if (stripos($checkOut, 'Master running') !== false) {
                $this->muxSocket = $socketPath;
                return; // Already open and alive
            }
            
            // If we get here, the socket exists but the master is dead or unresponsive
            if (class_exists('Logger')) {
                Logger::warning("Stale SSH Mux socket found, cleaning up", ['socket' => $socketPath, 'output' => trim($checkOut)]);
            }
            
            // Try to kill the master process officially, then force unlink
            $killCmd = sprintf('ssh -o ControlPath=%s -O exit dummy 2>&1', escapeshellarg($socketPath));
            @shell_exec($killCmd);
            @unlink($socketPath);
        }

        $this->muxSocket = $socketPath;

        [$authPrefix, $authOpts, $keyFile] = $this->buildSshAuth();
        $sshOpts = array_merge($this->buildSshOptions(), $authOpts, [
            '-o ControlMaster=yes',
            '-o ControlPersist=600',
            sprintf('-o ControlPath=%s', escapeshellarg($this->muxSocket)),
            '-N',
            '-f', // Go to background
        ]);

        $cmd = trim(sprintf(
            '%s ssh %s %s@%s',
            $authPrefix,
            implode(' ', $sshOpts),
            escapeshellarg($this->config->username),
            escapeshellarg($this->config->host)
        ));

        $muxOut = shell_exec($cmd . ' 2>&1');
        
        // Clean up temp key file if one was created
        if ($keyFile && file_exists($keyFile)) {
            @unlink($keyFile);
        }
        
        // Give the master a moment to establish and create the socket file
        for ($i = 0; $i < 10; $i++) {
            if (file_exists($this->muxSocket)) break;
            usleep(100000); // 100ms * 10 = 1s max wait
        }

        if (!file_exists($this->muxSocket)) {
            if ($muxOut && trim($muxOut)) {
                if (class_exists('Logger')) {
                    Logger::warning("SSH Mux establishment failed", [
                        'host' => $this->config->host,
                        'output' => trim($muxOut)
                    ]);
                }
            }
            $this->muxSocket = null;
            $this->muxOwner = false;
            // Fall back to per-command connections
        } else {
            $this->muxOwner = true;
        }
    }

    /**
     * Test the SSH connection.
     * 
     * @return bool True if successful
     * @throws SshConnectionException on failure
     */
    public function testConnection(): bool
    {
        // Try to establish the multiplexed master — this validates the connection
        try {
            $this->ensureMux();
            if ($this->muxSocket) {
                return true;
            }
        } catch (\Exception $e) {
            // If ensureMux throws, use that error
            throw new SshConnectionException("SSH Connection failed: " . $e->getMessage(), 0, $e);
        }

        // Fallback: simple connectivity test if Mux didn't work but didn't throw
        [$authPrefix, $authOpts, $keyFile] = $this->buildSshAuth();
        $sshOpts = array_merge($this->buildSshOptions(), $authOpts, [
            '-o ConnectTimeout=10',
        ]);

        $testCommand = sprintf(
            "%s ssh %s %s@%s 'echo test' 2>&1",
            $authPrefix,
            implode(' ', $sshOpts),
            escapeshellarg($this->config->username),
            escapeshellarg($this->config->host)
        );

        $result = shell_exec($testCommand);

        if ($keyFile && file_exists($keyFile)) {
            @unlink($keyFile);
        }

        $out = trim((string)$result);
        if ($out !== 'test') {
            throw new SshConnectionException("SSH test failed. Output: " . substr($out, 0, 200));
        }

        return true;
    }

    /**
     * Execute command on remote server and return output.
     * Uses SSH multiplexing when available for near-zero latency.
     * Throws an exception if the command exits non-zero.
     * 
     * @throws RemoteCommandException
     */
    public function executeCommand(string $command, bool $sudo = false, bool $checkExit = false, bool $silent = false, int $timeout = 60): string
    {
        if ($sudo && strtolower($this->config->username) !== 'root') {
            $command = sprintf(
                "echo %s | sudo -S sh -c %s",
                escapeshellarg($this->config->password ?? ''),
                escapeshellarg($command)
            );
        }

        // Create a scrubbed version of the command for logging
        $loggedCommand = $command;
        if (!empty($this->config->password)) {
            $loggedCommand = str_replace($this->config->password, '********', $loggedCommand);
        }
        // Scrub common sensitive patterns (private keys, etc)
        $loggedCommand = preg_replace('/echo [\'"].*[\'"] \| base64 -d/', 'echo [REDACTED_BASE64] | base64 -d', $loggedCommand);

        // Capture both stdout and exit code
        $wrappedCommand = $command . '; echo "__EXIT_CODE__:$?"';
        $escapedCommand = escapeshellarg($wrappedCommand);

        $maxRetries = 2;
        $attempt = 0;
        $rawOutput = '';

        while ($attempt < $maxRetries) {
            $attempt++;
            
            // Try multiplexed connection first
            $this->ensureMux();

            if ($this->muxSocket && file_exists($this->muxSocket)) {
                $sshCommand = sprintf(
                    'ssh -o ControlPath=%s %s %s@%s %s 2>&1',
                    escapeshellarg($this->muxSocket),
                    implode(' ', $this->buildSshOptions()),
                    escapeshellarg($this->config->username),
                    escapeshellarg($this->config->host),
                    "timeout {$timeout}s sh -c " . escapeshellarg($escapedCommand)
                );
            } else {
                [$authPrefix, $authOpts, $keyFile] = $this->buildSshAuth();
                $sshOpts = array_merge($this->buildSshOptions(), $authOpts);

                $sshCommand = sprintf(
                    '%s ssh %s %s@%s %s 2>&1',
                    $authPrefix,
                    implode(' ', $sshOpts),
                    escapeshellarg($this->config->username),
                    escapeshellarg($this->config->host),
                    "timeout {$timeout}s sh -c " . escapeshellarg($escapedCommand)
                );
            }

            if (!$silent && $attempt === 1 && class_exists('Logger')) {
                Logger::channel('ssh')->info('Executing command', [
                    'server_id' => $this->serverId,
                    'host' => $this->config->host,
                    'command' => $loggedCommand
                ]);
            }

            $rawOutput = (string)shell_exec($sshCommand);

            if (isset($keyFile) && $keyFile && file_exists($keyFile)) {
                @unlink($keyFile);
            }

            // Check for specific transient errors that warrant a retry
            if (stripos($rawOutput, 'Connection refused') !== false && stripos($rawOutput, 'Control socket') !== false) {
                if (class_exists('Logger')) {
                    Logger::warning("SSH Control socket refused connection, retrying without Mux", ['socket' => $this->muxSocket]);
                }
                if ($this->muxSocket) {
                    @unlink($this->muxSocket);
                    $this->muxSocket = null;
                }
                continue; // Retry
            }
            
            // If we got here, either it succeeded or it's a non-retryable error
            break;
        }

        if (preg_match('/^(.*?)__EXIT_CODE__:(\d+)\s*$/s', $rawOutput, $m)) {
            $output = $m[1];
            $exitCode = (int) $m[2];
        } else {
            $output = $rawOutput;
            $exitCode = 255; 
        }

        if (!$silent && class_exists('Logger')) {
            Logger::channel('ssh')->info('Command result', [
                'server_id' => $this->serverId,
                'exit_code' => $exitCode,
                'output' => substr(trim($output), 0, 1000)
            ]);
        }

        if ($checkExit && $exitCode !== 0) {
            throw new RemoteCommandException("Remote command failed (exit {$exitCode}): " . trim(substr($output, -500)));
        }

        // Stream output to Job if active
        if ($this->currentJob) {
            $this->currentJob->log($output, [
                'command' => $loggedCommand,
                'exit_code' => $exitCode
            ]);
        }

        return $output;
    }

    /**
     * Upload a local file to a remote path.
     */
    public function uploadFile(string $localPath, string $remotePath): void
    {
        if (!file_exists($localPath)) {
            throw new \InvalidArgumentException("Local file does not exist: {$localPath}");
        }
        $content = file_get_contents($localPath);
        if ($content === false) {
            throw new \RuntimeException("Failed to read local file: {$localPath}");
        }
        $base64 = base64_encode($content);
        $cmd = sprintf("echo %s | base64 -d > %s", escapeshellarg($base64), escapeshellarg($remotePath));
        $this->executeCommand($cmd, false, true);
    }
}
