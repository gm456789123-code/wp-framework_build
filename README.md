# Sovereign Engine (Pre-Built Distribution) 🚀

> **High-Performance Headless Framework for WordPress**  
> Built with Domain-Driven Design (DDD), Strict Types 100%, and Latte 3.x Engine.

This repository contains the **standalone, pre-compiled distribution** of Sovereign Engine.  
All composer dependencies (`vendor/`) and public entrypoints are included out-of-the-box. **Zero configuration or CLI needed!**

---

## ⚡ Quick Start on Shared Hosting (Hostinger, cPanel, LiteSpeed, Apache)

1. **Download or Clone:**
   * Download this repository as a `.zip` file, or clone it directly:
   ```bash
   git clone https://github.com/gm456789123-code/wp-framework_build.git
   ```
2. **Upload to Host:**
   * Upload all files into your domain's root folder (e.g. `public_html/`).
3. **Configure Database:**
   * Rename `.env.example` to `.env` and fill in your MySQL credentials:
   ```ini
   DB_HOST=localhost
   DB_PORT=3306
   DB_DATABASE=your_database_name
   DB_USERNAME=your_database_user
   DB_PASSWORD=your_database_password
   APP_URL=https://your-domain.com
   ```
4. **1-Click Installation:**
   * Open `https://your-domain.com` in your browser.
   * Click **"⚡ Install WordPress & Sovereign Engine"**.
   * You're done! Sovereign Engine will provision the database, activate the theme, and give you your admin credentials.

---

## 🛠️ CLI Installation (Optional for Developers)

If you have SSH terminal access:
```bash
php bin/console wp:install
```

---

## 💎 Key Features
* **Zero Boilerplate:** Includes pre-packaged `vendor/` autoloader.
* **403-Forbidden Immunity:** Equipped with root `index.php` and `.htaccess` for LiteSpeed & Apache.
* **Latte 3.x AST Block Renderer:** High-speed Gutenberg content rendering without WordPress frontend CSS bloat.
* **Enterprise Security:** Merkle Tree Audit Logger, AES-256 Field Encryption, and ABAC Policy.
* **Strict Type Safety:** Fully typed PHP 8.3/8.4/8.5 with `declare(strict_types=1);`.

---

© 2026 Sovereign Engine. Released under the MIT License.
