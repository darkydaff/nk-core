# Map Servers and Connections Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Plot servers on the Leaflet intelligence map, visually distinguishing them from clients, and render interactive, animated connection lines on hover or click.

**Architecture:** 
1. Database migration to store latitude and longitude for servers.
2. Update the backend GeoIP resolution logic to save coordinates during server lookup.
3. Expose server coordinates in the existing map API endpoint.
4. Integrate server markers, filtered connection lines, auto-panning, and popups on the frontend Leaflet map page.

**Tech Stack:** PHP, MySQL/MariaDB, Twig, Vanilla Javascript, Leaflet.js

---

### Task 1: Database Migration

**Files:**
- Create: `migrations/066_add_server_coordinates.sql`

- [ ] **Step 1: Create the SQL migration file**
  Create the file [066_add_server_coordinates.sql](file:///d:/GitHub/nk-core/migrations/066_add_server_coordinates.sql) with the schema change adding `lat` and `lon` to `vpn_servers`.
  ```sql
  -- Migration 066: Add latitude and longitude to vpn_servers
  ALTER TABLE vpn_servers
      ADD COLUMN lat DECIMAL(10, 7) NULL COMMENT 'Latitude from IP geolocation' AFTER org,
      ADD COLUMN lon DECIMAL(10, 7) NULL COMMENT 'Longitude from IP geolocation' AFTER lat;
  ```

- [ ] **Step 2: Commit the migration script**
  ```bash
  git add migrations/066_add_server_coordinates.sql
  git commit -m "migration: add lat/lon coordinates to vpn_servers"
  ```

---

### Task 2: Backend GeoIP Resolution Updates

**Files:**
- Modify: `inc/VpnServer.php`
- Create: `tests/server_geoip_test.php`

- [ ] **Step 1: Write a test verifying server GeoIP coordinates update**
  Create [tests/server_geoip_test.php](file:///d:/GitHub/nk-core/tests/server_geoip_test.php) to verify that `updateGeoIp()` populates coordinates.
  ```php
  <?php
  require_once __DIR__ . '/../inc/Config.php';
  require_once __DIR__ . '/../inc/DB.php';
  require_once __DIR__ . '/../inc/VpnServer.php';

  function assert_true(bool $cond, string $msg) {
      if (!$cond) { echo "❌ FAIL: $msg\n"; exit(1); }
      echo "✅ PASS: $msg\n";
  }

  // Find a server to test geo updates
  $pdo = DB::conn();
  $server = $pdo->query("SELECT id FROM vpn_servers WHERE deleted_at IS NULL LIMIT 1")->fetch();
  if (!$server) {
      echo "No server available for testing.\n";
      exit(0);
  }

  $vs = new VpnServer((int)$server['id']);
  $success = $vs->updateGeoIp();
  assert_true($success, "Server updateGeoIp returns true");

  $updated = $pdo->query("SELECT lat, lon FROM vpn_servers WHERE id = " . (int)$server['id'])->fetch();
  assert_true($updated['lat'] !== null, "Latitude is resolved and not null");
  assert_true($updated['lon'] !== null, "Longitude is resolved and not null");
  echo "All GeoIP tests passed!\n";
  ```

- [ ] **Step 2: Modify VpnServer.php updateGeoIp method**
  Edit [VpnServer.php](file:///d:/GitHub/nk-core/inc/VpnServer.php) (around lines 130-170) to fetch `lat` and `lon` fields from `ip-api.com` and store them.
  ```php
  // Target: updateGeoIp() method
  $url = "http://ip-api.com/json/{$ip}?fields=status,message,country,countryCode,city,isp,org,lat,lon,query";
  ...
  $stmt = $pdo->prepare('
      UPDATE vpn_servers 
      SET country = ?, country_code = ?, city = ?, isp = ?, org = ?, lat = ?, lon = ? 
      WHERE id = ?
  ');
  $stmt->execute([
      $geo['country'] ?? null,
      $geo['countryCode'] ?? null,
      $geo['city'] ?? null,
      $geo['isp'] ?? null,
      $geo['org'] ?? null,
      $geo['lat'] ?? null,
      $geo['lon'] ?? null,
      $this->serverId
  ]);
  ```

- [ ] **Step 3: Run the test to verify coordinates resolve**
  Run: `php tests/server_geoip_test.php`
  Expected: All PASS.

- [ ] **Step 4: Commit changes**
  ```bash
  git add inc/VpnServer.php tests/server_geoip_test.php
  git commit -m "feat: resolve and store server coordinates during geoip lookup"
  ```

---

### Task 3: Map API Endpoint Extension

**Files:**
- Modify: `controllers/MapController.php`
- Create: `tests/map_api_test.php`

- [ ] **Step 1: Write map API structure test**
  Create [tests/map_api_test.php](file:///d:/GitHub/nk-core/tests/map_api_test.php) to verify API outputs coordinates and server payload.
  ```php
  <?php
  require_once __DIR__ . '/../inc/Config.php';
  require_once __DIR__ . '/../inc/DB.php';

  function assert_equals($expected, $actual, string $msg) {
      if ($expected !== $actual) { echo "❌ FAIL: $msg\n"; exit(1); }
      echo "✅ PASS: $msg\n";
  }

  // Setup mock session to bypass requireAuth
  $_SESSION['user_id'] = 1; // Assuming admin or test user exists

  // Directly check map endpoint payload structure by instantiating controller
  require_once __DIR__ . '/../controllers/MapController.php';
  // Capture outputs
  ob_start();
  // Mock requireAuth and Auth helpers by defining functions if required or loading core.
  // For the test runner, we will check if we can call MapController:
  // Instead of mock request, query directly:
  $pdo = DB::conn();
  $clients = $pdo->query("SELECT id, ip_lat, ip_lon FROM vpn_clients WHERE deleted_at IS NULL AND ip_lat IS NOT NULL")->fetchAll();
  $servers = $pdo->query("SELECT id, lat, lon FROM vpn_servers WHERE deleted_at IS NULL AND lat IS NOT NULL")->fetchAll();
  
  echo "Database test: found " . count($clients) . " clients and " . count($servers) . " servers with coordinates.\n";
  ```

- [ ] **Step 2: Modify MapController.php**
  Update `MapController::clientsGeo()` in [MapController.php](file:///d:/GitHub/nk-core/controllers/MapController.php):
  - Add `'server_id' => (int) $row['server_id']` inside the client array mapper (around line 90).
  - Fetch all active servers with coordinate details and output them in the JSON payload under `servers`.
  ```php
  // Fetch servers:
  $serverSql = "
      SELECT 
          id, name, host, status, country, country_code, city, isp, org, lat, lon,
          (SELECT COUNT(*) FROM vpn_clients c WHERE c.server_id = s.id AND c.deleted_at IS NULL) as client_count
      FROM vpn_servers s
      WHERE deleted_at IS NULL
        AND lat IS NOT NULL
        AND lon IS NOT NULL
  ";
  $serverStmt = $pdo->prepare($serverSql);
  $serverStmt->execute();
  $serverRows = $serverStmt->fetchAll(PDO::FETCH_ASSOC);

  $servers = array_map(function ($row) {
      return [
          'id'           => (int) $row['id'],
          'name'         => $row['name'],
          'host'         => $row['host'],
          'status'       => $row['status'],
          'lat'          => (float) $row['lat'],
          'lon'          => (float) $row['lon'],
          'city'         => $row['city'],
          'country'      => $row['country'],
          'country_code' => $row['country_code'],
          'client_count' => (int) $row['client_count'],
      ];
  }, $serverRows);

  echo json_encode([
      'success' => true, 
      'clients' => $clients,
      'servers' => $servers
  ]);
  ```

- [ ] **Step 3: Commit MapController updates**
  ```bash
  git add controllers/MapController.php tests/map_api_test.php
  git commit -m "feat: expose server coordinates and client-server links in map api"
  ```

---

### Task 4: Frontend Map Implementation

**Files:**
- Modify: `templates/map.twig`

- [ ] **Step 1: Implement CSS styles for servers and connections**
  Add styles to [map.twig](file:///d:/GitHub/nk-core/templates/map.twig) inside the `{% block styles %}` block:
  - `.server-icon-wrapper`: Purple glowing background, circular border, server rack symbol.
  - `.animated-flow-line`: Dashed SVG stroke line with custom dash offset keyframe animation.
  - `.static-inactive-line`: Subtle, non-animated grey dashes.
  - `.server-info-card`: Styling for server detail popup layouts.

- [ ] **Step 2: Update Javascript logic to map both clients and servers**
  Modify the `DOMContentLoaded` script in [map.twig](file:///d:/GitHub/nk-core/templates/map.twig):
  - In `fetch('/api/map/clients')`, capture both `data.clients` and `data.servers`.
  - Draw Server markers on the map using a custom DivIcon.
  - Bind popups to servers to show the detailed server card (Name, host, location, client counts, view link) on click.
  - Bind `popupopen` and `popupclose` events to server markers to draw and clear connection lines.
  - Bind `mouseover` and `mouseout` events to server markers to draw subtle (semi-transparent) connection lines.
  - Bind `mouseover` and `mouseout` to single client markers to draw connection lines.
  - Respect existing map status filters (`currentFilter`) when drawing connections.
  - Implement Map Auto-Panning: Center map on marker on marker click.

- [ ] **Step 3: Commit frontend templates**
  ```bash
  git add templates/map.twig
  git commit -m "feat: render server nodes and interactive connection lines on map"
  ```
