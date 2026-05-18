<?php
declare(strict_types=1);

class Auth {
  private static ?array $cachedUser = null;
  private static ?int   $cachedUserId = null;
  public static function register(string $name, string $email, string $password): bool {
    $pdo = DB::conn();
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    if (strlen($password) < 8) return false;
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    if ($stmt->fetchColumn()) return false;
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, name, role, status) VALUES (?, ?, ?, ?, ?)');
    return $stmt->execute([$email, $hash, $name ?: $email, UserRole::USER->value, 'active']);
  }

  public static function login(string $email, string $password): bool {
    $pdo = DB::conn();
    $email = strtolower(trim($email));
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rateKey = 'login:' . substr(md5($email . '|' . $ip), 0, 32);

    // Check rate limit (stored in settings table)
    $rlStmt = $pdo->prepare("SELECT `value` FROM settings WHERE namespace = 'ratelimit' AND `key` = ? AND user_id IS NULL LIMIT 1");
    $rlStmt->execute([$rateKey]);
    $rlRow = $rlStmt->fetch();
    if ($rlRow) {
        $d = json_decode($rlRow['value'], true) ?? [];
        if (!empty($d['blocked_until']) && $d['blocked_until'] > time()) {
            sleep(3);
            return false;
        }
        // Reset stale counter (window = 5 minutes)
        if (!empty($d['last_failure']) && (time() - $d['last_failure']) > 300) {
            $pdo->prepare("DELETE FROM settings WHERE namespace = 'ratelimit' AND `key` = ? AND user_id IS NULL")->execute([$rateKey]);
            $rlRow = null;
        }
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        // Record failure
        $d   = isset($rlRow) && $rlRow ? (json_decode($rlRow['value'], true) ?? []) : [];
        $cnt = (int)($d['attempts'] ?? 0) + 1;
        $newVal = json_encode(['attempts' => $cnt, 'last_failure' => time(), 'blocked_until' => $cnt >= 10 ? time() + 300 : 0]);
        $pdo->prepare("INSERT INTO settings (user_id, namespace, `key`, `value`) VALUES (NULL, 'ratelimit', ?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = NOW()")
            ->execute([$rateKey, $newVal]);
        sleep(1);
        return false;
    }

    // Clear rate limit on success
    $pdo->prepare("DELETE FROM settings WHERE namespace = 'ratelimit' AND `key` = ? AND user_id IS NULL")->execute([$rateKey]);

    $_SESSION['user_id'] = (int)$user['id'];
    $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$user['id']]);
    return true;
  }

  public static function logout(): void {
    unset($_SESSION['user_id']);
    self::$cachedUser   = null;
    self::$cachedUserId = null;
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
  }
  public static function check(): bool { return isset($_SESSION['user_id']); }

  public static function getUserByEmail(string $email): ?array {
    $pdo = DB::conn();
    $email = strtolower(trim($email));
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    return $user ?: null;
  }

  public static function user(): ?array {
    if (!self::check()) return null;
    $uid = (int)$_SESSION['user_id'];
    if (self::$cachedUser !== null && self::$cachedUserId === $uid) {
        return self::$cachedUser;
    }
    $pdo = DB::conn();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$uid]);
    $u = $stmt->fetch();
    self::$cachedUser   = $u ?: null;
    self::$cachedUserId = $uid;
    return self::$cachedUser;
  }

  public static function isAdmin(): bool {
    $u = self::user();
    return $u && ($u['role'] === UserRole::ADMIN->value);
  }

  public static function seedAdmin(string $email, string $password): void {
    $pdo = DB::conn();
    $email = strtolower(trim($email));
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    if ($stmt->fetchColumn()) return;
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, name, role, status) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$email, $hash, 'Administrator', UserRole::ADMIN->value, 'active']);
  }

  public static function listUsers(): array {
    $pdo = DB::conn();
    $stmt = $pdo->query('SELECT id, email, name, role, status, created_at, last_login_at FROM users WHERE deleted_at IS NULL ORDER BY id DESC');
    return $stmt->fetchAll();
  }

  public static function deleteUser(int $userId): bool {
    $pdo = DB::conn();
    // Soft delete user
    $stmt = $pdo->prepare('UPDATE users SET deleted_at = NOW(), status = ? WHERE id = ?');
    return $stmt->execute(['disabled', $userId]);
  }

  public static function setRole(int $userId, UserRole $role): bool {
    $pdo = DB::conn();
    $stmt = $pdo->prepare('UPDATE users SET role = ? WHERE id = ?');
    return $stmt->execute([$role->value, $userId]);
  }

  public static function saveSetting(?int $userId, string $namespace, string $key, string $valueJson): bool {
    $pdo = DB::conn();
    $stmt = $pdo->prepare('INSERT INTO settings (user_id, namespace, `key`, `value`) VALUES (?, ?, ?, CAST(? AS JSON))
                           ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = NOW()');
    return $stmt->execute([$userId, $namespace, $key, $valueJson]);
  }

  public static function getSetting(?int $userId, string $namespace, string $key): array {
    $pdo = DB::conn();
    $stmt = $pdo->prepare('SELECT `value` FROM settings WHERE user_id <=> ? AND namespace = ? AND `key` = ? LIMIT 1');
    $stmt->execute([$userId, $namespace, $key]);
    $val = $stmt->fetchColumn();
    if (!$val) return [];
    $decoded = json_decode($val, true);
    return is_array($decoded) ? $decoded : [];
  }
}