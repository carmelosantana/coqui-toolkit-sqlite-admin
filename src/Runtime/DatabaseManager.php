<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\SqliteAdmin\Runtime;

use CoquiBot\Toolkits\SqliteAdmin\Exception\SqliteAdminException;

final class DatabaseManager
{
    private const int MAX_CONNECTIONS = 10;
    private const int BUSY_TIMEOUT_MS = 5_000;
    private const array ALLOWED_EXTENSIONS = ['db', 'sqlite', 'sqlite3', 'sqlite-journal', 's3db'];

    /** @var array<string, \PDO> */
    private array $connections = [];

    /** @var array<string, string> alias → real path */
    private array $paths = [];

    /** @var array<string, bool> alias → has active transaction */
    private array $transactions = [];

    private string $activeAlias = '';

    public function __construct(
        private readonly string $storagePath = '',
    ) {}

    public static function fromEnv(): self
    {
        $workspacePath = getenv('COQUI_WORKSPACE_PATH');

        return new self(
            storagePath: is_string($workspacePath) && $workspacePath !== '' ? $workspacePath : '',
        );
    }

    /**
     * Open or create a SQLite database.
     */
    public function connect(string $path, string $alias = '', bool $create = true): string
    {
        $resolvedPath = $this->resolvePath($path);

        // Auto-alias from basename
        if ($alias === '') {
            $alias = $this->generateAlias($resolvedPath);
        }

        $alias = $this->sanitizeAlias($alias);

        // Already connected with this alias? Just switch to it.
        if (isset($this->connections[$alias])) {
            $this->activeAlias = $alias;

            return $alias;
        }

        if (count($this->connections) >= self::MAX_CONNECTIONS) {
            throw SqliteAdminException::maxConnectionsReached(self::MAX_CONNECTIONS);
        }

        // Check if file exists when create=false
        if (!$create && $resolvedPath !== ':memory:' && !file_exists($resolvedPath)) {
            throw SqliteAdminException::connectionFailed($path, 'Database file does not exist and create=false');
        }

        // Ensure directory exists for file-based databases
        if ($resolvedPath !== ':memory:') {
            $dir = dirname($resolvedPath);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
        }

        try {
            $pdo = new \PDO('sqlite:' . $resolvedPath);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
            $pdo->exec('PRAGMA journal_mode=WAL');
            $pdo->exec('PRAGMA foreign_keys=ON');
            $pdo->exec(sprintf('PRAGMA busy_timeout=%d', self::BUSY_TIMEOUT_MS));
        } catch (\PDOException $e) {
            throw SqliteAdminException::connectionFailed($path, $e->getMessage());
        }

        $this->connections[$alias] = $pdo;
        $this->paths[$alias] = $resolvedPath;
        $this->transactions[$alias] = false;
        $this->activeAlias = $alias;

        return $alias;
    }

    /**
     * Close a connection.
     */
    public function disconnect(string $alias): void
    {
        if (!isset($this->connections[$alias])) {
            throw SqliteAdminException::connectionNotFound($alias);
        }

        // Rollback any active transaction
        if ($this->transactions[$alias]) {
            try {
                $this->connections[$alias]->rollBack();
            } catch (\PDOException) {
                // Ignore — connection is being closed
            }
        }

        unset($this->connections[$alias], $this->paths[$alias], $this->transactions[$alias]);

        if ($this->activeAlias === $alias) {
            $this->activeAlias = array_key_first($this->connections) ?? '';
        }
    }

    /**
     * Get the active database connection.
     */
    public function active(): \PDO
    {
        if ($this->activeAlias === '' || !isset($this->connections[$this->activeAlias])) {
            throw SqliteAdminException::noActiveConnection();
        }

        return $this->connections[$this->activeAlias];
    }

    /**
     * Get the active database alias.
     */
    public function activeAlias(): string
    {
        return $this->activeAlias;
    }

    /**
     * Switch active database.
     */
    public function switchTo(string $alias): void
    {
        if (!isset($this->connections[$alias])) {
            throw SqliteAdminException::connectionNotFound($alias);
        }

        $this->activeAlias = $alias;
    }

    /**
     * Get a specific connection by alias.
     */
    public function getConnection(string $alias): \PDO
    {
        if (!isset($this->connections[$alias])) {
            throw SqliteAdminException::connectionNotFound($alias);
        }

        return $this->connections[$alias];
    }

    /**
     * Resolve which PDO to use — if database alias is given, use it; otherwise use active.
     */
    public function resolveConnection(string $database = ''): \PDO
    {
        if ($database !== '') {
            return $this->getConnection($database);
        }

        return $this->active();
    }

    /**
     * Resolve the alias — returns the given alias if non-empty, otherwise the active alias.
     */
    public function resolveAlias(string $database = ''): string
    {
        if ($database !== '') {
            if (!isset($this->connections[$database])) {
                throw SqliteAdminException::connectionNotFound($database);
            }

            return $database;
        }

        if ($this->activeAlias === '') {
            throw SqliteAdminException::noActiveConnection();
        }

        return $this->activeAlias;
    }

    public function isConnected(string $alias): bool
    {
        return isset($this->connections[$alias]);
    }

    /**
     * @return list<array{alias: string, path: string, active: bool, tables: int, size_bytes: int, in_transaction: bool}>
     */
    public function list(): array
    {
        $result = [];

        foreach ($this->connections as $alias => $pdo) {
            $tableCount = 0;
            $sizeBytes = 0;

            try {
                $stmt = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'");
                $tableCount = (int) $stmt->fetchColumn();

                $path = $this->paths[$alias];
                if ($path !== ':memory:' && file_exists($path)) {
                    $sizeBytes = (int) filesize($path);
                }
            } catch (\PDOException) {
                // Connection might be stale — report what we can
            }

            $result[] = [
                'alias' => $alias,
                'path' => $this->paths[$alias],
                'active' => $alias === $this->activeAlias,
                'tables' => $tableCount,
                'size_bytes' => $sizeBytes,
                'in_transaction' => $this->transactions[$alias] ?? false,
            ];
        }

        return $result;
    }

    public function connectionCount(): int
    {
        return count($this->connections);
    }

    /**
     * Get the file path for a connected database.
     */
    public function getPath(string $alias): string
    {
        if (!isset($this->paths[$alias])) {
            throw SqliteAdminException::connectionNotFound($alias);
        }

        return $this->paths[$alias];
    }

    /**
     * Transaction state management.
     */
    public function beginTransaction(string $alias): void
    {
        $this->transactions[$alias] = true;
    }

    public function endTransaction(string $alias): void
    {
        $this->transactions[$alias] = false;
    }

    public function hasTransaction(string $alias): bool
    {
        return $this->transactions[$alias] ?? false;
    }

    public function storagePath(): string
    {
        return $this->storagePath;
    }

    private function resolvePath(string $path): string
    {
        if ($path === ':memory:') {
            return ':memory:';
        }

        // Expand ~ to home directory
        if (str_starts_with($path, '~/')) {
            $home = getenv('HOME');
            if (is_string($home) && $home !== '') {
                $path = $home . substr($path, 1);
            }
        }

        // If relative, resolve against storage path
        if (!str_starts_with($path, '/') && $this->storagePath !== '') {
            $path = rtrim($this->storagePath, '/') . '/databases/' . $path;
        }

        // Ensure proper extension for new files
        if (!file_exists($path) && !$this->hasAllowedExtension($path)) {
            $path .= '.db';
        }

        // Ensure directory exists
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        return $path;
    }

    private function hasAllowedExtension(string $path): bool
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($ext, self::ALLOWED_EXTENSIONS, true) || str_contains($path, ':memory:');
    }

    private function generateAlias(string $path): string
    {
        if ($path === ':memory:') {
            $base = 'memory';
        } else {
            $base = pathinfo($path, PATHINFO_FILENAME);
        }

        $alias = $base;
        $counter = 1;

        while (isset($this->connections[$alias])) {
            $alias = $base . '_' . $counter;
            $counter++;
        }

        return $alias;
    }

    private function sanitizeAlias(string $alias): string
    {
        // Only allow alphanumeric, underscore, hyphen
        $sanitized = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $alias);

        return $sanitized !== '' && $sanitized !== null ? $sanitized : 'db';
    }
}
