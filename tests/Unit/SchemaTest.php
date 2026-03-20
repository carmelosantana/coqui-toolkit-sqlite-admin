<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CoquiBot\Toolkits\SqliteAdmin\Runtime\DatabaseManager;
use CoquiBot\Toolkits\SqliteAdmin\Runtime\SchemaInspector;
use CoquiBot\Toolkits\SqliteAdmin\Tool\SchemaTool;
use CoquiBot\Toolkits\SqliteAdmin\Tool\SchemaModifyTool;

beforeEach(function () {
    $this->manager = new DatabaseManager();
    $this->manager->connect(':memory:', 'test');
    $this->inspector = new SchemaInspector();

    $db = $this->manager->getConnection('test');
    $db->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT NOT NULL, price REAL, category TEXT)');
    $db->exec('CREATE INDEX idx_products_category ON products (category)');
    $db->exec("INSERT INTO products (name, price, category) VALUES ('Widget', 9.99, 'tools')");
});

test('schema tables action lists tables', function () {
    $tool = (new SchemaTool($this->manager, $this->inspector))->build();
    $result = $tool->execute(['action' => 'tables']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('products');
});

test('schema describe shows columns', function () {
    $tool = (new SchemaTool($this->manager, $this->inspector))->build();
    $result = $tool->execute(['action' => 'describe', 'table' => 'products']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('name');
    expect($result->content)->toContain('price');
    expect($result->content)->toContain('category');
});

test('schema indexes shows index info', function () {
    $tool = (new SchemaTool($this->manager, $this->inspector))->build();
    $result = $tool->execute(['action' => 'indexes', 'table' => 'products']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('idx_products_category');
});

test('schema_modify create_table creates new table', function () {
    $tool = (new SchemaModifyTool($this->manager))->build();
    $result = $tool->execute([
        'action' => 'create_table',
        'table' => 'orders',
        'definition' => '[{"name": "id", "type": "INTEGER", "pk": true}, {"name": "product_id", "type": "INTEGER", "notnull": true}, {"name": "quantity", "type": "INTEGER", "default": 1}]',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('orders');

    // Verify table exists
    $db = $this->manager->getConnection('test');
    $stmt = $db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'orders'");
    expect($stmt->fetchColumn())->toBe('orders');
});

test('schema_modify add_column adds column', function () {
    $tool = (new SchemaModifyTool($this->manager))->build();
    $result = $tool->execute([
        'action' => 'add_column',
        'table' => 'products',
        'definition' => 'description TEXT',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);

    // Verify column exists
    $db = $this->manager->getConnection('test');
    $stmt = $db->query("PRAGMA table_info('products')");
    $columns = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'name');
    expect($columns)->toContain('description');
});

test('schema_modify create_index creates index', function () {
    $tool = (new SchemaModifyTool($this->manager))->build();
    $result = $tool->execute([
        'action' => 'create_index',
        'table' => 'products',
        'definition' => 'name',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('Index');
});

test('schema_modify drop_table drops table', function () {
    $tool = (new SchemaModifyTool($this->manager))->build();

    // Create a throwaway table
    $db = $this->manager->getConnection('test');
    $db->exec('CREATE TABLE temp_table (id INTEGER)');

    $result = $tool->execute([
        'action' => 'drop_table',
        'table' => 'temp_table',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);

    $stmt = $db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'temp_table'");
    expect($stmt->fetchColumn())->toBeFalse();
});
