<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\SqliteAdmin\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Toolkits\SqliteAdmin\Runtime\DatabaseManager;
use CoquiBot\Toolkits\SqliteAdmin\Runtime\SchemaInspector;

final readonly class SchemaTool
{
    public function __construct(
        private DatabaseManager $manager,
        private SchemaInspector $inspector,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'sqlite_schema',
            description: 'Inspect database schema: list tables, describe columns, view indexes, foreign keys, triggers, views, DDL, and table/database statistics.',
            parameters: [
                new EnumParameter(
                    'action',
                    'Schema inspection action',
                    values: ['tables', 'describe', 'indexes', 'foreign_keys', 'triggers', 'views', 'ddl', 'table_stats', 'db_stats'],
                    required: true,
                ),
                new StringParameter(
                    'table',
                    'Table name (required for describe, indexes, foreign_keys, ddl, table_stats)',
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
        $table = (string) ($args['table'] ?? '');
        $database = (string) ($args['database'] ?? '');

        try {
            $db = $this->manager->resolveConnection($database);

            return match ($action) {
                'tables' => $this->listTables($db),
                'describe' => $this->describeTable($db, $table),
                'indexes' => $this->listIndexes($db, $table),
                'foreign_keys' => $this->listForeignKeys($db, $table),
                'triggers' => $this->listTriggers($db, $table !== '' ? $table : null),
                'views' => $this->listViews($db),
                'ddl' => $this->showDdl($db, $table),
                'table_stats' => $this->tableStats($db, $table),
                'db_stats' => $this->dbStats($db),
                default => ToolResult::error(sprintf('Unknown action "%s".', $action)),
            };
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage());
        }
    }

    private function listTables(\PDO $db): ToolResult
    {
        $items = $this->inspector->getTables($db);

        if ($items === []) {
            return ToolResult::success('Database has no tables or views.');
        }

        $output = "| Name | Type |\n| --- | --- |\n";
        foreach ($items as $item) {
            $output .= sprintf("| %s | %s |\n", $item['name'], $item['type']);
        }

        return ToolResult::success($output);
    }

    private function describeTable(\PDO $db, string $table): ToolResult
    {
        if ($table === '') {
            return ToolResult::error('Parameter "table" is required for "describe" action.');
        }

        $columns = $this->inspector->getTableInfo($db, $table);

        if ($columns === []) {
            return ToolResult::error(sprintf('Table "%s" not found or has no columns.', $table));
        }

        $output = sprintf("**Table: %s**\n\n", $table);
        $output .= "| # | Name | Type | Nullable | Default | PK |\n";
        $output .= "| --- | --- | --- | --- | --- | --- |\n";

        foreach ($columns as $col) {
            $output .= sprintf(
                "| %d | %s | %s | %s | %s | %s |\n",
                (int) $col['cid'],
                $col['name'],
                $col['type'] ?: 'ANY',
                ((int) $col['notnull']) === 1 ? 'NOT NULL' : 'NULL',
                $col['dflt_value'] ?? 'none',
                ((int) $col['pk']) > 0 ? 'PK' . ($col['pk'] > 1 ? '(' . $col['pk'] . ')' : '') : '',
            );
        }

        return ToolResult::success($output);
    }

    private function listIndexes(\PDO $db, string $table): ToolResult
    {
        if ($table === '') {
            return ToolResult::error('Parameter "table" is required for "indexes" action.');
        }

        $indexes = $this->inspector->getIndexes($db, $table);

        if ($indexes === []) {
            return ToolResult::success(sprintf('Table "%s" has no indexes.', $table));
        }

        $output = sprintf("**Indexes on %s:**\n\n", $table);
        $output .= "| Name | Unique | Columns |\n";
        $output .= "| --- | --- | --- |\n";

        foreach ($indexes as $idx) {
            $colNames = array_map(fn(array $c): string => (string) $c['name'], $idx['columns'] ?? []);
            $output .= sprintf(
                "| %s | %s | %s |\n",
                $idx['name'],
                ((int) $idx['unique']) === 1 ? 'yes' : 'no',
                implode(', ', $colNames),
            );
        }

        return ToolResult::success($output);
    }

    private function listForeignKeys(\PDO $db, string $table): ToolResult
    {
        if ($table === '') {
            return ToolResult::error('Parameter "table" is required for "foreign_keys" action.');
        }

        $fks = $this->inspector->getForeignKeys($db, $table);

        if ($fks === []) {
            return ToolResult::success(sprintf('Table "%s" has no foreign keys.', $table));
        }

        $output = sprintf("**Foreign keys on %s:**\n\n", $table);
        $output .= "| From | To Table | To Column | On Update | On Delete |\n";
        $output .= "| --- | --- | --- | --- | --- |\n";

        foreach ($fks as $fk) {
            $output .= sprintf(
                "| %s | %s | %s | %s | %s |\n",
                $fk['from'],
                $fk['table'],
                $fk['to'],
                $fk['on_update'] ?? 'NO ACTION',
                $fk['on_delete'] ?? 'NO ACTION',
            );
        }

        return ToolResult::success($output);
    }

    private function listTriggers(\PDO $db, ?string $table): ToolResult
    {
        $triggers = $this->inspector->getTriggers($db, $table);

        if ($triggers === []) {
            $scope = $table !== null ? sprintf(' on table "%s"', $table) : '';

            return ToolResult::success(sprintf('No triggers found%s.', $scope));
        }

        $output = "**Triggers:**\n\n";
        foreach ($triggers as $trigger) {
            $output .= sprintf("### %s\n```sql\n%s\n```\n\n", $trigger['name'], $trigger['sql']);
        }

        return ToolResult::success($output);
    }

    private function listViews(\PDO $db): ToolResult
    {
        $views = $this->inspector->getViews($db);

        if ($views === []) {
            return ToolResult::success('No views found.');
        }

        $output = "**Views:**\n\n";
        foreach ($views as $view) {
            $output .= sprintf("### %s\n```sql\n%s\n```\n\n", $view['name'], $view['sql']);
        }

        return ToolResult::success($output);
    }

    private function showDdl(\PDO $db, string $table): ToolResult
    {
        if ($table === '') {
            return ToolResult::error('Parameter "table" is required for "ddl" action.');
        }

        $ddl = $this->inspector->getTableDdl($db, $table);

        if ($ddl === '') {
            return ToolResult::error(sprintf('Table or view "%s" not found.', $table));
        }

        return ToolResult::success(sprintf("```sql\n%s;\n```", $ddl));
    }

    private function tableStats(\PDO $db, string $table): ToolResult
    {
        if ($table === '') {
            return ToolResult::error('Parameter "table" is required for "table_stats" action.');
        }

        $stats = $this->inspector->getTableStats($db, $table);

        $output = sprintf("**Table: %s**\n\n", $table);
        $output .= sprintf("- Rows: %d\n", $stats['row_count']);
        $output .= sprintf("- Columns: %d\n", $stats['column_count']);
        $output .= sprintf("- Indexes: %d\n", $stats['index_count']);
        $output .= sprintf("- Primary key: %s\n", $stats['has_primary_key'] ? 'yes' : 'no');
        $output .= sprintf("- Foreign keys: %d\n", $stats['foreign_key_count']);
        $output .= sprintf("- Triggers: %d\n", $stats['trigger_count']);

        return ToolResult::success($output);
    }

    private function dbStats(\PDO $db): ToolResult
    {
        $stats = $this->inspector->getDatabaseStats($db);
        $allTables = $this->inspector->getAllTableStats($db);

        $output = "**Database Statistics:**\n\n";
        $output .= sprintf("- Size: %s\n", $this->formatBytes($stats['database_size_bytes']));
        $output .= sprintf("- Pages: %d (page size: %d bytes)\n", $stats['page_count'], $stats['page_size']);
        $output .= sprintf("- Free pages: %d\n", $stats['freelist_count']);
        $output .= sprintf("- Journal mode: %s\n", $stats['journal_mode']);
        $output .= sprintf("- Tables: %d\n\n", count($allTables));

        if ($allTables !== []) {
            $output .= "| Table | Rows | Columns | Indexes |\n";
            $output .= "| --- | --- | --- | --- |\n";
            foreach ($allTables as $t) {
                $output .= sprintf("| %s | %d | %d | %d |\n", $t['table'], $t['rows'], $t['columns'], $t['indexes']);
            }
        }

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
