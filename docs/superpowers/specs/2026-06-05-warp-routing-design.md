# Spec: Cloudflare WARP Routing Backend

This design document outlines the implementation of optional, per-client routing through Cloudflare WARP using `nftables`, persistent policy-based routing, and a fallback mechanism to prevent traffic blackholing on remote VPN nodes.

## Goal Description
Provide an option in the NK-Core administration panel to route selected VPN client traffic out via Cloudflare WARP instead of the server's default internet gateway (direct). This allows administrators to choose the egress route of specific clients without altering their local WireGuard/AmneziaWG configs. Client speed limits on `wg0` remain fully functional.

---

## Architectural Specification

### 1. Database Migration
Instead of a single boolean, we define a future-proof `routing_mode` enum configuration in the `vpn_clients` table.
```sql
ALTER TABLE vpn_clients
    ADD COLUMN routing_mode ENUM('direct', 'warp') NOT NULL DEFAULT 'direct' AFTER status;
```

### 2. Remote Server Networking & Persistence

#### Routing Table Registration
To avoid conflict with system table names and IDs, we reserve ID `200` under the name `warp`.
```bash
if ! grep -q "200 warp" /etc/iproute2/rt_tables; then
    echo "200 warp" >> /etc/iproute2/rt_tables
fi
```

#### WireGuard Interface Persistence
We register WARP using `wgcf` on the remote host and save the standard WireGuard configuration to `/etc/wireguard/wg-warp.conf`.
To prevent the default route of the main routing table from being hijacked on start, the config file will include:
```ini
[Interface]
...
Table = off
```
We configure `systemd` to enable and run this interface automatically across reboots:
```bash
systemctl enable wg-quick@wg-warp.service
systemctl start wg-quick@wg-warp.service
```

#### Boot-Persistent Routing Configuration
To make policy routing persistent, we create an interface up script (e.g. in `/etc/wireguard/post-up.d/wg-warp` or inside `/etc/network/interfaces` hooks, or as a dedicated systemd service):
```bash
#!/bin/bash
# Rebuild the warp table routes
ip route replace ${VPN_SUBNET} dev wg0 table warp
ip route replace default dev wg-warp table warp

# Add the PBR rules
ip rule del fwmark 100 lookup warp priority 200 2>/dev/null || true
ip rule add fwmark 100 lookup warp priority 200
```
This script runs dynamically using the configured `${VPN_SUBNET}` of the VPN server.

---

### 3. Scalable Packet Marking with nftables
We replace legacy `iptables`/`ipset` with `nftables` for modern, scalable packet classification.

#### Persistent nftables Ruleset
We deploy a custom configuration fragment at `/etc/nftables.d/nkcore-warp.nft`:
```nftables
table inet nkcore {
    set warp_clients {
        type ipv4_addr
    }

    chain prerouting {
        type filter hook prerouting priority mangle; policy accept;
        
        # Mark all packets originating from WARP-enabled clients with mark 100
        ip saddr @warp_clients meta mark set 100
    }
}
```
We ensure this is loaded in the main firewall configuration (typically `/etc/nftables.conf`):
```nftables
include "/etc/nftables.d/nkcore-warp.nft"
```

#### Egress NAT / Masquerading
To translate the client's internal VPN IP to the `wg-warp` interface IP, we register a nat postrouting chain to masquerade outgoing traffic on `wg-warp` using nftables:
```nftables
table ip nat {
    chain postrouting {
        type nat hook postrouting priority 100; policy accept;
        oifname "wg-warp" masquerade
    }
}
```

---

### 4. Dynamic Subnet Conflict Detection
During server provisioning and subnet updates, we will run a detection routine to check for overlaps:
1. Parse host routes using `ip route show` and active addresses with `ip addr show`.
2. Extract the active WARP address from `/etc/wireguard/wg-warp.conf` or `ip addr show wg-warp` (usually `172.16.0.2` or within `172.16.0.0/12`).
3. If the configured `vpn_subnet` (e.g., `172.16.10.0/24`) overlaps with the WARP subnet (`172.16.0.0/12`), reject the configuration with a validation error.

---

### 5. Health Watchdog and Automatic Failover (Option A)
To prevent clients from being blackholed if the WARP interface (`wg-warp`) goes down or loses connection to Cloudflare, we run a cron/timer watchdog. 

The **database remains the authoritative source of truth**, and the remote `nftables` set is treated as an active routing cache.

1. We install a health watchdog script at `/usr/local/bin/nk-warp-watchdog.sh` run by a systemd timer or cron job every minute:
   ```bash
   #!/bin/bash
   # 1. Verify policy routing points to the correct interface
   ROUTE_DEV=$(ip route get 1.1.1.1 mark 100 2>/dev/null | grep -oE "dev [a-zA-Z0-9_-]+" | awk '{print $2}')
   if [ "$ROUTE_DEV" != "wg-warp" ]; then
       echo "WARP policy route path invalid! Dev was: $ROUTE_DEV. Clearing set to force fallback."
       nft flush set inet nkcore warp_clients
       exit 0
   fi

   # 2. Verify link state and test ping through the route
   if ! ping -c 2 -W 3 -I wg-warp 1.1.1.1 >/dev/null 2>&1; then
       echo "WARP health check failed! Falling back to direct routing."
       nft flush set inet nkcore warp_clients
   fi
   ```
2. If WARP recovers (watchdog or manual test ping succeeds again), the panel's sync worker will reload the set. The panel retrieves all active warp-enabled clients (`routing_mode = 'warp'`) from the database and updates the remote set:
   ```bash
   # Sync worker reconstructs set from authoritative DB state
   nft flush set inet nkcore warp_clients
   nft add element inet nkcore warp_clients { client_ip_1, client_ip_2, ... }
   ```

---

### 6. Security Considerations
* **Strict Validation:** Before adding an IP address to the `warp_clients` set, the backend must verify that the target IP matches the client's statically assigned `vpn_clients.client_ip` database record.
* **No Raw Shell Interpolation:** Command execution on remote servers will be sanitized and parameter-bound to prevent command injection risks.

---

## Verification Plan

### Automated Verification
* Verify that the database migration executes without error.
* Check that client routing toggles correctly add and remove elements in the `warp_clients` `nftables` set on the remote node.
* Verify dynamic subnet validation blocks conflicting subnets.

### Manual Verification
* Deploy a test client, verify direct routing path (IP matches server main IP).
* Toggle WARP in the UI. Verify client egress IP changes to a Cloudflare WARP range.
* Simulate a WARP connection drop (e.g., downing the `wg-warp` interface) and verify the watchdog triggers automatic fallback to direct routing.
