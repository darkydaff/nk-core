# Design Spec: Map Servers and Interactive Connections

Implement a network visualization on the Intelligence Map page by plotting VPN servers and dynamically rendering connection lines (active/inactive) between clients and their connected servers.

## User Review Required

> [!NOTE]
> * **Interactive Hover Logic**: Clustered dots (multiple clients at the same location) do not show connection lines on hover to prevent map clutter. Hovering over a row inside the cluster's popup list highlights that individual client's connection line.
> * **Single Client Hover**: Standalone client dots will draw their connection line directly when hovered.
> * **Server Hover**: Hovering over a server marker shows all connections to its clients (online and offline) at once.
> * **Visual Distinction**: Servers are plotted as purple glowing server icon markers to stand out from client dots (pulsing green for online, static gray for offline).

## Proposed Changes

---

### Database Schema

#### [NEW] [066_add_server_coordinates.sql](file:///d:/GitHub/nk-core/migrations/066_add_server_coordinates.sql)
Add `latitude` and `longitude` columns to the `vpn_servers` table to store resolved geographical coordinates.
```sql
-- Migration 066: Add latitude and longitude to vpn_servers
ALTER TABLE vpn_servers
    ADD COLUMN lat DECIMAL(10, 7) NULL COMMENT 'Latitude from IP geolocation' AFTER org,
    ADD COLUMN lon DECIMAL(10, 7) NULL COMMENT 'Longitude from IP geolocation' AFTER lat;
```

---

### Backend Logic

#### [MODIFY] [VpnServer.php](file:///d:/GitHub/nk-core/inc/VpnServer.php)
Update `VpnServer::updateGeoIp()` to query `lat` and `lon` fields in the `ip-api.com` API request and save them in the new database columns.
```php
// In VpnServer::updateGeoIp()
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

#### [MODIFY] [MapController.php](file:///d:/GitHub/nk-core/controllers/MapController.php)
Update the Map API to return both client and server data. We'll load all active servers with coordinate details to avoid multiple API round trips.
* Return a list of servers with `id`, `name`, `status`, `lat`, `lon`, `city`, `country`, `country_code`, `client_count`.
* Client payloads already return `server_id` or `server_name` so we can map the links on the frontend.

---

### Frontend UI (Leaflet Map)

#### [MODIFY] [map.twig](file:///d:/GitHub/nk-core/templates/map.twig)
Update Leaflet map setup to fetch both clients and servers and render them with interactive connections.
* **Server Markers**:
  * Rendered using custom Leaflet `L.divIcon` with a purple theme containing `<i class="fas fa-server"></i>` and a subtle glow.
  * Add `mouseover` listener: draws dashed connection lines to all of its clients (online and offline) in a highly subtle/low-opacity style so it doesn't block the base map.
  * Add `mouseout` listener: clears connection lines.
  * Add `click` listener: opens an information card popup showing the server name, IP/host, city/country location, total & online client count, ping latency, and a link to view server details (`/servers/${server.id}`). Smart-pans the map to center the server.
* **Client Markers**:
  * Render standalone dots (1 client) and clustered dots (>1 clients).
  * Add `mouseover` listener to standalone client dots to immediately highlight their connection line.
  * Update cluster popups to list clients. Add `onmouseover` and `onmouseout` to the row elements so hovering a list row draws the corresponding client's line.
  * Add `click` listener to center the map on the client marker.
* **Connection Lines**:
  * Drawn using Leaflet `L.polyline`.
  * **Online Connection**: Purple flowing dashed line (using CSS stroke-dashoffset animation).
  * **Offline Connection**: Thin, low-opacity, static dashed gray line.
  * **Server Hover Overlay**: When highlighting connections by hovering over a server, line weight and opacity are reduced (e.g. `opacity: 0.35` for active, `opacity: 0.15` for inactive) to prevent blocking the map details.
  * **Respect Status Filters**: Connection lines shown on the map (including those drawn during server hovers) must respect the active map status filters (All, Online, Offline). For example, filtering by "Online" hides offline client dots and their inactive connection lines.
* **Smart Map Panning**:
  * Clicking any marker (client, cluster, or server) automatically pans the map to center on that coordinate to ensure the popup is fully visible and the connections are easy to trace.

## Verification Plan

### Manual Verification
1. Run database migration `066_add_server_coordinates.sql` using standard MySQL execution.
2. Trigger GeoIP lookup for existing servers to resolve their coordinates.
3. Open the Dashboard Map page and verify:
   * Servers appear as glowing purple icons.
   * Standalone clients show their line to their server on hover.
   * Clusters show a list of clients, and hovering over a list row draws that client's connection line.
   * Hovering a server lights up all its client connections.
   * Offline clients are gray, and show thin static gray connection lines.
