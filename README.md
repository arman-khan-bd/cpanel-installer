# Nexus cPanel Installer (`nexus-lab/cpanel-installer`)

> **Zero-SSH, Browser-Based Web Installer & Fortress Provisioner for Nexus Applications on cPanel Shared Hosting.**

[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B-blue.svg)](https://php.net)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![Zero-SSH](https://img.shields.io/badge/Hosting-cPanel%20%7C%20public__html-emerald.svg)](#)
[![Security: Armed](https://img.shields.io/badge/Security-256--bit%20Libsodium-purple.svg)](#)

---

## Overview

Deploying PHP applications on shared cPanel hosting is frequently hindered by lack of SSH shell access, terminal execution restrictions, and manual configuration errors in `.env` files. 

**`nexus/cpanel-installer`** eliminates terminal dependencies entirely. When installed in any Nexus application, it automatically intercepts unconfigured traffic at the front-controller layer and presents a reactive, Shadcn-styled browser setup wizard. 

Once the customer enters their database credentials and admin details, the engine:
1. Tests database connectivity in real time.
2. Runs all pending SQL migrations in `database/migrations/*.sql` transactionally.
3. Hashes the administrator password with **Argon2id** (or **Bcrypt**).
4. Generates a cryptographically secure **256-bit Libsodium `APP_KEY`**.
5. Atomically writes the `.env` configuration file with strict `0600` permissions.
6. Locks itself permanently using a read-only `storage/app.installed` lockfile (`0444`).
7. Executes a clean JavaScript post-installation handshake and redirects to `/`.

---

## Installation & Setup

### Option 1: Via Composer (Development / CLI)

In your Nexus project root:

```bash
composer require nexus-lab/cpanel-installer
```

### Option 2: Pre-Packaged Production ZIP (Zero-SSH Client Deployment)

For clients on shared hosting without Composer or SSH access:
1. Package the application using `php nexus build:cpanel`.
2. Upload the generated `dist/nexus-cpanel-production.zip` to cPanel **File Manager** inside `public_html`.
3. Click **Extract**.
4. Open your browser and navigate to your domain: `https://example.com/`.
5. The setup wizard boots automatically!

---

## Setup Wizard Workflow

```
┌─────────────────┐     ┌──────────────────┐     ┌──────────────────┐     ┌─────────────────┐
│  Step 1: Check  │ ──> │ Step 2: Database │ ──> │  Step 3: Admin   │ ──> │ Step 4: Deploy  │
│  Prerequisites  │     │  Test Connection │     │  & App Profile   │     │  & Arm Lockfile │
└─────────────────┘     └──────────────────┘     └──────────────────┘     └─────────────────┘
```

### 1. System Health Check
- Verifies PHP runtime version ($\ge 8.2$).
- Validates essential extensions: `pdo`, `json`, `sodium` (or `openssl`), and `mbstring`.
- Confirms write permissions on `storage/` and the application root directory.
- Displays clear Pass/Fail badges with cPanel remediation guidance if any extension is missing.

### 2. Database Configuration
- Requests Host (default `127.0.0.1`), Port (`3306`), Database Name, Username, and Password.
- Features a real-time **"Test Connection"** button that probes MySQL grants without reloading the page.

### 3. Administrator Profile & Application Settings
- Configures Application Name (`APP_NAME`) and auto-detects public URL (`APP_URL`).
- Collects Administrator Name, Email, and Password with client-side match validation and minimum 8-character enforcement.

### 4. Automated Execution & Post-Installation Handshake
- Executes database schema migrations in `database/migrations/`.
- Inserts administrator account into `users` table using prepared statements.
- Deploys the lockfile `storage/app.installed` (`0444`).
- Triggers a 3-second animated countdown and automatically redirects the user to `/`.

---

## Front-Controller Integration

Add [`InstallerBridge::handle($baseDir)`](file:///c:/Users/Admin/Documents/nexus-lang/core/Router/InstallerBridge.php) to the very top of your `index.php` front-controller, immediately after your PSR-4 autoloader:

```php
<?php

declare(strict_types=1);

$baseDir = __DIR__;

// 1. PSR-4 Autoloading
require_once $baseDir . '/vendor/autoload.php';

// 2. Intercept uninstalled applications before Env/Kernel boot
\Core\Router\InstallerBridge::handle($baseDir);

// 3. Load environment & boot application kernel
\Core\Env::load($baseDir . '/.env');
$kernel = \Core\Kernel::boot($baseDir);
$kernel->handle();
```

---

## Anti-Reinstallation Guard & Security

- **Tamper-Evident Lockfile**: Once installed, the installer creates `storage/app.installed` and `.installed` with read-only permissions (`0444`).
- **403 Forbidden Shield**: Any subsequent request to the installer endpoint or setup routes is immediately blocked with an HTTP 429 / 403 Forbidden page.
- **CSRF Defense**: All installer form submissions are bound to an ephemeral, timing-safe CSRF token validated with `hash_equals()`.
- **Re-Installation Procedure**: In the event of a deliberate re-installation, the administrator must log into cPanel File Manager or SSH and explicitly remove the lockfile:
  ```bash
  rm -f storage/app.installed .installed
  ```

---

## Automated Migration Engine (`DatabaseMigrator`)

The library includes [`DatabaseMigrator`](file:///c:/Users/Admin/Documents/nexus-lang/packages/cpanel-installer/src/DatabaseMigrator.php) which scans `database/migrations/*.sql` in chronological order:

```php
use NextPHP\Installer\DatabaseMigrator;

$migrator = new DatabaseMigrator($pdo, $baseDir . '/database/migrations');
$result = $migrator->run();
// Returns: ['success' => true, 'executed' => ['2026_01_01_000001_create_users_table.sql', ...]]
```

Each migration is executed within a transaction boundary (`PDO::beginTransaction()` / `commit()`). If any query fails, the transaction is rolled back immediately to prevent half-migrated schemas.

---

## License

This package is open-sourced software licensed under the [MIT license](LICENSE).
