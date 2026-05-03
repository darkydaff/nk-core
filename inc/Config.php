<?php
declare(strict_types=1);

class Config {
  protected static array $env = [];
  protected static bool $dbLoaded = false;
  protected static bool $loadingDb = false;

  public static function load(string $path): void {
    if (!file_exists($path)) {
      // allow running with only environment variables exported
      return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
      if (str_starts_with(trim($line), '#')) continue;
      $parts = explode('=', $line, 2);
      if (count($parts) !== 2) continue;
      $key = trim($parts[0]);
      $value = trim($parts[1]);
      $value = trim($value, "\"' ");
      self::$env[$key] = $value;
      @putenv($key . '=' . $value);
    }
  }

  private static function loadFromDb(): void {
      if (self::$dbLoaded || self::$loadingDb) return;
      self::$loadingDb = true;

      try {
          if (!class_exists('DB')) {
              $dbPath = __DIR__ . '/DB.php';
              if (file_exists($dbPath)) {
                  require_once $dbPath;
              }
          }
          if (class_exists('DB')) {
              $pdo = DB::conn();
              if ($pdo) {
                  $stmt = $pdo->query("SELECT `key`, `value` FROM settings WHERE namespace = 'env' AND user_id IS NULL");
                  if ($stmt) {
                      while ($row = $stmt->fetch()) {
                          $val = json_decode($row['value'], true);
                          self::$env[$row['key']] = $val;
                          @putenv($row['key'] . '=' . $val);
                      }
                  }
                  self::$dbLoaded = true;
              }
          }
      } catch (\Throwable $e) {
          // DB might not be ready yet
      } finally {
          self::$loadingDb = false;
      }
  }

  public static function get(string $key, $default = null) {
    self::loadFromDb();
    if (array_key_exists($key, self::$env)) {
      return self::$env[$key];
    }
    $env = getenv($key);
    if ($env !== false && $env !== null) return $env;
    return $default;
  }

  public static function set(string $key, string $value): void {
      self::$env[$key] = $value;
      @putenv($key . '=' . $value);
  }

  /**
   * Update the .env file with multiple keys safely and persist to database
   */
  public static function updateEnv(array $data): bool {
      // 1. Save new data to DB first
      if (!self::$loadingDb) {
          self::$loadingDb = true;
          try {
              if (!class_exists('DB')) {
                  $dbPath = __DIR__ . '/DB.php';
                  if (file_exists($dbPath)) {
                      require_once $dbPath;
                  }
              }
              if (class_exists('DB')) {
                  $pdo = DB::conn();
                  if ($pdo) {
                      $stmt = $pdo->prepare("INSERT INTO settings (user_id, namespace, `key`, value) VALUES (NULL, 'env', ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)");
                      foreach ($data as $key => $value) {
                          $stmt->execute([$key, json_encode((string)$value)]);
                      }
                  }
              }
          } catch (\Throwable $e) {
              \Logger::error("Config Error: Failed to save to DB settings - " . $e->getMessage());
          } finally {
              self::$loadingDb = false;
          }
      }

      // 2. Load ALL 'env' settings from DB to ensure we have the full picture
      self::loadFromDb();
      
      // Merge new data into our memory cache immediately
      foreach ($data as $key => $value) {
          self::$env[$key] = $value;
      }

      // 3. Update the .env file
      $path = __DIR__ . '/../.env';
      if (!file_exists($path)) {
          // If file doesn't exist, try to create it from .env.example
          $examplePath = __DIR__ . '/../.env.example';
          if (file_exists($examplePath)) {
              copy($examplePath, $path);
          } else {
              touch($path);
          }
      }

      $content = file_get_contents($path);
      
      // We want to ensure ALL keys in self::$env are correctly represented in $content
      // This makes the system self-healing if .env is partially wiped
      foreach (self::$env as $key => $value) {
          // Skip internal or non-string values if any
          if (!is_scalar($value)) continue;

          $pattern = "/^{$key}=.*/m";
          $replacement = "{$key}={$value}";
          
          if (preg_match($pattern, $content)) {
              $content = preg_replace($pattern, $replacement, $content);
          } else {
              // Append to the end, ensuring newline
              if (strlen($content) > 0 && substr($content, -1) !== "\n") {
                  $content .= "\n";
              }
              $content .= "{$key}={$value}\n";
          }
          @putenv($key . '=' . $value);
      }

      if (!is_writable($path)) {
          \Logger::error("Config Error: .env file is not writable at $path");
          // Even if .env isn't writable, we return true if we saved to DB
          return self::$dbLoaded; 
      }

      return @file_put_contents($path, $content) !== false;
  }
}