[[../index|Global Index]] → [[index|07 Security]] → [[Security Model]]

# Security Model

The NK-Core security model is built on layers of defense to protect the master panel, the remote nodes, and the VPN traffic itself.

## 🛡️ Layered Defense

### 1. Application Layer (PHP)
- **CSRF Protection**: All state-changing requests must include a valid `csrf_token`.
- **Prepared Statements**: All database interactions use PDO prepared statements via the `DB` class.
- **Strict Typing**: Leveraging PHP 8.1+ type system to reduce logic errors.
- **Auth Isolation**: JWT for API and Sessions for Web are handled by independent modules (`JWT.php` vs `Auth.php`).

### 2. Orchestration Layer (SSH)
- **SSH Multiplexing**: `ControlMaster` sockets are stored in `/tmp/ssh_mux/nk_*` with `0700` permissions.
- **Credential Fallback**: The system prefers SSH Private Keys if available, falling back to `sshpass` for password-based authentication.
- **Session Unlocking**: Critical paths use `unlockSession()` to allow the UI to remain responsive during long SSH operations (like Go compilation on the node).

### 3. Node & Container Layer (Docker)
- **Container Isolation**: VPN (AmneziaWG) and Proxy services are isolated in Docker containers.
- **Network Capabilities**: Containers run with `CAP_NET_ADMIN` to manage the `wg0` interface.
- **Host Hardening**: The deployment script automatically configures `iptables` on the host to allow VPN traffic and enable IP forwarding.

### 4. Protocol Layer (AmneziaWG)
- **Obfuscation**: AmneziaWG (AWG) provides mimicry against Deep Packet Inspection (DPI) by randomizing headers (`Jc`, `Jmin`, `Jmax`) and using static noise (`S1`, `S2`).
- **Key Isolation**: Every peer has a unique Curve25519 keypair.
- **Statelessness**: The remote node does not store client metadata beyond the `wg0.conf` and a local `clientsTable` JSON for observability.

## ⚠️ Known Risks & Hardening
| Risk | Current State | Recommendation |
| :--- | :--- | :--- |
| **Credential Exposure** | Stored in plain text in MariaDB. | Implement AES-256 encryption at rest for `vpn_servers.password` and `ssh_private_key`. |
| **DPI Detection** | Dependent on AWG parameters. | Periodically rotate mimicry parameters (J/S/H/I values). |
| **Node Resource Exhaustion** | No per-container cgroups limits. | Implement CPU/RAM limits in `VpnServer::runContainer`. |

## 🔒 Best Practices
- Keep the `.env` file secure (chmod 600).
- Disable `root` SSH login on nodes; use a dedicated `sudo` user.
- Ensure the Master Panel is behind a reverse proxy (Nginx) with TLS 1.3.

---
- ⬅️ Previous: [[Authentication System]]
- ➡️ Next: [[../08_deployment/index|08 Deployment]]
- 📍 Parent: [[index|07 Security]]
- 🔗 Related:
  - [[Authentication System]]
  - [[../01_architecture/System Architecture|System Architecture]]
  - [[../11_debugging/Logging & Monitoring|Logging & Monitoring]]
