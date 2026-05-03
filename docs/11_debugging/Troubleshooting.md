[[../index|Global Index]] → [[index|11 Debugging]] → [[Troubleshooting]]

# Troubleshooting

Common issues encountered when managing NK-Core and their solutions.

## 🖥️ Master Panel Issues

### 1. "Database connection error"
- **Cause**: MariaDB container is not ready or `.env` credentials are incorrect.
- **Fix**: 
  - Check `docker ps` to ensure the `db` container is running.
  - Verify `DB_HOST`, `DB_USER`, and `DB_PASSWORD` in `.env`.
  - Check `docker logs nk-core-db` for startup errors.

### 2. "Invalid CSRF Token"
- **Cause**: Session expired or multiple tabs are open with different tokens.
- **Fix**: Refresh the page. If persistent, clear browser cookies for the panel domain.

### 3. White Screen of Death (WSOD)
- **Cause**: Fatal PHP error (missing extension, syntax error).
- **Fix**: Run `docker logs -f nk-core-app` to see the actual error message.

## 🌐 Server Deployment Issues

### 1. "SSH connection failed"
- **Cause**: Incorrect host/port, firewall blocking SSH, or invalid credentials.
- **Fix**: 
  - Try connecting manually via `ssh -p <port> <user>@<host>`.
  - Ensure the Master Panel IP is whitelisted on the remote node's firewall.

### 2. "Docker image build failed"
- **Cause**: Low RAM on remote node or network issues while pulling Alpine/Go images.
- **Fix**: 
  - Ensure the node has at least 1GB (2GB preferred) of RAM for the Go compilation step.
  - Check node connectivity to `github.com` and `docker.io`.

### 3. "AmneziaWG kernel module failed to install"
- **Cause**: Incompatible kernel headers or non-Debian/Ubuntu OS.
- **Fix**: This is often a warning. The system will fall back to `amneziawg-go` (userspace), which is slower but functional.

## 👤 Client Issues

### 1. "Client is Offline" but should be Online
- **Cause**: `last_handshake` is not being updated or `syncStats` has not run.
- **Fix**: 
  - Trigger a manual sync from the server view.
  - Ensure the `collect_metrics.php` cron job is running.
  - Check if the client is actually sending traffic.

### 2. "Traffic Limit Exceeded"
- **Cause**: Client has consumed more bytes than allowed by their quota.
- **Fix**: Increase the limit in the Client Settings or wait for the quota reset period (if implemented).

## 🔧 Maintenance Commands
- **Clear Mux Sockets**: `rm -rf /tmp/ssh_mux/*` (run inside app container).
- **Manual Migration**: `php bin/migrate` (inside app container).
- **Restart App**: `docker-compose restart app web`.

---
- ⬅️ Previous: [[Logging & Monitoring]]
- ➡️ Next: [[../99_appendix/index|99 Appendix]]
- 📍 Parent: [[index|11 Debugging]]
- 🔗 Related:
  - [[Logging & Monitoring]]
  - [[../08_deployment/Deployment Guide|Deployment Guide]]
  - [[../06_services/Backup Management (S3)|Backup Management]]

