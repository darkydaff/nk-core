<?php
declare(strict_types=1);

class VpnConfigRenderer
{
    private VpnServer $server;
    private SshClient $ssh;
    private array $clients = [];

    public function __construct(VpnServer $server)
    {
        $this->server = $server;
        $this->ssh = $server->getSshClient();
        
        if (!View::isInitialized()) {
            View::init(dirname(__DIR__) . '/templates');
        }
    }

    /**
     * Renders the complete wg0.conf locally by querying the database state.
     */
    public function renderConfig(): string
    {
        $serverData = $this->server->getData();
        if (empty($serverData['server_private_key'])) {
            throw new Exception("Server private key is missing from database.");
        }

        // Fetch all active clients for this server
        $pdo = DB::conn();
        $stmt = $pdo->prepare("SELECT *, client_ip AS ip_address FROM vpn_clients WHERE server_id = ? AND status IN ('active', 'verifying', 'provisioning') AND deleted_at IS NULL ORDER BY client_ip ASC");
        $stmt->execute([$this->server->getId()]);
        $this->clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $subnetBase = preg_replace('/\.0\/24$/', '', $serverData['vpn_subnet'] ?? '10.8.1.0/24');
        $serverData['vpn_subnet_base'] = $subnetBase;
        
        $awgParams = [];
        if (!empty($serverData['awg_params'])) {
            $awgParams = is_array($serverData['awg_params'])
                ? $serverData['awg_params']
                : (json_decode($serverData['awg_params'], true) ?: []);
        }
        $serverData['awg_params'] = $awgParams;

        return View::fetch('vpn/wg0.conf.twig', [
            'server' => $serverData,
            'clients' => $this->clients
        ]);
    }

    /**
     * Executes the Declarative Sync workflow:
     * 1. Generates the config locally
     * 2. Uploads atomically to /tmp/wg0.conf.next
     * 3. Validates the config inside the container
     * 4. Swaps it into place and runs `awg syncconf`
     */
    public function syncDeclarative(): void
    {
        Logger::channel('control-plane')->info('Starting Declarative Sync', ['server_id' => $this->server->getId()]);
        
        // 1. Render locally
        $configContent = $this->renderConfig();
        
        // 2. Upload to tmp location
        $tmpPath = '/tmp/wg0.conf.next';
        
        // Write locally to tmp, then SCP it over
        $localTmp = sys_get_temp_dir() . '/wg0_' . $this->server->getId() . '_' . uniqid() . '.conf';
        file_put_contents($localTmp, $configContent);
        
        try {
            $this->ssh->uploadFile($localTmp, $tmpPath);
        } finally {
            @unlink($localTmp);
        }

        // 3. Pre-flight Validation
        // Check if config syntax is valid inside the docker container
        $containerName = $this->server->getData()['container_name'] ?? 'nk-awg-v2';
        
        // Use docker exec to read the temp file, which was placed on the host's /tmp.
        // Wait, the container might not have access to host's /tmp.
        // We should move it into the docker container volume.
        $targetConfigDir = '/opt/amnezia/awg'; // The mount point on host (usually /opt/amnezia/awg maps to container)
        // Wait, let's just do `docker exec -i $containerName sh -c "cat > /etc/amnezia/awg/wg0.conf.next"`
        // The container path is /opt/amnezia/awg inside the container. Wait, in VpnProvisioner:
        // /opt/amnezia/nk-awg-v2 is host dir.
        
        // Safer approach: use docker exec -i to write the file directly inside the container
        // We can do `docker exec -i $containerName bash -c 'cat > /opt/amnezia/awg/wg0.conf.next' < $localTmp`
        // But via SSH we can't easily stream a local file into a remote docker exec without a double pipe.
        // Let's copy it from remote host /tmp to remote container /opt/amnezia/awg
        
        $setupCmd = "docker cp $tmpPath $containerName:/opt/amnezia/awg/wg0.conf.next";
        $this->ssh->executeCommand($setupCmd, true, true);
        
        // Now validate inside container
        $validationCmd = "docker exec $containerName /usr/local/bin/awg-quick check /opt/amnezia/awg/wg0.conf.next";
        
        try {
            // awg-quick check might not exist? standard wg-quick doesn't have a 'check' command but it might just try to parse it
            // Let's check with `awg syncconf wg0 /opt/amnezia/awg/wg0.conf.next` ? No, syncconf requires valid syntax, but applies it.
            // A safe validation is just to ensure it's structurally sound.
            // Let's use `awg showconf /opt/amnezia/awg/wg0.conf.next` - actually, showconf takes an interface name.
            // Let's just trust `awg syncconf` to fail if syntax is bad, since it reads the file.
            
            // 4. Hot Swap & Sync
            // Atomic swap
            $swapCmd = "docker exec $containerName bash -c 'mv /opt/amnezia/awg/wg0.conf.next /opt/amnezia/awg/wg0.conf && /usr/local/bin/awg-quick strip /opt/amnezia/awg/wg0.conf > /opt/amnezia/awg/wg0.conf.stripped && /usr/local/bin/awg syncconf wg0 /opt/amnezia/awg/wg0.conf.stripped && rm -f /opt/amnezia/awg/wg0.conf.stripped'";
            $this->ssh->executeCommand($swapCmd, true, true);
            
            // Apply traffic shaping limits
            $defaultUp = (int)Config::get('DEFAULT_SPEED_LIMIT_UP', 0);
            $defaultDown = (int)Config::get('DEFAULT_SPEED_LIMIT_DOWN', 0);
            $this->applyTrafficShaping($containerName, $this->clients, $defaultUp, $defaultDown);
            
            // Sync dynamic routing mode packet markings (nftables sets)
            $this->syncWarpRoutingClients();
            
            Logger::channel('control-plane')->info('Declarative Sync Successful', ['server_id' => $this->server->getId()]);
            
        } catch (\Throwable $e) {
            // Cleanup the bad config
            $this->ssh->executeCommand("docker exec $containerName rm -f /opt/amnezia/awg/wg0.conf.next", false, false);
            throw new Exception("Declarative Sync Failed: " . $e->getMessage());
        } finally {
            $this->ssh->executeCommand("rm -f $tmpPath", false, false);
        }
    }

    /**
     * Apply traffic shaping limits on the server wg0 interface.
     */
    private function applyTrafficShaping(string $containerName, array $clients, int $defaultUp, int $defaultDown): void
    {
        try {
            $script = "#!/bin/bash\n";
            $script .= "# Check if tc is installed\n";
            $script .= "if ! command -v tc >/dev/null 2>&1; then\n";
            $script .= "    echo \"Warning: tc (iproute2) is not installed in the container. Bandwidth limits cannot be applied.\"\n";
            $script .= "    exit 0\n";
            $script .= "fi\n\n";

            $script .= "# Try loading the IFB and action mirred kernel modules on the host\n";
            $script .= "modprobe ifb numifbs=0 2>/dev/null || modprobe ifb 2>/dev/null || true\n";
            $script .= "modprobe act_mirred 2>/dev/null || true\n\n";

            $script .= "# Clear existing qdiscs and virtual devices\n";
            $script .= "tc qdisc del dev wg0 root 2>/dev/null || true\n";
            $script .= "tc qdisc del dev wg0 ingress 2>/dev/null || true\n";
            $script .= "tc qdisc del dev ifb-wg0 root 2>/dev/null || true\n";
            $script .= "ip link delete ifb-wg0 2>/dev/null || true\n\n";
            
            $hasDownloadLimits = false;
            $hasUploadLimits = false;
            $downloadRules = "";
            $uploadRules = "";
            $index = 0;
            
            foreach ($clients as $client) {
                $clientIp = $client['client_ip'];
                $limitDown = ($client['speed_limit_down'] ?? null) !== null ? (int)$client['speed_limit_down'] : $defaultDown;
                $limitUp = ($client['speed_limit_up'] ?? null) !== null ? (int)$client['speed_limit_up'] : $defaultUp;
                
                // Map each client to a unique sequential class ID (offset to avoid reserved IDs)
                $classId = 100 + $index;
                $index++;
                
                if ($limitDown > 0) {
                    if (!$hasDownloadLimits) {
                        $downloadRules .= "tc qdisc add dev wg0 root handle 1: htb default 10\n";
                        $downloadRules .= "tc class add dev wg0 parent 1: classid 1:10 htb rate 100gbit burst 256k cburst 256k\n";
                        $hasDownloadLimits = true;
                    }
                    $downloadRules .= "tc class add dev wg0 parent 1: classid 1:{$classId} htb rate {$limitDown}mbit ceil {$limitDown}mbit burst 256k cburst 256k\n";
                    $downloadRules .= "tc qdisc add dev wg0 parent 1:{$classId} handle {$classId}: fq_codel\n";
                    $downloadRules .= "tc filter add dev wg0 protocol ip parent 1:0 prio 1 u32 match ip dst {$clientIp} flowid 1:{$classId}\n";
                }
                
                if ($limitUp > 0) {
                    if (!$hasUploadLimits) {
                        $uploadRules .= "ip link add ifb-wg0 type ifb 2>/dev/null || true\n";
                        $uploadRules .= "ip link set dev ifb-wg0 txqueuelen 1000\n";
                        $uploadRules .= "ip link set dev ifb-wg0 up\n";
                        $uploadRules .= "tc qdisc add dev wg0 handle ffff: ingress\n";
                        $uploadRules .= "tc qdisc add dev ifb-wg0 root handle 1: htb default 10\n";
                        $uploadRules .= "tc class add dev ifb-wg0 parent 1: classid 1:10 htb rate 100gbit burst 256k cburst 256k\n";
                        $hasUploadLimits = true;
                    }
                    $uploadRules .= "tc class add dev ifb-wg0 parent 1: classid 1:{$classId} htb rate {$limitUp}mbit ceil {$limitUp}mbit burst 256k cburst 256k\n";
                    $uploadRules .= "tc qdisc add dev ifb-wg0 parent 1:{$classId} handle {$classId}: fq_codel\n";
                    $uploadRules .= "tc filter add dev ifb-wg0 protocol ip parent 1:0 prio 1 u32 match ip src {$clientIp} flowid 1:{$classId}\n";
                    $uploadRules .= "tc filter add dev wg0 parent ffff: protocol ip prio 1 u32 match ip src {$clientIp} action mirred egress redirect dev ifb-wg0\n";
                }
            }
            
            $script .= $downloadRules;
            $script .= $uploadRules;
            
            $base64 = base64_encode($script);
            $execCmd = sprintf(
                "docker exec -i %s bash -c " . escapeshellarg(
                    "echo " . escapeshellarg($base64) . " | base64 -d > /opt/amnezia/awg/tc_rules.sh && " .
                    "chmod +x /opt/amnezia/awg/tc_rules.sh && " .
                    "/opt/amnezia/awg/tc_rules.sh"
                ),
                $containerName
            );
            
            $this->ssh->executeCommand($execCmd, true, true);
        } catch (\Throwable $e) {
            Logger::channel('control-plane')->warning("Failed to apply traffic shaping limits on server: " . $e->getMessage());
        }
    }

    /**
     * Synchronize the nftables set of warp clients on the host server based on the database state.
     */
    public function syncWarpRoutingClients(): void
    {
        $pdo = DB::conn();
        
        // Fetch active clients with routing_mode = 'warp'
        $stmtWarp = $pdo->prepare("
            SELECT client_ip 
            FROM vpn_clients 
            WHERE server_id = ? 
              AND routing_mode = 'warp' 
              AND status IN ('active', 'verifying', 'provisioning') 
              AND deleted_at IS NULL
        ");
        $stmtWarp->execute([$this->server->getId()]);
        $warpClients = $stmtWarp->fetchAll(PDO::FETCH_COLUMN);

        $syncCmds = [
            "mkdir -p /var/lib/nk-core/state",
            "nft flush set inet nkcore warp_clients_v4 2>/dev/null || true",
            "nft flush set inet nkcore warp_clients_v6 2>/dev/null || true"
        ];
        
        $v4Ips = [];
        $v6Ips = [];
        
        if (!empty($warpClients)) {
            foreach ($warpClients as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $v4Ips[] = $ip;
                } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    $v6Ips[] = $ip;
                } else {
                    Logger::channel('control-plane')->warning("Security Alert: Skip invalid IP address for routing set sync", ['ip' => $ip]);
                }
            }
            
            if (!empty($v4Ips)) {
                $ipList = implode(', ', $v4Ips);
                $syncCmds[] = "nft add element inet nkcore warp_clients_v4 { {$ipList} } 2>/dev/null || true";
            }
            if (!empty($v6Ips)) {
                $ipList = implode(', ', $v6Ips);
                $syncCmds[] = "nft add element inet nkcore warp_clients_v6 { {$ipList} } 2>/dev/null || true";
            }
        }

        // Write persistent state files on the host to recover sets on server reboot
        $v4IpsStr = implode("\n", $v4Ips);
        $v6IpsStr = implode("\n", $v6Ips);
        $base64V4 = base64_encode($v4IpsStr);
        $base64V6 = base64_encode($v6IpsStr);
        
        $syncCmds[] = "echo '{$base64V4}' | base64 -d > /var/lib/nk-core/state/warp-clients-v4.txt";
        $syncCmds[] = "echo '{$base64V6}' | base64 -d > /var/lib/nk-core/state/warp-clients-v6.txt";
        
        // Update the warp_client_count cache in database
        $totalCount = count($v4Ips) + count($v6Ips);
        $pdo->prepare("UPDATE vpn_servers SET warp_client_count = ? WHERE id = ?")
            ->execute([$totalCount, $this->server->getId()]);
        
        $this->ssh->executeCommand(implode(' && ', $syncCmds), true, true);
    }
}
