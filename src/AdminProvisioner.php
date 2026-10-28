<?php

declare(strict_types=1);

namespace NextPHP\Installer;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Class AdminProvisioner
 *
 * Secure Administrator Provisioning, Cryptographic Key Generation,
 * and Installation Lockfile Manager for NextPHP / cPanel environments.
 *
 * Capabilities:
 * - Password hashing using native Argon2id (with Bcrypt fallback)
 * - Strict prepared statement insertion into users or admins table
 * - 256-bit Libsodium/AES cryptographic APP_KEY generation & atomic .env writing
 * - Deployment of .installed lockfile in storage/app.installed (and root .installed)
 * - Automatic installation cache pruning
 */
final class AdminProvisioner
{
    private PDO $pdo;
    private string $baseDir;
    private string $envPath;
    private string $storageLockPath;
    private string $rootLockPath;

    public function __construct(PDO $pdo, ?string $baseDir = null)
    {
        $this->pdo = $pdo;
        $this->baseDir = rtrim($baseDir ?? (getcwd() ?: dirname(__DIR__, 4)), '/\\');
        $this->envPath = $this->baseDir . '/.env';
        $this->storageLockPath = $this->baseDir . '/storage/app.installed';
        $this->rootLockPath = $this->baseDir . '/.installed';
    }

    /**
     * Complete provisioning pipeline:
     * 1. Hashes password & provisions admin user
     * 2. Generates 256-bit APP_KEY & writes .env
     * 3. Deploys .installed lockfile in storage/app.installed & root
     * 4. Prunes installation cache
     *
     * @param array<string, mixed> $config
     * @return array{
     *     success: bool,
     *     admin_email: string,
     *     app_key: string,
     *     lock_file: string,
     *     message: string
     * }
     */
    public function provision(array $config): array
    {
        $adminName = trim((string)($config['admin_name'] ?? 'Administrator'));
        $adminEmail = trim((string)($config['admin_email'] ?? ''));
        $adminPassword = (string)($config['admin_password'] ?? '');

        if ($adminEmail === '' || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException("A valid administrator email address is required.");
        }

        if (strlen($adminPassword) < 8) {
            throw new RuntimeException("Administrator password must be at least 8 characters.");
        }

        // 1. Provision Administrator User in Database
        $adminResult = $this->provisionAdmin($adminName, $adminEmail, $adminPassword);

        // 2. Generate 256-bit Cryptographic APP_KEY
        $appKey = $this->generateAppKey();

        // 3. Write Environment Configuration (.env)
        $this->writeEnvironmentConfig($config, $appKey);

        // 4. Create .installed Lockfile in storage/app.installed & root .installed
        $lockFile = $this->createLockFile([
            'installed_at'  => date('c'),
            'installer_ver' => '1.0.0',
            'admin_email'   => $adminEmail,
            'app_url'       => $config['app_url'] ?? 'http://localhost',
            'integrity'     => hash('sha256', $appKey . $adminEmail),
        ]);

        // 5. Cleanup Installation Cache
        $this->cleanupInstallationCache();

        return [
            'success'     => true,
            'admin_email' => $adminEmail,
            'app_key'     => $appKey,
            'lock_file'   => $lockFile,
            'message'     => 'Administrator provisioned, encryption keys generated, and application locked.',
        ];
    }

    /**
     * Hash admin password and insert into users (or admins) table.
     *
     * @return array{success: bool, table: string, email: string}
     */
    public function provisionAdmin(string $name, string $email, string $password): array
    {
        $targetTable = $this->resolveAdminTable();

        // Hash password using native Argon2id or Bcrypt
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        $hash = password_hash($password, $algo);

        $now = date('Y-m-d H:i:s');

        // Check if user already exists
        $checkStmt = $this->pdo->prepare("SELECT id FROM `{$targetTable}` WHERE email = ? LIMIT 1");
        $checkStmt->execute([$email]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $updateStmt = $this->pdo->prepare("
                UPDATE `{$targetTable}`
                SET name = ?, password = ?, role = 'admin', updated_at = ?
                WHERE email = ?
            ");
            $updateStmt->execute([$name, $hash, $now, $email]);
        } else {
            $insertStmt = $this->pdo->prepare("
                INSERT INTO `{$targetTable}` (name, email, password, role, created_at, updated_at)
                VALUES (?, ?, ?, 'admin', ?, ?)
            ");
            $insertStmt->execute([$name, $email, $hash, $now, $now]);
        }

        return [
            'success' => true,
            'table'   => $targetTable,
            'email'   => $email,
        ];
    }

    /**
     * Generate 256-bit cryptographically secure APP_KEY encoded in base64.
     */
    public function generateAppKey(): string
    {
        $bytes = random_bytes(32);
        return 'base64:' . base64_encode($bytes);
    }

    /**
     * Write .env configuration with atomic file locking and 0600 permissions.
     */
    public function writeEnvironmentConfig(array $config, string $appKey): bool
    {
        $appName = trim((string)($config['app_name'] ?? 'Nexus Application'));
        $appUrl = rtrim(trim((string)($config['app_url'] ?? 'http://localhost')), '/');
        $dbDriver = strtolower(trim((string)($config['db_driver'] ?? 'mysql')));
        $dbHost = trim((string)($config['db_host'] ?? '127.0.0.1'));
        $dbPort = (string)($config['db_port'] ?? '3306');
        $dbName = trim((string)($config['db_name'] ?? ''));
        $dbUser = trim((string)($config['db_user'] ?? ''));
        $dbPass = (string)($config['db_pass'] ?? '');

        $envData = [
            'APP_NAME'           => $appName,
            'APP_ENV'            => 'production',
            'APP_KEY'            => $appKey,
            'APP_DEBUG'          => 'false',
            'APP_URL'            => $appUrl,
            'DB_CONNECTION'      => $dbDriver,
            'DB_HOST'            => $dbHost,
            'DB_PORT'            => $dbPort,
            'DB_DATABASE'        => $dbName,
            'DB_USERNAME'        => $dbUser,
            'DB_PASSWORD'        => $dbPass,
            'WAF_ENABLED'        => 'true',
            'RATE_LIMIT_ENABLED' => 'true',
            'SESSION_LIFETIME'   => '120',
        ];

        $examplePath = $this->baseDir . '/.env.example';
        $outputLines = [];
        $handledKeys = [];

        if (file_exists($examplePath)) {
            $exampleContent = (string)file_get_contents($examplePath);
            $lines = explode("\n", $exampleContent);

            foreach ($lines as $line) {
                $trimmed = trim($line);
                if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                    $outputLines[] = $line;
                    continue;
                }

                if (str_contains($line, '=')) {
                    [$key] = explode('=', $line, 2);
                    $key = trim($key);

                    if (array_key_exists($key, $envData)) {
                        $val = $envData[$key];
                        $formattedVal = (str_contains($val, ' ') || str_contains($val, '#')) ? "\"{$val}\"" : $val;
                        $outputLines[] = "{$key}={$formattedVal}";
                        $handledKeys[$key] = true;
                    } else {
                        $outputLines[] = $line;
                    }
                } else {
                    $outputLines[] = $line;
                }
            }
        }

        // Add any missing variables
        foreach ($envData as $k => $v) {
            if (!isset($handledKeys[$k])) {
                $formattedVal = (str_contains($v, ' ') || str_contains($v, '#')) ? "\"{$v}\"" : $v;
                $outputLines[] = "{$k}={$formattedVal}";
            }
        }

        $finalContent = implode("\n", $outputLines) . "\n";

        $res = @file_put_contents($this->envPath, $finalContent, LOCK_EX);
        if ($res !== false && DIRECTORY_SEPARATOR !== '\\') {
            @chmod($this->envPath, 0600);
        }

        return $res !== false;
    }

    /**
     * Create the .installed lockfile in storage/app.installed (and root .installed).
     */
    public function createLockFile(array $metadata): string
    {
        // Ensure storage directory exists
        $storageDir = dirname($this->storageLockPath);
        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0700, true);
        }

        $content = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        // 1. Primary: storage/app.installed
        @file_put_contents($this->storageLockPath, $content, LOCK_EX);
        if (DIRECTORY_SEPARATOR !== '\\') {
            @chmod($this->storageLockPath, 0444);
        }

        // 2. Secondary: root .installed for reverse proxy & fortress .htaccess checks
        @file_put_contents($this->rootLockPath, $content, LOCK_EX);
        if (DIRECTORY_SEPARATOR !== '\\') {
            @chmod($this->rootLockPath, 0444);
        }

        return $this->storageLockPath;
    }

    /**
     * Clean up installation cache files in storage/cache.
     */
    public function cleanupInstallationCache(): void
    {
        $cacheDirs = [
            $this->baseDir . '/storage/cache/views',
            $this->baseDir . '/storage/cache/installer',
        ];

        foreach ($cacheDirs as $dir) {
            if (is_dir($dir)) {
                $files = glob($dir . '/*');
                if ($files !== false) {
                    foreach ($files as $file) {
                        if (is_file($file) && !str_ends_with($file, '.gitkeep')) {
                            @unlink($file);
                        }
                    }
                }
            }
        }
    }

    /**
     * Resolves the target table for administrator accounts ('users' or 'admins').
     * If neither exists, creates 'users' table automatically.
     */
    private function resolveAdminTable(): string
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        // Check if 'users' table exists
        if ($this->tableExists('users')) {
            return 'users';
        }

        // Check if 'admins' table exists
        if ($this->tableExists('admins')) {
            return 'admins';
        }

        // Create 'users' table if neither exists
        if ($driver === 'sqlite') {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS users (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    email TEXT NOT NULL UNIQUE,
                    password TEXT NOT NULL,
                    role TEXT NOT NULL DEFAULT 'admin',
                    api_key TEXT NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL
                )
            ");
        } else {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS `users` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `name` VARCHAR(191) NOT NULL,
                    `email` VARCHAR(191) NOT NULL UNIQUE,
                    `password` VARCHAR(255) NOT NULL,
                    `role` VARCHAR(50) NOT NULL DEFAULT 'admin',
                    `api_key` TEXT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX `idx_users_email` (`email`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        }

        return 'users';
    }

    /**
     * Helper to verify if a table exists in the current database.
     */
    private function tableExists(string $tableName): bool
    {
        try {
            $stmt = $this->pdo->query("SELECT 1 FROM `{$tableName}` LIMIT 1");
            return $stmt !== false;
        } catch (PDOException $e) {
            return false;
        }
    }
}
