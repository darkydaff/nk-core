[[../index|Global Index]] → [[index|01 Architecture]]

# Architecture

System design and internal module relationships.

## 📄 Core Notes
- [[System Architecture]]
- [[Service Architecture]]
- [[Internal Module Relationships]]

## 🏗️ Design Patterns
- **MVCish**: Controller-driven routing with logic encapsulated in `inc/` services.
- **Service Layer**: Heavy lifting (VPN, SSH, S3) handled by specialized classes.
- **Singleton/Static Hubs**: Core utilities like `DB`, `Config`, and `Auth`.

---
- ⬅️ Previous: [[../00_overview/Developer Guide|Developer Guide]]
- ➡️ Next: [[System Architecture]]
- 📍 Parent: [[../index|Global Index]]
- 🔗 Related:
  - [[../00_overview/Project Overview|Project Overview]]
  - [[../02_backend/index|Backend Overview]]

