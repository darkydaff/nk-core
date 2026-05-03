[[../index|Global Index]] → [[index|02 Backend]] → [[Controller Reference]]

# Controller Reference

Controllers in NK-Core act as the entry point for HTTP requests, coordinating between the view engine and the service layer.

## 📁 Web Controllers

### `ServerController` ([[/controllers/ServerController.php]])
- **Dashboard**: `GET /dashboard` - Primary monitoring view.
- **Management**: `GET /servers` - CRUD interface for nodes.
- **Sync**: `POST /servers/sync-all` - Triggers background stat refresh.

### `ClientController` ([[/controllers/ClientController.php]])
- **View**: `GET /clients/{id}` - Detailed client usage and config.
- **Operations**: `POST /clients/{id}/revoke`, `POST /clients/{id}/delete`.
- **Export**: `GET /clients/{id}/download` - Returns `.conf` file.

### `SettingsController` ([[/controllers/SettingsController.php]])
- **Profile**: Password change and account management.
- **System**: S3 configuration, Telegram Bot settings, Backup schedules.
- **Monitoring**: Beszel integration setup.

### `AuthController` ([[/controllers/AuthController.php]])
- **Login**: `GET /login`, `POST /login`.
- **Logout**: `GET /logout`.

## 📁 API Controllers

### `ApiController` ([[/controllers/ApiController.php]])
- Handles all routes under `/api/`.
- Enforces JWT authentication.
- Returns JSON responses exclusively.

## 📁 Auxiliary Controllers

### `MapController` ([[/controllers/MapController.php]])
- Provides GeoIP data for the visual dashboard map.

### `LanguageController` ([[/controllers/LanguageController.php]])
- Handles runtime language switching for the user session.

### `MonitoringController` ([[/controllers/MonitoringController.php]])
- Proxies requests to Beszel agents for real-time hardware metrics.

---
- ⬅️ Previous: [[Core Classes]]
- ➡️ Next: [[../03_frontend/index|03 Frontend]]
- 📍 Parent: [[index|02 Backend]]
- 🔗 Related:
  - [[Core Classes]]
  - [[../05_api/index|API Reference]]
  - [[../07_security/Authentication System|Authentication System]]

