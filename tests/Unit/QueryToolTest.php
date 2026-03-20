<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CoquiBot\Toolkits\SqliteAdmin\Runtime\DatabaseManager;
use CoquiBot\Toolkits\SqliteAdmin\Tool\QueryTool;

beforeEach(function () {
    $this->manager = new DatabaseManager();
    $this->manager->connect(':memory:', 'test');

    // Create a test table with data
    $db = $this->manager->getConnection('test');
    $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, email TEXT, age INTEGER)');
    $db->exec("INSERT INTO users (name, email, age) VALUES ('Alice', 'alice@test.com', 30)");
    $db->exec("INSERT INTO users (name, email, age) VALUES ('Bob', 'bob@test.com', 25)");
    $db->exec("INSERT INTO users (name, email, age) VALUES ('Charlie', 'charlie@test.com', 35)");

    $this->tool = (new QueryTool($this->manager))->build();
});

test('SELECT query returns markdown table', function () {
    $result = $this->tool->execute(['sql' => 'SELECT * FROM users ORDER BY id']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('Alice');
    expect($result->content)->toContain('Bob');
    expect($result->content)->toContain('Charlie');
    expect($result->content)->toContain('3 rows');
});

test('SELECT with WHERE clause', function () {
    $result = $this->tool->execute(['sql' => 'SELECT name FROM users WHERE age > 28']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('Alice');
    expect($result->content)->toContain('Charlie');
    expect($result->content)->not()->toContain('Bob');
});

test('INSERT returns affected rows', function () {
    $result = $this->tool->execute([
        'sql' => "INSERT INTO users (name, email, age) VALUES ('Dave', 'dave@test.com', 40)",
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('Rows affected: 1');
});

test('parameterized query with params', function () {
    $result = $this->tool->execute([
        'sql' => 'SELECT name FROM users WHERE age = :age',
        'params' => '{"age": 30}',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('Alice');
});

test('empty result set', function () {
    $result = $this->tool->execute([
        'sql' => 'SELECT * FROM users WHERE age > 100',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('No results');
});

test('UPDATE returns affected count', function () {
    $result = $this->tool->execute([
        'sql' => "UPDATE users SET age = 31 WHERE name = 'Alice'",
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);

    // Verify the update
    $verify = $this->tool->execute([
        'sql' => "SELECT age FROM users WHERE name = 'Alice'",
    ]);
    expect($verify->content)->toContain('31');
});

test('DELETE returns affected count', function () {
    $result = $this->tool->execute([
        'sql' => "DELETE FROM users WHERE name = 'Bob'",
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);

    // Verify deletion
    $verify = $this->tool->execute([
        'sql' => 'SELECT COUNT(*) as cnt FROM users',
    ]);
    expect($verify->content)->toContain('2');
});

test('invalid SQL returns error', function () {
    $result = $this->tool->execute([
        'sql' => 'SELECT * FROM nonexistent_table',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Error);
});

test('missing sql parameter returns error', function () {
    $result = $this->tool->execute([]);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('sql');
});

test('COUNT query returns result', function () {
    $result = $this->tool->execute([
        'sql' => 'SELECT COUNT(*) as total FROM users',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('3');
});
