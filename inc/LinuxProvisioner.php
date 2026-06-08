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
                   "    set warp_clients_v4 {\n" .
                   "        type ipv4_addr\n" .
                   "    }\n" .
                   "    set warp_clients_v6 {\n" .
                   "        type ipv6_addr\n" .
                   "    }\n" .
                   "    chain prerouting {\n" .
                   "        type filter hook prerouting priority mangle; policy accept;\n" .
                   "        ip saddr @warp_clients_v4 meta mark set 100\n" .
                   "        ip saddr @warp_clients_v6 meta mark set 100\n" .
                   "    }\n" .
                   "}\n";
        $base64NftWarp = base64_encode($nftWarp);

        // Construct the custom nftables rule for masquerading nat
        $nftNat = "table ip nat {\n" .
                  "    chain postrouting {\n" .
                  "        type nat hook postrouting priority 100; policy accept;\n" .
                  "        ip saddr {$vpnSubnet} oifname \"wg-warp\" masquerade\n" .
                  "    }\n" .
                  "}\n";
        $base64NftNat = base64_encode($nftNat);

        // Construct the persistent post-up interface routing setup script
        $wgWarpScript = "#!/bin/bash\n" .
                        "ip route replace " . $vpnSubnet . " dev wg0 table warp 2>/dev/null || true\n" .
                        "ip route replace default dev wg-warp table warp 2>/dev/null || true\n" .
                        "ip rule del fwmark 100 lookup warp priority 11000 2>/dev/null || true\n" .
                        "ip rule add fwmark 100 lookup warp priority 11000\n" .
                        "\n" .
                        "# Re-populate nftables sets from saved state files on startup/interface up\n" .
                        "if [ -f /var/lib/nk-core/state/warp-clients-v4.txt ]; then\n" .
                        "    IPS=\$(tr '\\n' ',' < /var/lib/nk-core/state/warp-clients-v4.txt | sed 's/,\$//')\n" .
                        "    if [ -n \"\$IPS\" ]; then\n" .
                        "        nft add element inet nkcore warp_clients_v4 { \$IPS } 2>/dev/null || true\n" .
                        "    fi\n" .
                        "fi\n" .
                        "if [ -f /var/lib/nk-core/state/warp-clients-v6.txt ]; then\n" .
                        "    IPS=\$(tr '\\n' ',' < /var/lib/nk-core/state/warp-clients-v6.txt | sed 's/,\$//')\n" .
                        "    if [ -n \"\$IPS\" ]; then\n" .
                        "        nft add element inet nkcore warp_clients_v6 { \$IPS } 2>/dev/null || true\n" .
                        "    fi\n" .
                        "fi\n";
        $base64WarpScript = base64_encode($wgWarpScript);

        $setupCmd = implode(' && ', [
            // Ensure wireguard-tools package and iproute2 directory/file are present on the host
            "mkdir -p /var/lib/nk-core/state /etc/iproute2 /etc/wireguard",
            "touch /etc/iproute2/rt_tables",
            "if ! command -v wg-quick >/dev/null 2>&1; then " .
                "(apt-get update -q && apt-get install -y wireguard-tools) || " .
                "(yum install -y wireguard-tools) || " .
                "(apk add wireguard-tools) || true; " .
            "fi",
            
            // Capture original gateway only if not already saved
            "if [ ! -f /var/lib/nk-core/state/warp-config.sh ]; then " .
                "GW=\$(ip route show default | awk '/default/ {print \$3}') && " .
                "DEV=\$(ip route show default | awk '/default/ {print \$5}') && " .
                "echo \"ORIG_GW=\\\"\$GW\\\"\" > /var/lib/nk-core/state/warp-config.sh && " .
                "echo \"ORIG_DEV=\\\"\$DEV\\\"\" >> /var/lib/nk-core/state/warp-config.sh && " .
                "echo \"VPN_SUBNET=\\\"$vpnSubnet\\\"\" >> /var/lib/nk-core/state/warp-config.sh && " .
                "echo \"WARP_DEV=\\\"wg-warp\\\"\" >> /var/lib/nk-core/state/warp-config.sh; " .
            "fi",

            // 1. Install and register Cloudflare WARP automatically if not present
            "if [ ! -f /etc/wireguard/wg-warp.conf ]; then " .
                "if [ ! -f /usr/local/bin/wgcf ]; then " .
                    "WGCF_URL=\$(curl -fsSL https://api.github.com/repos/ViRb3/wgcf/releases/latest 2>/dev/null | grep \"browser_download_url\" | grep \"linux_amd64\" | cut -d '\"' -f 4 || true) && " .
                    "if [ -z \"\$WGCF_URL\" ]; then WGCF_URL=\"https://github.com/ViRb3/wgcf/releases/download/v2.2.31/wgcf_2.2.31_linux_amd64\"; fi && " .
                    "curl -fsSL \"\$WGCF_URL\" -o /usr/local/bin/wgcf && " .
                    "chmod +x /usr/local/bin/wgcf; " .
                "fi && " .
                "mkdir -p /tmp/wgcf_setup && " .
                "cd /tmp/wgcf_setup && " .
                "wgcf register --accept-tos && " .
                "wgcf generate && " .
                "mkdir -p /etc/wireguard && " .
                "cp wgcf-profile.conf /etc/wireguard/wg-warp.conf && " .
                "cp wgcf-account.toml /etc/wireguard/wgcf-account.toml && " .
                "rm -rf /tmp/wgcf_setup; " .
            "fi",

            // Clean up any existing Table, PostUp, PostDown, or DNS configuration lines to ensure idempotency and correct section placement
            "sed -i '/^[[:space:]]*Table[[:space:]]*=/d' /etc/wireguard/wg-warp.conf",
            "sed -i '/^[[:space:]]*PostUp[[:space:]]*=/d' /etc/wireguard/wg-warp.conf",
            "sed -i '/^[[:space:]]*PostDown[[:space:]]*=/d' /etc/wireguard/wg-warp.conf",
            "sed -i '/^[[:space:]]*DNS[[:space:]]*=/d' /etc/wireguard/wg-warp.conf",
            
            // Ensure Table, PostUp, and PostDown rules are correctly placed in the [Interface] section of configuration
            "sed -i '/\\[Interface\\]/a Table = off' /etc/wireguard/wg-warp.conf",
            "sed -i '/\\[Interface\\]/a PostUp = /etc/wireguard/post-up.d/wg-warp.sh' /etc/wireguard/wg-warp.conf",
            "sed -i '/\\[Interface\\]/a PostDown = ip rule del fwmark 100 lookup warp priority 11000 2>/dev/null || true' /etc/wireguard/wg-warp.conf",
            
            // 2. Ensure Table warp (ID 200) registered
            "if ! grep -q '200 warp' /etc/iproute2/rt_tables; then echo '200 warp' >> /etc/iproute2/rt_tables; fi",
            
            // 3. Deploy nftables custom config
            "mkdir -p /etc/nftables.d",
            "echo '{$base64NftWarp}' | base64 -d > /etc/nftables.d/nkcore-warp.nft",
            "echo '{$base64NftNat}' | base64 -d > /etc/nftables.d/nkcore-nat.nft",
            
            // Ensure they are included in /etc/nftables.conf
            "if ! grep -q 'nkcore-warp.nft' /etc/nftables.conf; then echo 'include \"/etc/nftables.d/nkcore-warp.nft\"' >> /etc/nftables.conf; fi",
            "if ! grep -q 'nkcore-nat.nft' /etc/nftables.conf; then echo 'include \"/etc/nftables.d/nkcore-nat.nft\"' >> /etc/nftables.conf; fi",
            "systemctl reload nftables 2>/dev/null || systemctl restart nftables 2>/dev/null || true",

            // 4. Create persistent startup routing commands
            "mkdir -p /etc/wireguard/post-up.d",
            "echo '{$base64WarpScript}' | base64 -d > /etc/wireguard/post-up.d/wg-warp.sh",
            "chmod +x /etc/wireguard/post-up.d/wg-warp.sh",
            
            // 5. Enable and start wg-warp interface
            "systemctl enable wg-quick@wg-warp.service",
            "systemctl restart wg-quick@wg-warp.service",
            
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
                  "        ip saddr {$vpnSubnet} tcp flags syn tcp option maxseg size set 1240\n" .
                  "        ip daddr {$vpnSubnet} tcp flags syn tcp option maxseg size set 1240\n" .
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
mkdir -p /var/lib/nk-core/state
CONFIG_FILE="/var/lib/nk-core/state/warp-config.sh"
HEALTH_FILE="/var/lib/nk-core/state/warp-health.json"

if [ ! -f "$CONFIG_FILE" ]; then
    echo "Error: Configuration file $CONFIG_FILE not found. Exiting."
    exit 1
fi
source "$CONFIG_FILE"

# Initialize health variables if not exists
if [ ! -f "$HEALTH_FILE" ] || [ ! -s "$HEALTH_FILE" ]; then
    echo '{"status":"error","failures":0,"repairs":0,"cloudflare_ip":null,"last_check_at":null,"last_check_status":null,"last_repair_at":null,"last_repair_result":null}' > "$HEALTH_FILE"
fi

get_json_val() {
    local key=$1
    grep -oP '"'"$key"'"\s*:\s*(("[^"]*")|([0-9]+)|(null))' "$HEALTH_FILE" | head -1 | cut -d: -f2- | tr -d '" '
}

FAILURES=$(get_json_val "failures")
REPAIRS=$(get_json_val "repairs")
LAST_REPAIR_AT=$(grep -oP '"last_repair_at"\s*:\s*"[^"]*"' "$HEALTH_FILE" | cut -d: -f2- | tr -d '" ')
[ -z "$FAILURES" ] && FAILURES=0
[ -z "$REPAIRS" ] && REPAIRS=0

CHECK_STATUS="Healthy"
IS_HEALTHY=1
CLOUDFLARE_IP=""

# 1. Link check
if ! ip link show "$WARP_DEV" >/dev/null 2>&1; then
    IS_HEALTHY=0
    CHECK_STATUS="Interface $WARP_DEV missing"
elif ! ip link show "$WARP_DEV" | grep -q "UP"; then
    IS_HEALTHY=0
    CHECK_STATUS="Interface $WARP_DEV down"
fi

# 2. Handshake age check
if [ "$IS_HEALTHY" -eq 1 ]; then
    HANDSHAKE=$(wg show "$WARP_DEV" latest-handshakes 2>/dev/null | awk '{print $2}')
    if [ -z "$HANDSHAKE" ] || [ "$HANDSHAKE" -eq 0 ]; then
        IS_HEALTHY=0
        CHECK_STATUS="No WireGuard handshake"
    else
        NOW=$(date +%s)
        AGE=$((NOW - HANDSHAKE))
        if [ "$AGE" -gt 300 ]; then
            IS_HEALTHY=0
            CHECK_STATUS="Handshake age is $AGE seconds"
        fi
    fi
fi

# 3. Internet connectivity via WARP
if [ "$IS_HEALTHY" -eq 1 ]; then
    TRACE=$(curl -s --interface "$WARP_DEV" --connect-timeout 5 https://www.cloudflare.com/cdn-cgi/trace)
    if [ $? -ne 0 ]; then
        IS_HEALTHY=0
        CHECK_STATUS="Trace request failed"
    elif ! echo "$TRACE" | grep -q "warp=on"; then
        IS_HEALTHY=0
        CHECK_STATUS="WARP is off in trace"
    else
        CLOUDFLARE_IP=$(echo "$TRACE" | grep -E "^ip=" | cut -d= -f2)
    fi
fi

TIMESTAMP=$(date -u +"%Y-%m-%d %H:%M:%S UTC")
REPAIR_MSG=""

if [ "$IS_HEALTHY" -eq 1 ]; then
    FAILURES=0
    REPAIRS=0
    STATUS_STR="connected"
    
    # Restore WARP route
    ip route replace default dev "$WARP_DEV" table warp 2>/dev/null || true
else
    FAILURES=$((FAILURES + 1))
    
    if [ "$FAILURES" -ge 3 ]; then
        STATUS_STR="error"
        if [ -n "$ORIG_GW" ] && [ -n "$ORIG_DEV" ]; then
            ip route replace default via "$ORIG_GW" dev "$ORIG_DEV" table warp 2>/dev/null || true
            STATUS_STR="degraded"
            CHECK_STATUS="$CHECK_STATUS (Failover Active)"
        fi
        
        NOW_SEC=$(date +%s)
        LAST_REPAIR_SEC=0
        if [ -n "$LAST_REPAIR_AT" ] && [ "$LAST_REPAIR_AT" != "null" ]; then
            LAST_REPAIR_SEC=$(date -d "$LAST_REPAIR_AT" +%s 2>/dev/null || echo 0)
        fi
        TIME_DIFF=$((NOW_SEC - LAST_REPAIR_SEC))
        
        if [ "$TIME_DIFF" -lt 900 ]; then
            if [ "$REPAIRS" -ge 3 ]; then
                REPAIR_MSG="Ceased repairs (Cooldown active, diff: ${TIME_DIFF}s)"
            else
                REPAIRS=$((REPAIRS + 1))
                systemctl restart wg-quick@"$WARP_DEV".service
                LAST_REPAIR_AT="$TIMESTAMP"
                REPAIR_MSG="Restarted wg-quick@$WARP_DEV (Attempt $REPAIRS)"
            fi
        else
            REPAIRS=1
            systemctl restart wg-quick@"$WARP_DEV".service
            LAST_REPAIR_AT="$TIMESTAMP"
            REPAIR_MSG="Restarted wg-quick@$WARP_DEV (New Window)"
        fi
    else
        STATUS_STR="connected"
    fi
fi

TMP_JSON=$(mktemp)
CF_IP_VAL="null"
[ -n "$CLOUDFLARE_IP" ] && CF_IP_VAL="\"$CLOUDFLARE_IP\""

REP_AT_VAL="null"
[ -n "$LAST_REPAIR_AT" ] && [ "$LAST_REPAIR_AT" != "null" ] && REP_AT_VAL="\"$LAST_REPAIR_AT\""

REP_MSG_VAL="null"
[ -n "$REPAIR_MSG" ] && REP_MSG_VAL="\"$REPAIR_MSG\""

cat <<EOF > "$TMP_JSON"
{
  "status": "$STATUS_STR",
  "failures": $FAILURES,
  "repairs": $REPAIRS,
  "cloudflare_ip": $CF_IP_VAL,
  "last_check_at": "$TIMESTAMP",
  "last_check_status": "$CHECK_STATUS",
  "last_repair_at": $REP_AT_VAL,
  "last_repair_result": $REP_MSG_VAL
}
EOF

mv "$TMP_JSON" "$HEALTH_FILE"
BASH;

        $base64Script = base64_encode($watchdogScript);

        $serviceDef = <<<SYSTEMD
[Unit]
Description=NK-Core Cloudflare WARP Watchdog
After=network.target

[Service]
Type=oneshot
ExecStart=/usr/local/bin/nk-warp-watchdog.sh
StandardOutput=journal
StandardError=journal
SYSTEMD;
        $base64Service = base64_encode($serviceDef);

        $timerDef = <<<SYSTEMD
[Unit]
Description=Run NK-Core Cloudflare WARP Watchdog every minute

[Timer]
OnBootSec=1min
OnUnitActiveSec=1min

[Install]
WantedBy=timers.target
SYSTEMD;
        $base64Timer = base64_encode($timerDef);
        
        $setupCmd = implode(' && ', [
            "echo '{$base64Script}' | base64 -d > /usr/local/bin/nk-warp-watchdog.sh",
            "chmod +x /usr/local/bin/nk-warp-watchdog.sh",
            
            "echo '{$base64Service}' | base64 -d > /etc/systemd/system/nk-warp-watchdog.service",
            "echo '{$base64Timer}' | base64 -d > /etc/systemd/system/nk-warp-watchdog.timer",
            
            "systemctl daemon-reload",
            "systemctl enable nk-warp-watchdog.timer",
            "systemctl restart nk-warp-watchdog.timer",
            
            // Trigger immediately
            "systemctl start nk-warp-watchdog.service"
        ]);

        $this->ssh->executeCommand($setupCmd, true, true);
    }

    public function removeWarp(): void
    {
        $removeCmd = implode(' && ', [
            // Stop and disable systemd timer
            "systemctl disable --now nk-warp-watchdog.timer 2>/dev/null || true",
            "systemctl stop wg-quick@wg-warp 2>/dev/null || true",
            "systemctl disable wg-quick@wg-warp 2>/dev/null || true",
            
            // Delete configuration & helper files
            "rm -f /etc/wireguard/wg-warp.conf",
            "rm -f /etc/wireguard/wgcf-account.toml",
            "rm -f /etc/wireguard/post-up.d/wg-warp.sh",
            "rm -f /etc/nftables.d/nkcore-warp.nft",
            "rm -f /etc/nftables.d/nkcore-nat.nft",
            "rm -f /usr/local/bin/nk-warp-watchdog.sh",
            "rm -f /etc/systemd/system/nk-warp-watchdog.service",
            "rm -f /etc/systemd/system/nk-warp-watchdog.timer",
            "rm -rf /var/lib/nk-core",
            
            "systemctl daemon-reload",
            "systemctl reload nftables 2>/dev/null || systemctl restart nftables 2>/dev/null || true",
            
            // Delete PBR rules & table (ignore if fail)
            "ip rule del fwmark 100 lookup warp priority 11000 2>/dev/null || true"
        ]);

        $this->ssh->executeCommand($removeCmd, true, true);
    }

    public function readWarpHealthCache(): array
    {
        try {
            $out = $this->ssh->executeCommand("cat /var/lib/nk-core/state/warp-health.json 2>/dev/null || echo '{}'", true);
            $data = json_decode(trim($out), true);
            return is_array($data) ? $data : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function runWarpDiagnostics(): array
    {
        // Force-run systemd service immediately to refresh stats
        $this->ssh->executeCommand("systemctl start nk-warp-watchdog.service", true, true);
        return $this->readWarpHealthCache();
    }
}
