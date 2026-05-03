[[../index|Global Index]] → [[index|06 Services]] → [[Backup Management (S3)]]

# Backup Management (S3)

NK-Core includes a robust backup system that ensures the state of the master panel can be recovered in the event of hardware failure or data corruption.

## 📦 What is Backed Up?
1. **Database Dump**: A full export of the MariaDB database including all tables (users, servers, clients, settings).
2. **System State**: The `.env` file and critical configuration artifacts.

## ☁️ S3 Integration
The system uses the `S3Lite` class to communicate with S3-compatible providers.
- **Supported Providers**: AWS S3, DigitalOcean Spaces, Backblaze B2, MinIO, etc.
- **Configuration**: Settings are stored in the `settings` table under the `backups` namespace.

## 🕰️ Scheduling
Backups can be scheduled via the dashboard:
- **Frequency**: Daily, Weekly, or Monthly.
- **Retention**: Configurable number of backups to keep before older ones are rotated.

## 🛠️ Manual Operations
- **Trigger Backup**: `POST /settings/run-backup` via UI or `php bin/run_backup.php` via CLI.
- **Restore**: `POST /settings/restore-backup`.
- **Upload & Restore**: Allows the admin to upload a previous backup file directly to the panel for restoration.

## 🔄 Restore Workflow
1. **Cleanup**: System clears existing database tables (or creates a fresh DB).
2. **Injection**: SQL dump from the backup is executed.
3. **Synchronization**: After DB restoration, the system triggers a `syncAll` to verify that the remote nodes still match the restored state.

---
- ⬅️ Previous: [[index|06 Services]]
- ➡️ Next: [[../07_security/index|07 Security]]
- 📍 Parent: [[index|06 Services]]
- 🔗 Related:
  - [[../04_database/Database Schema|Database Schema]]
  - [[../09_infrastructure/Background Jobs & Async Processing|Background Jobs]]
  - [[../11_debugging/Troubleshooting|Troubleshooting]]

