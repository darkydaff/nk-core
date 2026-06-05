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

        // Step 5: Load module
        $this->ssh->executeCommand('modprobe amneziawg 2>/dev/null || true', true);

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

        // Attempt 1 — exact versioned match (most reliable on standard kernels)
        $this->ssh->executeCommand("apt-get install -y linux-headers-{$kernel} 2>/dev/null || true", true, false, false, 300);

        $headersExist = $this->ssh->executeCommand(
            "test -d /usr/src/linux-headers-{$kernel} && echo yes || echo no", true
        );
        if (trim($headersExist) === 'yes') {
            return;
        }

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

        $headersExist = $this->ssh->executeCommand(
            "test -d /usr/src/linux-headers-{$kernel} && echo yes || echo no", true
        );
        if (trim($headersExist) !== 'yes') {
            Logger::channel('deployments')->warning('Could not install kernel headers — DKMS may fail, userspace fallback will be used', [
                'server_id' => $this->serverId,
                'kernel'    => $kernel,
                'dpkg_arch' => $dpkgArch,
                'xanmod'    => $isXanmod,
            ]);
        }
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

        $this->ssh->executeCommand('modprobe amneziawg 2>/dev/null || true', true);

        $check = $this->ssh->executeCommand('lsmod | grep -c amneziawg 2>/dev/null || echo 0');
        $loaded = (int)trim($check) > 0;

        Logger::channel('deployments')->info('AmneziaWG kernel module (dnf/yum) install result', [
            'server_id' => $this->serverId,
            'loaded'    => $loaded,
        ]);

        return $loaded;
    }

    /**
     * Set up Cloudflare WARP firewall and policy-based routing on the remote host.
     */
    public function setupWarpHostRules(string $vpnSubnet): void
    {
        $escapedSubnet = escapeshellarg($vpnSubnet);
        
        $setupCmd = implode(' && ', [
            // 1. Ensure Table warp (ID 200) registered
            "if ! grep -q '200 warp' /etc/iproute2/rt_tables; then echo '200 warp' >> /etc/iproute2/rt_tables; fi",
            
            // 2. Deploy nftables custom config
            "mkdir -p /etc/nftables.d",
            "echo 'table inet nkcore {
                set warp_clients {
                    type ipv4_addr
                }
                chain prerouting {
                    type filter hook prerouting priority mangle; policy accept;
                    ip saddr @warp_clients meta mark set 100
                }
            }' > /etc/nftables.d/nkcore-warp.nft",
            
            // 3. Deploy nat postrouting config (pure nftables)
            "echo 'table ip nat {
                chain postrouting {
                    type nat hook postrouting priority 100; policy accept;
                    oifname \"wg-warp\" masquerade
                }
            }' > /etc/nftables.d/nkcore-nat.nft",
            
            // Ensure they are included in /etc/nftables.conf
            "if ! grep -q 'nkcore-warp.nft' /etc/nftables.conf; then echo 'include \"/etc/nftables.d/nkcore-warp.nft\"' >> /etc/nftables.conf; fi",
            "if ! grep -q 'nkcore-nat.nft' /etc/nftables.conf; then echo 'include \"/etc/nftables.d/nkcore-nat.nft\"' >> /etc/nftables.conf; fi",
            "systemctl reload nftables 2>/dev/null || systemctl restart nftables 2>/dev/null || true",

            // 4. Create persistent startup routing commands
            "mkdir -p /etc/wireguard/post-up.d",
            "echo \"#!/bin/bash
ip route replace \" . $escapedSubnet . \" dev wg0 table warp 2>/dev/null || true
ip route replace default dev wg-warp table warp 2>/dev/null || true
ip rule del fwmark 100 lookup warp priority 200 2>/dev/null || true
ip rule add fwmark 100 lookup warp priority 200\" > /etc/wireguard/post-up.d/wg-warp.sh",
            "chmod +x /etc/wireguard/post-up.d/wg-warp.sh",
            
            // Execute rules immediately
            "/etc/wireguard/post-up.d/wg-warp.sh"
        ]);

        $this->ssh->executeCommand($setupCmd, true, true);
    }
}
