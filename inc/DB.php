<?php
class DB {
  private static ?PDO $pdo = null;
  private static int $lastVerified = 0;

  public static function conn(): PDO {
    if (self::$pdo) {
      // Verify connection is still alive (handles long-running processes)
      // Throttle verification to max once per 5 seconds to reduce N+1 queries
      $now = time();
      if ($now - self::$lastVerified > 5) {
        try {
          self::$pdo->query('SELECT 1');
          self::$lastVerified = $now;
        } catch (\Throwable $e) {
          self::$pdo = null; // Force reconnect
          self::$lastVerified = 0;
        }
      }
    }
    if (self::$pdo) return self::$pdo;

    $host = Config::get('DB_HOST', '127.0.0.1');
    $port = Config::get('DB_PORT', '3306');
    $db   = Config::get('DB_DATABASE', 'amnezia_panel');
    $user = Config::get('DB_USERNAME', 'amnezia');
    $pass = Config::get('DB_PASSWORD', '');
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $db);
    $options = [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false,
      PDO::ATTR_TIMEOUT => 5,
    ];
    self::$pdo = new PDO($dsn, $user, $pass, $options);
    
    // Explicitly set UTF-8 encoding and timezone (+03:00 for Moscow)
    self::$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    self::$pdo->exec("SET time_zone = '+03:00'");
    
    return self::$pdo;
  }

  /**
   * Drop the PDO singleton so the next conn() call opens a fresh connection.
   * Must be called after a full DB restore so subsequent queries see the new schema.
   */
  public static function invalidate(): void {
    self::$pdo = null;
  }
}