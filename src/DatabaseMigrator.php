<?php

declare(strict_types=1);

namespace NextPHP\Installer;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Class DatabaseMigrator
 *
 * Transactional SQL Database Migration Engine for NextPHP & cPanel environments.
 *
 * Capabilities:
 * - Scans database/migrations/ for *.sql files in chronological order (natsort)
 * - Auto-creates the 'migrations' tracking table if not present
 * - Executes migrations with atomic transaction boundaries (PDO::beginTransaction / commit)
 * - Intelligent SQL statement splitter supporting multi-query files and comment stripping
 * - Robust rollback on error to prevent half-migrated states on cPanel MySQL servers
 */
final class DatabaseMigrator
{
    private PDO $pdo;
    private string $migrationsPath;

    public function __construct(PDO $pdo, ?string $migrationsPath = null)
    {
        $this->pdo = $pdo;
        $this->migrationsPath = $migrationsPath ?? (dirname(__DIR__, 4) . '/database/migrations');
    }

    /**
     * Run all pending SQL migrations in chronological order.
     *
     * @return array{
     *     success: bool,
     *     executed: array<string>,
     *     message: string,
     *     errors: array<string>
     * }
     */
    public function run(): array
    {
        // 1. Ensure migrations tracking table exists
        $this->ensureMigrationsTable();

        // 2. Resolve pending migrations
        $pending = $this->getPendingMigrations();

        if (empty($pending)) {
            return [
                'success'  => true,
                'executed' => [],
                'message'  => 'No pending migrations found. Database is up to date.',
                'errors'   => [],
            ];
        }

        $executed = [];
        $errors = [];
        $batch = $this->getNextBatchNumber();

        // 3. Process each migration in chronological order
        foreach ($pending as $migrationFile) {
            $filename = basename($migrationFile);

            try {
                $this->executeMigrationFile($migrationFile, $filename, $batch);
                $executed[] = $filename;
            } catch (Throwable $e) {
                $errorMsg = "Migration failed in [{$filename}]: " . $e->getMessage();
                $errors[] = $errorMsg;

                return [
                    'success'  => false,
                    'executed' => $executed,
                    'message'  => $errorMsg,
                    'errors'   => $errors,
                ];
            }
        }

        return [
            'success'  => true,
            'executed' => $executed,
            'message'  => 'Successfully executed ' . count($executed) . ' database migration(s).',
            'errors'   => [],
        ];
    }

    /**
     * Create the 'migrations' table if it does not already exist.
     */
    public function ensureMigrationsTable(): void
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $sql = "
                CREATE TABLE IF NOT EXISTS migrations (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    migration TEXT NOT NULL UNIQUE,
                    batch INTEGER NOT NULL,
                    executed_at DATETIME NOT NULL
                )
            ";
        } else {
            $sql = "
                CREATE TABLE IF NOT EXISTS `migrations` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `migration` VARCHAR(255) NOT NULL UNIQUE,
                    `batch` INT UNSIGNED NOT NULL,
                    `executed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_migrations_batch` (`batch`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ";
        }

        $this->pdo->exec($sql);
    }

    /**
     * Get list of migration filenames that have already been executed.
     *
     * @return array<string>
     */
    public function getRanMigrations(): array
    {
        $this->ensureMigrationsTable();

        try {
            $stmt = $this->pdo->query('SELECT migration FROM migrations ORDER BY id ASC');
            $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
            return is_array($rows) ? $rows : [];
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Discover all *.sql migration files in the migrations directory
     * and filter out those that have already run.
     *
     * @return array<string>
     */
    public function getPendingMigrations(): array
    {
        if (!is_dir($this->migrationsPath)) {
            return [];
        }

        $allFiles = glob($this->migrationsPath . '/*.sql');
        if ($allFiles === false || empty($allFiles)) {
            return [];
        }

        // Natural sort by filename to guarantee strict chronological order
        natsort($allFiles);

        $ran = $this->getRanMigrations();
        $pending = [];

        foreach ($allFiles as $filePath) {
            $filename = basename($filePath);
            if (!in_array($filename, $ran, true)) {
                $pending[] = $filePath;
            }
        }

        return $pending;
    }

    /**
     * Execute a single SQL migration file inside an atomic transaction.
     */
    private function executeMigrationFile(string $filePath, string $filename, int $batch): void
    {
        $sqlContent = (string)file_get_contents($filePath);
        $statements = $this->splitSqlStatements($sqlContent);

        if (empty($statements)) {
            // Empty migration file - record as run and return
            $this->recordMigration($filename, $batch);
            return;
        }

        $inTx = false;

        try {
            // Begin atomic transaction if not already in one
            if (!$this->pdo->inTransaction()) {
                $this->pdo->beginTransaction();
                $inTx = true;
            }

            // Execute each discrete statement
            foreach ($statements as $statement) {
                $statement = trim($statement);
                if ($statement !== '') {
                    $this->pdo->exec($statement);
                }
            }

            // Record successful migration in tracking table
            $this->recordMigration($filename, $batch);

            // Commit atomic transaction
            if ($inTx && $this->pdo->inTransaction()) {
                $this->pdo->commit();
                $inTx = false;
            }
        } catch (Throwable $e) {
            // Roll back on failure to avoid half-migrated state
            if ($inTx && $this->pdo->inTransaction()) {
                try {
                    $this->pdo->rollBack();
                } catch (Throwable $rollbackException) {
                    // Suppress secondary rollback exceptions
                }
            }
            throw new RuntimeException("SQL Execution Error in [{$filename}]: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    /**
     * Record a completed migration in the 'migrations' table.
     */
    private function recordMigration(string $filename, int $batch): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO migrations (migration, batch, executed_at) VALUES (?, ?, ?)');
        $stmt->execute([
            $filename,
            $batch,
            date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Calculate the next migration batch number.
     */
    public function getNextBatchNumber(): int
    {
        $this->ensureMigrationsTable();

        try {
            $stmt = $this->pdo->query('SELECT MAX(batch) as max_batch FROM migrations');
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $maxBatch = (int)($row['max_batch'] ?? 0);
            return $maxBatch + 1;
        } catch (PDOException $e) {
            return 1;
        }
    }

    /**
     * Split multi-statement SQL text into individual executable statements,
     * safely ignoring semicolons inside strings, backticks, and comments.
     *
     * @return array<string>
     */
    public function splitSqlStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $len = strlen($sql);

        $inSingleQuote = false;
        $inDoubleQuote = false;
        $inBacktick = false;
        $inLineComment = false;
        $inBlockComment = false;

        for ($i = 0; $i < $len; $i++) {
            $char = $sql[$i];
            $nextChar = ($i + 1 < $len) ? $sql[$i + 1] : '';

            // Handle line comments: -- or #
            if ($inLineComment) {
                if ($char === "\n") {
                    $inLineComment = false;
                    $current .= $char;
                }
                continue;
            }

            // Handle block comments: /* ... */
            if ($inBlockComment) {
                if ($char === '*' && $nextChar === '/') {
                    $inBlockComment = false;
                    $i++; // skip /
                }
                continue;
            }

            // Start line comment
            if (!$inSingleQuote && !$inDoubleQuote && !$inBacktick) {
                if (($char === '-' && $nextChar === '-') || $char === '#') {
                    $inLineComment = true;
                    if ($char === '-') {
                        $i++;
                    }
                    continue;
                }
                if ($char === '/' && $nextChar === '*') {
                    $inBlockComment = true;
                    $i++;
                    continue;
                }
            }

            // Handle quotes
            if ($char === "'" && !$inDoubleQuote && !$inBacktick) {
                // Check if escaped
                $escaped = ($i > 0 && $sql[$i - 1] === '\\');
                if (!$escaped) {
                    $inSingleQuote = !$inSingleQuote;
                }
            } elseif ($char === '"' && !$inSingleQuote && !$inBacktick) {
                $escaped = ($i > 0 && $sql[$i - 1] === '\\');
                if (!$escaped) {
                    $inDoubleQuote = !$inDoubleQuote;
                }
            } elseif ($char === '`' && !$inSingleQuote && !$inDoubleQuote) {
                $inBacktick = !$inBacktick;
            }

            // End of statement delimiter ;
            if ($char === ';' && !$inSingleQuote && !$inDoubleQuote && !$inBacktick) {
                $trimmed = trim($current);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $trimmed = trim($current);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }
}
