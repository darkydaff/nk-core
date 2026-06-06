<?php

class AwgConfigGenerator
{
    /**
     * Get default mimicry presets for AWG 2.0
     */
    public function getMimicryPresets(): array
    {
        return [
            'quic' => [
                'Jc' => 4,
                'Jmin' => 50,
                'Jmax' => 80
            ],
            'standard' => [
                'Jc' => 1,
                'Jmin' => 10,
                'Jmax' => 50
            ]
        ];
    }

    /**
     * Get mimicry preset based on type
     */
    public function getMimicryPreset(string $type = 'quic'): array
    {
        $presets = $this->getMimicryPresets();
        return $presets[$type] ?? $presets['quic'];
    }

    /**
     * Get dynamic QUIC payloads for CPS
     */
    public function getDynamicQuicPayloads(string $quicFilePath): array
    {
        if (!file_exists($quicFilePath)) {
            return [];
        }

        $lines = file($quicFilePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
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
    public function generateNonOverlappingHeaderRanges(): array
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
     * Generate or sanitize AWG parameters
     */
    public function generateAwgParams(?array $existingParams, array $mimicry = []): array
    {
        $awgParams = $existingParams ?: [];

        if ($awgParams) {
            foreach (['S1', 'S2', 'S3', 'S4'] as $sKey) {
                if (isset($awgParams[$sKey]) && (int) $awgParams[$sKey] === 0) {
                    $awgParams[$sKey] = 1;
                }
            }
        }

        if (!$awgParams || !isset($awgParams['Jc'])) {
            $headerRanges = $this->generateNonOverlappingHeaderRanges();
            $jmin = 64;
            $jmax = random_int(max($jmin + 1, 70), 80);

            $mimicryType = $awgParams['mimicry_type'] ?? 'quic';
            
            if (empty($mimicry)) {
                $mimicry = $this->getMimicryPreset($mimicryType);
            }

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

        return $awgParams;
    }

    /**
     * Generate the WireGuard wg0.conf content
     */
    public function generateWgConfig(string $subnetBase, int $vpnPort, string $privKey, array $awgParams): string
    {
        $wgConfig = "[Interface]\n";
        $wgConfig .= "PrivateKey = {$privKey}\n";
        $wgConfig .= "Address = {$subnetBase}.1/24\n";
        $wgConfig .= "ListenPort = {$vpnPort}\n";
        $wgConfig .= "MTU = 1420\n";

        foreach ($awgParams as $key => $value) {
            if ($value === null || $value === '' || $key === 'mimicry_type')
                continue;
            $wgConfig .= "{$key} = {$value}\n";
        }
        $wgConfig .= "\n";

        return $wgConfig;
    }

    /**
     * Generate the Dockerfile content
     */
    public function getDockerfile(): string
    {
        return <<<DOCKERFILE
# Stage 1: Build amneziawg-tools
FROM alpine:latest AS builder
RUN apk add --no-cache git make build-base bash libmnl-dev pkgconfig

ARG AMNEZIAWG_TOOLS_REF=v1.0.20260223

# Build amneziawg-tools
RUN git clone --branch \${AMNEZIAWG_TOOLS_REF} https://github.com/amnezia-vpn/amneziawg-tools.git /build/amneziawg-tools && \\
    cd /build/amneziawg-tools/src && \\
    make && \\
    make install PREFIX=/usr

# Stage 2: Final Image
FROM alpine:latest
RUN apk add --no-cache bash iptables iproute2 coreutils dumb-init libmnl

# Copy binaries from builder
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
    }

    /**
     * Generate the start script content
     */
    public function getStartScript(string $subnet, int $vpnPort): string
    {
        return <<<BASH
#!/bin/bash
echo "Container startup: Initializing AmneziaWG..."

# 1. Detect default interface for routing
DEFAULT_IF=$(ip route | grep '^default' | awk '{print \$5}' | head -n1)
if [ -z "\$DEFAULT_IF" ]; then
    DEFAULT_IF="eth0"
fi
echo "Using default interface: \$DEFAULT_IF"

# 2. Cleanup function for graceful shutdown
cleanup() {
    echo "Caught shutdown signal! Cleaning up resources..."
    
    # Bring down the interface
    if [ -f /opt/amnezia/awg/wg0.conf ]; then
        /usr/local/bin/awg-quick down /opt/amnezia/awg/wg0.conf 2>/dev/null || ip link delete wg0 2>/dev/null
    fi
    
    # Remove firewall rules
    iptables -D INPUT -i wg0 -j ACCEPT 2>/dev/null
    iptables -D FORWARD -i wg0 -j ACCEPT 2>/dev/null
    iptables -D OUTPUT -o wg0 -j ACCEPT 2>/dev/null
    
    if [ "{$vpnPort}" != "0" ]; then
        iptables -D INPUT -p udp --dport {$vpnPort} -j ACCEPT 2>/dev/null
    fi
    
    iptables -D FORWARD -i wg0 -o "\$DEFAULT_IF" -s {$subnet} -j ACCEPT 2>/dev/null
    iptables -t nat -D POSTROUTING -s {$subnet} -o "\$DEFAULT_IF" -j MASQUERADE 2>/dev/null
    iptables -t mangle -D FORWARD -p tcp --tcp-flags SYN,RST SYN -j TCPMSS --clamp-mss-to-pmtu 2>/dev/null
    
    echo "Cleanup complete. Exiting."
    exit 0
}

# Register the trap
trap cleanup SIGTERM SIGINT

# 3. Wait for config if not exists yet
for i in {1..30}; do
    if [ -f /opt/amnezia/awg/wg0.conf ]; then
        break
    fi
    sleep 1
done

# 4. Start WireGuard / AmneziaWG
if [ -f /opt/amnezia/awg/wg0.conf ]; then
    # Ensure no ghost interface exists
    /usr/local/bin/awg-quick down /opt/amnezia/awg/wg0.conf 2>/dev/null || ip link delete wg0 2>/dev/null || true
    
    # Try loading the module in case it's not loaded yet (since container has privileged access)
    modprobe amneziawg 2>/dev/null || true

    # Check if amneziawg kernel module is available (checking lsmod, or reading /proc/modules directly)
    if lsmod 2>/dev/null | grep -q "amneziawg" || grep -q "amneziawg" /proc/modules 2>/dev/null; then
        echo "AmneziaWG kernel module detected. Using kernel mode."
        unset WG_QUICK_USERSPACE_IMPLEMENTATION
    else
        echo "ERROR: AmneziaWG kernel module not found. Userspace fallback is disabled."
        exit 1
    fi
    
    export WG_SUDO=1
    if /usr/local/bin/awg-quick up /opt/amnezia/awg/wg0.conf; then
        echo "VPN interface wg0 is UP."
    else
        echo "ERROR: Failed to bring up wg0 interface."
        exit 1
    fi
else
    echo "ERROR: No wg0.conf found, cannot start VPN."
    exit 1
fi

# 5. Apply Firewall Rules (Centralized)
echo "Applying firewall rules..."
iptables -A INPUT -i wg0 -j ACCEPT 2>/dev/null
iptables -A FORWARD -i wg0 -j ACCEPT 2>/dev/null
iptables -A OUTPUT -o wg0 -j ACCEPT 2>/dev/null

if [ "{$vpnPort}" != "0" ]; then
    # Use -I (Insert) for the external port to ensure it bypasses other restrictive rules
    iptables -I INPUT -p udp --dport {$vpnPort} -j ACCEPT 2>/dev/null
fi

# Enable forwarding
sysctl -w net.ipv4.ip_forward=1 || echo 'Notice: sysctl ip_forward failed, check host config'
iptables -A FORWARD -i wg0 -o "\$DEFAULT_IF" -s {$subnet} -j ACCEPT 2>/dev/null
iptables -A FORWARD -m state --state ESTABLISHED,RELATED -j ACCEPT 2>/dev/null

# NAT and MSS Clamping
iptables -t nat -A POSTROUTING -s {$subnet} -o "\$DEFAULT_IF" -j MASQUERADE 2>/dev/null
iptables -t mangle -A FORWARD -p tcp --tcp-flags SYN,RST SYN -j TCPMSS --clamp-mss-to-pmtu 2>/dev/null

# Restore traffic shaping rules if they exist
if [ -f /opt/amnezia/awg/tc_rules.sh ]; then
    echo "Restoring traffic shaping rules..."
    bash /opt/amnezia/awg/tc_rules.sh || echo "Notice: Failed to restore traffic shaping rules"
fi

echo "VPN service fully operational. Waiting for signals..."

# 6. Keep container alive and wait for signals
while true; do
    sleep 1
done
BASH;
    }
}
