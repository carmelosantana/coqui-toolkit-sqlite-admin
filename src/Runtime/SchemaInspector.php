<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\SqliteAdmin\Runtime;

final readonly class SchemaInspector
{
    /**
     * @return list<array{name: string, type: string}>
     */
    public function getTables(\PDO $db): array
    {
        $stmt = $db->query(
            "SELECT name, type FROM sqlite_master WHERE type IN ('table', 'view') AND name NOT LIKE 'sqlite_%' ORDER BY type, name",
        );

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getTableInfo(\PDO $db, string $table): array
    {
        $table = $this->sanitizeName($table);
        $stmt = $db->query(sprintf('PRAGMA table_xinfo(%s)', $this->quoteName($table)));

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getIndexes(\PDO $db, string $table): array
    {
        $table = $this->sanitizeName($table);
        $stmt = $db->query(sprintf('PRAGMA index_list(%s)', $this->quoteName($table)));
        $indexes = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($indexes as &$index) {
            $infoStmt = $db->query(sprintf('PRAGMA index_info(%s)', $this->quoteName($index['name'])));
            $index['columns'] = $infoStmt->fetchAll(\PDO::FETCH_ASSOC);
        }

        return $indexes;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getForeignKeys(\PDO $db, string $table): array
    {
        $table = $this->sanitizeName($table);
        $stmt = $db->query(sprintf('PRAGMA foreign_key_list(%s)', $this->quoteName($table)));

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array{name: string, sql: string}>
     */
    public function getTriggers(\PDO $db, ?string $table = null): array
    {
        $sql = "SELECT name, sql FROM sqlite_master WHERE type = 'trigger'";

        if ($table !== null) {
            $table = $this->sanitizeName($table);
            $sql .= sprintf(' AND tbl_name = %s', $db->quote($table));
        }

        $sql .= ' ORDER BY name';
        $stmt = $db->query($sql);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array{name: string, sql: string}>
     */
    public function getViews(\PDO $db): array
    {
        $stmt = $db->query(
            "SELECT name, sql FROM sqlite_master WHERE type = 'view' ORDER BY name",
        );

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getTableRowCount(\PDO $db, string $table): int
    {
        $table = $this->sanitizeName($table);
        $stmt = $db->query(sprintf('SELECT COUNT(*) FROM %s', $this->quoteName($table)));

        return (int) $stmt->fetchColumn();
    }

    public function getTableDdl(\PDO $db, string $table): string
    {
        $table = $this->sanitizeName($table);
        $stmt = $db->prepare("SELECT sql FROM sqlite_master WHERE name = :name");
        $stmt->execute(['name' => $table]);
        $result = $stmt->fetchColumn();

        return is_string($result) ? $result : '';
    }

    /**
     * @return array{page_count: int, page_size: int, freelist_count: int, journal_mode: string, database_size_bytes: int, wal_mode: bool}
     */
    public function getDatabaseStats(\PDO $db): array
    {
        $pageCount = (int) $db->query('PRAGMA page_count')->fetchColumn();
        $pageSize = (int) $db->query('PRAGMA page_size')->fetchColumn();
        $freelistCount = (int) $db->query('PRAGMA freelist_count')->fetchColumn();
        $journalMode = (string) $db->query('PRAGMA journal_mode')->fetchColumn();

        return [
            'page_count' => $pageCount,
            'page_size' => $pageSize,
            'freelist_count' => $freelistCount,
            'journal_mode' => $journalMode,
            'database_size_bytes' => $pageCount * $pageSize,
            'wal_mode' => strtolower($journalMode) === 'wal',
        ];
    }

    /**
     * @return array{row_count: int, column_count: int, index_count: int, has_primary_key: bool, foreign_key_count: int, trigger_count: int, ddl: string}
     */
    public function getTableStats(\PDO $db, string $table): array
    {
        $table = $this->sanitizeName($table);
        $columns = $this->getTableInfo($db, $table);
        $indexes = $this->getIndexes($db, $table);
        $foreignKeys = $this->getForeignKeys($db, $table);
        $triggers = $this->getTriggers($db, $table);
        $hasPrimaryKey = array_any($columns, fn(array $col): bool => (int) ($col['pk'] ?? 0) > 0);

        return [
            'row_count' => $this->getTableRowCount($db, $table),
            'column_count' => count($columns),
            'index_count' => count($indexes),
            'has_primary_key' => $hasPrimaryKey,
            'foreign_key_count' => count($foreignKeys),
            'trigger_count' => count($triggers),
            'ddl' => $this->getTableDdl($db, $table),
        ];
    }

    /**
     * @return list<array{table: string, rows: int, columns: int, indexes: int}>
     */
    public function getAllTableStats(\PDO $db): array
    {
        $tables = $this->getTables($db);
        $stats = [];

        foreach ($tables as $table) {
            if ($table['type'] !== 'table') {
                continue;
            }

            $name = $table['name'];
            $columns = $this->getTableInfo($db, $name);
            $indexes = $this->getIndexes($db, $name);

            $stats[] = [
                'table' => $name,
                'rows' => $this->getTableRowCount($db, $name),
                'columns' => count($columns),
                'indexes' => count($indexes),
            ];
        }

        return $stats;
    }

    /**
     * Sanitize an identifier name to prevent SQL injection.
     */
    private function sanitizeName(string $name): string
    {
        // Strip any surrounding quotes
        $name = trim($name, '`"\'[]');

        // Allow alphanumeric, underscore, dot (for schema.table), and hyphen
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_.\-]*$/', $name) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid identifier name: "%s"', $name));
        }

        return $name;
    }

    /**
     * Quote an identifier for safe use in SQL.
     */
    private function quoteName(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }
}
