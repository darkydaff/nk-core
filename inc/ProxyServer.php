<?php
declare(strict_types=1);

/**
 * HTTP Proxy Server Management Class
 * Handles deployment and management of 3proxy on remote servers
 */
class ProxyServer
{
    private $serverId;
    private $data;
    private VpnServer $server;

    public function __construct(int $serverId)
    {
        $this->serverId = $serverId;
        $this->server = new VpnServer($serverId);
        $this->data = $this->server->getData();
    }

    public function executeCommand(string $command, bool $sudo = false, bool $checkExit = false, bool $silent = false, int $timeout = 60): string
    {
        return $this->server->executeCommand($command, $sudo, $checkExit, $silent, $timeout);
    }

    /**
     * Install 3proxy via Docker (preferred) or native package
     * Idempotent: skips if already present
     */
    public function install(): void
    {
        // 0. Check if already installed (native or docker)
        $hasNative = !empty(trim($this->executeCommand('which 3proxy')));
        $hasDockerContainer = !empty(trim($this->executeCommand('docker ps -a --filter name=3proxy --format "{{.Names}}"')));
        
        if ($hasNative || $hasDockerContainer) {
            // Already installed, but ensure directories exist for config sync
            $this->executeCommand('mkdir -p /etc/3proxy /var/log/3proxy', true);
            return;
        }

        // 1. Check if Docker is available
        $hasDocker = !empty(trim($this->executeCommand('which docker')));

        if ($hasDocker) {
            // Pull official image
            $this->executeCommand('docker pull 3proxy/3proxy:latest', true, true, false, 600);
            $this->executeCommand('mkdir -p /etc/3proxy /var/log/3proxy', true, true);
            $this->executeCommand('chmod -R 777 /var/log/3proxy', true);
            return;
        }

        // 2. Fallback to native installation if Docker is not present
        $this->executeCommand('apt-get update', true, true, false, 600);
        $this->executeCommand('apt-get install -y 3proxy', true, true, false, 600);

        $this->executeCommand('mkdir -p /var/log/3proxy /etc/3proxy', true, true);
        $this->executeCommand('chown proxy:proxy /var/log/3proxy', true, true);

        // Check if systemd service exists, if not create it
        $serviceCheck = $this->executeCommand('systemctl list-unit-files | grep 3proxy.service');
        if (empty(trim($serviceCheck))) {
            $serviceFile = "[Unit]\nDescription=3proxy Proxy Server\nAfter=network.target\n\n[Service]\nType=simple\nExecStart=/usr/bin/3proxy /etc/3proxy/3proxy.cfg\nRestart=always\nUser=root\n\n[Install]\nWantedBy=multi-user.target";
            $base64 = base64_encode($serviceFile);
            $this->executeCommand("echo '{$base64}' | base64 -d | tee /etc/systemd/system/3proxy.service", true, true);
            $this->executeCommand('systemctl daemon-reload', true, true);
        }

        $this->executeCommand('systemctl enable 3proxy', true, true);
    }

    /**
     * Synchronize all proxy users to the server config
     */
    public function syncUsers(): void
    {
        $pdo = DB::conn();
        $stmt = $pdo->prepare('SELECT * FROM http_proxies WHERE server_id = ? AND status != "error" AND deleted_at IS NULL');
        $stmt->execute([$this->serverId]);
        $proxies = $stmt->fetchAll();

        // Detect if we should try to import existing config (only if DB is empty for this server)
        if (empty($proxies)) {
            $imported = $this->detectAndImportExistingConfig();
            if ($imported > 0) {
                // Refresh proxies list after import
                $stmt->execute([$this->serverId]);
                $proxies = $stmt->fetchAll();
            }
        }

        $hasDocker = !empty(trim($this->executeCommand('which docker')));
        
        // Check if 3proxy is already running (native or docker)
        $hasNative = !empty(trim($this->executeCommand('which 3proxy')));
        $hasDockerContainer = !empty(trim($this->executeCommand('docker ps -a --filter name=3proxy --format "{{.Names}}"')));

        if (!$hasDocker && !$hasNative && !$hasDockerContainer) {
            $this->install();
            $hasDocker = !empty(trim($this->executeCommand('which docker')));
        }

        $config = "nserver 8.8.8.8\n";
        $config .= "nserver 1.1.1.1\n";
        $config .= "nscache 65536\n";
        $config .= "timeouts 1 5 30 60 180 1800 15 60\n";
        
        if (!$hasDocker) {
            $config .= "daemon\n";
        }
        
        $config .= "log /var/log/3proxy/3proxy.log D\n";
        $config .= "logformat \"- +_L%t.%. %N.%p %E %U %C:%c %R:%r %O %I %h %T\"\n";
        $config .= "auth strong\n\n";

        // Add users
        foreach ($proxies as $proxy) {
            $config .= "users " . $proxy['username'] . ":CL:" . $proxy['password'] . "\n";
        }

        $config .= "\n";

        // Add proxy rules
        foreach ($proxies as $proxy) {
            if ($proxy['status'] === ServerStatus::ACTIVE->value || $proxy['status'] === 'active') {
                $config .= "auth strong\n";
                $config .= "allow " . $proxy['username'] . "\n";
                $type = ProxyType::tryFrom($proxy['type'] ?? 'http') ?? ProxyType::HTTP;
                if ($type === ProxyType::SOCKS5) {
                    $config .= "socks -p" . $proxy['port'] . "\n";
                } else {
                    // Removed -a (anonymous) to ensure 'auth strong' works
                    $config .= "proxy -n -p" . $proxy['port'] . "\n";
                }
                $config .= "flush\n\n";
                
                // Specific firewall rules (iptables + ufw)
                // Open both TCP and UDP for SOCKS5, TCP for HTTP
                $protocols = ($type === ProxyType::SOCKS5) ? ['tcp', 'udp'] : ['tcp'];
                foreach ($protocols as $proto) {
                    $this->executeCommand("iptables -C INPUT -p {$proto} --dport {$proxy['port']} -j ACCEPT 2>/dev/null || iptables -A INPUT -p {$proto} --dport {$proxy['port']} -j ACCEPT", true);
                    $this->executeCommand("iptables -C OUTPUT -p {$proto} --sport {$proxy['port']} -j ACCEPT 2>/dev/null || iptables -A OUTPUT -p {$proto} --sport {$proxy['port']} -j ACCEPT", true);
                    $this->executeCommand("which ufw > /dev/null && ufw allow {$proxy['port']}/{$proto} || true", true);
                }
            }
        }

        $base64 = base64_encode($config);
        $this->executeCommand("echo '{$base64}' | base64 -d | tee /etc/3proxy/3proxy.cfg", true, true);
        
        // Final fallback for native: also write to /etc/3proxy.cfg
        $this->executeCommand("cp /etc/3proxy/3proxy.cfg /etc/3proxy.cfg 2>/dev/null || true", true);

        // Restart logic
        $hasDocker = !empty(trim($this->executeCommand('which docker')));
        if ($hasDocker) {
            // Run/Restart Docker container via DeploymentService
            require_once __DIR__ . '/DeploymentService.php';
            
            $containerName = '3proxy';
            $runOptions = '--user root --network host -v /etc/3proxy/3proxy.cfg:/etc/3proxy/3proxy.cfg -v /var/log/3proxy:/var/log/3proxy';
            
            DeploymentService::deployDockerContainer($this, $containerName, '3proxy/3proxy:latest', $runOptions);
        } else {
            // Use systemd fallback
            $this->executeCommand('systemctl restart 3proxy', true, true);
        }
    }

    /**
     * Find a free port in the range 30000-40000
     */
    public function findFreePort(): int
    {
        $pdo = DB::conn();
        
        // Get all used ports for this server from DB
        $stmt = $pdo->prepare('SELECT port FROM http_proxies WHERE server_id = ?');
        $stmt->execute([$this->serverId]);
        $usedPorts = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $attempts = 0;
        while ($attempts < 100) {
            $port = rand(30000, 40000);
            if (in_array($port, $usedPorts)) {
                $attempts++;
                continue;
            }

            // Also check on the server
            $cmd = "ss -ltn | grep -E ':(" . $port . ")($| )' || true";
            $out = $this->executeCommand($cmd, false);
            if (trim($out) === '') {
                return $port;
            }
            $attempts++;
        }

        throw new Exception('Could not find a free port in range 30000-40000 after 100 attempts');
    }

    /**
     * Update traffic statistics for all proxies on this server
     */
    public function updateTrafficStats(): void
    {
        $pdo = DB::conn();
        $stmt = $pdo->prepare('SELECT id, port FROM http_proxies WHERE server_id = ? AND status = "active" AND deleted_at IS NULL');
        $stmt->execute([$this->serverId]);
        $proxies = $stmt->fetchAll();

        // Get all iptables counters at once for performance
        $inputCounters = $this->executeCommand("iptables -L INPUT -n -v -x | grep -E 'tcp dpt:(3[0-9]{4}|40000)'", true);
        $outputCounters = $this->executeCommand("iptables -L OUTPUT -n -v -x | grep -E 'tcp spt:(3[0-9]{4}|40000)'", true);

        foreach ($proxies as $proxy) {
            $received = 0;
            $sent = 0;

            if (preg_match('/^\s*(\d+)\s+(\d+).*dpt:' . $proxy['port'] . '\s*$/m', $inputCounters, $matches)) {
                $received = (int)$matches[2];
            }

            if (preg_match('/^\s*(\d+)\s+(\d+).*spt:' . $proxy['port'] . '\s*$/m', $outputCounters, $matches)) {
                $sent = (int)$matches[2];
            }

            if ($received > 0 || $sent > 0) {
                $stmt = $pdo->prepare('UPDATE http_proxies SET bytes_received = ?, bytes_sent = ?, last_sync_at = NOW() WHERE id = ?');
                $stmt->execute([$received, $sent, $proxy['id']]);
            }
        }
    }
    /**
     * Detect existing 3proxy configuration on the server and import users to DB
     */
    public function detectAndImportExistingConfig(): int
    {
        $pdo = DB::conn();
        $configPath = '/etc/3proxy/3proxy.cfg';
        $exists = !empty(trim($this->executeCommand("[ -f $configPath ] && echo '1' || echo ''")));
        
        if (!$exists) {
            $configPath = '/etc/3proxy.cfg';
            $exists = !empty(trim($this->executeCommand("[ -f $configPath ] && echo '1' || echo ''")));
        }
        
        $content = '';
        if ($exists) {
            $content = $this->executeCommand("cat $configPath", true);
        } else {
            // Try fallback to docker container if it exists
            $dockerExists = !empty(trim($this->executeCommand("docker ps --filter name=3proxy --format '{{.Names}}'")));
            if ($dockerExists) {
                $content = $this->executeCommand("docker exec 3proxy cat /etc/3proxy/3proxy.cfg 2>/dev/null || docker exec 3proxy cat /etc/3proxy.cfg 2>/dev/null", true);
            }
        }
        
        if (empty($content)) return 0;
        
        $importedCount = 0;
        $lines = explode("\n", $content);
        
        // Parse users: "users username:CL:password"
        $foundUsers = [];
        foreach ($lines as $line) {
            if (preg_match('/^users\s+([^:]+):CL:(.+)$/i', trim($line), $matches)) {
                $foundUsers[$matches[1]] = $matches[2];
            }
        }
        
        if (empty($foundUsers)) return 0;
        
        // Parse ports: "proxy -pPort" or "socks -pPort"
        $foundPorts = []; // username => [port, type]
        $currentAllow = null;
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^allow\s+(.+)$/i', $line, $matches)) {
                $currentAllow = $matches[1];
            } elseif (preg_match('/^(proxy|socks)\s+.*-p(\d+)/i', $line, $matches)) {
                if ($currentAllow && isset($foundUsers[$currentAllow])) {
                    $foundPorts[$currentAllow] = [
                        'port' => (int)$matches[2],
                        'type' => strtolower($matches[1]) === 'socks' ? 'socks5' : 'http'
                    ];
                }
            }
        }
        
        // Default port if not found (very rough fallback)
        $defaultPort = 30001;

        foreach ($foundUsers as $username => $password) {
            $portData = $foundPorts[$username] ?? ['port' => $defaultPort++, 'type' => 'http'];
            
            // Assign to server owner, fallback to 1 (admin) if not found
            $ownerId = $this->data['user_id'] ?? 1;

            // Check if already in DB (including soft-deleted)
            $check = $pdo->prepare('SELECT id, deleted_at FROM http_proxies WHERE server_id = ? AND username = ?');
            $check->execute([$this->serverId, $username]);
            $existing = $check->fetch();
            
            if ($existing) {
                if ($existing['deleted_at']) {
                    // RESTORE: It was deleted in panel but exists on server!
                    $pdo->prepare('UPDATE http_proxies SET deleted_at = NULL, status = "active" WHERE id = ?')
                        ->execute([$existing['id']]);
                    $importedCount++;
                }
                continue;
            }

            $stmt = $pdo->prepare('INSERT INTO http_proxies (user_id, server_id, username, password, port, type, status) VALUES (?, ?, ?, ?, ?, ?, "active")');
            $stmt->execute([
                $ownerId,
                $this->serverId,
                $username,
                $password,
                $portData['port'],
                $portData['type']
            ]);
            $importedCount++;
        }
        
        return $importedCount;
    }
    /**
     * Synchronize all proxies on all servers
     */
    public static function syncAllServers(): void
    {
        $pdo = DB::conn();
        $stmt = $pdo->query('SELECT DISTINCT server_id FROM http_proxies');
        $serverIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($serverIds as $serverId) {
            try {
                $ps = new self((int)$serverId);
                $ps->syncUsers();
            } catch (Exception $e) {
                \Logger::error("Failed to sync proxies for server $serverId after restore: " . $e->getMessage());
            }
        }
    }
}
