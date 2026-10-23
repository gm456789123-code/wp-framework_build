# wp-framework — Sovereign Engine 🚀

> **A minimal headless framework for WordPress.**  
> Clean Hexagonal DDD architecture, 100% Strict Types, zero frontend CSS bloat, and Latte 3.x templates.

WordPress is used strictly as a data store and admin dashboard (`wp-admin` + the Gutenberg editor); the public-facing site is rendered entirely by this codebase, never by a WordPress theme.

---

## 📦 Repositories

* **Source Code (Core Dev, Pest Tests & Docker):**  
  [https://github.com/gm456789123-code/my-frame.git](https://github.com/gm456789123-code/my-frame.git)
* **Pre-Built Distribution (Shared Hosting & Production Ready with `vendor/`):**  
  [https://github.com/gm456789123-code/wp-framework_build.git](https://github.com/gm456789123-code/wp-framework_build.git)

---

## 🏛️ Architecture (Hexagonal DDD)

```
src/
├── Domain/           Pure business rules. Never imports wpdb/WP_*/WordPress functions.
│   ├── Model/        Page, Article, BlockDto, etc.
│   ├── ValueObject/  UuidV7, PostId, Email, Locale, PageStatus, etc.
│   └── Event/        DomainEventDispatcherInterface, PageCreated, PageDeleted
├── Application/      Use cases (Command/Query handlers, Input/Output DTOs).
│   └── UseCase/      CreatePage, GetPage, DeletePage, GetLatestPublishedPageId
├── Infrastructure/   Adapters — WordPress ($wpdb-equivalent via PDO), events, storage, cache.
│   ├── Wordpress/    WpdbConnection, WpPageRepository, BlockAstParser (Gutenberg AST)
│   ├── Security/     MerkleAuditLogger, FieldEncryptor, CsrfTokenManager, CspBridge
│   ├── Cache/        RedisTaggedCache, CacheInterface
│   └── Installer/    SmartBootstrapper (Headless core bootstrap & table provisioning)
└── Presentation/     CLI commands, Latte views, HTTP controllers.
    ├── Http/         PageController, DevToolbar, CsrfController, SecureFileController
    ├── View/         BlockRenderer (Latte 3.x AST block templates)
    └── Cli/          Command line console (bin/console)
```

The Domain/Application purity rule is enforced automatically by `tests/Architecture/DomainPurityTest.php`.

---

## ⚡ Deployment Options

### Option 1: Shared Hosting (Hostinger, cPanel, LiteSpeed, Apache)
1. Download or clone the pre-built distribution:
   ```bash
   git clone https://github.com/gm456789123-code/wp-framework_build.git public_html
   ```
2. Set your MySQL credentials in `.env` (copied from `.env.example`).
3. Open your domain in the browser and click **"⚡ Install WordPress & Sovereign Engine"**.
4. The root `index.php` and `.htaccess` guarantee zero 403 Forbidden errors.

### Option 2: Docker Development
```bash
docker compose up -d
docker compose exec app composer check
```
The site is then available at `http://localhost:8080`.

---

## 🛡️ Quality & Verification
* **PHPStan:** Level 9 — `0 Errors`
* **Psalm:** Level 1 — `0 Errors, 99.88% type coverage`
* **Pest:** `62 passed (173 assertions)`
* **Strict Types:** `declare(strict_types=1);` in every PHP file.

---

© 2026 Sovereign Engine. MIT License.
