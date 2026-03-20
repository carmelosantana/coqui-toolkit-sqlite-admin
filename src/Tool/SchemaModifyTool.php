<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\SqliteAdmin\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Toolkits\SqliteAdmin\Runtime\DatabaseManager;

final readonly class SchemaModifyTool
{
    public function __construct(
        private DatabaseManager $manager,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'sqlite_schema_modify',
            description: 'Modify database schema: create/drop/rename tables, add/rename/drop columns, create/drop indexes and views. Destructive operations (drop_table, drop_index, drop_view) require user confirmation.',
            parameters: [
                new EnumParameter(
                    'action',
                    'DDL action to perform',
                    values: ['create_table', 'add_column', 'rename_column', 'drop_column', 'create_index', 'drop_index', 'drop_table', 'rename_table', 'create_view', 'drop_view'],
                    required: true,
                ),
                new StringParameter(
                    'table',
                    'Table or view name to operate on',
                    required: true,
                ),
                new StringParameter(
                    'definition',
                    'Column definition, table DDL body, index expression, or view SELECT. Context depends on action. For create_table: JSON column definitions like [{"name":"id","type":"INTEGER","pk":true},{"name":"email","type":"TEXT","notnull":true}] or raw SQL column definitions. For add_column: "column_name TYPE [constraints]". For create_index: "col1, col2" or "(col1, col2) WHERE condition". For create_view: "SELECT ..." query.',
                    required: false,
                ),
                new StringParameter(
                    'new_name',
                    'New name for rename operations (rename_table, rename_column)',
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
        $definition = (string) ($args['definition'] ?? '');
        $newName = (string) ($args['new_name'] ?? '');
        $database = (string) ($args['database'] ?? '');

        if ($table === '') {
            return ToolResult::error('Parameter "table" is required.');
        }

        try {
            $db = $this->manager->resolveConnection($database);

            return match ($action) {
                'create_table' => $this->createTable($db, $table, $definition),
                'add_column' => $this->addColumn($db, $table, $definition),
                'rename_column' => $this->renameColumn($db, $table, $definition, $newName),
                'drop_column' => $this->dropColumn($db, $table, $definition),
                'create_index' => $this->createIndex($db, $table, $definition),
                'drop_index' => $this->dropIndex($db, $definition !== '' ? $definition : $table),
                'drop_table' => $this->dropTable($db, $table),
                'rename_table' => $this->renameTable($db, $table, $newName),
                'create_view' => $this->createView($db, $table, $definition),
                'drop_view' => $this->dropView($db, $table),
                default => ToolResult::error(sprintf('Unknown action "%s".', $action)),
            };
        } catch (\PDOException $e) {
            return ToolResult::error(sprintf('Schema error: %s', $e->getMessage()));
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage());
        }
    }

    private function createTable(\PDO $db, string $table, string $definition): ToolResult
    {
        if ($definition === '') {
            return ToolResult::error('Parameter "definition" is required for create_table. Provide JSON column array or raw SQL column definitions.');
        }

        $sql = $this->buildCreateTableSql($table, $definition);
        $db->exec($sql);

        return ToolResult::success(sprintf("Table **%s** created.\n\n```sql\n%s\n```", $table, $sql));
    }

    private function addColumn(\PDO $db, string $table, string $definition): ToolResult
    {
        if ($definition === '') {
            return ToolResult::error('Parameter "definition" is required. Example: "email TEXT NOT NULL DEFAULT \'\'"');
        }

        $sql = sprintf('ALTER TABLE %s ADD COLUMN %s', $this->quoteName($table), $definition);
        $db->exec($sql);

        return ToolResult::success(sprintf('Column added to **%s**: `%s`', $table, $definition));
    }

    private function renameColumn(\PDO $db, string $table, string $oldName, string $newName): ToolResult
    {
        if ($oldName === '' || $newName === '') {
            return ToolResult::error('Both "definition" (old column name) and "new_name" are required for rename_column.');
        }

        $sql = sprintf(
            'ALTER TABLE %s RENAME COLUMN %s TO %s',
            $this->quoteName($table),
            $this->quoteName($oldName),
            $this->quoteName($newName),
        );
        $db->exec($sql);

        return ToolResult::success(sprintf('Column renamed on **%s**: `%s` → `%s`', $table, $oldName, $newName));
    }

    private function dropColumn(\PDO $db, string $table, string $column): ToolResult
    {
        if ($column === '') {
            return ToolResult::error('Parameter "definition" (column name) is required for drop_column.');
        }

        $sql = sprintf('ALTER TABLE %s DROP COLUMN %s', $this->quoteName($table), $this->quoteName($column));
        $db->exec($sql);

        return ToolResult::success(sprintf('Column `%s` dropped from **%s**.', $column, $table));
    }

    private function createIndex(\PDO $db, string $table, string $definition): ToolResult
    {
        if ($definition === '') {
            return ToolResult::error('Parameter "definition" is required. Provide column names: "col1, col2" or "(col1) WHERE condition"');
        }

        // Auto-generate index name
        $columns = $this->extractIndexColumns($definition);
        $indexName = sprintf('idx_%s_%s', $table, implode('_', $columns));

        // Check if definition contains parentheses already
        $expression = str_contains($definition, '(') ? $definition : '(' . $definition . ')';

        $sql = sprintf('CREATE INDEX %s ON %s %s', $this->quoteName($indexName), $this->quoteName($table), $expression);
        $db->exec($sql);

        return ToolResult::success(sprintf("Index **%s** created on **%s**.\n\n```sql\n%s\n```", $indexName, $table, $sql));
    }

    private function dropIndex(\PDO $db, string $indexName): ToolResult
    {
        $sql = sprintf('DROP INDEX IF EXISTS %s', $this->quoteName($indexName));
        $db->exec($sql);

        return ToolResult::success(sprintf('Index **%s** dropped.', $indexName));
    }

    private function dropTable(\PDO $db, string $table): ToolResult
    {
        $sql = sprintf('DROP TABLE IF EXISTS %s', $this->quoteName($table));
        $db->exec($sql);

        return ToolResult::success(sprintf('Table **%s** dropped.', $table));
    }

    private function renameTable(\PDO $db, string $table, string $newName): ToolResult
    {
        if ($newName === '') {
            return ToolResult::error('Parameter "new_name" is required for rename_table.');
        }

        $sql = sprintf('ALTER TABLE %s RENAME TO %s', $this->quoteName($table), $this->quoteName($newName));
        $db->exec($sql);

        return ToolResult::success(sprintf('Table renamed: **%s** → **%s**', $table, $newName));
    }

    private function createView(\PDO $db, string $name, string $definition): ToolResult
    {
        if ($definition === '') {
            return ToolResult::error('Parameter "definition" is required for create_view. Provide a SELECT query.');
        }

        $sql = sprintf('CREATE VIEW %s AS %s', $this->quoteName($name), $definition);
        $db->exec($sql);

        return ToolResult::success(sprintf("View **%s** created.\n\n```sql\n%s\n```", $name, $sql));
    }

    private function dropView(\PDO $db, string $name): ToolResult
    {
        $sql = sprintf('DROP VIEW IF EXISTS %s', $this->quoteName($name));
        $db->exec($sql);

        return ToolResult::success(sprintf('View **%s** dropped.', $name));
    }

    private function buildCreateTableSql(string $table, string $definition): string
    {
        // Try JSON column definitions first
        $columns = json_decode($definition, true);

        if (is_array($columns) && $columns !== [] && isset($columns[0]['name'])) {
            $colDefs = [];
            $pkColumns = [];

            foreach ($columns as $col) {
                $name = (string) ($col['name'] ?? '');
                $type = (string) ($col['type'] ?? 'TEXT');
                $parts = [$this->quoteName($name), $type];

                if (!empty($col['pk'])) {
                    $pkColumns[] = $name;
                    if (count(array_filter($columns, fn(array $c): bool => !empty($c['pk']))) === 1) {
                        $parts[] = 'PRIMARY KEY';
                        if (!empty($col['autoincrement'])) {
                            $parts[] = 'AUTOINCREMENT';
                        }
                        $pkColumns = []; // Single-column PK handled inline
                    }
                }

                if (!empty($col['notnull'])) {
                    $parts[] = 'NOT NULL';
                }

                if (!empty($col['unique'])) {
                    $parts[] = 'UNIQUE';
                }

                if (array_key_exists('default', $col)) {
                    $default = $col['default'];
                    if (is_string($default)) {
                        $parts[] = sprintf("DEFAULT '%s'", str_replace("'", "''", $default));
                    } elseif (is_null($default)) {
                        $parts[] = 'DEFAULT NULL';
                    } else {
                        $parts[] = sprintf('DEFAULT %s', $default);
                    }
                }

                if (!empty($col['references'])) {
                    $parts[] = sprintf('REFERENCES %s', $col['references']);
                }

                $colDefs[] = implode(' ', $parts);
            }

            if ($pkColumns !== []) {
                $colDefs[] = sprintf('PRIMARY KEY (%s)', implode(', ', array_map($this->quoteName(...), $pkColumns)));
            }

            return sprintf("CREATE TABLE %s (\n    %s\n)", $this->quoteName($table), implode(",\n    ", $colDefs));
        }

        // Raw SQL column definitions
        return sprintf('CREATE TABLE %s (%s)', $this->quoteName($table), $definition);
    }

    /**
     * @return list<string>
     */
    private function extractIndexColumns(string $definition): array
    {
        // Strip parentheses and WHERE clause
        $clean = preg_replace('/\bWHERE\b.*/i', '', $definition) ?? $definition;
        $clean = trim($clean, '() ');
        $parts = array_map('trim', explode(',', $clean));

        return array_map(
            fn(string $col): string => preg_replace('/[^a-zA-Z0-9_]/', '', $col) ?: 'col',
            $parts,
        );
    }

    private function quoteName(string $name): string
    {
        $name = trim($name, '`"\'[]');

        return '"' . str_replace('"', '""', $name) . '"';
    }
}
