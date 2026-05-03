[[../index|Global Index]] → [[index|05 API]] → [[API Reference]]

# API Reference

The NK-Core REST API allows programmatic management of servers, clients, and system settings. All requests must be authenticated via JWT.

## 🔑 Authentication
- **Endpoint**: `POST /api/auth/token`
- **Body**: `{"email": "...", "password": "..."}`
- **Response**: `{"token": "...", "expires_in": 2592000}`
- **Usage**: Include `Authorization: Bearer <token>` in subsequent requests.

## 🔑 Token Management
- `GET /api/tokens` - List active API tokens.
- `POST /api/tokens` - Create a new token.
- `DELETE /api/tokens/{id}` - Revoke a token.

## 🖥️ Server Endpoints

### Management
- `GET /api/servers` - List all servers.
- `POST /api/servers/create` - Provision a new server node.
- `DELETE /api/servers/{id}/delete` - Soft-delete a server.

### Backups
- `GET /api/servers/{id}/backups` - List backups for a server.
- `POST /api/servers/{id}/backup` - Trigger a new backup.
- `POST /api/servers/{id}/restore` - Restore a server from a backup.
- `DELETE /api/backups/{id}` - Delete a specific backup file.

### Metrics & Health
- `GET /api/servers/{id}/metrics` - Hardware metrics (Beszel).
- `GET /api/servers/{id}/clients` - List all active peers on this server.

## 👤 Client Endpoints

### Lifecycle
- `GET /api/clients` - List all clients across all servers.
- `GET /api/clients/{id}/details` - Detailed stats and config.
- `POST /api/clients/create` - Create a new VPN client.
- `POST /api/clients/{id}/revoke` - Set status to `disabled` and remove from node.
- `POST /api/clients/{id}/restore` - Restore access on the node.

### Quotas & Expiration
- `POST /api/clients/{id}/set-expiration` - Update expiration date.
- `POST /api/clients/{id}/extend` - Add days to current expiration.
- `GET /api/clients/expiring` - List clients expiring soon.
- `POST /api/clients/{id}/set-traffic-limit` - Set byte quota.
- `GET /api/clients/{id}/traffic-limit-status` - Check current quota usage.

## 🔌 Proxy Endpoints
- `GET /api/proxies` - List all proxy instances.
- `POST /api/proxies` - Create a new SOCKS5/HTTP proxy.
- `POST /api/proxies/{id}/pause` - Temporarily disable a proxy.
- `POST /api/proxies/{id}/resume` - Re-enable a proxy.
- `DELETE /api/proxies/{id}` - Remove a proxy from the node.

## 🌐 Translation Endpoints
- `POST /api/translations/auto-translate` - Trigger AI translation.
- `GET /api/translations/export/{lang}` - Download translation JSON.

## 📝 Error Handling
Standard HTTP status codes:
- `200/201`: Success.
- `400`: Bad Request.
- `401`: Unauthorized (Missing/invalid JWT).
- `403`: Forbidden (Insufficient permissions).
- `404`: Not Found.
- `500`: Internal Server Error.

---
- ⬅️ Previous: [[index|05 API]]
- ➡️ Next: [[../06_services/index|06 Services]]
- 📍 Parent: [[index|05 API]]
- 🔗 Related:
  - [[../07_security/Authentication System|Authentication System]]
  - [[../02_backend/Controller Reference|Controller Reference]]
  - [[../10_workflows/Data Flow|Data Flow]]
