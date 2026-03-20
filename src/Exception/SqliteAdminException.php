<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\SqliteAdmin\Exception;

final class SqliteAdminException extends \RuntimeException
{
    public static function connectionFailed(string $path, string $reason): self
    {
        return new self(sprintf('Failed to connect to database "%s": %s', $path, $reason));
    }

    public static function noActiveConnection(): self
    {
        return new self('No active database connection. Use sqlite_connect to open a database first.');
    }

    public static function connectionNotFound(string $alias): self
    {
        return new self(sprintf('No database connection found with alias "%s". Use sqlite_databases to list open connections.', $alias));
    }

    public static function maxConnectionsReached(int $max): self
    {
        return new self(sprintf('Maximum of %d concurrent connections reached. Disconnect an existing database first.', $max));
    }

    public static function pathNotAllowed(string $path): self
    {
        return new self(sprintf('Path "%s" is outside the allowed workspace directory.', $path));
    }

    public static function queryFailed(string $sql, string $reason): self
    {
        return new self(sprintf('Query failed: %s — SQL: %s', $reason, mb_substr($sql, 0, 200)));
    }

    public static function schemaError(string $operation, string $reason): self
    {
        return new self(sprintf('Schema operation "%s" failed: %s', $operation, $reason));
    }

    public static function importExportError(string $operation, string $reason): self
    {
        return new self(sprintf('Import/export operation "%s" failed: %s', $operation, $reason));
    }

    public static function backupError(string $operation, string $reason): self
    {
        return new self(sprintf('Backup operation "%s" failed: %s', $operation, $reason));
    }

    public static function vectorError(string $operation, string $reason): self
    {
        return new self(sprintf('Vector operation "%s" failed: %s', $operation, $reason));
    }

    public static function invalidTable(string $table): self
    {
        return new self(sprintf('Invalid table name: "%s". Table names must be alphanumeric with underscores.', $table));
    }
}
