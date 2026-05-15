## 2024-03-XX - DB Connection Overhead
**Learning:** `DB::conn()` calls `self::$pdo->query('SELECT 1')` EVERY time it returns an existing connection. Since `DB::conn()` is called multiple times per request (especially in loops or when instantiating many objects that fetch from DB), this adds a significant overhead of unnecessary database pings.
**Action:** Remove or rate-limit the `SELECT 1` ping.
