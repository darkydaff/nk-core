<?php
declare(strict_types=1);

require_once __DIR__ . '/DeploymentResult.php';

class VpnProvisioner
{
    private VpnServer $server;
    private LinuxProvisioner $linux;
    private AwgConfigGenerator $configGen;
    private array $timings = [];
    private array $warnings = [];
    private string $currentPhase = 'INIT';

    public function __construct(LinuxProvisioner $linux, AwgConfigGenerator $configGen)
    {
        $this->linux = $linux;
        $this->configGen = $configGen;
    }

    private function getData(): array
    {
        return $this->server->getData() ?? [];
    }

    private function getId(): ?int
    {
        return $this->server->getId();
    }

/**
     * Deploy or Re-deploy VPN server using existing data if available
     */
    private function runProvisioningStep(string $title, string $stepType, callable $work): mixed
    {
        $start = microtime(true);
        try {
            return $this->server->runStep($title, $stepType, $work);
        } finally {
            $duration = round(microtime(true) - $start, 2);
            $this->timings[$stepType] = $duration;
        }
    }

    public function deploy(VpnServer $server, bool $forceRebuild = false): DeploymentResult
    {
        $this->server = $server;
        $serverData = $this->getData();
        if (!$serverData) {
            throw new Exception('Server not loaded');
        }

        // Disable PHP timeout for long builds (Go compilation takes several minutes)
        ini_set('max_execution_time', '0');

        $pdo = DB::conn();
        $errors = [];

        try {
            // Update status to deploying
            $this->server->setStatus(ServerStatus::DEPLOYING);

            // Pipeline execution
            $this->currentPhase = 'PREPARING';
            $this->runProvisioningStep("Testing SSH connection...", 'test_connection', fn() => $this->stepTestConnection());
            $this->runProvisioningStep("Preparing remote system...", 'prepare_system', fn() => $this->linux->prepareSystem($serverData['username'] ?? 'root'));
            $this->runProvisioningStep("Installing Docker...", 'install_docker', fn() => $this->linux->installDocker());
            
            $this->runProvisioningStep("Installing AmneziaWG Kernel Module...", 'install_kernel_module', function() {
                if (!$this->linux->installKernelModule()) {
                    $msg = 'Warning: AmneziaWG kernel module failed to install/load. Using slower userspace fallback (amneziawg-go).';
                    $this->warnings[] = $msg;
                    $pdo = DB::conn();
                    $pdo->prepare('UPDATE vpn_servers SET error_message = ? WHERE id = ?')
                        ->execute([$msg, $this->server->getId()]);
                }
            });

            // IDEMPOTENCY: Check if container already exists and try to adopt it
            $containerName = $serverData['container_name'] ?? 'nk-awg-v2';
            $containerRunning = !empty(trim($this->server->executeCommand("docker ps --filter name={$containerName} --format '{{.Names}}'")));
            
            if ($containerRunning && !$forceRebuild) {
                if ($this->tryAdoptExistingConfig()) {
                    return DeploymentResult::success($this->timings, $this->getDeploymentResult(), $this->warnings);
                }
            }

            $this->currentPhase = 'CONFIGURING';
            $this->runProvisioningStep("Creating directories...", 'create_dirs', fn() => $this->server->executeCommand('mkdir -p /opt/amnezia/nk-awg-v2', true, true));

            $vpnPort = (int)($serverData['vpn_port'] ?? 0);
            if ($vpnPort <= 0) {
                $vpnPort = $this->runProvisioningStep("Finding free UDP port...", 'find_port', fn() => $this->linux->findFreeUdpPort());
            }

            $this->runProvisioningStep("Generating Dockerfile...", 'create_dockerfile', function() {
                $dockerfile = $this->configGen->getDockerfile();
                $base64 = base64_encode(trim($dockerfile));
                $this->server->executeCommand("echo \"{$base64}\" | base64 -d > /opt/amnezia/nk-awg-v2/Dockerfile", true, true);
            });

            $this->runProvisioningStep("Generating scripts...", 'create_scripts', function() use ($serverData, $vpnPort) {
                $subnet = $serverData['vpn_subnet'] ?? '10.8.1.0/24';
                $script = $this->configGen->getStartScript($subnet, $vpnPort);
                $base64 = base64_encode(trim($script));
                $this->server->executeCommand("echo '{$base64}' | base64 -d > /opt/amnezia/nk-awg-v2/start.sh && chmod +x /opt/amnezia/nk-awg-v2/start.sh", true, true);
            });
            
            $this->currentPhase = 'BUILDING';
            $this->runProvisioningStep("Building Image...", 'build_image', fn() => $this->buildDockerImage($forceRebuild));
            
            $this->currentPhase = 'STARTING';
            $this->runProvisioningStep("Starting VPN Container...", 'run_container', function() use ($vpnPort) {
                $this->runContainer($vpnPort);
            });

            $this->currentPhase = 'FINALIZING';
            $keys = $this->runProvisioningStep("Initializing VPN...", 'init_config', fn() => $this->initializeServerConfig($vpnPort));

            // Update database with deployment info
            $this->finalizeDeployment($vpnPort, $keys);

            $totalDuration = array_sum($this->timings);
            Logger::channel('deployments')->info('Deployment Pipeline Completed', [
                'server_id' => $this->server->getId(),
                'total_duration' => $totalDuration,
                'timings' => $this->timings
            ]);

            return DeploymentResult::success($this->timings, $this->getDeploymentResult(), $this->warnings);

        } catch (Throwable $e) {
            $errorMsg = mb_convert_encoding($e->getMessage(), 'UTF-8', 'UTF-8');
            $errorMsg = preg_replace('/[^\x20-\x7E\n]/', '', $errorMsg);
            
            if (strlen($errorMsg) > 2000) {
                $errorMsg = substr($errorMsg, 0, 1000) . "..." . substr($errorMsg, -500);
            }

            Logger::channel('deployments')->error("Deployment failed during phase {$this->currentPhase}: {$errorMsg}", [
                'server_id' => $this->server->getId(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);

            $rollbackExecuted = false;
            try {
                $rollbackExecuted = $this->rollback();
            } catch (Throwable $re) {
                Logger::channel('deployments')->error("Rollback failed!", [
                    'server_id' => $this->server->getId(),
                    'rollback_error' => $re->getMessage()
                ]);
            }

            $this->server->setStatus(ServerStatus::ERROR);
            $pdo->prepare('UPDATE vpn_servers SET error_message = ? WHERE id = ?')
                ->execute([$errorMsg, $this->server->getId()]);

            return DeploymentResult::failure(
                $this->currentPhase,
                $errorMsg,
                $e,
                $this->timings,
                [],
                $this->warnings,
                $rollbackExecuted
            );
        }
    }

    /**
     * Safe rollback based on the current deployment phase.
     */
    private function rollback(): bool
    {
        $serverData = $this->getData();
        $containerName = $serverData['container_name'] ?? 'nk-awg-v2';

        Logger::channel('deployments')->info("Executing rollback for phase {$this->currentPhase}", ['server_id' => $this->getId()]);

        if (in_array($this->currentPhase, ['BUILDING', 'STARTING', 'CONFIGURING'])) {
            // Safe to remove the partially created container and tmp dir
            $this->server->executeCommand("docker rm -f {$containerName} 2>/dev/null || true", true);
            $this->server->executeCommand("rm -rf /opt/amnezia/nk-awg-v2 2>/dev/null || true", true);
            return true;
        }

        return false;
    }



    private function stepTestConnection(): void
    {
        $serverData = $this->getData();
        if (!$this->server->testConnection()) {
            throw new Exception('SSH connection failed');
        }
    }



    private function tryAdoptExistingConfig(): bool
    {
        $serverData = $this->getData();
        Logger::channel('deployments')->info("Existing container detected. Initiating adoption...", ['server_id' => $this->getId()]);
        try {
            if ($this->detectAndImportExistingConfig()) {
                $this->server->load();
                // Automatically install telemetry agent if push mode is active
                $this->installTelemetryAgent();
                return true;
            }
        } catch (Exception $e) {
            Logger::channel('deployments')->warning("Adoption failed: " . $e->getMessage());
        }
        return false;
    }



    private function finalizeDeployment(int $vpnPort, array $keys): void
    {
        $serverData = $this->getData();
        $pdo = DB::conn();
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
            $this->getId()
        ]);
        $this->server->load();

        // Automatically fetch GeoIP data after successful deployment
        $this->server->updateGeoIp();

        // Automatically install adaptive push telemetry agent
        try {
            $this->installTelemetryAgent();
        } catch (Exception $e) {
            Logger::channel('deployments')->error("Failed to install telemetry agent: " . $e->getMessage(), [
                'server_id' => $this->getId()
            ]);
        }
    }

    /**
     * Install the lightweight adaptive push telemetry agent on the remote VPN node.
     */
    private function installTelemetryAgent(): void
    {
        $serverData = $this->getData();
        if (($serverData['telemetry_mode'] ?? 'ssh') !== 'push') {
            return;
        }

        $token = $serverData['telemetry_token'] ?? '';
        if (empty($token)) {
            $token = bin2hex(random_bytes(32));
            $pdo = DB::conn();
            $pdo->prepare('UPDATE vpn_servers SET telemetry_token = ? WHERE id = ?')
                ->execute([$token, $this->getId()]);
            $serverData['telemetry_token'] = $token;
        }

        // Auto-detect the panel host address using a secure waterfall mechanism
        $panelHost = '';
        if (isset($_SERVER['HTTP_HOST'])) {
            $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://';
            $panelHost = $scheme . $_SERVER['HTTP_HOST'];
        }
        
        if (empty($panelHost)) {
            $externalIp = trim(@file_get_contents('http://ipinfo.io/ip'));
            if (!empty($externalIp) && filter_var($externalIp, FILTER_VALIDATE_IP)) {
                $panelHost = 'http://' . $externalIp . ':8443';
            } else {
                $panelHost = 'http://localhost:8443';
            }
        }

        $url = rtrim($panelHost, '/') . '/api/telemetry.php';

        Logger::channel('deployments')->info('Installing automated telemetry agent on node...', [
            'server_id' => $this->getId(),
            'url' => $url
        ]);

        $pythonCode = <<<'PYTHON'
import subprocess, json, urllib.request, time, sys

TOKEN = "{TOKEN}"
URL = "{URL}"

interval = 15

while True:
    try:
        container = "nk-awg-v2"
        res = subprocess.run(["docker", "exec", container, "/usr/local/bin/awg", "show", "all", "dump"], capture_output=True, text=True)
        if res.returncode != 0:
            container = "amnezia-wg"
            res = subprocess.run(["docker", "exec", container, "/usr/local/bin/awg", "show", "all", "dump"], capture_output=True, text=True)
            
        dump = res.stdout.strip()
        peers = []
        
        if dump:
            for line in dump.split("\n"):
                parts = line.split("\t")
                if len(parts) >= 8:
                    peers.append({
                        "public_key": parts[1],
                        "bytes_received": int(parts[6]),
                        "bytes_sent": int(parts[7]),
                        "last_handshake": int(parts[5])
                    })
        
        payload = {
            "timestamp": int(time.time()),
            "peers": peers
        }
        
        req = urllib.request.Request(
            URL,
            data=json.dumps(payload).encode("utf-8"),
            headers={
                "Content-Type": "application/json",
                "Authorization": f"Bearer {TOKEN}",
                "X-Telemetry-Token": TOKEN
            },
            method="POST"
        )
        
        with urllib.request.urlopen(req, timeout=10) as f:
            resp = f.read().decode().strip()
            if resp.isdigit():
                interval = int(resp)
            else:
                interval = 15
                
    except Exception as e:
        interval = 15
        
    time.sleep(interval)
PYTHON;

        $pythonCode = str_replace(['{TOKEN}', '{URL}'], [$token, $url], $pythonCode);
        $base64 = base64_encode($pythonCode);

        // Provision agent and register it as an active systemd unit service
        $setupCmd = implode(' && ', [
            "echo '{$base64}' | base64 -d > /usr/local/bin/nk-telemetry-agent.py",
            "chmod +x /usr/local/bin/nk-telemetry-agent.py",
            "echo '[Unit]
Description=NK-Core Adaptive Telemetry Agent
After=docker.service
Wants=docker.service

[Service]
Type=simple
ExecStart=/usr/bin/python3 /usr/local/bin/nk-telemetry-agent.py
Restart=always
RestartSec=5
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target' > /etc/systemd/system/nk-telemetry.service",
            "systemctl daemon-reload",
            "systemctl enable nk-telemetry.service",
            "systemctl restart nk-telemetry.service"
        ]);

        $this->server->executeCommand($setupCmd, true, true);
        Logger::channel('deployments')->info('Telemetry agent systemd service deployed successfully on host', ['server_id' => $this->getId()]);
    }



    private function getDeploymentResult(): array
    {
        $serverData = $this->getData();
        return [
            'success' => true,
            'vpn_port' => (int)($serverData['vpn_port'] ?? 0),
            'public_key' => $serverData['server_public_key'] ?? ''
        ];
    }























/**
     * Build Docker image
     */
    private function buildDockerImage(bool $force = false): void
    {
        $serverData = $this->getData();
        $containerName = $serverData['container_name'];

        // Check if image exists
        $imageExists = trim($this->server->executeCommand("docker image inspect {$containerName} --format='{{.Id}}' 2>/dev/null", true));
        if ($imageExists && !$force) {
            return; // Already exists, skip expensive build
        }

        // Cleanup old container/image
        $this->server->executeCommand(sprintf(
            "docker stop %s 2>/dev/null || true; docker rm -fv %s 2>/dev/null || true; docker rmi %s 2>/dev/null || true",
            $containerName, $containerName, $containerName
        ), true);

        // Build new image — Go compilation can take 5-10 minutes
        $buildCmd = sprintf(
            'docker build --no-cache --pull -t %s /opt/amnezia/nk-awg-v2 2>&1',
            $containerName
        );
        $buildOutput = $this->server->executeCommand($buildCmd, true, true, false, 900);

        // Verify the image was actually created
        $check = trim($this->server->executeCommand("docker image inspect {$containerName} --format='{{.Id}}' 2>/dev/null", true));
        if (empty($check)) {
            throw new Exception('Docker image build failed. Build output: ' . substr($buildOutput, -1000));
        }
    }

/**
     * Run Docker container
     */
    private function runContainer(int $vpnPort): void
    {
        $serverData = $this->getData();
        require_once __DIR__ . '/DeploymentService.php';
        
        $containerName = $serverData['container_name'];
        
        // Using --network host for zero-latency performance.
        // Mounting /opt/amnezia/nk-awg-v2 to /opt/amnezia/awg for persistent storage on the host.
        $runOptions = sprintf(
            '--privileged --network host --cap-add=NET_ADMIN --cap-add=SYS_MODULE -v /lib/modules:/lib/modules -v /opt/amnezia/nk-awg-v2:/opt/amnezia/awg -e WG_THREADS=4'
        );

        DeploymentService::deployDockerContainer($this->server, $containerName, $containerName, $runOptions);
    }

/**
     * Initialize server configuration with AWG parameters
     */
    private function initializeServerConfig(int $vpnPort): array
    {
        $serverData = $this->getData();
        $containerName = $serverData['container_name'];
        $pdo = DB::conn();

        $this->server->executeCommand("docker exec -i {$containerName} mkdir -p /opt/amnezia/awg", true, true);

        $keys = $this->generateOrRestoreAwgKeys($containerName, $vpnPort);

        return [
            'public_key' => $keys['public_key'],
            'private_key' => $keys['private_key'],
            'preshared_key' => $keys['preshared_key'],
            'awg_params' => $keys['awg_params']
        ];
    }

    private function generateOrRestoreAwgKeys(string $containerName, int $vpnPort): array
    {
        $serverData = $this->getData();
        $privKey = $serverData['server_private_key'] ?? null;
        $pubKey = $serverData['server_public_key'] ?? null;
        $psk = $serverData['preshared_key'] ?? null;

        if ($privKey && $pubKey && $psk) {
            $restoreCmd = sprintf(
                "echo %s | base64 -d > /opt/amnezia/awg/server_private.key && " .
                "echo %s | base64 -d > /opt/amnezia/awg/wireguard_server_public_key.key && " .
                "echo %s | base64 -d > /opt/amnezia/awg/wireguard_psk.key",
                escapeshellarg(base64_encode($privKey)),
                escapeshellarg(base64_encode($pubKey)),
                escapeshellarg(base64_encode($psk))
            );
            $this->server->executeCommand("docker exec -i {$containerName} sh -c " . escapeshellarg($restoreCmd), true, true);
        } else {
            $this->server->executeCommand("docker exec -i {$containerName} sh -c 'cd /opt/amnezia/awg && umask 077 && /usr/local/bin/awg genkey | tee server_private.key | /usr/local/bin/awg pubkey > wireguard_server_public_key.key'", true, true);
            $this->server->executeCommand("docker exec -i {$containerName} sh -c 'cd /opt/amnezia/awg && /usr/local/bin/awg genpsk > wireguard_psk.key'", true, true);

            $privKey = trim($this->server->executeCommand("docker exec -i {$containerName} cat /opt/amnezia/awg/server_private.key", true));
            $pubKey = trim($this->server->executeCommand("docker exec -i {$containerName} cat /opt/amnezia/awg/wireguard_server_public_key.key", true));
            $psk = trim($this->server->executeCommand("docker exec -i {$containerName} cat /opt/amnezia/awg/wireguard_psk.key", true));
        }        $this->server->executeCommand("docker exec -i {$containerName} chmod 600 /opt/amnezia/awg/server_private.key /opt/amnezia/awg/wireguard_psk.key /opt/amnezia/awg/wireguard_server_public_key.key", true, true);

        if (empty($privKey) || empty($pubKey) || empty($psk)) {
            throw new Exception('Key initialization failed.');
        }

        $awgParams = $serverData['awg_params'] ?? null;
        if (is_string($awgParams)) {
            $awgParams = json_decode($awgParams, true);
        }

        $mimicryPresets = [];
        $mimicryType = $awgParams['mimicry_type'] ?? 'quic';
        if ($mimicryType === 'quic') {
            $mimicryPresets = $this->configGen->getDynamicQuicPayloads(dirname(__DIR__) . '/quic-example.txt');
        }

        $awgParams = $this->configGen->generateAwgParams($awgParams, $mimicryPresets);
        
        $subnetBase = preg_replace('/\.\d+\/\d+$/', '', $serverData['vpn_subnet']);
        $wgConfig = $this->configGen->generateWgConfig($subnetBase, $vpnPort, $privKey, $awgParams);
        $base64 = base64_encode($wgConfig);
        $this->server->executeCommand("echo \"{$base64}\" | docker exec -i {$containerName} sh -c 'base64 -d > /opt/amnezia/awg/wg0.conf'", true, true);
        $this->server->executeCommand("docker exec -i {$containerName} chmod 600 /opt/amnezia/awg/wg0.conf", true, true);

        $this->initializeClientsTable($containerName);
        $this->waitForWgInterface($containerName);

        sleep(2); 

        return [
            'private_key' => $privKey,
            'public_key' => $pubKey,
            'preshared_key' => $psk,
            'awg_params' => $awgParams
        ];
    }

    private function initializeClientsTable(string $containerName): void
    {
        $this->server->executeCommand("docker exec -i {$containerName} sh -c 'echo \"[]\" > /opt/amnezia/awg/clientsTable'", true, true);
    }

    private function waitForWgInterface(string $containerName): void
    {
        $wgReady = false;
        for ($i = 0; $i < 10; $i++) {
            $check = $this->server->executeCommand("docker exec -i {$containerName} ip link show wg0 2>/dev/null | grep -c wg0", true);
            if (trim($check) === '1') {
                $wgReady = true;
                break;
            }
            sleep(1);
        }
        if (!$wgReady) {
            $logs = trim($this->server->executeCommand("docker logs --tail 60 {$containerName} 2>&1", true));
            $show = trim($this->server->executeCommand("docker exec -i {$containerName} sh -c '/usr/local/bin/awg show 2>&1 || true'", true));
            throw new Exception('wg0 interface failed to start. awg-quick likely rejected config. '
                . 'Container logs: ' . substr($logs, -800) . ' | awg show: ' . substr($show, -400));
        }
    }









/**
     * Detect existing AWG configuration and import to database
     */
    public function detectAndImportExistingConfig(): ?array
    {
        $serverData = $this->getData();
        $pdo = DB::conn();
        $containerName = $serverData['container_name'] ?? 'nk-awg-v2';
        
        // 1. Read wg0.conf from container
        $wgConf = $this->server->executeCommand("docker exec {$containerName} cat /opt/amnezia/awg/wg0.conf 2>/dev/null", true);
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
        $pubKey = trim($this->server->executeCommand("docker exec {$containerName} cat /opt/amnezia/awg/wireguard_server_public_key.key 2>/dev/null", true));
        $psk = trim($this->server->executeCommand("docker exec {$containerName} cat /opt/amnezia/awg/wireguard_psk.key 2>/dev/null", true));

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
                deployed_at = NOW(),
                error_message = NULL
            WHERE id = ?
        ');
        $stmt->execute([
            $vpnPort,
            $pubKey,
            $privKey,
            $psk,
            json_encode($awgParams),
            ServerStatus::ACTIVE->value,
            $this->getId()
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
        $serverData = $this->getData();
        $pdo = DB::conn();
        
        // Read clientsTable (JSON)
        $tableJson = $this->server->executeCommand("docker exec {$containerName} cat /opt/amnezia/awg/clientsTable 2>/dev/null", true);
        $clientsTable = json_decode(trim($tableJson), true) ?: [];
        
        // Read wg0.conf again to get IPs and names for public keys
        $wgConf = $this->server->executeCommand("docker exec {$containerName} cat /opt/amnezia/awg/wg0.conf 2>/dev/null", true);
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
            $stmt->execute([$this->getId(), $pubKey]);
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
                $this->getId(),
                $serverData['user_id'] ?? 1,
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
            $this->server->syncClientsWithServer(); 
        } catch (Exception $e) {
            Logger::warning("Initial stats sync failed after discovery: " . $e->getMessage());
        }
    }

}
