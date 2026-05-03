[[../index|Global Index]] → [[index|01 Architecture]] → [[Service Architecture]]

# Service Architecture

The service layer in `inc/` represents the core business logic of the NK-Core system. These classes are designed to be independent of the HTTP context, allowing them to be used in web controllers, CLI scripts, and background tasks.

## 📂 Internal Services

### `VpnServer.php`
- **Responsibility**: Lifecycle of VPN nodes.
- **Key Features**: 
  - SSH Multiplexing for high performance.
  - Automatic Docker deployment and image building.
  - AmneziaWG parameter randomization (obfuscation).
  - UDP port management and firewall (iptables) configuration.

### `VpnClient.php`
- **Responsibility**: Lifecycle of VPN peers.
- **Key Features**:
  - Keypair generation.
  - QR code and config file export.
  - Traffic limit enforcement (logic only; enforcement happens on node).
  - Expiration tracking.

### `BackupManager.php`
- **Responsibility**: System state preservation.
- **Key Features**:
  - Database dumps.
  - S3-compatible storage integration via `S3Lite`.
  - Incremental and scheduled backups.

### `BeszelClient.php`
- **Responsibility**: Infrastructure monitoring.
- **Key Features**:
  - Integrates with Beszel (system monitoring tool).
  - Fetches CPU, RAM, and Disk metrics from remote nodes via API.

## 🔌 External Integrations

### S3 Storage (`S3Lite.php`)
- Custom, lightweight implementation for S3-compatible APIs.
- Used for offloading backups.

### Telegram Bot (`TelegramClient.php`)
- Sends notifications for system alerts (e.g., node down, low disk space).
- Uses the standard Telegram Bot API.

### GeoIP
- Used to map client and server IPs to physical locations.
- Powers the dashboard map view.

## 🛠️ Static Utility Hubs
- **`DB`**: PDO wrapper for database connectivity.
- **`Config`**: Environment variable loader and accessor.
- **`Auth`**: Session-based user management.
- **`JWT`**: Token-based authentication for the REST API.
- **`Translator`**: Multilingual support engine.

---
- ⬅️ Previous: [[System Architecture]]
- ➡️ Next: [[Internal Module Relationships]]
- 📍 Parent: [[index|01 Architecture]]
- 🔗 Related:
  - [[System Architecture]]
  - [[Internal Module Relationships]]
  - [[../02_backend/Core Classes|Core Classes]]

