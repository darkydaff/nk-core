<?php

class LinuxProvisioner
{
    private SshClient $ssh;
    private int $serverId;

    public function __construct(SshClient $ssh, int $serverId)
    {
        $this->ssh = $ssh;
        $this->serverId = $serverId;
    }

    /**
     * Prepare system with basic dependencies (sudo, curl, gnupg, etc)
     */
    public function prepareSystem(string $username): void
    {
        $hasApt = $this->ssh->executeCommand('which apt-get 2>/dev/null');
        $hasDnf = $this->ssh->executeCommand('which dnf 2>/dev/null');
        $hasYum = $this->ssh->executeCommand('which yum 2>/dev/null');

        if (!empty(trim($hasApt))) {
            // Fix potential dpkg interruption
            $this->ssh->executeCommand('dpkg --configure -a 2>/dev/null || true', true);

            // Install sudo if missing
            $hasSudo = $this->ssh->executeCommand('which sudo 2>/dev/null');
            if (empty(trim($hasSudo)) && strtolower($username) === 'root') {
                $this->ssh->executeCommand('apt-get update && apt-get install -y sudo', false, false, false, 600);
            }

            // Install curl and gnupg if missing (needed for Docker and PPA key import).
            // Use { } grouping to avoid && / || precedence issues.
            $this->ssh->executeCommand(
                '{ apt-get install -y curl ca-certificates gnupg2; } 2>/dev/null || ' .
                '{ apt-get update -q && apt-get install -y curl ca-certificates gnupg2; }',
                true, false, false, 600
            );
        } elseif (!empty(trim($hasDnf)) || !empty(trim($hasYum))) {
            $mgr = !empty(trim($hasDnf)) ? 'dnf' : 'yum';

            // Install sudo/curl on RPM-based systems
            $this->ssh->executeCommand("{$mgr} install -y sudo curl ca-certificates 2>/dev/null || true", true, false, false, 300);
        }

        // Enable and persist IP forwarding and optimize network buffers for high-speed VPN on the host
        $this->ssh->executeCommand(
            'sysctl -w net.ipv4.ip_forward=1 net.ipv6.conf.all.forwarding=1 ' .
            'net.core.rmem_max=16777216 net.core.wmem_max=16777216 net.core.netdev_max_backlog=10000 2>/dev/null || true', 
            true
        );
        $this->ssh->executeCommand(
            'mkdir -p /etc/sysctl.d && ' .
            'echo "net.ipv4.ip_forward=1" | tee /etc/sysctl.d/99-ip-forward.conf && ' .
            'echo "net.ipv6.conf.all.forwarding=1" | tee /etc/sysctl.d/99-ip-forward-v6.conf && ' .
            'echo "net.core.rmem_max=16777216" | tee /etc/sysctl.d/99-vpn-buffers.conf && ' .
            'echo "net.core.wmem_max=16777216" | tee -a /etc/sysctl.d/99-vpn-buffers.conf && ' .
            'echo "net.core.netdev_max_backlog=10000" | tee -a /etc/sysctl.d/99-vpn-buffers.conf && ' .
            'sysctl --system 2>/dev/null || true',
            true
        );
    }

    /**
     * Install Docker on remote server
     */
    public function installDocker(): void
    {
        $dockerVersion = $this->ssh->executeCommand('docker --version');
        if (stripos($dockerVersion, 'version') !== false) {
            return; // Docker already installed
        }

        $this->ssh->executeCommand('curl -fsSL https://get.docker.com | sh', true, true);
        $this->ssh->executeCommand('systemctl enable --now docker', true, true);
    }

    /**
     * Find free UDP port on remote server
     */
    public function findFreeUdpPort(): int
    {
        $out = $this->ssh->executeCommand("ss -lun | awk '{print \$4}' | grep -oE '[0-9]+$'", false);
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
     * Install AmneziaWG kernel module — supports Ubuntu, Debian, RHEL/CentOS/Fedora.
     * Returns true if successfully loaded, false otherwise.
     */
    public function installKernelModule(): bool
    {
        // Check if module is already loaded
        $check = $this->ssh->executeCommand('lsmod | grep -c amneziawg 2>/dev/null || echo 0');
        if ((int)trim($check) > 0) {
            return true;
        }

        // Gather distro/kernel info in one SSH round-trip
        $env = $this->detectRemoteEnvironment();

        Logger::channel('deployments')->info('Installing AmneziaWG kernel module', [
            'server_id'   => $this->serverId,
            'kernel'      => $env['kernel'],
            'arch'        => $env['arch'],
            'distro_id'   => $env['distro_id'],
            'codename'    => $env['codename'],
            'pkg_manager' => $env['pkg_manager'],
        ]);

        $installed = false;
        if ($env['pkg_manager'] === 'apt') {
            $installed = $this->installKernelModuleApt($env);
        } elseif (in_array($env['pkg_manager'], ['dnf', 'yum'], true)) {
            $installed = $this->installKernelModuleDnf($env);
        } else {
            Logger::channel('deployments')->warning('Unsupported package manager — skipping kernel module, will use userspace fallback', [
                'server_id'   => $this->serverId,
                'pkg_manager' => $env['pkg_manager'],
            ]);
            return false;
        }

        return $installed;
    }

    /**
     * Gather kernel/distro/architecture info from the remote host in a single SSH call.
     */
    public function detectRemoteEnvironment(): array
    {
        $script =
            'echo "KERNEL=$(uname -r)"; ' .
            'echo "ARCH=$(uname -m)"; ' .
            'DPKG=$(dpkg --print-architecture 2>/dev/null || echo unknown); echo "DPKG_ARCH=${DPKG}"; ' .
            '{ . /etc/os-release 2>/dev/null && echo "DISTRO_ID=${ID}" && C=${VERSION_CODENAME}; [ -z "$C" ] && C=${UBUNTU_CODENAME}; [ -z "$C" ] && C=unknown; echo "CODENAME=$C"; } || true; ' .
            '{ which apt-get >/dev/null 2>&1 && echo "PKG_MGR=apt"; } || ' .
            '{ which dnf >/dev/null 2>&1 && echo "PKG_MGR=dnf"; } || ' .
            '{ which yum >/dev/null 2>&1 && echo "PKG_MGR=yum"; } || ' .
            'echo "PKG_MGR=apt"';

        $output = $this->ssh->executeCommand($script, false, false, false, 15);

        $env = [
            'kernel'      => '',
            'arch'        => 'x86_64',
            'dpkg_arch'   => 'amd64',
            'distro_id'   => 'unknown',
            'codename'    => 'unknown',
            'pkg_manager' => 'apt',
        ];

        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if (preg_match('/^KERNEL=(.+)$/', $line, $m))    $env['kernel']      = trim($m[1]);
            if (preg_match('/^ARCH=(.+)$/', $line, $m))      $env['arch']        = trim($m[1]);
            if (preg_match('/^DPKG_ARCH=(.+)$/', $line, $m)) $env['dpkg_arch']   = trim($m[1]);
            if (preg_match('/^DISTRO_ID=(.+)$/', $line, $m)) $env['distro_id']   = strtolower(trim($m[1]));
            if (preg_match('/^CODENAME=(.+)$/', $line, $m))  $env['codename']    = strtolower(trim($m[1]));
            if (preg_match('/^PKG_MGR=(.+)$/', $line, $m))   $env['pkg_manager'] = strtolower(trim($m[1]));
        }

        return $env;
    }

    /**
     * Install AmneziaWG on apt-based systems (Ubuntu, Debian, Linux Mint).
     * Ubuntu: uses add-apt-repository (auto-resolves codename).
     * Debian/other: uses manual focal PPA per official amnezia docs.
     */
    private function installKernelModuleApt(array $env): bool
    {
        $kernel   = $env['kernel'];
        $isUbuntu = in_array($env['distro_id'], ['ubuntu', 'linuxmint'], true);
        $isXanmod = stripos($kernel, 'xanmod') !== false;

        // Step 1: Prerequisites (build tools + DKMS + PPA helpers)
        $this->ssh->executeCommand(
            '{ DEBIAN_FRONTEND=noninteractive apt-get install -y ' .
            'software-properties-common python3-launchpadlib gnupg2 ca-certificates ' .
            'build-essential dkms; } 2>/dev/null || ' .
            '{ apt-get update -q && DEBIAN_FRONTEND=noninteractive apt-get install -y ' .
            'software-properties-common python3-launchpadlib gnupg2 ca-certificates build-essential dkms; }',
            true, false, false, 600
        );

        // Step 2: Kernel headers — waterfall fallback chain
        $this->installKernelHeadersApt($kernel, $env['dpkg_arch'], $isXanmod);

        // Step 3: Add Amnezia PPA
        if ($isUbuntu) {
            $this->ssh->executeCommand('add-apt-repository -y ppa:amnezia/ppa', true, false, false, 120);
        } else {
            $this->setupAmneziaRepoDeb($env['dpkg_arch']);
        }

        // Step 4: Update index and install
        $this->ssh->executeCommand('apt-get update', true, false, false, 300);
        $this->ssh->executeCommand('DEBIAN_FRONTEND=noninteractive apt-get install -y amneziawg', true, false, false, 600);

        // Step 5: Load module and persist on boot
        $this->ssh->executeCommand('modprobe amneziawg 2>/dev/null || true', true);
        $this->ssh->executeCommand('echo "amneziawg" | tee /etc/modules-load.d/amneziawg.conf 2>/dev/null || true', true);

        // Verify
        $check = $this->ssh->executeCommand('lsmod | grep -c amneziawg 2>/dev/null || echo 0');
        $loaded = (int)trim($check) > 0;

        Logger::channel('deployments')->info('AmneziaWG kernel module (apt) install result', [
            'server_id' => $this->serverId,
            'loaded'    => $loaded,
        ]);

        return $loaded;
    }

    /**
     * Install kernel headers using a waterfall fallback chain.
     */
    private function installKernelHeadersApt(string $kernel, string $dpkgArch, bool $isXanmod): void
    {
        if (empty($kernel)) {
            Logger::channel('deployments')->warning('Empty kernel string — skipping header install, userspace fallback will be used', [
                'server_id' => $this->serverId,
            ]);
            return;
        }

        // 1. Attempt exact versioned match (most reliable for current running kernel)
        $this->ssh->executeCommand("apt-get install -y linux-headers-{$kernel} 2>/dev/null || true", true, false, false, 300);

        // 2. Also install the metapackage to ensure future kernel updates pull headers automatically (prevents DKMS breaks on reboot)
        if ($isXanmod) {
            $this->ssh->executeCommand(
                'apt-get install -y linux-headers-xanmod-edge 2>/dev/null || ' .
                'apt-get install -y linux-headers-xanmod-lts 2>/dev/null || ' .
                'apt-get install -y linux-headers-xanmod 2>/dev/null || true',
                true, false, false, 300
            );
        } else {
            $this->ssh->executeCommand('apt-get install -y linux-headers-generic 2>/dev/null || true', true, false, false, 300);
        }

        // Verify if headers for the current kernel exist
        $headersExist = $this->ssh->executeCommand(
            "test -d /usr/src/linux-headers-{$kernel} && echo yes || echo no", true
        );
        if (trim($headersExist) === 'yes') {
            return;
        }

        // 3. Version-flavor fallback if exact match wasn't found (e.g. linux-headers-amd64, linux-headers-generic)
        $flavor = '';
        if (preg_match('/^\d+\.\d+\.\d+-\d+-(.+)$/', $kernel, $m)) {
            $flavor = $m[1];
        }

        if ($flavor && !$isXanmod) {
            $this->ssh->executeCommand("apt-get install -y linux-headers-{$flavor} 2>/dev/null || true", true, false, false, 300);
            $headersExist = $this->ssh->executeCommand(
                "test -d /usr/src/linux-headers-{$kernel} && echo yes || echo no", true
            );
            if (trim($headersExist) === 'yes') {
                return;
            }
        }

        Logger::channel('deployments')->warning('Could not install kernel headers — DKMS may fail, userspace fallback will be used', [
            'server_id' => $this->serverId,
            'kernel'    => $kernel,
            'dpkg_arch' => $dpkgArch,
            'xanmod'    => $isXanmod,
        ]);
    }

    /**
     * Add the Amnezia PPA on Debian systems.
     */
    private function setupAmneziaRepoDeb(string $dpkgArch): void
    {
        $ppaUrl   = 'https://ppa.launchpadcontent.net/amnezia/ppa/ubuntu focal main';
        $keyring  = '/usr/share/keyrings/amnezia-archive-keyring.gpg';

        $gpgImport = implode(' && ', [
            'mkdir -p /usr/share/keyrings',
            'gpg --batch --keyserver keyserver.ubuntu.com --recv-keys 57290828',
            "gpg --batch --yes --export 57290828 | gpg --batch --yes --dearmor -o {$keyring}",
            "test -s {$keyring}",   // verify the file is non-empty
        ]);

        $exitCode = $this->ssh->executeCommand(
            "{$gpgImport} && echo GPG_OK || echo GPG_FAIL",
            true, false, false, 60
        );

        if (strpos($exitCode, 'GPG_OK') !== false) {
            $signedBy = "[arch={$dpkgArch} signed-by={$keyring}]";
            $this->ssh->executeCommand("echo \"deb {$signedBy} {$ppaUrl}\" | tee /etc/apt/sources.list.d/amnezia.list", true);
            $this->ssh->executeCommand("echo \"deb-src {$signedBy} {$ppaUrl}\" | tee -a /etc/apt/sources.list.d/amnezia.list", true);
        } else {
            Logger::channel('deployments')->info('GPG dearmor failed — falling back to apt-key', ['server_id' => $this->serverId]);
            $this->ssh->executeCommand('apt-key adv --keyserver keyserver.ubuntu.com --recv-keys 57290828', true, false, false, 60);
            $this->ssh->executeCommand("echo \"deb {$ppaUrl}\" | tee /etc/apt/sources.list.d/amnezia.list", true);
            $this->ssh->executeCommand("echo \"deb-src {$ppaUrl}\" | tee -a /etc/apt/sources.list.d/amnezia.list", true);
        }
    }

    /**
     * Install AmneziaWG on RPM-based systems (RHEL, CentOS, Fedora, SUSE).
     */
    private function installKernelModuleDnf(array $env): bool
    {
        $mgr = $env['pkg_manager']; // 'dnf' or 'yum'

        $this->ssh->executeCommand("{$mgr} install -y epel-release 2>/dev/null || true", true, false, false, 300);

        if ($mgr === 'yum') {
            $this->ssh->executeCommand('yum install -y yum-plugin-copr 2>/dev/null || true', true, false, false, 120);
        } else {
            $this->ssh->executeCommand('dnf install -y dnf-plugins-core 2>/dev/null || true', true, false, false, 120);
        }

        $coprResult = $this->ssh->executeCommand(
            "{$mgr} copr enable -y amneziavpn/amneziawg && echo COPR_OK || echo COPR_FAIL",
            true, false, false, 120
        );

        if (strpos($coprResult, 'COPR_OK') === false) {
            Logger::channel('deployments')->warning('Failed to enable Amnezia COPR repository', [
                'server_id' => $this->serverId,
                'output'    => substr($coprResult, 0, 500),
            ]);
            return false;
        }

        $this->ssh->executeCommand("{$mgr} install -y amneziawg-dkms amneziawg-tools 2>/dev/null || true", true, false, false, 600);

        // Load module and persist on boot
        $this->ssh->executeCommand('modprobe amneziawg 2>/dev/null || true', true);
        $this->ssh->executeCommand('echo "amneziawg" | tee /etc/modules-load.d/amneziawg.conf 2>/dev/null || true', true);

        $check = $this->ssh->executeCommand('lsmod | grep -c amneziawg 2>/dev/null || echo 0');
        $loaded = (int)trim($check) > 0;

        Logger::channel('deployments')->info('AmneziaWG kernel module (dnf/yum) install result', [
            'server_id' => $this->serverId,
            'loaded'    => $loaded,
        ]);

        return $loaded;
    }

    public function setupWarpHostRules(string $vpnSubnet): void
    {
        // Construct the custom nftables rule for packet marking
        $nftWarp = "table inet nkcore {\n" .
                   "    set warp_clients {\n" .
                   "        type ipv4_addr\n" .
                   "    }\n" .
                   "    chain prerouting {\n" .
                   "        type filter hook prerouting priority mangle; policy accept;\n" .
                   "        ip saddr @warp_clients meta mark set 100\n" .
                   "    }\n" .
                   "}\n";
        $base64NftWarp = base64_encode($nftWarp);

        // Construct the custom nftables rule for masquerading nat
        $nftNat = "table ip nat {\n" .
                  "    chain postrouting {\n" .
                  "        type nat hook postrouting priority 100; policy accept;\n" .
                  "        oifname \"wg-warp\" masquerade\n" .
                  "    }\n" .
                  "}\n";
        $base64NftNat = base64_encode($nftNat);

        // Construct the persistent post-up interface routing setup script
        $wgWarpScript = "#!/bin/bash\n" .
                        "ip route replace " . $vpnSubnet . " dev wg0 table warp 2>/dev/null || true\n" .
                        "ip route replace default dev wg-warp table warp 2>/dev/null || true\n" .
                        "ip rule del fwmark 100 lookup warp priority 200 2>/dev/null || true\n" .
                        "ip rule add fwmark 100 lookup warp priority 200\n";
        $base64WarpScript = base64_encode($wgWarpScript);

        $setupCmd = implode(' && ', [
            // 1. Install and register Cloudflare WARP automatically if not present
            "if [ ! -f /etc/wireguard/wg-warp.conf ]; then " .
                "if [ ! -f /usr/local/bin/wgcf ]; then " .
                    "curl -fsSL https://github.com/ViRb3/wgcf/releases/latest/download/wgcf_linux_amd64 -o /usr/local/bin/wgcf && " .
                    "chmod +x /usr/local/bin/wgcf; " .
                "fi && " .
                "mkdir -p /tmp/wgcf_setup && " .
                "cd /tmp/wgcf_setup && " .
                "wgcf register --accept-tos && " .
                "wgcf generate && " .
                "mkdir -p /etc/wireguard && " .
                "cp wgcf-profile.conf /etc/wireguard/wg-warp.conf && " .
                "echo '' >> /etc/wireguard/wg-warp.conf && " .
                "echo 'Table = off' >> /etc/wireguard/wg-warp.conf && " .
                "rm -rf /tmp/wgcf_setup; " .
            "fi",
            
            // 2. Enable and start wg-warp WireGuard interface
            "systemctl enable wg-quick@wg-warp.service",
            "systemctl start wg-quick@wg-warp.service",

            // 3. Ensure Table warp (ID 200) registered
            "if ! grep -q '200 warp' /etc/iproute2/rt_tables; then echo '200 warp' >> /etc/iproute2/rt_tables; fi",
            
            // 4. Deploy nftables custom config using base64 decoding to avoid multiline shell parsing issues
            "mkdir -p /etc/nftables.d",
            "echo '{$base64NftWarp}' | base64 -d > /etc/nftables.d/nkcore-warp.nft",
            
            // 5. Deploy nat postrouting config (pure nftables)
            "echo '{$base64NftNat}' | base64 -d > /etc/nftables.d/nkcore-nat.nft",
            
            // Ensure they are included in /etc/nftables.conf
            "if ! grep -q 'nkcore-warp.nft' /etc/nftables.conf; then echo 'include \"/etc/nftables.d/nkcore-warp.nft\"' >> /etc/nftables.conf; fi",
            "if ! grep -q 'nkcore-nat.nft' /etc/nftables.conf; then echo 'include \"/etc/nftables.d/nkcore-nat.nft\"' >> /etc/nftables.conf; fi",
            "systemctl reload nftables 2>/dev/null || systemctl restart nftables 2>/dev/null || true",

            // 6. Create persistent startup routing commands using base64 decoding
            "mkdir -p /etc/wireguard/post-up.d",
            "echo '{$base64WarpScript}' | base64 -d > /etc/wireguard/post-up.d/wg-warp.sh",
            "chmod +x /etc/wireguard/post-up.d/wg-warp.sh",
            
            // Execute rules immediately
            "/etc/wireguard/post-up.d/wg-warp.sh"
        ]);

        $this->ssh->executeCommand($setupCmd, true, true);
    }

    /**
     * Setup native host-level NAT masquerading rules for the VPN subnet via nftables
     */
    public function setupHostNatRules(string $vpnSubnet): void
    {
        $nftNat = "table ip nkcore_vpn_nat {\n" .
                  "    chain postrouting {\n" .
                  "        type nat hook postrouting priority 100; policy accept;\n" .
                  "        ip saddr {$vpnSubnet} masquerade\n" .
                  "    }\n" .
                  "    chain forward {\n" .
                  "        type filter hook forward priority 0; policy accept;\n" .
                  "        ip saddr {$vpnSubnet} tcp flags syn tcp option maxseg size set 1380\n" .
                  "        ip daddr {$vpnSubnet} tcp flags syn tcp option maxseg size set 1380\n" .
                  "    }\n" .
                  "}\n";
        $base64NftNat = base64_encode($nftNat);

        $setupCmd = implode(' && ', [
            "which nft >/dev/null 2>&1 || (apt-get update -q && apt-get install -y nftables) || (yum install -y nftables) || true",
            "systemctl enable nftables",
            "mkdir -p /etc/nftables.d",
            "echo '{$base64NftNat}' | base64 -d > /etc/nftables.d/nkcore-vpn-nat.nft",
            "if ! grep -q 'nkcore-vpn-nat.nft' /etc/nftables.conf; then echo 'include \"/etc/nftables.d/nkcore-vpn-nat.nft\"' >> /etc/nftables.conf; fi",
            "systemctl reload nftables 2>/dev/null || systemctl restart nftables 2>/dev/null || true"
        ]);

        $this->ssh->executeCommand($setupCmd, true, true);
    }

    /**
     * Install the health watchdog failover systemd service/cron job on the host.
     */
    public function installWarpWatchdog(): void {
        $watchdogScript = <<<'BASH'
#!/bin/bash
# 1. Verify policy routing dev matches wg-warp
ROUTE_DEV=$(ip route get 1.1.1.1 mark 100 2>/dev/null | grep -oE "dev [a-zA-Z0-9_-]+" | awk '{print $2}')
if [ "$ROUTE_DEV" != "wg-warp" ]; then
    echo "WARP routing invalid! Dev: $ROUTE_DEV. Flushing warp_clients set."
    nft flush set inet nkcore warp_clients
    exit 0
fi

# 2. Verify link state and test ping through the route
if ! ping -c 2 -W 3 -I wg-warp 1.1.1.1 >/dev/null 2>&1; then
    echo "WARP health check failed! Flushing set to fallback to direct."
    nft flush set inet nkcore warp_clients
fi
BASH;

        $base64Script = base64_encode($watchdogScript);
        
        $setupCmd = implode(' && ', [
            "echo '{$base64Script}' | base64 -d > /usr/local/bin/nk-warp-watchdog.sh",
            "chmod +x /usr/local/bin/nk-warp-watchdog.sh",
            
            // Setup Cron job to run watchdog every minute
            "(crontab -l 2>/dev/null | grep -v 'nk-warp-watchdog.sh'; echo '* * * * * /usr/local/bin/nk-warp-watchdog.sh') | crontab -"
        ]);

        $this->ssh->executeCommand($setupCmd, true, true);
    }
}
