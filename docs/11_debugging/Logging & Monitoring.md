[[../index|Global Index]] → [[index|11 Debugging]] → [[Logging & Monitoring]]

# Logging & Monitoring

NK-Core provides multiple layers of observability to ensure the health of the management panel and the distributed VPN fleet.

## 📝 Logging Strategy

### 1. Application Logs
- **Mechanism**: PHP `error_log()`.
- **Location**: 
  - In Docker: `docker logs -f nk-core-app`.
  - On Host: Typically `/var/log/nginx/error.log` and `/var/log/php-fpm/error.log`.
- **Content**: Stack traces, database errors, SSH connection failures, and API authentication issues.

### 2. Service-Specific Logs
- **Metrics Collector**: Redirects errors to `/var/log/metrics_collector_errors.log` (in container).
- **Deployment Logs**: Deployment errors are captured and stored in the `vpn_servers.error_message` database column for display in the UI.

### 3. Container Logs
- **VPN Container**: Run `docker logs <container_name>` on the remote node to see WireGuard/AWG startup logs.
- **Master Panel**: `docker-compose logs -f` shows real-time output from PHP, Nginx, and MariaDB.

## 📊 Monitoring Systems

### 1. Internal Health Checks (`ping_servers.php`)
- **Metric**: TCP availability of SSH and VPN ports.
- **Display**: Colored status indicators (Green/Red) on the dashboard.
- **Alerts**: Can trigger Telegram notifications if configured.

### 2. Resource Monitoring (Beszel Integration)
- **Integration**: `inc/BeszelClient.php`.
- **Metrics**: CPU Usage, RAM Utilization, Disk IO, and Network Bandwidth for the host machine.
- **Visualization**: Historical charts embedded in the server view.

### 3. VPN Traffic Metrics (`collect_metrics.php`)
- **Metric**: Bytes sent/received per client and per server.
- **Source**: `awg show all dump`.
- **History**: Stored in `server_metrics` and `client_metrics` tables for trend analysis.

## 🚨 Alerts & Notifications
- **Telegram Client**: `inc/TelegramClient.php`.
- **Triggers**:
  - Node Status Change (Online -> Offline).
  - Backup Success/Failure.
  - Critical Security Events (e.g., repeated login failures).

## 🔍 Debugging Mode
To enable deeper debugging:
1. Set `APP_DEBUG=true` in `.env`.
2. Check `docker-compose logs -f app` for detailed PHP notices.
3. Use the browser developer tools to inspect AJAX responses for non-JSON unexpected output.
4. Check the `scratch/` directory for temporary files generated during SSH operations.

---
- ⬅️ Previous: [[index|11 Debugging]]
- ➡️ Next: [[Troubleshooting]]
- 📍 Parent: [[index|11 Debugging]]
- 🔗 Related:
  - [[Troubleshooting]]
  - [[../09_infrastructure/Background Jobs & Async Processing|Background Jobs]]
  - [[../06_services/Backup Management (S3)|Backup Management]]

