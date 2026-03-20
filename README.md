# SQLite Admin Toolkit for Coqui

A comprehensive SQLite database administration toolkit for the [Coqui](https://github.com/carmelosantana/coqui) bot platform. Provides 12 tools covering database connectivity, SQL execution, schema management, backup/restore, vector search, and query analysis.

## Features

- **Multi-database connections** — open up to 10 SQLite databases simultaneously with alias-based switching
- **SQL query execution** — parameterized queries with auto-LIMIT, write detection, and markdown-formatted results
- **Schema management** — inspect tables/indexes/views, create/alter/drop with JSON column definitions
- **Import/Export** — CSV and JSON import with auto-table creation, CSV/JSON export, full SQL dump
- **Backup & Restore** — consistent backups via VACUUM INTO, clone databases, restore from backup files
- **Vector store & RAG** — SQLite-backed vector storage with FTS5 full-text search and cosine similarity
- **Query analysis** — EXPLAIN QUERY PLAN, index suggestions, unused index detection
- **Transactions** — BEGIN/COMMIT/ROLLBACK with savepoint support for nested operations
- **Database optimization** — VACUUM, ANALYZE, integrity checks, WAL checkpoints, PRAGMA reporting

## Requirements

- PHP 8.4+
- `ext-pdo_sqlite`

## Installation

```bash
composer require coquibot/coqui-toolkit-sqlite-admin
```

The toolkit registers itself automatically via Coqui's auto-discovery system. No additional configuration needed.

## Tool Reference

| Tool | Description |
|------|-------------|
| `sqlite_connect` | Open or create a SQLite database |
| `sqlite_disconnect` | Close a database connection |
| `sqlite_databases` | List all open connections |
| `sqlite_query` | Execute SQL queries with parameterized statements |
| `sqlite_schema` | Inspect database schema (tables, columns, indexes, views, triggers) |
| `sqlite_schema_modify` | Create/alter/drop tables, indexes, and views |
| `sqlite_import_export` | Import CSV/JSON, export data, generate SQL dumps |
| `sqlite_backup_restore` | Backup, restore, and clone databases |
| `sqlite_optimize` | VACUUM, ANALYZE, integrity checks, PRAGMA tuning |
| `sqlite_vector` | Vector storage with FTS5 search and cosine similarity |
| `sqlite_analyze` | Query plan analysis and index suggestions |
| `sqlite_transaction` | Transaction management with savepoint support |

## Usage Examples

### Connect and Query

```
sqlite_connect(path: "myapp.db")
sqlite_query(sql: "SELECT * FROM users WHERE active = :active", params: '{"active": 1}')
```

### Create a Table

```
sqlite_schema_modify(
    action: "create_table",
    table: "products",
    columns: '[
        {"name": "id", "type": "INTEGER", "pk": true, "autoincrement": true},
        {"name": "name", "type": "TEXT", "notnull": true},
        {"name": "price", "type": "REAL", "default": 0.0},
        {"name": "category_id", "type": "INTEGER", "references": "categories(id)"}
    ]'
)
```

### Vector Store (RAG)

```
sqlite_vector(action: "create_store", store: "knowledge_base")
sqlite_vector(action: "add", store: "knowledge_base", text: "PHP 8.4 introduces property hooks and asymmetric visibility.")
sqlite_vector(action: "add", store: "knowledge_base", text: "SQLite supports FTS5 for full-text search.", embedding: "[0.1, -0.3, ...]")
sqlite_vector(action: "search", store: "knowledge_base", text: "property hooks in PHP")
```

### Import CSV Data

```
sqlite_import_export(
    action: "import_csv",
    table: "sales",
    content: "date,amount,customer\n2024-01-15,299.99,Acme Corp\n2024-01-16,149.50,Widgets Inc"
)
```

### Backup and Optimize

```
sqlite_backup_restore(action: "backup")
sqlite_optimize(action: "vacuum")
sqlite_optimize(action: "analyze")
sqlite_analyze(action: "explain_plan", sql: "SELECT * FROM orders WHERE customer_id = 42")
```

### Transactions

```
sqlite_transaction(action: "begin")
sqlite_query(sql: "UPDATE accounts SET balance = balance - 100 WHERE id = 1")
sqlite_query(sql: "UPDATE accounts SET balance = balance + 100 WHERE id = 2")
sqlite_transaction(action: "commit")
```

## Gated Operations

The following destructive operations require user confirmation (unless `--auto-approve` is enabled):

- `sqlite_schema_modify` — `drop_table`, `drop_index`, `drop_view` actions
- `sqlite_backup_restore` — `restore` action
- `sqlite_query` — write operations (INSERT, UPDATE, DELETE, etc.)

## Path Resolution

Database paths resolve in this order:
1. `:memory:` — in-memory database
2. Absolute paths — used as-is
3. `~` prefix — expands to home directory
4. Relative paths — resolve under the workspace `databases/` directory

Allowed file extensions: `.db`, `.sqlite`, `.sqlite3`, `.sqlite-journal`, `.s3db`

## Development

```bash
# Install dependencies
composer install

# Run tests
./vendor/bin/pest

# Static analysis
./vendor/bin/phpstan analyse
```

## License

MIT
