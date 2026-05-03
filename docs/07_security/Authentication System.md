[[../index|Global Index]] → [[index|07 Security]] → [[Authentication System]]

# Authentication System

NK-Core implements a dual-mode authentication system to support both interactive web users and programmatic API consumers.

## 🔑 Authentication Modes

### 1. Session-Based (Web Dashboard)
- **Implementation**: `inc/Auth.php`
- **Mechanism**: Standard PHP sessions.
- **Storage**: User IDs are stored in `$_SESSION['user_id']`.
- **Flow**:
  1. User submits login form with email/password.
  2. System verifies credentials against `users` table using `password_verify`.
  3. Session is initialized.
  4. Subsequent requests are authenticated via the session cookie.

### 2. JWT-Based (REST API)
- **Implementation**: `inc/JWT.php`
- **Mechanism**: JSON Web Tokens (HS256).
- **Header**: `Authorization: Bearer <token>`.
- **Persistence**: Long-lived API tokens are stored in the `api_tokens` table for revocation tracking.
- **Flow**:
  1. Consumer provide a valid JWT in the Authorization header.
  2. System decodes the token using the secret from `.env`.
  3. User ID is extracted and the user record is loaded.

## 🛡️ Protection Mechanisms

### CSRF Protection
- **Implementation**: `inc/CSRF.php`
- **Scope**: Enabled for all non-GET requests in the Web Dashboard.
- **Headers/Fields**: Supports `csrf_token` POST field or `X-CSRF-Token` HTTP header.
- **Exception**: API routes (`/api/*`) are exempt from CSRF as they use JWT.

### Session Management
- **Concurrent Access**: Supports session unlocking (`unlockSession()`) for long-running tasks (e.g., server deployment) to prevent UI blocking.
- **Security**: custom session names defined in `.env` or defaulting to `amnezia_panel_session`.

## 👤 User Roles (RBAC)
- **`admin`**: Full access to all system functions.
- **`user`**: Restricted access.

## 📝 Critical Security Notes
> [!WARNING]
> **Credential Storage**: Currently, SSH passwords and private keys for remote nodes are stored in **plain text** within the `vpn_servers` table. Access to the MariaDB database must be strictly controlled.

> [!IMPORTANT]
> **Admin Seeding**: On the first run, the system automatically creates an admin account using `ADMIN_EMAIL` and `ADMIN_PASSWORD` from the `.env` file if they are provided.

---
- ⬅️ Previous: [[index|07 Security]]
- ➡️ Next: [[Security Model]]
- 📍 Parent: [[index|07 Security]]
- 🔗 Related:
  - [[Security Model]]
  - [[../05_api/API Reference|API Reference]]
  - [[../02_backend/Controller Reference|Controller Reference]]
