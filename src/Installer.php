<?php

declare(strict_types=1);

namespace NextPHP\Installer;

use PDO;
use PDOException;
use Throwable;

/**
 * Class Installer
 *
 * Standalone, zero-SSH cPanel Installation Engine for NextPHP/Nexus applications.
 *
 * Security Architecture:
 * - Anti-Reinstallation Guard: Strict 403 Forbidden lockfile (.installed) enforcement
 * - Session-bound CSRF token verification on all mutating requests
 * - Pre-flight database credential probing with timeout safety
 * - 256-bit Libsodium/AES cryptographic APP_KEY provisioning
 * - Hardened file permissions (0600 for .env, 0444 for .installed)
 * - Prepared statement schema migration and Argon2id/Bcrypt password hashing
 */
final class Installer
{
    private string $baseDir;
    private string $lockFile;
    private string $envFile;

    public function __construct(?string $baseDir = null)
    {
        $this->baseDir = rtrim($baseDir ?? (getcwd() ?: dirname(__DIR__, 2)), '/\\');
        $this->lockFile = $this->baseDir . '/.installed';
        $this->envFile = $this->baseDir . '/.env';
    }

    /**
     * Web front-controller entry point for the installation wizard.
     */
    public static function run(?string $baseDir = null): void
    {
        $installer = new self($baseDir);
        $installer->dispatch();
    }

    /**
     * Dispatch incoming HTTP request.
     */
    public function dispatch(): void
    {
        // 1. Anti-Reinstallation Guard
        if ($this->isInstalled()) {
            $this->renderForbidden();
            return;
        }

        // Start session for CSRF token handling if not already active
        if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
            @session_start();
        }

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        // 2. Handle POST Actions (AJAX or Form Submissions)
        if ($method === 'POST') {
            $this->handlePostRequest();
            return;
        }

        // 3. Render Setup Wizard View
        $this->renderWizard();
    }

    /**
     * Check if application has already been installed.
     * Prevents re-installation and configuration overwrite attacks.
     */
    public function isInstalled(): bool
    {
        // 1. Check storage/app.installed lockfile
        if (file_exists($this->baseDir . '/storage/app.installed')) {
            return true;
        }

        // 2. Check root .installed lockfile
        if (file_exists($this->lockFile)) {
            return true;
        }

        // 3. Secondary defense: if .env exists and has an active APP_KEY and DB_PASSWORD
        if (file_exists($this->envFile)) {
            $content = (string)file_get_contents($this->envFile);
            if (preg_match('/^APP_KEY=base64:[A-Za-z0-9+\/]{43}=/m', $content) &&
                preg_match('/^DB_DATABASE=.+$/m', $content)) {
                // Ensure lockfiles are present if .env is already configured
                @file_put_contents($this->lockFile, json_encode([
                    'installed_at' => date('c'),
                    'source'       => 'auto-lock-guard',
                ]));
                @chmod($this->lockFile, 0444);

                $storageLock = $this->baseDir . '/storage/app.installed';
                @file_put_contents($storageLock, json_encode([
                    'installed_at' => date('c'),
                    'source'       => 'auto-lock-guard',
                ]));
                @chmod($storageLock, 0444);

                return true;
            }
        }

        return false;
    }

    /**
     * Comprehensive system prerequisites and environment verification.
     *
     * @return array{
     *     can_install: bool,
     *     php: array{required: string, current: string, pass: bool},
     *     extensions: array<string, array{name: string, required: bool, pass: bool, purpose: string}>,
     *     permissions: array<string, array{path: string, writable: bool, exists: bool}>,
     *     errors: array<string>,
     *     warnings: array<string>
     * }
     */
    public function checkPrerequisites(): array
    {
        $errors = [];
        $warnings = [];

        // 1. PHP Version Check (>= 8.2 required)
        $requiredPhp = '8.2.0';
        $currentPhp = PHP_VERSION;
        $phpPass = version_compare($currentPhp, $requiredPhp, '>=');
        if (!$phpPass) {
            $errors[] = "PHP Version {$currentPhp} is insufficient. PHP {$requiredPhp}+ is required.";
        }

        // 2. Extensions Checklist
        $checkedExtensions = [
            'pdo' => [
                'name'     => 'PDO',
                'required' => true,
                'pass'     => extension_loaded('pdo'),
                'purpose'  => 'Database connection & SQL injection defense'
            ],
            'json' => [
                'name'     => 'JSON',
                'required' => true,
                'pass'     => extension_loaded('json'),
                'purpose'  => 'API and SPA DOM morph data exchange'
            ],
            'sodium' => [
                'name'     => 'Sodium / OpenSSL',
                'required' => true,
                'pass'     => extension_loaded('sodium') || extension_loaded('openssl'),
                'purpose'  => 'Libsodium / AES-256-GCM authenticated encryption'
            ],
            'mbstring' => [
                'name'     => 'Mbstring',
                'required' => true,
                'pass'     => extension_loaded('mbstring'),
                'purpose'  => 'Multibyte string & UTF-8 input sanitization'
            ],
            'pdo_mysql' => [
                'name'     => 'PDO MySQL',
                'required' => false,
                'pass'     => extension_loaded('pdo_mysql'),
                'purpose'  => 'cPanel MySQL / MariaDB database connectivity'
            ],
            'fileinfo' => [
                'name'     => 'Fileinfo',
                'required' => false,
                'pass'     => extension_loaded('fileinfo'),
                'purpose'  => 'MIME-type inspection for file uploads'
            ],
            'gd' => [
                'name'     => 'GD',
                'required' => false,
                'pass'     => extension_loaded('gd'),
                'purpose'  => 'Image re-encoding and EXIF script stripping'
            ],
        ];

        foreach ($checkedExtensions as $key => $ext) {
            if ($ext['required'] && !$ext['pass']) {
                $errors[] = "Required PHP extension [{$ext['name']}] is missing. Purpose: {$ext['purpose']}.";
            } elseif (!$ext['required'] && !$ext['pass']) {
                $warnings[] = "Recommended extension [{$ext['name']}] is not loaded. ({$ext['purpose']})";
            }
        }

        // 3. Write Permissions Checklist
        $storageDirs = [
            $this->baseDir . '/storage',
            $this->baseDir . '/storage/cache',
            $this->baseDir . '/storage/cache/views',
            $this->baseDir . '/storage/sessions',
            $this->baseDir . '/storage/ratelimit',
            $this->baseDir . '/storage/logs',
            $this->baseDir . '/storage/uploads',
        ];

        // Ensure directories exist or attempt creation
        foreach ($storageDirs as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0700, true);
            }
        }

        $checkedPermissions = [
            'root' => [
                'path'     => $this->baseDir,
                'exists'   => is_dir($this->baseDir),
                'writable' => is_writable($this->baseDir),
            ],
            'storage' => [
                'path'     => $this->baseDir . '/storage',
                'exists'   => is_dir($this->baseDir . '/storage'),
                'writable' => is_writable($this->baseDir . '/storage'),
            ],
            'env' => [
                'path'     => $this->envFile,
                'exists'   => file_exists($this->envFile),
                'writable' => file_exists($this->envFile) ? is_writable($this->envFile) : is_writable($this->baseDir),
            ],
        ];

        if (!$checkedPermissions['root']['writable'] && !is_writable($this->envFile)) {
            $errors[] = "Root project directory is not writable. Installer cannot create or update .env.";
        }

        if (!$checkedPermissions['storage']['writable']) {
            $errors[] = "storage/ directory is not writable. Installer cannot provision session or view caches.";
        }

        $canInstall = empty($errors);

        return [
            'can_install' => $canInstall,
            'php'         => [
                'required' => $requiredPhp,
                'current'  => $currentPhp,
                'pass'     => $phpPass,
            ],
            'extensions'  => $checkedExtensions,
            'permissions' => $checkedPermissions,
            'errors'      => $errors,
            'warnings'    => $warnings,
        ];
    }

    /**
     * Test connection to MySQL / SQLite database with supplied credentials.
     *
     * @param array<string, mixed> $credentials
     * @return array{success: bool, message: string}
     */
    public function testDatabase(array $credentials): array
    {
        $driver = strtolower((string)($credentials['db_driver'] ?? 'mysql'));
        $host = (string)($credentials['db_host'] ?? '127.0.0.1');
        $port = (int)($credentials['db_port'] ?? 3306);
        $dbname = (string)($credentials['db_name'] ?? '');
        $user = (string)($credentials['db_user'] ?? '');
        $pass = (string)($credentials['db_pass'] ?? '');

        if ($driver === 'sqlite') {
            try {
                $dbPath = $dbname !== '' ? $dbname : ($this->baseDir . '/storage/database.sqlite');
                $pdo = new PDO("sqlite:{$dbPath}", null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 3,
                ]);
                return ['success' => true, 'message' => 'SQLite connection verified successfully!'];
            } catch (PDOException $e) {
                return ['success' => false, 'message' => 'SQLite error: ' . $e->getMessage()];
            }
        }

        if ($dbname === '') {
            return ['success' => false, 'message' => 'Database name cannot be blank.'];
        }

        try {
            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT            => 5,
            ]);

            // Run light query to verify grants
            $stmt = $pdo->query('SELECT 1');
            $stmt->fetch();

            return ['success' => true, 'message' => 'Database connection established and verified!'];
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'Access denied')) {
                return ['success' => false, 'message' => 'Access denied: Please verify your database username and password.'];
            }
            if (str_contains($msg, 'Unknown database')) {
                return ['success' => false, 'message' => "Database [{$dbname}] does not exist. Please create it in cPanel MySQL Databases first."];
            }
            if (str_contains($msg, 'Connection refused')) {
                return ['success' => false, 'message' => "Connection refused on {$host}:{$port}. Please check your host and port."];
            }
            return ['success' => false, 'message' => 'Database Error: ' . $msg];
        }
    }

    /**
     * Execute full installation pipeline.
     *
     * @param array<string, mixed> $data
     * @return array{success: bool, message: string, redirect?: string, error?: string}
     */
    public function install(array $data): array
    {
        // 1. Verify CSRF
        $token = $data['_token'] ?? '';
        if (!$this->verifyCsrfToken(is_string($token) ? $token : null)) {
            return ['success' => false, 'error' => 'CSRF Token Mismatch. Please refresh the page and try again.'];
        }

        // 2. Prerequisites Guard
        $prereqs = $this->checkPrerequisites();
        if (!$prereqs['can_install']) {
            return ['success' => false, 'error' => 'Prerequisites check failed: ' . implode(' ', $prereqs['errors'])];
        }

        // 3. Validate Inputs
        $appName = trim((string)($data['app_name'] ?? 'Nexus Application'));
        $appUrl = rtrim(trim((string)($data['app_url'] ?? $this->detectAppUrl())), '/');
        $dbDriver = strtolower(trim((string)($data['db_driver'] ?? 'mysql')));
        $dbHost = trim((string)($data['db_host'] ?? '127.0.0.1'));
        $dbPort = (int)($data['db_port'] ?? 3306);
        $dbName = trim((string)($data['db_name'] ?? ''));
        $dbUser = trim((string)($data['db_user'] ?? ''));
        $dbPass = (string)($data['db_pass'] ?? '');

        $adminName = trim((string)($data['admin_name'] ?? 'Administrator'));
        $adminEmail = trim((string)($data['admin_email'] ?? ''));
        $adminPassword = (string)($data['admin_password'] ?? '');

        if ($appName === '') {
            return ['success' => false, 'error' => 'Application name cannot be empty.'];
        }

        if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Please provide a valid administrator email address.'];
        }

        if (strlen($adminPassword) < 8) {
            return ['success' => false, 'error' => 'Administrator password must be at least 8 characters.'];
        }

        // 4. Test DB Connection
        $dbTest = $this->testDatabase([
            'db_driver' => $dbDriver,
            'db_host'   => $dbHost,
            'db_port'   => $dbPort,
            'db_name'   => $dbName,
            'db_user'   => $dbUser,
            'db_pass'   => $dbPass,
        ]);

        if (!$dbTest['success']) {
            return ['success' => false, 'error' => $dbTest['message']];
        }

        // 5. Connect to PDO instance for migrations & provisioning
        try {
            if ($dbDriver === 'sqlite') {
                $pdo = new PDO("sqlite:{$dbName}", null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);
            } else {
                $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
                $pdo = new PDO($dsn, $dbUser, $dbPass, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
            }
        } catch (Throwable $e) {
            return ['success' => false, 'error' => 'PDO Connection failed: ' . $e->getMessage()];
        }

        // 6. Execute Transactional Database Migrations (database/migrations/*.sql)
        $migrator = new DatabaseMigrator($pdo, $this->baseDir . '/database/migrations');
        $migrationResult = $migrator->run();
        if (!$migrationResult['success']) {
            return ['success' => false, 'error' => $migrationResult['message']];
        }

        // 7. Provision Administrator, Generate 256-Bit APP_KEY & Deploy .installed Lockfile
        try {
            $provisioner = new AdminProvisioner($pdo, $this->baseDir);
            $provisionResult = $provisioner->provision($data);
        } catch (Throwable $e) {
            return ['success' => false, 'error' => 'Provisioning failed: ' . $e->getMessage()];
        }

        return [
            'success'  => true,
            'message'  => 'Installation successfully completed! Redirecting to application...',
            'redirect' => $appUrl !== '' ? $appUrl : '/',
        ];
    }

    /**
     * Provision users schema and seed the primary administrator.
     */
    private function provisionDatabaseSchema(
        string $driver,
        string $host,
        int $port,
        string $dbname,
        string $user,
        string $pass,
        string $adminName,
        string $adminEmail,
        string $adminPassword
    ): array {
        try {
            if ($driver === 'sqlite') {
                $dsn = "sqlite:{$dbname}";
                $pdo = new PDO($dsn, null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);

                // Create users table for SQLite
                $pdo->exec("
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
                $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
                $pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);

                // Create users table for MySQL / MariaDB
                $pdo->exec("
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

            // Hash admin password using Argon2id if supported, otherwise Bcrypt
            $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
            $passwordHash = password_hash($adminPassword, $algo);

            // Check if admin email already exists
            $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $checkStmt->execute([$adminEmail]);
            $existing = $checkStmt->fetch();

            $now = date('Y-m-d H:i:s');

            if ($existing) {
                // Update password and name
                $upStmt = $pdo->prepare("UPDATE users SET name = ?, password = ?, role = 'admin', updated_at = ? WHERE email = ?");
                $upStmt->execute([$adminName, $passwordHash, $now, $adminEmail]);
            } else {
                // Insert new admin user
                $insStmt = $pdo->prepare("
                    INSERT INTO users (name, email, password, role, created_at, updated_at)
                    VALUES (?, ?, ?, 'admin', ?, ?)
                ");
                $insStmt->execute([$adminName, $adminEmail, $passwordHash, $now, $now]);
            }

            return ['success' => true, 'message' => 'Schema migrated and admin provisioned.'];
        } catch (Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Write .env configuration with atomic lock and 0600 permissions.
     */
    public function writeEnvFile(array $envData): bool
    {
        $existing = [];
        $examplePath = $this->baseDir . '/.env.example';

        if (file_exists($examplePath)) {
            $exampleContent = (string)file_get_contents($examplePath);
            $lines = explode("\n", $exampleContent);
            $output = [];

            foreach ($lines as $line) {
                $trimmed = trim($line);
                if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                    $output[] = $line;
                    continue;
                }

                if (str_contains($line, '=')) {
                    [$key] = explode('=', $line, 2);
                    $key = trim($key);
                    if (array_key_exists($key, $envData)) {
                        $val = $envData[$key];
                        // Wrap in quotes if spaces present
                        $formattedVal = (str_contains($val, ' ') || str_contains($val, '#')) ? "\"{$val}\"" : $val;
                        $output[] = "{$key}={$formattedVal}";
                        $existing[$key] = true;
                    } else {
                        $output[] = $line;
                    }
                } else {
                    $output[] = $line;
                }
            }

            // Append any additional keys not present in .env.example
            foreach ($envData as $k => $v) {
                if (!isset($existing[$k])) {
                    $formattedVal = (str_contains($v, ' ') || str_contains($v, '#')) ? "\"{$v}\"" : $v;
                    $output[] = "{$k}={$formattedVal}";
                }
            }

            $content = implode("\n", $output) . "\n";
        } else {
            $lines = [];
            foreach ($envData as $k => $v) {
                $formattedVal = (str_contains($v, ' ') || str_contains($v, '#')) ? "\"{$v}\"" : $v;
                $lines[] = "{$k}={$formattedVal}";
            }
            $content = implode("\n", $lines) . "\n";
        }

        $res = @file_put_contents($this->envFile, $content, LOCK_EX);
        if ($res !== false && DIRECTORY_SEPARATOR !== '\\') {
            @chmod($this->envFile, 0600);
        }

        return $res !== false;
    }

    /**
     * Create .installed lockfile with 0444 read-only permissions.
     */
    public function writeLockfile(array $data): bool
    {
        $content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $res = @file_put_contents($this->lockFile, $content, LOCK_EX);
        if ($res !== false && DIRECTORY_SEPARATOR !== '\\') {
            @chmod($this->lockFile, 0444);
        }
        return $res !== false;
    }

    /**
     * Handle incoming POST requests from the installation wizard.
     */
    private function handlePostRequest(): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
        }

        $input = $this->parsePostInput();
        $action = $input['action'] ?? $input['_action'] ?? '';

        if ($action === 'test_db') {
            $token = $input['_token'] ?? '';
            if (!$this->verifyCsrfToken(is_string($token) ? $token : null)) {
                if (!headers_sent()) {
                    http_response_code(403);
                }
                echo json_encode(['success' => false, 'message' => 'CSRF Token Mismatch. Please refresh page.']);
                return;
            }

            $result = $this->testDatabase($input);
            if (!headers_sent()) {
                http_response_code($result['success'] ? 200 : 400);
            }
            echo json_encode($result);
            return;
        }

        if ($action === 'install') {
            $result = $this->install($input);
            if (!headers_sent()) {
                http_response_code($result['success'] ? 200 : 400);
            }
            echo json_encode($result);
            return;
        }

        if (!headers_sent()) {
            http_response_code(400);
        }
        echo json_encode(['success' => false, 'message' => "Unknown action '{$action}'."]);
    }

    /**
     * Safely parse POST inputs from JSON body or standard form-data.
     */
    private function parsePostInput(): array
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $raw = (string)file_get_contents('php://input');
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return $_POST;
    }

    /**
     * Render the Shadcn UI Setup Wizard view.
     */
    public function renderWizard(): void
    {
        $prerequisites = $this->checkPrerequisites();
        $csrfToken = $this->getCsrfToken();
        $detectedUrl = $this->detectAppUrl();

        $viewPath = __DIR__ . '/views/wizard.php';
        if (file_exists($viewPath)) {
            require $viewPath;
            return;
        }

        // Fallback minimal HTML
        echo "<h1>NextPHP Installer Wizard view missing at [{$viewPath}].</h1>";
    }

    /**
     * Render hardened 403 Forbidden page when installation is locked.
     */
    private function renderForbidden(): void
    {
        if (!headers_sent()) {
            http_response_code(403);
            header('Content-Type: text/html; charset=UTF-8');
        }

        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>403 - Application Already Installed | NextPHP</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background-color: #030712;
            color: #f3f4f6;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 1.5rem;
        }
        .card {
            background: #111827;
            border: 1px solid #1f2937;
            border-radius: 16px;
            max-width: 520px;
            width: 100%;
            padding: 2.75rem 2rem;
            text-align: center;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.7);
        }
        .badge {
            display: inline-block;
            background: rgba(239, 68, 68, 0.15);
            color: #f87171;
            border: 1px solid rgba(239, 68, 68, 0.3);
            padding: 0.25rem 0.85rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 1.5rem;
        }
        h1 { font-size: 1.5rem; font-weight: 700; color: #ffffff; margin-bottom: 0.75rem; }
        p { color: #9ca3af; font-size: 0.95rem; line-height: 1.6; margin-bottom: 1.75rem; }
        .code-box {
            background: #030712;
            border: 1px solid #1e293b;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            font-family: ui-monospace, monospace;
            font-size: 0.85rem;
            color: #38bdf8;
            margin-bottom: 1.75rem;
            text-align: left;
            word-break: break-all;
        }
        .btn {
            display: inline-block;
            background: #3b82f6;
            color: #ffffff;
            font-weight: 600;
            font-size: 0.875rem;
            padding: 0.75rem 1.75rem;
            border-radius: 8px;
            text-decoration: none;
            transition: background-color 0.2s;
        }
        .btn:hover { background: #2563eb; }
    </style>
</head>
<body>
    <div class="card">
        <div class="badge">Security Lock Active</div>
        <h1>Application Already Installed</h1>
        <p>The setup wizard is permanently locked to prevent unauthorized database overwrite attacks. To re-run the installer, remove the lockfile via cPanel File Manager or SSH:</p>
        <div class="code-box">rm -f .installed</div>
        <a href="/" class="btn">Launch Application &rarr;</a>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Get or initialize session-bound CSRF token.
     */
    public function getCsrfToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
            @session_start();
        }

        if (empty($_SESSION['_installer_csrf_token'])) {
            $_SESSION['_installer_csrf_token'] = bin2hex(random_bytes(32));
        }

        return (string)$_SESSION['_installer_csrf_token'];
    }

    /**
     * Verify submitted CSRF token using constant-time comparison.
     */
    public function verifyCsrfToken(?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        $sessionToken = $this->getCsrfToken();
        return hash_equals($sessionToken, $token);
    }

    /**
     * Detect current application URL from server variables.
     */
    private function detectAppUrl(): string
    {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
                   (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
                   (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

        $protocol = $isHttps ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $script = $_SERVER['SCRIPT_NAME'] ?? '/';
        $dir = str_replace('\\', '/', dirname($script));
        $base = ($dir === '/' || $dir === '.') ? '' : $dir;

        return $protocol . $host . $base;
    }
}
