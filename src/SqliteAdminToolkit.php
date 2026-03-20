<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\SqliteAdmin;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CoquiBot\Toolkits\SqliteAdmin\Runtime\DatabaseManager;
use CoquiBot\Toolkits\SqliteAdmin\Runtime\SchemaInspector;
use CoquiBot\Toolkits\SqliteAdmin\Tool\AnalyzeTool;
use CoquiBot\Toolkits\SqliteAdmin\Tool\BackupRestoreTool;
use CoquiBot\Toolkits\SqliteAdmin\Tool\ConnectTool;
use CoquiBot\Toolkits\SqliteAdmin\Tool\DisconnectTool;
use CoquiBot\Toolkits\SqliteAdmin\Tool\ImportExportTool;
use CoquiBot\Toolkits\SqliteAdmin\Tool\ListDatabasesTool;
use CoquiBot\Toolkits\SqliteAdmin\Tool\OptimizeTool;
use CoquiBot\Toolkits\SqliteAdmin\Tool\QueryTool;
use CoquiBot\Toolkits\SqliteAdmin\Tool\SchemaModifyTool;
use CoquiBot\Toolkits\SqliteAdmin\Tool\SchemaTool;
use CoquiBot\Toolkits\SqliteAdmin\Tool\TransactionTool;
use CoquiBot\Toolkits\SqliteAdmin\Tool\VectorStoreTool;

final class SqliteAdminToolkit implements ToolkitInterface
{
    private readonly SchemaInspector $inspector;

    public function __construct(
        private readonly DatabaseManager $manager = new DatabaseManager(),
    ) {
        $this->inspector = new SchemaInspector();
    }

    public static function fromEnv(): self
    {
        return new self(
            manager: DatabaseManager::fromEnv(),
        );
    }

    /**
     * @return list<ToolInterface>
     */
    public function tools(): array
    {
        return [
            (new ConnectTool($this->manager))->build(),
            (new DisconnectTool($this->manager))->build(),
            (new ListDatabasesTool($this->manager))->build(),
            (new QueryTool($this->manager))->build(),
            (new SchemaTool($this->manager, $this->inspector))->build(),
            (new SchemaModifyTool($this->manager))->build(),
            (new ImportExportTool($this->manager))->build(),
            (new BackupRestoreTool($this->manager))->build(),
            (new OptimizeTool($this->manager))->build(),
            (new VectorStoreTool($this->manager))->build(),
            (new AnalyzeTool($this->manager))->build(),
            (new TransactionTool($this->manager))->build(),
        ];
    }

    public function guidelines(): string
    {
        return <<<'GUIDELINES'
        <sqlite_admin_guidelines>
        ## SQLite Admin Toolkit

        ### Connection Workflow
        1. Connect to a database first: `sqlite_connect(path: "mydb.db")` — creates if it doesn't exist.
        2. Relative paths resolve to the workspace `databases/` directory. Absolute paths and `~` are supported.
        3. Multiple databases can be open simultaneously (max 10). Switch with `sqlite_connect` or pass `database:` to any tool.
        4. List open connections: `sqlite_databases()`.

        ### Query Execution
        - `sqlite_query(sql: "SELECT ...")` — read queries return markdown tables.
        - `sqlite_query(sql: "INSERT ...", params: '{"name": "value"}')` — use named parameters for safe writes.
        - Write queries (INSERT, UPDATE, DELETE, CREATE, DROP, ALTER) are auto-detected. SELECT is limited to 1000 rows.
        - Always prefer parameterized queries over string concatenation.

        ### Schema Operations
        - Inspect: `sqlite_schema(action: "tables")`, `sqlite_schema(action: "describe", table: "users")`.
        - Create tables: `sqlite_schema_modify(action: "create_table", table: "users", columns: '[{"name": "id", "type": "INTEGER", "pk": true}, ...]')`.
        - Modify: add_column, rename_column, rename_table, create_index, drop_table, drop_index, drop_view.

        ### Transactions
        - Wrap multi-step writes: `sqlite_transaction(action: "begin")` → writes → `sqlite_transaction(action: "commit")`.
        - Use savepoints for nested operations: `sqlite_transaction(action: "savepoint", name: "sp1")`.

        ### Vector Store / RAG
        - Create a vector store: `sqlite_vector(action: "create_store", store: "docs")`.
        - Add records with text: `sqlite_vector(action: "add", store: "docs", text: "content here")`.
        - Add with pre-computed embeddings: include `embedding: "[0.1, -0.3, ...]"`.
        - Search: `sqlite_vector(action: "search", store: "docs", text: "query")` — uses FTS5 full-text search and/or cosine similarity.

        ### Backup & Maintenance
        - Backup: `sqlite_backup_restore(action: "backup")` — uses VACUUM INTO for consistent snapshots.
        - Optimize: `sqlite_optimize(action: "vacuum")`, `sqlite_optimize(action: "analyze")`.
        - Analyze queries: `sqlite_analyze(action: "explain_plan", sql: "SELECT ...")`.

        ### Import/Export
        - Import CSV/JSON: `sqlite_import_export(action: "import_csv", table: "data", content: "col1,col2\nval1,val2")`.
        - Export: `sqlite_import_export(action: "export_csv", table: "data")`.
        - Full SQL dump: `sqlite_import_export(action: "sql_dump")`.
        </sqlite_admin_guidelines>
        GUIDELINES;
    }
}
