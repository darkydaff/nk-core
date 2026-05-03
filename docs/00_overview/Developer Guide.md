[[../index|Global Index]] → [[index|00 Overview]] → [[Developer Guide]]

# Developer Guide

A guide for engineers contributing to or maintaining the NK-Core system.

## 🛠️ Local Environment Setup
1. **Clone the repository**: `git clone ...`
2. **Install dependencies**: `composer install`
3. **Configure Environment**: Copy `.env.example` to `.env` and fill in credentials.
4. **Launch Docker**: `docker-compose up -d`
5. **Database Setup**: Migrations run automatically on boot (via entrypoint) or manually via `bin/migrate`.

## 📂 Codebase Navigation
- **Adding a Route**: Update `public/index.php` with the new URI and corresponding controller method.
- **Creating a Controller**: Add a new class in `controllers/`. Ensure it handles both HTML and JSON requests appropriately.
- **Core Logic**: Place complex logic in `inc/` classes rather than controllers.
- **UI Changes**: Modify `.twig` files in `templates/`. Assets are in `public/css`, `public/js`, etc.

## 📝 Coding Standards
- **Strict Typing**: Use PHP type hinting and return types where possible.
- **Enums**: Leverage PHP Enums for status codes and protocol types (modernizing toward PHP 8.5).
- **Service Isolation**: Controllers should only coordinate; services in `inc/` should do the work.
- **Translation**: Never hardcode strings; use `Translator::t('key')` or `{{ t('key') }}` in Twig.

## 🧪 Testing & Verification
- Manual verification of UI changes.
- Checking `docker-compose logs -f app` for PHP errors.
- Verifying API responses via Postman or `curl`.

## 🔄 Deployment Pipeline
1. Code pushed to main.
2. Docker image built and pushed to registry.
3. Production server pulls new image and restarts containers.
4. Migrations applied during container startup.

---
- ⬅️ Previous: [[Project Overview]]
- ➡️ Next: [[../01_architecture/index|01 Architecture]]
- 📍 Parent: [[index|00 Overview]]
- 🔗 Related:
  - [[Project Overview]]
  - [[../01_architecture/Service Architecture|Service Architecture]]

