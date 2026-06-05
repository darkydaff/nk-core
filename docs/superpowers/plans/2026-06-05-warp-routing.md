# Cloudflare WARP Routing Backend Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Provide an option in the panel to route specific VPN client traffic through Cloudflare WARP on the remote server using nftables policy routing, while keeping speed limits intact.

**Architecture:** Create a routing table (`warp`) and route mark policy rules on the remote host. Use `nftables` IP sets to dynamically classify client source IP addresses and mark packets. A health check watchdog on the server monitors WARP connectivity and drops failover cache if down.

**Tech Stack:** PHP, MySQL (PDO), Twig, Bash, systemd, WireGuard, nftables.

---

### Task 1: Database Migration

**Files:**
- Create: [068_add_client_routing_mode.sql](file:///d:/GitHub/nk-core/migrations/068_add_client_routing_mode.sql)

- [ ] **Step 1: Create the SQL migration file**
  Write the migration query to add `routing_mode` to `vpn_clients`:
  ```sql
  -- Migration 068: Add routing_mode to vpn_clients
  ALTER TABLE vpn_clients
      ADD COLUMN routing_mode ENUM('direct', 'warp') NOT NULL DEFAULT 'direct' AFTER status;
  ```

- [ ] **Step 2: Commit migration file**
  ```bash
  git add migrations/068_add_client_routing_mode.sql
  git commit -m "migration: add routing_mode to vpn_clients"
  ```

---

### Task 2: VpnClient Model Updates

**Files:**
- Modify: [VpnClient.php](file:///d:/GitHub/nk-core/inc/VpnClient.php)

- [ ] **Step 1: Load and expose `routing_mode`**
  Modify `load()` in [VpnClient.php](file:///d:/GitHub/nk-core/inc/VpnClient.php) to ensure the column is retrieved. Update `getData()` to return `routing_mode`.
  Ensure that `routing_mode` defaults to `'direct'` if null/empty.

- [ ] **Step 2: Add validation and update method**
  Add a method `setRoutingMode(string $mode)` in [VpnClient.php](file:///d:/GitHub/nk-core/inc/VpnClient.php):
  ```php
  public function setRoutingMode(string $mode): void {
      if (!in_array($mode, ['direct', 'warp'], true)) {
          throw new InvalidArgumentException("Invalid routing mode: {$mode}");
      }
      
      $pdo = DB::conn();
      $stmt = $pdo->prepare('UPDATE vpn_clients SET routing_mode = ? WHERE id = ?');
      $stmt->execute([$mode, $this->clientId]);
      $this->data['routing_mode'] = $mode;
  }
  ```

- [ ] **Step 3: Commit client model changes**
  ```bash
  git add inc/VpnClient.php
  git commit -m "feat: update VpnClient model to support routing_mode"
  ```

---

### Task 3: ClientController & Routing API

**Files:**
- Modify: [ClientController.php](file:///d:/GitHub/nk-core/controllers/ClientController.php)

- [ ] **Step 1: Add update logic for `routing_mode`**
  Modify the `update($params)` method in [ClientController.php](file:///d:/GitHub/nk-core/controllers/ClientController.php) to support updating the routing mode from a POST request:
  ```php
  // Inside ClientController::update
  $routingModeChanged = false;
  if (isset($_POST['routing_mode'])) {
      $newMode = trim($_POST['routing_mode']);
      $oldMode = $clientData['routing_mode'] ?? 'direct';
      if ($newMode !== $oldMode) {
          $client->setRoutingMode($newMode);
          $routingModeChanged = true;
      }
  }

  // Under the existing queues triggers
  if ($speedLimitUpChanged || $speedLimitDownChanged || $routingModeChanged) {
      require_once __DIR__ . '/../inc/Queue.php';
      Queue::push('deployments', [
          'type' => 'sync_server_config',
          'server_id' => $clientData['server_id']
      ]);
  }
  ```

- [ ] **Step 2: Commit controller updates**
  ```bash
  git add controllers/ClientController.php
  git commit -m "feat: handle routing_mode updates in ClientController"
  ```

---

### Task 4: UI Changes

**Files:**
- Modify: [view.twig](file:///d:/GitHub/nk-core/templates/clients/view.twig)

- [ ] **Step 1: Add Routing Mode form selector**
  Update the client detail editing view in [view.twig](file:///d:/GitHub/nk-core/templates/clients/view.twig) to include a dropdown or button group for Routing Mode:
  ```html
  <!-- Inside edit client form -->
  <div class="mb-4">
      <label for="routing_mode" class="block text-sm font-medium text-secondary mb-1">Routing Mode</label>
      <select id="routing_mode" name="routing_mode" class="w-full bg-base border border-default rounded-lg px-3 py-2 text-primary focus:outline-none focus:border-accent">
          <option value="direct" {% if client.routing_mode == 'direct' %}selected{% endif %}>Direct (Server WAN)</option>
          <option value="warp" {% if client.routing_mode == 'warp' %}selected{% endif %}>Cloudflare WARP</option>
      </select>
  </div>
  ```

- [ ] **Step 2: Commit template changes**
  ```bash
  git add templates/clients/view.twig
  git commit -m "feat: add Routing Mode selector to client view template"
  ```

---

### Task 5: Server Provisioning & Host Routing Setup

**Files:**
- Modify: [LinuxProvisioner.php](file:///d:/GitHub/nk-core/inc/LinuxProvisioner.php)
- Modify: [VpnProvisioner.php](file:///d:/GitHub/nk-core/inc/VpnProvisioner.php)

- [ ] **Step 1: Add subnet overlap checks**
  Add a validation check in [VpnProvisioner.php](file:///d:/GitHub/nk-core/inc/VpnProvisioner.php) inside `deploy()` to check if `$serverData['vpn_subnet']` conflicts with the system interfaces or typical WARP ranges.
  Implement a method:
  ```php
  private function verifyNoSubnetConflict(string $vpnSubnet): void {
      // Typically WARP uses 172.16.0.0/12 ranges.
      // Parse local subnet & match against WARP ranges or local server routes
      $overlap = false;
      
      // Basic check: if vpnSubnet is inside 172.16.0.0/12, flag conflict.
      if (strpos($vpnSubnet, '172.16.') === 0 || strpos($vpnSubnet, '172.17.') === 0 || strpos($vpnSubnet, '172.18.') === 0 || strpos($vpnSubnet, '172.19.') === 0 || strpos($vpnSubnet, '172.2') === 0 || strpos($vpnSubnet, '172.30.') === 0 || strpos($vpnSubnet, '172.31.') === 0) {
          $overlap = true;
      }
      
      if ($overlap) {
          throw new Exception("VPN Subnet {$vpnSubnet} overlaps with WARP dynamic routing space (172.16.0.0/12). Please use a different range (e.g. 10.8.x.x).");
      }
  }
  ```
  Call this at the beginning of the deployment phase.

- [ ] **Step 2: Persist WARP and custom routing configs**
  Implement host configuration setup in [LinuxProvisioner.php](file:///d:/GitHub/nk-core/inc/LinuxProvisioner.php) to initialize routing rules and nftables setup:
  ```php
  public function setupWarpHostRules(string $vpnSubnet): void {
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
          "systemctl reload nftables || systemctl restart nftables",

          // 4. Create persistent startup up routing commands
          "mkdir -p /etc/wireguard/post-up.d",
          "echo '#!/bin/bash
ip route replace {$vpnSubnet} dev wg0 table warp 2>/dev/null || true
ip route replace default dev wg-warp table warp 2>/dev/null || true
ip rule del fwmark 100 lookup warp priority 200 2>/dev/null || true
ip rule add fwmark 100 lookup warp priority 200' > /etc/wireguard/post-up.d/wg-warp.sh",
          "chmod +x /etc/wireguard/post-up.d/wg-warp.sh",
          
          // Execute rules immediately
          "/etc/wireguard/post-up.d/wg-warp.sh"
      ]);

      $this->ssh->executeCommand($setupCmd, true, true);
  }
  ```

- [ ] **Step 3: Call rules configuration during deploy**
  Modify [VpnProvisioner.php](file:///d:/GitHub/nk-core/inc/VpnProvisioner.php) in `finalizeDeployment()` to call `setupWarpHostRules($serverData['vpn_subnet'])`.

- [ ] **Step 4: Commit provisioning logic**
  ```bash
  git add inc/LinuxProvisioner.php inc/VpnProvisioner.php
  git commit -m "feat: add subnet conflict checks and remote WARP routing persistence setup"
  ```

---

### Task 6: Health Watchdog Failover Service

**Files:**
- Modify: [LinuxProvisioner.php](file:///d:/GitHub/nk-core/inc/LinuxProvisioner.php)

- [ ] **Step 1: Implement watchdog installation**
  Add a method in [LinuxProvisioner.php](file:///d:/GitHub/nk-core/inc/LinuxProvisioner.php) to install the watchdog systemd service and script on the remote node:
  ```php
  public function installWarpWatchdog(): void {
      $watchdogScript = <<<'BASH'
  #!/bin/bash
  # Verify policy routing dev matches wg-warp
  ROUTE_DEV=$(ip route get 1.1.1.1 mark 100 2>/dev/null | grep -oE "dev [a-zA-Z0-9_-]+" | awk '{print $2}')
  if [ "$ROUTE_DEV" != "wg-warp" ]; then
      echo "WARP routing invalid! Dev: $ROUTE_DEV. Flushing warp_clients set."
      nft flush set inet nkcore warp_clients
      exit 0
  fi

  # Verify connectivity through wg-warp interface
  if ! ping -c 2 -W 3 -I wg-warp 1.1.1.1 >/dev/null 2>&1; then
      echo "WARP health check failed! Flushing set to fallback to direct."
      nft flush set inet nkcore warp_clients
  fi
  BASH;

      $base64Script = base64_encode($watchdogScript);
      
      $setupCmd = implode(' && ', [
          "echo '{$base64Script}' | base64 -d > /usr/local/bin/nk-warp-watchdog.sh",
          "chmod +x /usr/local/bin/nk-warp-watchdog.sh",
          
          // Setup Cron job or systemd timer to run watchdog every minute
          "(crontab -l 2>/dev/null | grep -v 'nk-warp-watchdog.sh'; echo '* * * * * /usr/local/bin/nk-warp-watchdog.sh') | crontab -"
      ]);

      $this->ssh->executeCommand($setupCmd, true, true);
  }
  ```

- [ ] **Step 2: Commit watchdog changes**
  ```bash
  git add inc/LinuxProvisioner.php
  git commit -m "feat: add cron-based health watchdog and failover for WARP"
  ```

---

### Task 7: Declarative Sync Implementation

**Files:**
- Modify: [VpnConfigRenderer.php](file:///d:/GitHub/nk-core/inc/VpnConfigRenderer.php)

- [ ] **Step 1: Sync nftables element list**
  Modify `syncDeclarative()` in [VpnConfigRenderer.php](file:///d:/GitHub/nk-core/inc/VpnConfigRenderer.php) to synchronize the list of WARP routing clients dynamically.
  We retrieve all active clients on the server who have `routing_mode = 'warp'`.
  We flush the set first, and then add elements dynamically:
  ```php
  // Inside VpnConfigRenderer::syncDeclarative
  // Fetch active clients with routing_mode = 'warp'
  $stmtWarp = $pdo->prepare("SELECT client_ip FROM vpn_clients WHERE server_id = ? AND routing_mode = 'warp' AND status IN ('active', 'verifying', 'provisioning') AND deleted_at IS NULL");
  $stmtWarp->execute([$this->server->getId()]);
  $warpClients = $stmtWarp->fetchAll(PDO::FETCH_COLUMN);

  // Re-sync rules on the server
  $syncCmds = [
      "nft flush set inet nkcore warp_clients"
  ];
  if (!empty($warpClients)) {
      // Secure IP address formatting and joining
      $ipList = implode(', ', array_map(function($ip) {
          if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
              throw new Exception("Security Alert: Invalid IP address '{$ip}' skipped.");
          }
          return $ip;
      }, $warpClients));
      
      $syncCmds[] = "nft add element inet nkcore warp_clients { {$ipList} }";
  }
  
  $this->ssh->executeCommand(implode(' && ', $syncCmds), true, true);
  ```

- [ ] **Step 2: Commit declarative sync changes**
  ```bash
  git add inc/VpnConfigRenderer.php
  git commit -m "feat: synchronize nftables warp_clients set during declarative sync"
  ```

---

### Verification and Test Plan

#### Manual Verification Tasks
1. Run database migration `migrations/068_add_client_routing_mode.sql` manually or via test harness.
2. Setup the mock WARP interface (`wg-warp`) on a test server.
3. Toggle "Routing Mode" to "Cloudflare WARP" for a specific VPN client IP.
4. Verify the client IP gets added to `nft list set inet nkcore warp_clients` on the host.
5. Verify policy routing rules apply correctly via `ip rule show` and `ip route show table warp`.
6. Terminate the WARP tunnel (e.g. `ip link set wg-warp down`) and verify the watchdog flushes the active set within 1 minute, allowing fallback to direct path.
7. Restore interface, verify the next panel config sync recovers active set members.
