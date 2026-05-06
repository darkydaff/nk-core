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

    public function executeCommand(string $command, bool $sudo = false, bool $checkExit = false): string
    {
        return $this->server->executeCommand($command, $sudo, $checkExit);
    }

    /**
     * Install 3proxy via Docker (preferred) or native package
     */
    public function install(): void
    {
        // 1. Check if Docker is available
        $hasDocker = !empty(trim($this->executeCommand('which docker')));

        if ($hasDocker) {
            // Pull official image
            $this->executeCommand('docker pull 3proxy/3proxy:latest', true, true);
            $this->executeCommand('mkdir -p /etc/3proxy /var/log/3proxy', true, true);
            return;
        }

        // 2. Fallback to native installation if Docker is not present
        $check = $this->executeCommand('which 3proxy');
        if (empty(trim($check))) {
            $this->executeCommand('apt-get update', true, true);
            $this->executeCommand('apt-get install -y 3proxy', true, true);
        }

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

        $hasDocker = !empty(trim($this->executeCommand('which docker')));
        
        // If no docker and no native 3proxy, we need to install first (likely after a server wipe)
        $hasNative = !empty(trim($this->executeCommand('which 3proxy')));
        if (!$hasDocker && !$hasNative) {
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
                $config .= "allow " . $proxy['username'] . "\n";
                $type = ProxyType::tryFrom($proxy['type'] ?? 'http') ?? ProxyType::HTTP;
                if ($type === ProxyType::SOCKS5) {
                    $config .= "socks -p" . $proxy['port'] . "\n";
                } else {
                    $config .= "proxy -n -a -p" . $proxy['port'] . "\n";
                }
                $config .= "flush\n\n";
                
                // Specific firewall rules (iptables + ufw)
                $this->executeCommand("iptables -C INPUT -p tcp --dport {$proxy['port']} -j ACCEPT 2>/dev/null || iptables -A INPUT -p tcp --dport {$proxy['port']} -j ACCEPT", true);
                $this->executeCommand("iptables -C OUTPUT -p tcp --sport {$proxy['port']} -j ACCEPT 2>/dev/null || iptables -A OUTPUT -p tcp --sport {$proxy['port']} -j ACCEPT", true);
                
                // Open port in UFW if present
                $this->executeCommand("which ufw > /dev/null && ufw allow {$proxy['port']}/tcp || true", true);
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
            $runOptions = '--network host -v /etc/3proxy/3proxy.cfg:/etc/3proxy/3proxy.cfg -v /var/log/3proxy:/var/log/3proxy';
            
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
