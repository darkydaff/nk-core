[[../index|Global Index]] → [[index|10 Workflows]] → [[Data Flow]]

# Data Flow

This document traces the end-to-end data lifecycle for key operations within the NK-Core system.

## 🚀 Server Deployment Flow
How a new VPN node is brought online.

1. **Input**: User provides IP, SSH Port, and Credentials in the Web UI.
2. **Controller**: `ServerController::store` validates input and creates a record in `vpn_servers` (Status: `deploying`).
3. **Service**: `VpnServer::deploy` is triggered.
   - **SSH Handshake**: Connects to the remote node.
   - **Docker Setup**: Installs Docker Engine if missing.
   - **Kernel Hardening**: Installs AmneziaWG kernel module on host for high performance.
   - **Directory Structure**: Creates `/opt/amnezia/nk-awg-v2`.
   - **Image Build**: Transmits Dockerfile and starts background build (compiling Go binaries).
   - **Network Setup**: Finds a free UDP port.
   - **Orchestration**: Runs the container and configures `iptables` for UDP/Forwarding.
   - **Key Generation**: Generates server keypairs and initial AWG mimicry parameters.
4. **Database**: Updates `vpn_servers` with keys and sets status to `active`.
5. **Output**: Server appears on dashboard with real-time stats.

## 👤 Client Creation Flow
How a new VPN user is provisioned.

1. **Input**: User clicks "Create Client" on a specific server view.
2. **Service**: `VpnClient::create`.
   - **IP Assignment**: Finds a free IP in the server's subnet (skipping network/gateway).
   - **Key Generation**: Runs `awg genkey` inside the remote container.
   - **Persistent Config**: Master panel appends the peer to `wg0.conf` on the node.
   - **Live Apply**: Master runs `awg syncconf` to update the running interface without dropping other connections.
   - **Metadata Update**: Updates `clientsTable` on the node for local observability.
3. **Database**: Stores client metadata (Keys, Client IP, expiration).
4. **Output**: Web UI generates a `.conf` file and a QR code for the user.

## 📊 Stat Synchronization Flow
How real-time metrics reach the dashboard.

1. **Trigger**: Cron job (`bin/ping_servers.php`) or manual refresh.
2. **Orchestrator**: Iterates through all `active` servers.
3. **Service**: `VpnClient::syncStats`.
   - **Command Execution**: SSHs into node and runs `awg show all dump`.
   - **Parsing**: Extracts `transferRx`, `transferTx`, and `latestHandshake` for every peer.
   - **GeoIP Lookup**: If a client's external IP has changed, triggers a GeoIP lookup for country/city.
   - **Beszel Sync**: (Optional) Fetches system metrics (CPU/RAM) via `BeszelClient`.
4. **Database**: Updates `vpn_clients` (traffic, geo) and `vpn_servers` (latency, status).
5. **Output**: Dashboard charts and tables update with fresh data.

## 🔒 API Request Lifecycle
How an external API call is handled.

1. **Request**: `POST /api/clients/create` with `Authorization: Bearer <token>`.
2. **Router**: Dispatches to `ApiController`.
3. **Auth Middleware**: `JWT::requireAuth` validates the token against the `api_tokens` table.
4. **Logic**: `ApiController::createClient` calls `VpnClient::create`.
5. **Response**: Returns `201 Created` with a JSON payload containing the client configuration.

---
- ⬅️ Previous: [[index|10 Workflows]]
- ➡️ Next: [[../11_debugging/index|11 Debugging]]
- 📍 Parent: [[index|10 Workflows]]
- 🔗 Related:
  - [[../01_architecture/System Architecture|System Architecture]]
  - [[../09_infrastructure/Background Jobs & Async Processing|Background Jobs]]
  - [[../04_database/Database Schema|Database Schema]]
