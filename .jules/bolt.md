## 2024-05-16 - [DB Connection Ping Overhead]
**Learning:** The DB singleton (`DB::conn()`) previously ran `SELECT 1` on *every* invocation to ensure connection liveliness for long-running processes (e.g. queue workers). This created an N+1 query overhead for typical web requests that call `DB::conn()` multiple times.
**Action:** Implemented a 5-second throttle (`$lastVerified`) to avoid redundant connection pings. This keeps the safety for long-running workers without destroying standard request performance.
