<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\SqliteAdmin\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Toolkits\SqliteAdmin\Runtime\DatabaseManager;

final readonly class AnalyzeTool
{
    public function __construct(
        private DatabaseManager $manager,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'sqlite_analyze',
            description: 'Analyze query performance: EXPLAIN QUERY PLAN for SQL statements, suggest missing indexes, compare table statistics, and identify potential performance bottlenecks.',
            parameters: [
                new EnumParameter(
                    'action',
                    'Analysis action',
                    values: ['explain', 'explain_plan', 'index_suggestions', 'table_stats', 'unused_indexes'],
                    required: true,
                ),
                new StringParameter(
                    'sql',
                    'SQL query to analyze (required for explain and explain_plan)',
                    required: false,
                ),
                new StringParameter(
                    'table',
                    'Table name (required for index_suggestions and table_stats)',
                    required: false,
                ),
                new StringParameter(
                    'database',
                    'Database alias (defaults to active)',
                    required: false,
                ),
            ],
            callback: fn(array $args): ToolResult => $this->execute($args),
        );
    }

    private function execute(array $args): ToolResult
    {
        $action = (string) ($args['action'] ?? '');
        $sql = (string) ($args['sql'] ?? '');
        $table = (string) ($args['table'] ?? '');
        $database = (string) ($args['database'] ?? '');

        try {
            $db = $this->manager->resolveConnection($database);

            return match ($action) {
                'explain' => $this->explain($db, $sql),
                'explain_plan' => $this->explainPlan($db, $sql),
                'index_suggestions' => $this->indexSuggestions($db, $table),
                'table_stats' => $this->tableStats($db, $table),
                'unused_indexes' => $this->unusedIndexes($db),
                default => ToolResult::error(sprintf('Unknown action "%s".', $action)),
            };
        } catch (\Throwable $e) {
            return ToolResult::error(sprintf('Analysis error: %s', $e->getMessage()));
        }
    }

    private function explain(\PDO $db, string $sql): ToolResult
    {
        if ($sql === '') {
            return ToolResult::error('Parameter "sql" is required for explain.');
        }

        $stmt = $db->query('EXPLAIN ' . $sql);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if ($rows === []) {
            return ToolResult::success('No EXPLAIN output.');
        }

        $output = "**EXPLAIN output:**\n\n```\n";
        $columns = array_keys($rows[0]);
        $output .= implode(' | ', $columns) . "\n";
        $output .= str_repeat('-', 60) . "\n";

        foreach ($rows as $row) {
            $output .= implode(' | ', array_map(fn(mixed $v): string => (string) ($v ?? ''), $row)) . "\n";
        }

        $output .= "```";

        return ToolResult::success($output);
    }

    private function explainPlan(\PDO $db, string $sql): ToolResult
    {
        if ($sql === '') {
            return ToolResult::error('Parameter "sql" is required for explain_plan.');
        }

        $stmt = $db->query('EXPLAIN QUERY PLAN ' . $sql);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if ($rows === []) {
            return ToolResult::success('No query plan output.');
        }

        $output = "**Query Plan:**\n\n";

        $hasFullScan = false;
        $hasIndex = false;

        foreach ($rows as $row) {
            $detail = $row['detail'] ?? '';
            $indent = str_repeat('  ', (int) ($row['selectid'] ?? 0));
            $output .= sprintf("%s- %s\n", $indent, $detail);

            if (str_contains(strtoupper((string) $detail), 'SCAN')) {
                $hasFullScan = true;
            }
            if (str_contains(strtoupper((string) $detail), 'INDEX') || str_contains(strtoupper((string) $detail), 'SEARCH')) {
                $hasIndex = true;
            }
        }

        // Add assessment
        $output .= "\n**Assessment:** ";
        if ($hasFullScan && !$hasIndex) {
            $output .= "⚠ Full table scan detected. Consider adding an index on the WHERE/JOIN columns.";
        } elseif ($hasFullScan && $hasIndex) {
            $output .= "Mixed — some index scans, some full scans. Review for optimization opportunities.";
        } else {
            $output .= "Good — query uses index scans.";
        }

        return ToolResult::success($output);
    }

    private function indexSuggestions(\PDO $db, string $table): ToolResult
    {
        if ($table === '') {
            return ToolResult::error('Parameter "table" is required for index_suggestions.');
        }

        $quotedTable = '"' . str_replace('"', '""', $table) . '"';

        // Get current columns and indexes
        $columnsStmt = $db->query(sprintf('PRAGMA table_info(%s)', $quotedTable));
        $columns = $columnsStmt->fetchAll(\PDO::FETCH_ASSOC);

        $indexesStmt = $db->query(sprintf('PRAGMA index_list(%s)', $quotedTable));
        $indexes = $indexesStmt->fetchAll(\PDO::FETCH_ASSOC);

        // Get indexed columns
        $indexedColumns = [];
        foreach ($indexes as $idx) {
            $infoStmt = $db->query(sprintf('PRAGMA index_info("%s")', str_replace('"', '""', $idx['name'])));
            $indexCols = $infoStmt->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($indexCols as $ic) {
                $indexedColumns[] = $ic['name'];
            }
        }

        // Get row count for relevance
        $rowCount = (int) $db->query(sprintf('SELECT COUNT(*) FROM %s', $quotedTable))->fetchColumn();

        $suggestions = [];

        foreach ($columns as $col) {
            $colName = $col['name'];
            $colType = strtoupper($col['type'] ?? '');
            $isPk = ((int) $col['pk']) > 0;

            if ($isPk || in_array($colName, $indexedColumns, true)) {
                continue;
            }

            // Suggest indexes for common patterns
            if (str_contains($colName, '_id') || str_ends_with($colName, 'Id')) {
                $suggestions[] = sprintf(
                    'Foreign key column `%s` — `CREATE INDEX idx_%s_%s ON %s(%s)`',
                    $colName,
                    $table,
                    $colName,
                    $quotedTable,
                    '"' . $colName . '"',
                );
            } elseif (in_array($colName, ['email', 'username', 'slug', 'code', 'uuid', 'token'], true)) {
                $suggestions[] = sprintf(
                    'Lookup column `%s` — `CREATE UNIQUE INDEX idx_%s_%s ON %s(%s)`',
                    $colName,
                    $table,
                    $colName,
                    $quotedTable,
                    '"' . $colName . '"',
                );
            } elseif (str_contains($colName, 'date') || str_contains($colName, 'time') || str_ends_with($colName, '_at')) {
                $suggestions[] = sprintf(
                    'Timestamp column `%s` — `CREATE INDEX idx_%s_%s ON %s(%s)`',
                    $colName,
                    $table,
                    $colName,
                    $quotedTable,
                    '"' . $colName . '"',
                );
            } elseif (str_contains($colName, 'status') || str_contains($colName, 'type') || str_contains($colName, 'category')) {
                if ($rowCount > 100) {
                    $suggestions[] = sprintf(
                        'Filter column `%s` (with %d rows, an index may help) — `CREATE INDEX idx_%s_%s ON %s(%s)`',
                        $colName,
                        $rowCount,
                        $table,
                        $colName,
                        $quotedTable,
                        '"' . $colName . '"',
                    );
                }
            }
        }

        $output = sprintf("**Index suggestions for %s** (%d rows, %d existing indexes):\n\n", $table, $rowCount, count($indexes));

        if ($suggestions === []) {
            $output .= "No obvious index improvements detected. Current indexing appears adequate.";
        } else {
            foreach ($suggestions as $i => $suggestion) {
                $output .= sprintf("%d. %s\n", $i + 1, $suggestion);
            }
        }

        return ToolResult::success($output);
    }

    private function tableStats(\PDO $db, string $table): ToolResult
    {
        if ($table === '') {
            // Show all tables
            $stmt = $db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
            $tables = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            if ($tables === []) {
                return ToolResult::success('No tables found.');
            }

            $output = "**All Table Statistics:**\n\n";
            $output .= "| Table | Rows | Columns | Indexes | Avg Row Size |\n";
            $output .= "| --- | --- | --- | --- | --- |\n";

            foreach ($tables as $tableName) {
                $quotedName = '"' . str_replace('"', '""', $tableName) . '"';
                $rows = (int) $db->query(sprintf('SELECT COUNT(*) FROM %s', $quotedName))->fetchColumn();
                $cols = count($db->query(sprintf('PRAGMA table_info(%s)', $quotedName))->fetchAll());
                $indexes = count($db->query(sprintf('PRAGMA index_list(%s)', $quotedName))->fetchAll());

                // Estimate average row size
                $avgRowSize = 'n/a';
                if ($rows > 0) {
                    $pageCount = (int) $db->query('PRAGMA page_count')->fetchColumn();
                    $pageSize = (int) $db->query('PRAGMA page_size')->fetchColumn();
                    $totalSize = $pageCount * $pageSize;
                    $tableCount = count($tables);
                    if ($tableCount > 0) {
                        $estimatedTableSize = (int) ($totalSize / $tableCount);
                        $avgRowSize = $this->formatBytes((int) ($estimatedTableSize / max($rows, 1)));
                    }
                }

                $output .= sprintf("| %s | %d | %d | %d | %s |\n", $tableName, $rows, $cols, $indexes, $avgRowSize);
            }

            return ToolResult::success($output);
        }

        $quotedTable = '"' . str_replace('"', '""', $table) . '"';
        $rows = (int) $db->query(sprintf('SELECT COUNT(*) FROM %s', $quotedTable))->fetchColumn();
        $cols = $db->query(sprintf('PRAGMA table_info(%s)', $quotedTable))->fetchAll(\PDO::FETCH_ASSOC);
        $indexes = $db->query(sprintf('PRAGMA index_list(%s)', $quotedTable))->fetchAll(\PDO::FETCH_ASSOC);
        $fks = $db->query(sprintf('PRAGMA foreign_key_list(%s)', $quotedTable))->fetchAll(\PDO::FETCH_ASSOC);

        $output = sprintf("**Table Statistics: %s**\n\n", $table);
        $output .= sprintf("- Rows: %d\n", $rows);
        $output .= sprintf("- Columns: %d\n", count($cols));
        $output .= sprintf("- Indexes: %d\n", count($indexes));
        $output .= sprintf("- Foreign keys: %d\n", count($fks));

        // Column type distribution
        $typeCounts = [];
        foreach ($cols as $col) {
            $type = strtoupper($col['type'] ?: 'ANY');
            $typeCounts[$type] = ($typeCounts[$type] ?? 0) + 1;
        }
        $output .= "\n**Column types:** " . implode(', ', array_map(fn(string $t, int $c): string => sprintf('%s(%d)', $t, $c), array_keys($typeCounts), array_values($typeCounts)));

        // Nullable column count
        $nullableCount = count(array_filter($cols, fn(array $c): bool => ((int) $c['notnull']) === 0));
        $output .= sprintf("\n**Nullable columns:** %d of %d", $nullableCount, count($cols));

        return ToolResult::success($output);
    }

    private function unusedIndexes(\PDO $db): ToolResult
    {
        // SQLite doesn't track index usage stats directly, but we can check for autoindexes and redundant indexes
        $stmt = $db->query("SELECT name, tbl_name, sql FROM sqlite_master WHERE type = 'index' AND sql IS NOT NULL ORDER BY tbl_name, name");
        $indexes = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if ($indexes === []) {
            return ToolResult::success('No user-created indexes found.');
        }

        $output = "**Index Analysis:**\n\n";
        $output .= "Note: SQLite does not track index usage statistics. This analysis identifies potential redundancies and optimization opportunities.\n\n";
        $output .= "| Index | Table | Definition |\n";
        $output .= "| --- | --- | --- |\n";

        foreach ($indexes as $idx) {
            $definition = $idx['sql'] ?? '';
            // Truncate long definitions
            if (strlen($definition) > 80) {
                $definition = substr($definition, 0, 77) . '...';
            }
            $output .= sprintf("| %s | %s | `%s` |\n", $idx['name'], $idx['tbl_name'], $definition);
        }

        $output .= sprintf("\n**Total user indexes:** %d\n", count($indexes));
        $output .= "\nTo check if a specific query uses an index, run: `sqlite_analyze(action: \"explain_plan\", sql: \"YOUR SELECT QUERY\")`";

        return ToolResult::success($output);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1_048_576) {
            return sprintf('%.1f KB', $bytes / 1024);
        }

        return sprintf('%.1f MB', $bytes / 1_048_576);
    }
}
