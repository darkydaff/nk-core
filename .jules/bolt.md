## 2026-05-19 - [N+1 Queries in Controllers]
**Learning:** Found an N+1 query pattern where controllers fetched a list of arrays from the DB, then iterated over them calling `new Model($id)` just to use formatting methods. This triggered a `load()` DB query on every instantiation.
**Action:** Extract formatters to `public static function` that accept the data array directly to avoid DB calls.
