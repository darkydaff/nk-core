[[../index|Global Index]] → [[index|02 Backend]] → [[Core Classes]]

# Core Classes

The engine room of NK-Core. This document provides a summary of the most important classes in the `inc/` directory.

## 🏗️ Orchestration Classes

### `VpnServer` ([[/inc/VpnServer.php]])
The primary class for managing remote nodes.
- **Purpose**: Server deployment, SSH multiplexing, container management.
- **Notable Methods**: `deploy()`, `executeCommand()`, `syncStats()`.

### `VpnClient` ([[/inc/VpnClient.php]])
Manages individual VPN users (peers).
- **Purpose**: Peer configuration, key management, traffic tracking.
- **Notable Methods**: `create()`, `revoke()`, `getFormattedStats()`.

### `ProxyServer` ([[/inc/ProxyServer.php]])
Manages SOCKS5/HTTP proxy instances on nodes.
- **Purpose**: Installing proxy containers, managing user accounts.
- **Notable Methods**: `install()`, `syncUsers()`, `findFreePort()`.

## 🛠️ Infrastructure Classes

### `BackupManager` ([[/inc/BackupManager.php]])
Handles system-wide state preservation.
- **Purpose**: Database dumps, file archiving, S3 uploads.

### `BeszelClient` ([[/inc/BeszelClient.php]])
Client for the Beszel monitoring system.
- **Purpose**: Fetching hardware metrics from nodes.

### `S3Lite` ([[/inc/S3Lite.php]])
A custom, zero-dependency S3 client.
- **Purpose**: Multi-part uploads, bucket management.

## ⚙️ Utility Classes

### `DB` ([[/inc/DB.php]])
- **Purpose**: Singleton PDO connection manager.

### `Auth` ([[/inc/Auth.php]])
- **Purpose**: User session management and RBAC.

### `JWT` ([[/inc/JWT.php]])
- **Purpose**: API token generation and validation.

### `Translator` ([[/inc/Translator.php]])
- **Purpose**: Localization and AI-powered translation management.

---
- ⬅️ Previous: [[index|02 Backend]]
- ➡️ Next: [[Controller Reference]]
- 📍 Parent: [[index|02 Backend]]
- 🔗 Related:
  - [[../01_architecture/Service Architecture|Service Architecture]]
  - [[Controller Reference]]
  - [[../06_services/index|External Services]]

