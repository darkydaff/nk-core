[[../index|Global Index]] → [[index|09 Infrastructure]] → [[Background Jobs & Async Processing]]

# Background Jobs & Async Processing

NK-Core uses a combination of scheduled CLI scripts and "Pseudo-Async" AJAX requests to handle long-running tasks without blocking the user interface.

## 🕰️ Scheduled Tasks (Cron)
The system relies on several PHP scripts in the `/bin` directory, which should be configured as cron jobs.

### 1. Metrics Collection (`bin/collect_metrics.php`)
- **Frequency**: Every 1-5 minutes.
- **Purpose**: SSHs into all active servers, pulls VPN traffic stats (`awg show`), and records them in the database.
- **Impact**: Powers the dashboard charts and enforces traffic limits.

### 2. Health Monitoring (`bin/ping_servers.php`)
- **Frequency**: Every 1 minute.
- **Purpose**: Verifies SSH and VPN port availability for all nodes.
- **Impact**: Updates server status icons and sends Telegram alerts if a node goes offline.

### 3. Automated Backups (`bin/run_backup.php`)
- **Frequency**: Daily or as configured in Settings.
- **Purpose**: Dumps the database and uploads a compressed archive to S3.
- **Impact**: Ensures disaster recovery capability.

## ⚡ Pseudo-Async Processing
For tasks triggered by the UI that take longer than a standard HTTP timeout (e.g., deploying a new server), NK-Core uses several techniques:

### 1. Session Unlocking
In `public/index.php`, the `unlockSession()` function is called before long-running operations. This releases the PHP session lock, allowing the user to continue browsing other pages while the task completes in the background.

```php
function unlockSession(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}
```

### 2. Long Polling / Progress Tracking
The frontend initiates a deployment and then polls an API endpoint (e.g., `GET /api/servers/{id}`) to check the `status` and `error_message` fields. The UI updates dynamically based on the state transitions in the database.

## 🛠️ Service Workers (Planned)
Future versions may implement a dedicated queue system (e.g., Redis + Worker) for better scalability, but the current SSH Multiplexing + Cron approach is optimized for low-resource environments.

---
- ⬅️ Previous: [[Infrastructure Overview]]
- ➡️ Next: [[../10_workflows/index|10 Workflows]]
- 📍 Parent: [[index|09 Infrastructure]]
- 🔗 Related:
  - [[Infrastructure Overview]]
  - [[../06_services/Backup Management (S3)|Backup Management]]
  - [[../11_debugging/Logging & Monitoring|Logging & Monitoring]]

