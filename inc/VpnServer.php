<?php
declare(strict_types=1);

/**
 * VPN Server Management Class
 * Handles deployment and management of Amnezia VPN servers
 * Based on amnezia_deploy_v2.php
 */
class VpnServer
{
    private readonly ?int $serverId;
    private ?array $data = null;

    /** @var string|null Path to the active ControlMaster socket */
    private ?string $muxSocket = null;

    /** @var bool Whether this instance owns the master connection */
    private bool $muxOwner = false;

    public function __construct(?int $serverId = null)
    {
        $this->serverId = $serverId;
        if ($serverId) {
            $this->load();
        }
    }

    /**
     * Destructor — tear down the SSH ControlMaster if we own it.
     */
    public function __destruct()
    {
        $this->closeMux();
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
        $opts = [
            '-o ConnectTimeout=15',
            '-o ServerAliveInterval=5',
            '-o ServerAliveCountMax=3',
            '-o StrictHostKeyChecking=no',
            '-o UserKnownHostsFile=/dev/null',
            '-o Ciphers=aes128-ctr,aes128-gcm@openssh.com,chacha20-poly1305@openssh.com,chacha20-poly1305@openssh.com',
            '-o MACs=hmac-sha2-256-etm@openssh.com,hmac-sha2-512-etm@openssh.com,umac-128-etm@openssh.com',
            '-o HostKeyAlgorithms=ssh-ed25519-cert-v01@openssh.com,ssh-ed25519,ecdsa-sha2-nistp256-cert-v01@openssh.com,ecdsa-sha2-nistp256,rsa-sha2-512-cert-v01@openssh.com,rsa-sha2-256-cert-v01@openssh.com,rsa-sha2-512,rsa-sha2-256',
            '-o KexAlgorithms=curve25519-sha256,curve25519-sha256@libssh.org,ecdh-sha2-nistp256,ecdh-sha2-nistp384,ecdh-sha2-nistp521,diffie-hellman-group-exchange-sha256,diffie-hellman-group16-sha512,diffie-hellman-group18-sha512,diffie-hellman-group14-sha256'
        ];

        return $opts;
    }

    /**
     * Build the SSH auth prefix (sshpass or key-based).
     * Returns [prefix_string, extra_ssh_options[]].
     */
    private function buildSshAuth(): array
    {
        $prefix = '';
        $extra = ['-o LogLevel=ERROR'];
        $keyPath = null;

        // 1. Prepare SSH Key if available
        if (!empty($this->data['ssh_private_key'])) {
            $keyPath = tempnam(sys_get_temp_dir(), 'nk_ssh_');
            file_put_contents($keyPath, $this->data['ssh_private_key']);
            chmod($keyPath, 0600);
            $extra[] = '-i ' . escapeshellarg($keyPath);
            $extra[] = '-o PubkeyAuthentication=yes';
        } else {
            $extra[] = '-o PubkeyAuthentication=no';
        }

        // 2. Prepare Password (sshpass) if available
        if (!empty($this->data['password'])) {
            $prefix = sprintf(
                "SSHPASS='%s' sshpass -e",
                str_replace("'", "'\\''", $this->data['password'])
            );
            $extra[] = '-o BatchMode=no';
            
            if (!empty($this->data['ssh_private_key'])) {
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

        // Calculate expected socket path based on host, port and current PID
        $socketPath = $muxDir . '/nk_' . md5(($this->data['host'] ?? '') . ':' . ($this->data['port'] ?? '')) . '_' . getmypid();

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
            Logger::warning("Stale SSH Mux socket found, cleaning up", ['socket' => $socketPath, 'output' => trim($checkOut)]);
            
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
            escapeshellarg($this->data['username'] ?? 'root'),
            escapeshellarg($this->data['host'] ?? '')
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
                Logger::warning("SSH Mux establishment failed", [
                    'host' => $this->data['host'] ?? 'unknown',
                    'output' => trim($muxOut)
                ]);
            }
            $this->muxSocket = null;
            $this->muxOwner = false;
            // Fall back to per-command connections
        } else {
            $this->muxOwner = true;
        }
    }

    /**
     * Set server status with transition validation
     */
    public function setStatus(ServerStatus $newStatus): bool
    {
        if (!$this->data) return false;
        
        $currentStatus = $this->getStatus();
        if (!$currentStatus->canTransitionTo($newStatus)) {
            throw new Exception("Invalid status transition from {$currentStatus->value} to {$newStatus->value}");
        }

        $pdo = DB::conn();
        $stmt = $pdo->prepare('UPDATE vpn_servers SET status = ? WHERE id = ?');
        if ($stmt->execute([$newStatus->value, $this->serverId])) {
            $this->data['status'] = $newStatus;
            return true;
        }
        return false;
    }

    /**
     * Load server data from database
     */
    private function load(): void
    {
        $pdo = DB::conn();
        $stmt = $pdo->prepare('SELECT * FROM vpn_servers WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$this->serverId]);
        $data = $stmt->fetch();
        if (!$data) {
            throw new Exception('Server not found or has been deleted');
        }
        $this->data = $data;

        // Map status to Enum
        $this->data['status'] = ServerStatus::tryFrom((string)($this->data['status'] ?? '')) ?? ServerStatus::ERROR;

        // Decode JSON parameters
        if (isset($this->data['awg_params']) && is_string($this->data['awg_params'])) {
            $this->data['awg_params'] = json_decode($this->data['awg_params'], true);
        }
    }

    /**
     * Create new VPN server in database
     */
    public static function create(array $data): int
    {
        $pdo = DB::conn();

        // Validate required fields
        $required = ['user_id', 'name', 'host', 'port', 'username', 'password'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new Exception("Field {$field} is required");
            }
        }

        $stmt = $pdo->prepare('
            INSERT INTO vpn_servers 
            (user_id, name, host, port, username, password, container_name, vpn_port, vpn_subnet, awg_params, status, deployed_at, last_check_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL)
        ');

        $stmt->execute([
            $data['user_id'],
            $data['name'],
            $data['host'],
            $data['port'],
            $data['username'],
            $data['password'],
            $data['container_name'] ?? 'nk-awg-v2',
            $data['vpn_port'] ?? NULL,
            $data['vpn_subnet'] ?? '10.8.1.0/24',
            json_encode(['mimicry_type' => $data['mimicry_type'] ?? 'quic']),
            ServerStatus::DEPLOYING->value
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Deploy or Re-deploy VPN server using existing data if available
     */
    public function deploy(bool $forceRebuild = false): array
    {
        if (!$this->data) {
            throw new Exception('Server not loaded');
        }

        // Disable PHP timeout for long builds (Go compilation takes several minutes)
        ini_set('max_execution_time', '0');

        $pdo = DB::conn();
        $errors = [];

        try {
            // Update status to deploying
            $pdo->prepare('UPDATE vpn_servers SET status = ? WHERE id = ?')
                ->execute([ServerStatus::DEPLOYING->value, $this->serverId]);
            $this->data['status'] = ServerStatus::DEPLOYING;

            Logger::channel('deployments')->info("Starting deployment process", ['server_id' => $this->serverId, 'host' => $this->data['host']]);

            // Test SSH connection
            Logger::channel('deployments')->info("Testing SSH connection...", ['server_id' => $this->serverId]);
            if (!$this->testConnection()) {
                throw new Exception('SSH connection failed');
            }
            Logger::channel('deployments')->info("SSH connection successful", ['server_id' => $this->serverId]);

            // Prepare system (sudo, curl, etc)
            Logger::channel('deployments')->info("Preparing remote system environment...", ['server_id' => $this->serverId]);
            $this->prepareSystem();

            // Install Docker if needed
            Logger::channel('deployments')->info("Checking Docker installation...", ['server_id' => $this->serverId]);
            $this->installDocker();

            // Install AmneziaWG kernel module on host for high performance
            Logger::channel('deployments')->info("Installing AmneziaWG kernel module (this may take a few minutes)...", ['server_id' => $this->serverId]);
            $this->installKernelModule();

            // IDEMPOTENCY: Check if container already exists and try to adopt it
            $containerName = $this->data['container_name'] ?? 'nk-awg-v2';
            $containerRunning = !empty(trim($this->executeCommand("docker ps --filter name={$containerName} --format '{{.Names}}'")));
            
            if ($containerRunning && !$forceRebuild) {
                Logger::channel('deployments')->info("Existing AWG container '{$containerName}' detected. Initiating adoption...", ['server_id' => $this->serverId]);
                try {
                    $importedData = $this->detectAndImportExistingConfig();
                    if ($importedData) {
                        Logger::channel('deployments')->info("Successfully adopted existing configuration and imported clients.", ['server_id' => $this->serverId]);
                        $this->load();
                        return [
                            'success' => true,
                            'vpn_port' => (int)$this->data['vpn_port'],
                            'public_key' => $this->data['server_public_key']
                        ];
                    }
                } catch (Exception $e) {
                    Logger::channel('deployments')->warning("Failed to adopt existing config: " . $e->getMessage(), ['server_id' => $this->serverId]);
                    // Continue with normal deployment if adoption fails
                }
            }

            // Create directories
            Logger::channel('deployments')->info("Creating deployment directories...", ['server_id' => $this->serverId]);
            $this->executeCommand('mkdir -p /opt/amnezia/nk-awg-v2', true, true);

            // Use existing VPN port if available, otherwise find a free one
            $vpnPort = (int) ($this->data['vpn_port'] ?? 0);
            if ($vpnPort <= 0) {
                $vpnPort = $this->findFreeUdpPort();
            }

            // Create Dockerfile
            Logger::channel('deployments')->info("Generating Dockerfile...", ['server_id' => $this->serverId]);
            $this->createDockerfile();

            // Create start script
            Logger::channel('deployments')->info("Generating start scripts...", ['server_id' => $this->serverId]);
            $this->createStartScript();

            // Build Docker image
            Logger::channel('deployments')->info("Building Docker image (compiling AmneziaWG-go)...", ['server_id' => $this->serverId]);
            $this->buildDockerImage($forceRebuild);

            // Run container
            Logger::channel('deployments')->info("Starting VPN container...", ['server_id' => $this->serverId]);
            $this->runContainer($vpnPort);

            // Allow UDP port on host
            $this->executeCommand("iptables -A INPUT -p udp --dport {$vpnPort} -j ACCEPT 2>/dev/null || true", true);

            // Enable IP forwarding on host
            $this->executeCommand("sysctl -w net.ipv4.ip_forward=1 && echo 'net.ipv4.ip_forward=1' >> /etc/sysctl.conf", true);

            // Initialize server config
            Logger::channel('deployments')->info("Initializing VPN configuration and keys...", ['server_id' => $this->serverId]);
            $keys = $this->initializeServerConfig($vpnPort);

            // Update database with deployment info
            Logger::channel('deployments')->info("Finalizing server activation...", ['server_id' => $this->serverId]);
            $stmt = $pdo->prepare('
                UPDATE vpn_servers 
                SET vpn_port = ?, 
                    server_public_key = ?, 
                    server_private_key = ?,
                    preshared_key = ?, 
                    awg_params = ?,
                    status = ?,
                    deployed_at = NOW(),
                    error_message = NULL
                WHERE id = ?
            ');

            $stmt->execute([
                $vpnPort,
                $keys['public_key'],
                $keys['private_key'],
                $keys['preshared_key'],
                json_encode($keys['awg_params']),
                ServerStatus::ACTIVE->value,
                $this->serverId
            ]);

            // Reload data
            $this->load();

            return [
                'success' => true,
                'vpn_port' => $vpnPort,
                'public_key' => $keys['public_key']
            ];

        } catch (Exception $e) {
            // Update status to error
            $errorMsg = mb_convert_encoding($e->getMessage(), 'UTF-8', 'UTF-8');
            $errorMsg = preg_replace('/[^\x20-\x7E\n]/', '', $errorMsg);
            
            // Clean up potentially long output from exceptions to keep DB column clean
            if (strlen($errorMsg) > 2000) {
                $errorMsg = substr($errorMsg, 0, 1000) . "..." . substr($errorMsg, -500);
            }

            $pdo->prepare('UPDATE vpn_servers SET status = ?, error_message = ? WHERE id = ?')
                ->execute([ServerStatus::ERROR->value, $errorMsg, $this->serverId]);
            $this->data['status'] = ServerStatus::ERROR;
            $this->data['error_message'] = $errorMsg;

            throw $e;
        }
    }

    /**
     * Test SSH connection to server. Throws exception with details on failure.
     */
    private function testConnection(): bool
    {
        // Try to establish the multiplexed master — this validates the connection
        try {
            $this->ensureMux();
            if ($this->muxSocket) {
                return true;
            }
        } catch (Exception $e) {
            // If ensureMux throws, use that error
            throw new Exception("SSH Connection failed: " . $e->getMessage());
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
            escapeshellarg($this->data['username']),
            escapeshellarg($this->data['host'])
        );

        $result = shell_exec($testCommand);

        if ($keyFile && file_exists($keyFile)) {
            unlink($keyFile);
        }

        $out = trim((string)$result);
        if ($out !== 'test') {
            throw new Exception("SSH test failed. Output: " . substr($out, 0, 200));
        }

        return true;
    }

    /**
     * Execute command on remote server and return output.
     * Uses SSH multiplexing when available for near-zero latency.
     * Throws an exception if the command exits non-zero.
     */
    public function executeCommand(string $command, bool $sudo = false, bool $checkExit = false, bool $silent = false): string
    {
        if ($sudo && strtolower($this->data['username'] ?? '') !== 'root') {
            $command = sprintf(
                "echo %s | sudo -S sh -c %s",
                escapeshellarg($this->data['password'] ?? ''),
                escapeshellarg($command)
            );
        }

        // Create a scrubbed version of the command for logging
        $loggedCommand = $command;
        if (isset($this->data['password'])) {
            $loggedCommand = str_replace($this->data['password'], '********', $loggedCommand);
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
                    escapeshellarg($this->data['username'] ?? 'root'),
                    escapeshellarg($this->data['host'] ?? ''),
                    "timeout 60s sh -c " . escapeshellarg($escapedCommand)
                );
            } else {
                [$authPrefix, $authOpts, $keyFile] = $this->buildSshAuth();
                $sshOpts = array_merge($this->buildSshOptions(), $authOpts);

                $sshCommand = sprintf(
                    '%s ssh %s %s@%s %s 2>&1',
                    $authPrefix,
                    implode(' ', $sshOpts),
                    escapeshellarg($this->data['username'] ?? 'root'),
                    escapeshellarg($this->data['host'] ?? ''),
                    "timeout 60s sh -c " . escapeshellarg($escapedCommand)
                );
            }

            if (!$silent && $attempt === 1 && class_exists('Logger')) {
                Logger::channel('ssh')->info('Executing command', [
                    'server_id' => $this->serverId ?? null,
                    'host' => $this->data['host'] ?? null,
                    'command' => $loggedCommand
                ]);
            }

            $rawOutput = (string)shell_exec($sshCommand);

            if (isset($keyFile) && $keyFile && file_exists($keyFile)) {
                @unlink($keyFile);
            }

            // Check for specific transient errors that warrant a retry
            if (stripos($rawOutput, 'Connection refused') !== false && stripos($rawOutput, 'Control socket') !== false) {
                Logger::warning("SSH Control socket refused connection, retrying without Mux", ['socket' => $this->muxSocket]);
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
                'server_id' => $this->serverId ?? null,
                'exit_code' => $exitCode,
                'output' => substr(trim($output), 0, 1000)
            ]);
        }

        if ($checkExit && $exitCode !== 0) {
            throw new Exception("Remote command failed (exit {$exitCode}): " . trim(substr($output, -500)));
        }

        return $output;
    }

    /**
     * Prepare system with basic dependencies (sudo, curl, etc)
     */
    private function prepareSystem(): void
    {
        // Check for apt-get (Debian/Ubuntu)
        $hasApt = $this->executeCommand('which apt-get');
        if (empty(trim($hasApt))) {
            return;
        }

        // Fix potential dpkg interruption
        $this->executeCommand('dpkg --configure -a', true);

        // Install sudo if missing (only if we are root, otherwise we can't install it)
        $hasSudo = $this->executeCommand('which sudo');
        if (empty(trim($hasSudo)) && strtolower($this->data['username'] ?? '') === 'root') {
            $this->executeCommand('apt-get update && apt-get install -y sudo', false);
        }

        // Install curl if missing (needed for Docker installation)
        $hasCurl = $this->executeCommand('which curl');
        if (empty(trim($hasCurl))) {
            $this->executeCommand('apt-get update && apt-get install -y curl ca-certificates', true);
        }
    }

    /**
     * Install AmneziaWG kernel module on remote host (Ubuntu/Debian)
     */
    private function installKernelModule(): void
    {
        // Check if already loaded
        $check = $this->executeCommand('lsmod | grep -c amneziawg');
        if (trim($check) === '1') {
            return; // Already loaded
        }

        // Check for apt-get (Debian/Ubuntu)
        $hasApt = $this->executeCommand('which apt-get');
        if (empty(trim($hasApt))) {
            return; // Not a debian-based system, skip kernel module (will fallback to userspace)
        }

        $this->executeCommand('apt-get install -y gnupg2 ca-certificates dkms', true);

        // Handle XanMod headers
        $uname = $this->executeCommand('uname -r');
        if (stripos($uname, 'xanmod') !== false) {
            // Find specific xanmod headers
            $this->executeCommand('apt-get install -y linux-headers-xanmod-edge || apt-get install -y linux-headers-xanmod-lts || apt-get install -y linux-headers-xanmod', true);
        } else {
            $this->executeCommand('apt-get install -y linux-headers-$(uname -r)', true);
        }

        // Manual PPA addition (Works on both Debian and Ubuntu)
        $this->executeCommand('apt-key adv --keyserver keyserver.ubuntu.com --recv-keys 57290828', true);
        $ppaUrl = "https://ppa.launchpadcontent.net/amnezia/ppa/ubuntu focal main";
        $this->executeCommand("echo \"deb {$ppaUrl}\" | tee /etc/apt/sources.list.d/amnezia.list", true);
        $this->executeCommand("echo \"deb-src {$ppaUrl}\" | tee -a /etc/apt/sources.list.d/amnezia.list", true);

        $this->executeCommand('apt-get update', true);

        // Install amneziawg (DKMS)
        $this->executeCommand('DEBIAN_FRONTEND=noninteractive apt-get install -y amneziawg', true);

        // Load module
        $this->executeCommand('modprobe amneziawg', true);

        // Final verify
        $checkFinal = $this->executeCommand('lsmod | grep -c amneziawg');
        if (trim($checkFinal) !== '1') {
            // Log warning but don't stop deployment as userspace fallback exists
            $pdo = DB::conn();
            $pdo->prepare('UPDATE vpn_servers SET error_message = ? WHERE id = ?')
                ->execute(['Warning: AmneziaWG kernel module failed to install/load. Using slow userspace fallback.', $this->serverId]);
        }
    }

    /**
     * Install Docker on remote server
     */
    private function installDocker(): void
    {
        $dockerVersion = $this->executeCommand('docker --version');
        if (stripos($dockerVersion, 'version') !== false) {
            return; // Docker already installed
        }

        $this->executeCommand('curl -fsSL https://get.docker.com | sh', true, true);
        $this->executeCommand('systemctl enable --now docker', true, true);
    }

    /**
     * Find free UDP port on remote server
     */
    private function findFreeUdpPort(): int
    {
        $out = $this->executeCommand("ss -lun | awk '{print $4}' | grep -oE '[0-9]+$'", false);
        $usedPorts = array_map('intval', explode("\n", trim($out)));
        $usedPorts = array_filter($usedPorts);

        $min = 30000;
        $max = 65000;

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $candidate = random_int($min, $max);
            if (!in_array($candidate, $usedPorts)) {
                return $candidate;
            }
        }

        throw new Exception('Could not find free UDP port');
    }

    /**
     * Create Dockerfile on remote server
     */
    private function createDockerfile(): void
    {
        $dockerfile = <<<DOCKERFILE
# Stage 1: Build amneziawg-go and amneziawg-tools
FROM golang:alpine AS builder
RUN apk add --no-cache git make build-base bash libmnl-dev pkgconfig

ARG AMNEZIAWG_GO_REF=master
ARG AMNEZIAWG_TOOLS_REF=v1.0.20260223

# Build amneziawg-go
RUN git clone --depth 1 --branch \${AMNEZIAWG_GO_REF} https://github.com/amnezia-vpn/amneziawg-go.git /build/amneziawg-go && \
    cd /build/amneziawg-go && \
    make && \
    cp amneziawg-go /usr/local/bin/

# Build amneziawg-tools
RUN git clone --depth 1 --branch \${AMNEZIAWG_TOOLS_REF} https://github.com/amnezia-vpn/amneziawg-tools.git /build/amneziawg-tools && \
    cd /build/amneziawg-tools/src && \
    make && \
    make install PREFIX=/usr

# Stage 2: Final Image
FROM alpine:latest
RUN apk add --no-cache bash iptables iproute2 coreutils dumb-init libmnl

# Copy binaries from builder
COPY --from=builder /usr/local/bin/amneziawg-go /usr/local/bin/
COPY --from=builder /usr/bin/awg /usr/local/bin/
COPY --from=builder /usr/bin/awg-quick /usr/local/bin/

# Create necessary directories
RUN mkdir -p /opt/amnezia/awg /etc/amnezia/awg /var/run/amneziawg

# Copy start script
COPY start.sh /opt/amnezia/start.sh
RUN chmod +x /opt/amnezia/start.sh

WORKDIR /opt/amnezia
ENTRYPOINT [ "dumb-init", "/opt/amnezia/start.sh" ]
DOCKERFILE;

        $base64 = base64_encode(trim($dockerfile));
        $this->executeCommand("echo \"{$base64}\" | base64 -d > /opt/amnezia/nk-awg-v2/Dockerfile", true, true);
    }

    /**
     * Create start script on remote server
     */
    private function createStartScript(): void
    {
        $subnet = $this->data['vpn_subnet'] ?? '10.8.1.0/24';
        $script = <<<BASH
#!/bin/bash
################# старт файла start.sh
echo "Container startup"

# Wait for config if not exists yet
for i in {1..30}; do
    if [ -f /opt/amnezia/awg/wg0.conf ]; then
        break
    fi
    sleep 1
done

# Kill daemons in case of restart
/usr/local/bin/awg-quick down /opt/amnezia/awg/wg0.conf 2>/dev/null || true

# Start WireGuard / AmneziaWG
if [ -f /opt/amnezia/awg/wg0.conf ]; then
    # Check if amneziawg kernel module is available
    if lsmod | grep -q "amneziawg"; then
        echo "AmneziaWG kernel module detected. Using kernel mode for maximum performance."
        unset WG_QUICK_USERSPACE_IMPLEMENTATION
    else
        echo "AmneziaWG kernel module not found. Falling back to userspace amneziawg-go."
        export WG_QUICK_USERSPACE_IMPLEMENTATION=/usr/local/bin/amneziawg-go
    fi
    
    export WG_SUDO=1
    /usr/local/bin/awg-quick up /opt/amnezia/awg/wg0.conf
    echo "VPN service started"
else
    echo "No wg0.conf found, skipping VPN startup"
fi

# Allow traffic on the TUN/WG interface
iptables -A INPUT -i wg0 -j ACCEPT 2>/dev/null || true
iptables -A FORWARD -i wg0 -j ACCEPT 2>/dev/null || true
iptables -A OUTPUT -o wg0 -j ACCEPT 2>/dev/null || true

# Allow forwarding traffic only from the VPN
sysctl -w net.ipv4.ip_forward=1 || echo 'Notice: sysctl ip_forward failed, check host config'
iptables -A FORWARD -i wg0 -o eth0 -s {$subnet} -j ACCEPT 2>/dev/null || true
iptables -A FORWARD -i wg0 -o eth1 -s {$subnet} -j ACCEPT 2>/dev/null || true

# State tracking rules
iptables -A FORWARD -m state --state ESTABLISHED,RELATED -j ACCEPT 2>/dev/null || true

# NAT rules
iptables -t nat -A POSTROUTING -s {$subnet} -o eth0 -j MASQUERADE 2>/dev/null || true
iptables -t nat -A POSTROUTING -s {$subnet} -o eth1 -j MASQUERADE 2>/dev/null || true

# MSS Clamping - CRITICAL for websites loading via VPN
iptables -t mangle -A FORWARD -p tcp --tcp-flags SYN,RST SYN -j TCPMSS --clamp-mss-to-pmtu 2>/dev/null || true

tail -f /dev/null
#################
BASH;

        $base64 = base64_encode(trim($script));
        $this->executeCommand("echo '{$base64}' | base64 -d > /opt/amnezia/nk-awg-v2/start.sh && chmod +x /opt/amnezia/nk-awg-v2/start.sh", true, true);
    }

    /**
     * Build Docker image
     */
    private function buildDockerImage(bool $force = false): void
    {
        $containerName = $this->data['container_name'];

        // Check if image exists
        $imageExists = trim($this->executeCommand("docker image inspect {$containerName} --format='{{.Id}}' 2>/dev/null", true));
        if ($imageExists && !$force) {
            return; // Already exists, skip expensive build
        }

        // Cleanup old container/image
        $this->executeCommand(sprintf(
            "docker stop %s 2>/dev/null || true; docker rm -fv %s 2>/dev/null || true; docker rmi %s 2>/dev/null || true",
            $containerName, $containerName, $containerName
        ), true);

        // Build new image — Go compilation can take 5-10 minutes
        $buildCmd = sprintf(
            'docker build --no-cache --pull -t %s /opt/amnezia/nk-awg-v2 2>&1',
            $containerName
        );
        $buildOutput = $this->executeCommand($buildCmd, true, true);

        // Verify the image was actually created
        $check = trim($this->executeCommand("docker image inspect {$containerName} --format='{{.Id}}' 2>/dev/null", true));
        if (empty($check)) {
            throw new Exception('Docker image build failed. Build output: ' . substr($buildOutput, -1000));
        }
    }

    /**
     * Run Docker container
     */
    private function runContainer(int $vpnPort): void
    {
        require_once __DIR__ . '/DeploymentService.php';
        
        $containerName = $this->data['container_name'];
        $runOptions = sprintf(
            '--privileged --cap-add=NET_ADMIN --cap-add=SYS_MODULE -p %d:%d/udp -v /lib/modules:/lib/modules -e WG_THREADS=4',
            $vpnPort,
            $vpnPort
        );

        DeploymentService::deployDockerContainer($this, $containerName, $containerName, $runOptions);
    }

    /**
     * Get default mimicry presets for AWG 2.0
     */
    public static function getMimicryPresets(): array
    {
        return [
            'none' => [],
            'quic' => [
                'I1' => '<b 0xc700000001><rc 8><t><r 80>',
                'I2' => '<b 0xc000000001><t><rc 12><r 64>'
            ],
            'dns' => [
                'I1' => '<b 0x123401000001000000000000><rd 4><b 0x03636f6d0000010001><t><r 32>'
            ],
            'stun' => [
                'I1' => '<b 0x000100002112a442><r 12><t><r 48>'
            ],
            'sip' => [
                'I1' => '<b 0x4f5054494f4e53207369703a><rc 12><b 0x205349502f322e300d0a><t><r 40>'
            ],
        ];
    }

    /**
     * Initialize server configuration with AWG parameters
     */
    private function initializeServerConfig(int $vpnPort): array
    {
        $containerName = $this->data['container_name'];
        $pdo = DB::conn();

        // 1. Check if we have existing keys in DB
        $privKey = $this->data['server_private_key'] ?? null;
        $pubKey = $this->data['server_public_key'] ?? null;
        $psk = $this->data['preshared_key'] ?? null;
        $awgParams = $this->data['awg_params'] ?? null;
        if (is_string($awgParams)) {
            $awgParams = json_decode($awgParams, true);
        }

        // Sanitize existing parameters: S1-S4 should never be 0 (as per user requirement)
        if ($awgParams) {
            foreach (['S1', 'S2', 'S3', 'S4'] as $sKey) {
                if (isset($awgParams[$sKey]) && (int) $awgParams[$sKey] === 0) {
                    $awgParams[$sKey] = 1;
                }
            }
        }

        // Create directory inside container
        $this->executeCommand("docker exec -i {$containerName} mkdir -p /opt/amnezia/awg", true, true);

        if ($privKey && $pubKey && $psk) {
            // Restore existing keys to container
            $restoreCmd = sprintf(
                "echo %s | base64 -d > /opt/amnezia/awg/server_private.key && " .
                "echo %s | base64 -d > /opt/amnezia/awg/wireguard_server_public_key.key && " .
                "echo %s | base64 -d > /opt/amnezia/awg/wireguard_psk.key",
                escapeshellarg(base64_encode($privKey)),
                escapeshellarg(base64_encode($pubKey)),
                escapeshellarg(base64_encode($psk))
            );
            $this->executeCommand("docker exec -i {$containerName} sh -c " . escapeshellarg($restoreCmd), true, true);
        } else {
            // Generate NEW keys
            $this->executeCommand("docker exec -i {$containerName} sh -c 'cd /opt/amnezia/awg && umask 077 && /usr/local/bin/awg genkey | tee server_private.key | /usr/local/bin/awg pubkey > wireguard_server_public_key.key'", true, true);
            $this->executeCommand("docker exec -i {$containerName} sh -c 'cd /opt/amnezia/awg && /usr/local/bin/awg genpsk > wireguard_psk.key'", true, true);

            $privKey = trim($this->executeCommand("docker exec -i {$containerName} cat /opt/amnezia/awg/server_private.key", true));
            $pubKey = trim($this->executeCommand("docker exec -i {$containerName} cat /opt/amnezia/awg/wireguard_server_public_key.key", true));
            $psk = trim($this->executeCommand("docker exec -i {$containerName} cat /opt/amnezia/awg/wireguard_psk.key", true));
        }

        $this->executeCommand("docker exec -i {$containerName} chmod 600 /opt/amnezia/awg/server_private.key /opt/amnezia/awg/wireguard_psk.key /opt/amnezia/awg/wireguard_server_public_key.key", true, true);

        if (empty($privKey) || empty($pubKey) || empty($psk)) {
            throw new Exception('Key initialization failed.');
        }

        // 2. Use existing AWG params or generate new ones
        // Check for a core parameter like 'Jc' to determine if we need to generate new ones
        if (!$awgParams || !isset($awgParams['Jc'])) {
            $headerRanges = $this->generateNonOverlappingHeaderRanges();
            $mimicry = $this->getDynamicQuicPayloads() ?: $this->getMimicryPreset();
            $jmin = 64;
            $jmax = random_int(max($jmin + 1, 70), 80);

            // Preserve mimicry_type if it was already set
            $mimicryType = $awgParams['mimicry_type'] ?? 'quic';

            $awgParams = array_merge([
                'mimicry_type' => $mimicryType,
                'Jc' => random_int(3, 5),
                'Jmin' => $jmin,
                'Jmax' => $jmax,
                'S1' => rand(1, 64),
                'S2' => rand(1, 64),
                'S3' => rand(1, 64),
                'S4' => rand(1, 32),
                'H1' => $headerRanges['H1'],
                'H2' => $headerRanges['H2'],
                'H3' => $headerRanges['H3'],
                'H4' => $headerRanges['H4']
            ], $mimicry);
        }

        // 3. Create wg0.conf
        $wgConfig = "[Interface]\n";
        $wgConfig .= "PrivateKey = {$privKey}\n";

        // Use .1 as the server IP for the interface
        $subnetBase = preg_replace('/\.\d+\/\d+$/', '', $this->data['vpn_subnet']);
        $wgConfig .= "Address = {$subnetBase}.1/24\n";
        $wgConfig .= "ListenPort = {$vpnPort}\n";
        $wgConfig .= "MTU = 1280\n";

        foreach ($awgParams as $key => $value) {
            if ($value === null || $value === '' || $key === 'mimicry_type')
                continue;
            $wgConfig .= "{$key} = {$value}\n";
        }
        $wgConfig .= "\n";

        $base64 = base64_encode($wgConfig);
        $this->executeCommand("echo \"{$base64}\" | docker exec -i {$containerName} sh -c 'base64 -d > /opt/amnezia/awg/wg0.conf'", true, true);
        $this->executeCommand("docker exec -i {$containerName} chmod 600 /opt/amnezia/awg/wg0.conf", true, true);

        // Create clientsTable
        $this->executeCommand("docker exec -i {$containerName} sh -c 'echo \"[]\" > /opt/amnezia/awg/clientsTable'", true, true);

        // The start.sh script in the container is already looping and waiting for wg0.conf.
        // Wait for wg0 and fail deployment if interface never appears.
        $wgReady = false;
        for ($i = 0; $i < 10; $i++) {
            $check = $this->executeCommand("docker exec -i {$containerName} ip link show wg0 2>/dev/null | grep -c wg0", true);
            if (trim($check) === '1') {
                $wgReady = true;
                break;
            }
            sleep(1);
        }
        if (!$wgReady) {
            $logs = trim($this->executeCommand("docker logs --tail 60 {$containerName} 2>&1", true));
            $show = trim($this->executeCommand("docker exec -i {$containerName} sh -c '/usr/local/bin/awg show 2>&1 || true'", true));
            throw new Exception('wg0 interface failed to start. awg-quick likely rejected config. '
                . 'Container logs: ' . substr($logs, -800) . ' | awg show: ' . substr($show, -400));
        }

        // Apply firewall rules
        $this->executeCommand("docker exec -i {$containerName} sh -c 'iptables -A INPUT -i wg0 -j ACCEPT 2>/dev/null || true'", true);
        $this->executeCommand("docker exec -i {$containerName} sh -c 'iptables -A FORWARD -i wg0 -j ACCEPT 2>/dev/null || true'", true);
        $this->executeCommand("docker exec -i {$containerName} sh -c 'iptables -A OUTPUT -o wg0 -j ACCEPT 2>/dev/null || true'", true);
        $subnet = $this->data['vpn_subnet'] ?: '10.8.1.0/24';
        $this->executeCommand("docker exec -i {$containerName} sh -c 'iptables -A FORWARD -i wg0 -o eth0 -s {$subnet} -j ACCEPT 2>/dev/null || true'", true);
        $this->executeCommand("docker exec -i {$containerName} sh -c 'iptables -t nat -A POSTROUTING -s {$subnet} -o eth0 -j MASQUERADE 2>/dev/null || true'", true);
        $this->executeCommand("docker exec -i {$containerName} sh -c 'iptables -t mangle -A FORWARD -p tcp --tcp-flags SYN,RST SYN -j TCPMSS --clamp-mss-to-pmtu 2>/dev/null || true'", true);

        sleep(2);

        return [
            'public_key' => $pubKey,
            'private_key' => $privKey,
            'preshared_key' => $psk,
            'awg_params' => $awgParams
        ];
    }

    /**
     * Get dynamic QUIC payloads for CPS
     */
    private function getDynamicQuicPayloads(): array
    {
        $filePath = dirname(__DIR__) . '/quic-example.txt';
        if (!file_exists($filePath)) {
            return [];
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $payloads = [];
        foreach ($lines as $line) {
            // Format can be "name: 0xHEX" or just hex (with or without 0x)
            if (preg_match('/:\s*(?:0x)?([0-9a-fA-F]+)/i', $line, $matches)) {
                $payloads[] = $matches[1];
            } elseif (preg_match('/(?:0x)?([0-9a-fA-F]{20,})/i', $line, $matches)) {
                $payloads[] = $matches[1];
            }
        }

        if (empty($payloads)) {
            return [];
        }

        shuffle($payloads);
        $selected = array_slice($payloads, 0, min(2, count($payloads)));

        $params = [];
        for ($i = 0; $i < count($selected); $i++) {
            $key = 'I' . ($i + 1);
            $hex = strtolower(trim($selected[$i]));
            $hex = preg_replace('/^0x/', '', $hex);
            $hex = preg_replace('/[^0-9a-f]/', '', $hex);
            if (strlen($hex) < 20) {
                continue;
            }

            // Keep CPS packets compact to avoid fragmentation / send failures.
            $maxHexChars = 320; // 160 bytes
            $hex = substr($hex, 0, $maxHexChars);
            if ((strlen($hex) % 2) !== 0) {
                $hex = substr($hex, 0, -1);
            }

            $params[$key] = "<b 0x{$hex}><t><r 48>";
        }

        return $params;
    }

    /**
     * Generate random non-overlapping header ranges for H1-H4
     * Each range is within 32-bit unsigned int bounds (0 - 4,294,967,295)
     */
    private function generateNonOverlappingHeaderRanges(): array
    {
        $max32 = 4294967295;
        $ranges = [];
        $cursor = random_int(100000000, 300000000);

        $hKeys = ['H1', 'H2', 'H3', 'H4'];
        foreach ($hKeys as $index => $key) {
            $rangeSize = random_int(64, 4096);
            $remaining = count($hKeys) - $index - 1;

            // Keep enough room for remaining ranges + separators inside uint32 bounds.
            $maxStart = $max32 - (($rangeSize + 50000) * ($remaining + 1));
            if ($cursor > $maxStart) {
                $cursor = max(1, $maxStart);
            }

            $min = $cursor;
            $max = $min + $rangeSize;
            $ranges[$key] = "{$min}-{$max}";

            // Explicit gap to guarantee non-overlap between H1-H4 ranges.
            $cursor = $max + random_int(50000, 5000000);
        }

        return $ranges;
    }

    /**
     * Get mimicry preset based on server data or default
     */
    private function getMimicryPreset(): array
    {
        $params = $this->data['awg_params'] ?? [];
        if (is_string($params)) {
            $params = json_decode($params, true) ?: [];
        }
        $type = $params['mimicry_type'] ?? 'quic';
        $presets = self::getMimicryPresets();
        return $presets[$type] ?? $presets['quic'];
    }

    /**
     * Get server status Enum
     */
    public function getStatus(): ServerStatus
    {
        return $this->data['status'] ?? ServerStatus::ERROR;
    }

    /**
     * Ping the server to check connectivity and measure latency
     */
    public function pingNode(): ?int
    {
        $host = $this->data['host'];
        $startTime = microtime(true);

        $pingResult = false;
        // Simple TCP check instead of ICMP ping to avoid permission issues and firewalls
        $connection = @fsockopen($host, $this->data['port'], $errno, $errstr, 2);

        if (is_resource($connection)) {
            fclose($connection);
            $endTime = microtime(true);
            $latency = (int) (($endTime - $startTime) * 1000);
            return $latency;
        }

        return null;
    }

    /**
     * Update server status and ping in the database
     */
    public function updatePingAndStatus(): bool {
        if (!$this->data) return false;
        
        $host = $this->data['host'];
        $port = $this->data['port'] ?? 22;
        
        $latency = null;
        $newStatus = ServerStatus::STOPPED; // Must be enum from the start for === comparisons and ->value access
        
        // 1. Try standard system ping (ICMP)
        $pingCmd = "ping -c 1 -W 2 " . escapeshellarg($host) . " 2>&1";
        $pingOut = shell_exec($pingCmd);
        
        // Match both "time=XX.X ms" and "min/avg/max = XX/XX/XX" formats
        if ($pingOut && preg_match('/\/([0-9.]+)\/[0-9.]+\/[0-9.]+ ms/', $pingOut, $m)) {
            $latency = (int)round((float)$m[1]);
            $newStatus = ServerStatus::ACTIVE;
        } elseif ($pingOut && preg_match('/time=([0-9.]+)/', $pingOut, $m)) {
            $latency = (int)round((float)$m[1]);
            $newStatus = ServerStatus::ACTIVE;
        }
        
        // 2. Fallback: If ICMP is blocked, use a real TCP handshake via curl (bypassing local proxies)
        if ($newStatus === ServerStatus::STOPPED || $latency === null || $latency <= 1) {
            $tcpCmd = "curl --noproxy '*' -o /dev/null -s -w '%{time_connect}' --connect-timeout 2 " . escapeshellarg($host . ":" . $port);
            $tcpOut = shell_exec($tcpCmd);
            
            if ($tcpOut !== null && is_numeric(trim($tcpOut)) && (float)trim($tcpOut) > 0.0001) {
                $latency = (int)round((float)trim($tcpOut) * 1000);
                $newStatus = ServerStatus::ACTIVE;
            }
        }
        
        // Last resort fallback
        if ($newStatus === ServerStatus::STOPPED) {
            $connection = @fsockopen($host, $port, $errno, $errstr, 1.5);
            if ($connection) {
                fclose($connection);
                $newStatus = ServerStatus::ACTIVE;
                $latency = 1;
            }
        }
        
        // Don't override 'deploying' or 'error' status automatically unless it's now 'active'
        $currentStatus = $this->data['status'];
        if ($newStatus === ServerStatus::ACTIVE || ($currentStatus !== ServerStatus::DEPLOYING->value && $currentStatus !== ServerStatus::ERROR->value)) {
            $pdo = DB::conn();
            $stmt = $pdo->prepare('UPDATE vpn_servers SET status = ?, last_ping_ms = ?, last_check_at = NOW() WHERE id = ?');
            $stmt->execute([$newStatus->value, $latency, $this->serverId]);
            $this->data['status'] = ServerStatus::tryFrom($newStatus->value) ?? ServerStatus::ERROR;
            $this->data['last_ping_ms'] = $latency;
        }
        
        return ($this->data['status'] ?? '') === ServerStatus::ACTIVE->value;
    }

    /**
     * Get all servers for a user
     */
    public static function listByUser(int $userId): array
    {
        $pdo = DB::conn();
        $stmt = $pdo->prepare('
            SELECT s.*, COUNT(CASE WHEN c.deleted_at IS NULL THEN 1 END) as client_count 
            FROM vpn_servers s 
            LEFT JOIN vpn_clients c ON s.id = c.server_id 
            WHERE s.user_id = ? AND s.deleted_at IS NULL
            GROUP BY s.id 
            ORDER BY s.created_at DESC
        ');
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    /**
     * Get all servers (admin only)
     */
    public static function listAll(): array
    {
        $pdo = DB::conn();
        $stmt = $pdo->query('
            SELECT s.*, MAX(u.email) as user_email, COUNT(CASE WHEN c.deleted_at IS NULL THEN 1 END) as client_count 
            FROM vpn_servers s 
            LEFT JOIN users u ON s.user_id = u.id 
            LEFT JOIN vpn_clients c ON s.id = c.server_id 
            WHERE s.deleted_at IS NULL
            GROUP BY s.id 
            ORDER BY s.created_at DESC
        ');
        return $stmt->fetchAll();
    }

    /**
     * Delete server
     */
    public function delete(): bool
    {
        if (!$this->serverId) return false;

        $pdo = DB::conn();
        
        // 1. Mark as deleting in DB
        $stmt = $pdo->prepare('UPDATE vpn_servers SET status = ? WHERE id = ?');
        $stmt->execute([ServerStatus::DELETING->value, $this->serverId]);

        // 2. Queue for remote cleanup
        require_once __DIR__ . '/Queue.php';
        Queue::push('deployments', [
            'type' => 'delete_server',
            'server_id' => $this->serverId
        ]);

        return true;
    }

    /**
     * Perform actual remote resource cleanup (called by worker)
     */
    public function cleanupRemoteResources(): void
    {
        if (!$this->data) return;
        
        $pdo = DB::conn();

        // Stop and remove container
        try {
            $containerName = $this->data['container_name'] ?? 'nk-awg-v2';
            $this->executeCommand("docker stop {$containerName} 2>/dev/null || true", true);
            $this->executeCommand("docker rm -fv {$containerName} 2>/dev/null || true", true);
            $this->executeCommand("rm -rf /opt/amnezia/nk-awg-v2", true);

            // Cleanup firewall
            if (isset($this->data['vpn_port'])) {
                $port = $this->data['vpn_port'];
                $this->executeCommand("iptables -D INPUT -p udp --dport {$port} -j ACCEPT 2>/dev/null || true", true);
            }
        } catch (Exception $e) {
            // Ignore errors during cleanup but log them
            Logger::error("Cleanup failed for server {$this->serverId}: " . $e->getMessage());
        }

        // Mark as deleted in database instead of deleting row
        // Also soft-delete all clients of this server to ensure consistency
        $stmtClients = $pdo->prepare('UPDATE vpn_clients SET deleted_at = NOW(), status = ? WHERE server_id = ? AND deleted_at IS NULL');
        $stmtClients->execute([ClientStatus::DISABLED->value, $this->serverId]);

        // Also soft-delete all proxies of this server
        $stmtProxies = $pdo->prepare('UPDATE http_proxies SET deleted_at = NOW(), status = ? WHERE server_id = ? AND deleted_at IS NULL');
        $stmtProxies->execute(['deleted', $this->serverId]);

        $stmtServer = $pdo->prepare('UPDATE vpn_servers SET deleted_at = NOW(), status = ? WHERE id = ?');
        $stmtServer->execute([ServerStatus::STOPPED->value, $this->serverId]);
    }

    /**
     * Get server data. Status is returned as string for template/API compatibility.
     */
    public function getData(): ?array
    {
        if (!$this->data) return null;
        $data = $this->data;
        if ($data['status'] instanceof ServerStatus) {
            $data['status'] = $data['status']->value;
        }
        $data['container_name'] = $data['container_name'] ?? 'nk-awg-v2';
        return $data;
    }

    /**
     * Create backup of server configuration and all clients
     * 
     * @param int $userId User who creates the backup
     * @param string $backupType Type: 'manual' or 'automatic'
     * @return int Backup ID
     */
    public function createBackup(int $userId, string $backupType = 'manual'): int
    {
        if (!$this->data) {
            throw new Exception('Server not loaded');
        }

        $pdo = DB::conn();
        $backupName = 'backup_' . $this->serverId . '_' . date('Y-m-d_His') . '.json';
        $backupDir = '/var/www/html/backups';
        $backupPath = $backupDir . '/' . $backupName;

        // Create backups directory if not exists
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        try {
            // Get all clients for this server
            $stmt = $pdo->prepare('
                SELECT id, name, client_ip, public_key, private_key, preshared_key, 
                       config, status, expires_at, created_at
                FROM vpn_clients 
                WHERE server_id = ? AND deleted_at IS NULL
            ');
            $stmt->execute([$this->serverId]);
            $clients = $stmt->fetchAll();

            // Prepare backup data
            $backupData = [
                'server' => [
                    'name' => $this->data['name'],
                    'host' => $this->data['host'],
                    'port' => $this->data['port'],
                    'vpn_port' => $this->data['vpn_port'],
                    'vpn_subnet' => $this->data['vpn_subnet'],
                    'container_name' => $this->data['container_name'],
                    'server_public_key' => $this->data['server_public_key'],
                    'preshared_key' => $this->data['preshared_key'],
                    'awg_params' => $this->data['awg_params'],
                ],
                'clients' => $clients,
                'backup_date' => date('Y-m-d H:i:s'),
                'version' => '1.0'
            ];

            // Write backup to file
            $json = json_encode($backupData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            file_put_contents($backupPath, $json);

            $backupSize = filesize($backupPath);

            // Insert backup record
            $stmt = $pdo->prepare('
                INSERT INTO server_backups 
                (server_id, backup_name, backup_path, backup_size, clients_count, backup_type, status, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ');

            $stmt->execute([
                $this->serverId,
                $backupName,
                $backupPath,
                $backupSize,
                count($clients),
                $backupType,
                'completed',
                $userId
            ]);

            return (int) $pdo->lastInsertId();

        } catch (Exception $e) {
            // Mark backup as failed
            if (isset($stmt)) {
                $stmt = $pdo->prepare('
                    INSERT INTO server_backups 
                    (server_id, backup_name, backup_path, backup_type, status, error_message, created_by) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ');

                $stmt->execute([
                    $this->serverId,
                    $backupName,
                    $backupPath,
                    $backupType,
                    'failed',
                    $e->getMessage(),
                    $userId
                ]);
            }

            throw $e;
        }
    }

    /**
     * List all backups for this server
     * 
     * @return array List of backups
     */
    public function listBackups(): array
    {
        if (!$this->data) {
            throw new Exception('Server not loaded');
        }

        $pdo = DB::conn();
        $stmt = $pdo->prepare('
            SELECT b.*, u.name as created_by_name, u.email as created_by_email
            FROM server_backups b
            LEFT JOIN users u ON b.created_by = u.id
            WHERE b.server_id = ?
            ORDER BY b.created_at DESC
        ');
        $stmt->execute([$this->serverId]);
        return $stmt->fetchAll();
    }

    /**
     * Restore server from backup
     * Note: This only restores client configurations to database
     * Server must already be deployed
     * 
     * @param int $backupId Backup ID
     * @return array Restoration results
     */
    public function restoreBackup(int $backupId): array
    {
        if (!$this->data) {
            throw new Exception('Server not loaded');
        }

        if ($this->data['status'] !== ServerStatus::ACTIVE) {
            throw new Exception('Server must be active to restore backup');
        }

        $pdo = DB::conn();

        // Get backup record
        $stmt = $pdo->prepare('SELECT * FROM server_backups WHERE id = ? AND server_id = ?');
        $stmt->execute([$backupId, $this->serverId]);
        $backup = $stmt->fetch();

        if (!$backup) {
            throw new Exception('Backup not found');
        }

        if (!file_exists($backup['backup_path'])) {
            throw new Exception('Backup file not found');
        }

        // Read backup data
        $backupData = json_decode(file_get_contents($backup['backup_path']), true);

        if (!$backupData || !isset($backupData['clients'])) {
            throw new Exception('Invalid backup format');
        }

        $restored = 0;
        $failed = 0;
        $errors = [];

        foreach ($backupData['clients'] as $clientData) {
            try {
                // Check if client already exists by IP
                $stmt = $pdo->prepare('SELECT id FROM vpn_clients WHERE server_id = ? AND client_ip = ?');
                $stmt->execute([$this->serverId, $clientData['client_ip']]);
                $existing = $stmt->fetch();

                if ($existing) {
                    $errors[] = "Client {$clientData['name']} already exists";
                    $failed++;
                    continue;
                }

                // Insert client
                $stmt = $pdo->prepare('
                    INSERT INTO vpn_clients 
                    (server_id, user_id, name, client_ip, public_key, private_key, preshared_key, 
                     config, status, expires_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ');

                $stmt->execute([
                    $this->serverId,
                    $this->data['user_id'],
                    $clientData['name'],
                    $clientData['client_ip'],
                    $clientData['public_key'],
                    $clientData['private_key'],
                    $clientData['preshared_key'],
                    $clientData['config'],
                    ServerStatus::ACTIVE->value, // Restore as active since we're adding it to the server
                    $clientData['expires_at']
                ]);

                // Add client to server container
                VpnClient::addClientToServer($this->data, $clientData['public_key'], $clientData['client_ip']);

                $restored++;

            } catch (Exception $e) {
                $failed++;
                $errors[] = "Failed to restore {$clientData['name']}: " . $e->getMessage();
            }
        }

        return [
            'success' => true, // Always success if process completed
            'restored' => $restored,
            'failed' => $failed,
            'total' => count($backupData['clients']),
            'errors' => $errors,
            'message' => $restored > 0 ? "Restored $restored clients" : "No clients restored"
        ];
    }

    /**
     * Delete backup
     * 
     * @param int $backupId Backup ID
     * @return bool Success
     */
    public static function deleteBackup(int $backupId): bool
    {
        $pdo = DB::conn();

        // Get backup path
        $stmt = $pdo->prepare('SELECT backup_path FROM server_backups WHERE id = ?');
        $stmt->execute([$backupId]);
        $backup = $stmt->fetch();

        if (!$backup) {
            return false;
        }

        // Delete file
        if (file_exists($backup['backup_path'])) {
            unlink($backup['backup_path']);
        }

        // Delete record
        $stmt = $pdo->prepare('DELETE FROM server_backups WHERE id = ?');
        return $stmt->execute([$backupId]);
    }

    /**
     * Get backup by ID
     * 
     * @param int $backupId Backup ID
     * @return array|null Backup data
     */
    public static function getBackup(int $backupId): ?array
    {
        $pdo = DB::conn();
        $stmt = $pdo->prepare('SELECT * FROM server_backups WHERE id = ?');
        $stmt->execute([$backupId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Rebuild entire wg0.conf from database and push to server
     * This is useful after a database restore to ensure server state matches DB.
     */
    public function syncClientsWithServer(): void
    {
        if (!$this->data || $this->data['status'] !== ServerStatus::ACTIVE)
            return;

        $containerName = $this->data['container_name'];

        // Check if container exists. If not, trigger re-deploy.
        $containerStatus = trim($this->executeCommand("docker inspect --format='{{.State.Running}}' {$containerName} 2>/dev/null", true));
        if ($containerStatus !== 'true') {
            \Logger::error("VPN Server {$this->serverId} container missing or stopped. Triggering re-deployment...");
            try {
                $this->deploy();
                $this->load(); // Refresh data from DB after deployment (new keys, params, etc)
                // After deploy, initializeServerConfig has already written an empty wg0.conf
                // We continue here to populate it with all existing clients
            } catch (Exception $e) {
                \Logger::error("Failed to re-deploy VPN server {$this->serverId}: " . $e->getMessage());
                return;
            }
        }

        $pdo = DB::conn();
        $stmt = $pdo->prepare('SELECT * FROM vpn_clients WHERE server_id = ? AND status = ? AND deleted_at IS NULL');
        $stmt->execute([$this->serverId, ClientStatus::ACTIVE->value]);
        $clients = $stmt->fetchAll();

        // 1. Build Base Config
        $privKey = $this->data['server_private_key'] ?? null;
        if (empty($privKey)) {
            // Fallback for old servers: read from remote if missing in DB
            $privKey = trim($this->executeCommand("docker exec -i {$this->data['container_name']} cat /opt/amnezia/awg/server_private.key", true));
        }

        $awgParams = $this->data['awg_params'];
        if (is_string($awgParams))
            $awgParams = json_decode($awgParams, true) ?: [];

        $wgConfig = "[Interface]\n";
        $wgConfig .= "PrivateKey = {$privKey}\n";
        $subnetBase = preg_replace('/\.\d+\/\d+$/', '', $this->data['vpn_subnet']);
        $wgConfig .= "Address = {$subnetBase}.1/24\n";
        $wgConfig .= "ListenPort = {$this->data['vpn_port']}\n";
        $wgConfig .= "MTU = 1280\n";

        foreach ($awgParams as $key => $value) {
            if ($value === null || $value === '' || $key === 'mimicry_type')
                continue;
            $wgConfig .= "{$key} = {$value}\n";
        }
        $wgConfig .= "\n";

        // 2. Add All Peers
        foreach ($clients as $client) {
            $wgConfig .= "# Name = {$client['name']}\n";
            $wgConfig .= "[Peer]\n";
            $wgConfig .= "PublicKey = {$client['public_key']}\n";
            $wgConfig .= "PresharedKey = {$this->data['preshared_key']}\n";
            $wgConfig .= "AllowedIPs = {$client['client_ip']}/32\n\n";
        }

        // 3. Rebuild clientsTable JSON
        $clientsTable = [];
        foreach ($clients as $client) {
            $clientsTable[] = [
                'clientId' => $client['public_key'],
                'name' => $client['name'],
                'public_key' => $client['public_key'],
                'private_key' => $client['private_key'],
                'client_ip' => $client['client_ip'],
                'created_at' => $client['created_at'],
                'userData' => [
                    'clientName' => $client['name'],
                    'creationDate' => $client['created_at']
                ]
            ];
        }
        $clientsTableJson = json_encode($clientsTable);
        $clientsTableBase64 = base64_encode($clientsTableJson);

        // 4. Push and Apply
        $containerName = $this->data['container_name'];
        $base64 = base64_encode($wgConfig);
        $this->executeCommand("echo \"{$base64}\" | docker exec -i {$containerName} sh -c 'base64 -d > /opt/amnezia/awg/wg0.conf && chmod 600 /opt/amnezia/awg/wg0.conf'", true);
        $this->executeCommand("echo \"{$clientsTableBase64}\" | docker exec -i {$containerName} sh -c 'base64 -d > /opt/amnezia/awg/clientsTable'", true);
        $this->executeCommand("docker exec -i {$containerName} bash -c '/usr/local/bin/awg syncconf wg0 <(/usr/local/bin/awg-quick strip /opt/amnezia/awg/wg0.conf)'", true);
    }

    /**
     * Detect existing AWG configuration and import to database
     */
    public function detectAndImportExistingConfig(): ?array
    {
        $pdo = DB::conn();
        $containerName = $this->data['container_name'] ?? 'nk-awg-v2';
        
        // 1. Read wg0.conf from container
        $wgConf = $this->executeCommand("docker exec {$containerName} cat /opt/amnezia/awg/wg0.conf 2>/dev/null", true);
        if (empty($wgConf)) return null;

        $awgParams = [];
        $privKey = '';
        $vpnPort = 0;
        $lines = explode("\n", $wgConf);
        $currentSection = '';

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '#')) continue;
            
            if (preg_match('/^\[(.*)\]$/', $line, $m)) {
                $currentSection = strtolower($m[1]);
                continue;
            }

            if ($currentSection === 'interface') {
                if (preg_match('/^PrivateKey\s*=\s*(.+)$/i', $line, $m)) $privKey = trim($m[1]);
                elseif (preg_match('/^ListenPort\s*=\s*(\d+)$/i', $line, $m)) $vpnPort = (int)$m[1];
                elseif (preg_match('/^(Jc|Jmin|Jmax|S1|S2|S3|S4|H1|H2|H3|H4|I1|I2|I3|I4|I5|I6|I7|I8|I9)\s*=\s*(.+)$/i', $line, $m)) {
                    $awgParams[$m[1]] = trim($m[2]);
                }
            }
        }

        if (empty($privKey)) return null;

        // 2. Read Public Key and PSK
        $pubKey = trim($this->executeCommand("docker exec {$containerName} cat /opt/amnezia/awg/wireguard_server_public_key.key 2>/dev/null", true));
        $psk = trim($this->executeCommand("docker exec {$containerName} cat /opt/amnezia/awg/wireguard_psk.key 2>/dev/null", true));

        if (empty($pubKey) || empty($psk)) return null;

        // 3. Update server record
        $stmt = $pdo->prepare('
            UPDATE vpn_servers 
            SET vpn_port = ?, 
                server_public_key = ?, 
                server_private_key = ?,
                preshared_key = ?, 
                awg_params = ?,
                status = ?,
                deployed_at = NOW()
            WHERE id = ?
        ');
        $stmt->execute([
            $vpnPort,
            $pubKey,
            $privKey,
            $psk,
            json_encode($awgParams),
            ServerStatus::ACTIVE->value,
            $this->serverId
        ]);

        // 4. Import Clients
        $this->importClientsFromContainer($containerName, $psk);

        return [
            'public_key' => $pubKey,
            'private_key' => $privKey,
            'preshared_key' => $psk,
            'awg_params' => $awgParams,
            'vpn_port' => $vpnPort
        ];
    }

    /**
     * Import clients from container files
     */
    private function importClientsFromContainer(string $containerName, string $psk): void
    {
        $pdo = DB::conn();
        
        // Read clientsTable (JSON)
        $tableJson = $this->executeCommand("docker exec {$containerName} cat /opt/amnezia/awg/clientsTable 2>/dev/null", true);
        $clientsTable = json_decode(trim($tableJson), true) ?: [];
        
        // Read wg0.conf again to get IPs and names for public keys
        $wgConf = $this->executeCommand("docker exec {$containerName} cat /opt/amnezia/awg/wg0.conf 2>/dev/null", true);
        $peers = []; // pubKey => ['ip' => ..., 'name' => ...]
        if (preg_match_all('/(?:#\s*Name\s*=\s*([^\r\n]+)\s+)?\[Peer\]\s+PublicKey\s*=\s*([^\s\r\n]+)\s+.*?AllowedIPs\s*=\s*([^\/]+)/s', $wgConf, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $name = !empty($m[1]) ? trim($m[1]) : null;
                $peers[trim($m[2])] = [
                    'ip' => trim($m[3]),
                    'name' => $name
                ];
            }
        }

        foreach ($peers as $pubKey => $peerData) {
            $ip = $peerData['ip'];
            
            // Check if already in DB (including soft-deleted)
            $stmt = $pdo->prepare('SELECT id, deleted_at FROM vpn_clients WHERE server_id = ? AND public_key = ?');
            $stmt->execute([$this->serverId, $pubKey]);
            $existing = $stmt->fetch();
            
            if ($existing) {
                if ($existing['deleted_at']) {
                    // RESTORE: It was deleted in panel but exists on server!
                    $pdo->prepare('UPDATE vpn_clients SET deleted_at = NULL, status = ? WHERE id = ?')
                        ->execute([ClientStatus::ACTIVE->value, $existing['id']]);
                    Logger::info("Restored soft-deleted client during discovery", ['public_key' => $pubKey]);
                }
                continue; 
            }

            // Find name and private key from clientsTable, fallback to comment name
            $name = $peerData['name'] ?? ("imported_" . substr($pubKey, 0, 8));
            $privKey = 'IMPORTED_KEY_UNKNOWN';
            foreach ($clientsTable as $entry) {
                // Compatibility with both formats
                $match = false;
                if (($entry['clientId'] ?? '') === $pubKey) $match = true;
                elseif (($entry['public_key'] ?? '') === $pubKey) $match = true;

                if ($match) {
                    $name = $entry['userData']['clientName'] ?? $entry['name'] ?? $name;
                    $privKey = $entry['private_key'] ?? 'IMPORTED_KEY_UNKNOWN';
                    break;
                }
            }

            // Insert client
            $stmt = $pdo->prepare('
                INSERT INTO vpn_clients 
                (server_id, user_id, name, client_ip, public_key, private_key, preshared_key, status, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ');
            $stmt->execute([
                $this->serverId,
                $this->data['user_id'] ?? 1,
                $name,
                $ip,
                $pubKey,
                $privKey,
                $psk,
                ClientStatus::ACTIVE->value
            ]);
        }

        // Trigger immediate stats sync so traffic shows up right away
        try {
            $this->syncClientsWithServer(); 
        } catch (Exception $e) {
            Logger::warning("Initial stats sync failed after discovery: " . $e->getMessage());
        }
    }

    /**
     * Synchronize all VPN servers
     */
    public static function syncAllServers(): void
    {
        $pdo = DB::conn();
        $stmt = $pdo->prepare('SELECT id FROM vpn_servers WHERE status = ?');
        $stmt->execute([ServerStatus::ACTIVE->value]);
        $serverIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($serverIds as $serverId) {
            try {
                $vs = new self((int) $serverId);
                $vs->syncClientsWithServer();
            } catch (Exception $e) {
                \Logger::error("Failed to sync VPN server $serverId after restore: " . $e->getMessage());
            }
        }
    }
}
