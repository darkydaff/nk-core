[[../index|Global Index]] → [[index|08 Deployment]] → [[Deployment Guide]]

# Deployment Guide

This guide covers the production deployment of the NK-Core Master Panel using Docker.

## 📋 Prerequisites
- **Server**: VPS with Public IP (2GB RAM, 20GB SSD recommended).
- **OS**: Linux with Docker and Docker Compose installed.
- **Domain**: Optional but recommended for SSL.

## 🚀 Quick Start (Docker Compose)

1. **Clone the repository**:
   ```bash
   git clone https://github.com/amnezia-vpn/nk-core.git /opt/nk-core
   cd /opt/nk-core
   ```

2. **Configure Environment**:
   ```bash
   cp .env.example .env
   nano .env
   ```
   *Required variables*: `DB_PASSWORD`, `ADMIN_EMAIL`, `ADMIN_PASSWORD`, `JWT_SECRET`.

3. **Launch Containers**:
   ```bash
   docker-compose up -d
   ```
   This will start:
   - `app`: PHP-FPM application.
   - `web`: Nginx web server.
   - `db`: MariaDB database.

4. **Initialize Database**:
   Migrations run automatically on first boot. To manually run them:
   ```bash
   docker-compose exec app php bin/migrate
   ```

## 🔧 Nginx Configuration
The master panel includes a pre-configured `nginx.conf`. If using a reverse proxy (like Nginx Proxy Manager or Traefik), ensure you pass the following headers:
- `X-Forwarded-For`
- `X-Forwarded-Proto`
- `X-Real-IP`

## 🕰️ Configuring Cron Jobs
To enable monitoring and backups, add the following to your host's crontab:
```bash
* * * * * docker-compose -f /opt/nk-core/docker-compose.yml exec -T app php bin/ping_servers.php
*/5 * * * * docker-compose -f /opt/nk-core/docker-compose.yml exec -T app php bin/collect_metrics.php
0 3 * * * docker-compose -f /opt/nk-core/docker-compose.yml exec -T app php bin/run_backup.php
```

## 🆙 Updating
To update to the latest version:
```bash
cd /opt/nk-core
git pull
docker-compose build --pull app
docker-compose up -d
```

## 🛡️ Hardening
- **Firewall**: Restrict access to port 3306 (DB) to the internal Docker network only.
- **SSH**: Use SSH Keys for the Master Panel and disable password authentication.
- **SSL**: Use Certbot or a similar tool to enable HTTPS on the Master Panel.

---
- ⬅️ Previous: [[index|08 Deployment]]
- ➡️ Next: [[../09_infrastructure/index|09 Infrastructure]]
- 📍 Parent: [[index|08 Deployment]]
- 🔗 Related:
  - [[../00_overview/Developer Guide|Developer Guide]]
  - [[../09_infrastructure/Infrastructure Overview|Infrastructure Overview]]
  - [[../11_debugging/Troubleshooting|Troubleshooting]]

