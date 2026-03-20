<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CoquiBot\Toolkits\SqliteAdmin\Runtime\DatabaseManager;
use CoquiBot\Toolkits\SqliteAdmin\Tool\TransactionTool;

beforeEach(function () {
    $this->manager = new DatabaseManager();
    $this->manager->connect(':memory:', 'test');

    $db = $this->manager->getConnection('test');
    $db->exec('CREATE TABLE counter (id INTEGER PRIMARY KEY, value INTEGER NOT NULL)');
    $db->exec('INSERT INTO counter (value) VALUES (0)');

    $this->tool = (new TransactionTool($this->manager))->build();
});

test('begin transaction', function () {
    $result = $this->tool->execute(['action' => 'begin']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('Transaction started');
    expect($this->manager->hasTransaction('test'))->toBeTrue();
});

test('commit transaction persists changes', function () {
    $db = $this->manager->getConnection('test');

    $this->tool->execute(['action' => 'begin']);
    $db->exec('UPDATE counter SET value = 42 WHERE id = 1');
    $result = $this->tool->execute(['action' => 'commit']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('committed');
    expect($this->manager->hasTransaction('test'))->toBeFalse();

    $value = (int) $db->query('SELECT value FROM counter WHERE id = 1')->fetchColumn();
    expect($value)->toBe(42);
});

test('rollback transaction discards changes', function () {
    $db = $this->manager->getConnection('test');

    $this->tool->execute(['action' => 'begin']);
    $db->exec('UPDATE counter SET value = 99 WHERE id = 1');
    $result = $this->tool->execute(['action' => 'rollback']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('rolled back');
    expect($this->manager->hasTransaction('test'))->toBeFalse();

    $value = (int) $db->query('SELECT value FROM counter WHERE id = 1')->fetchColumn();
    expect($value)->toBe(0);
});

test('double begin returns error', function () {
    $this->tool->execute(['action' => 'begin']);
    $result = $this->tool->execute(['action' => 'begin']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('already has an active transaction');
});

test('commit without begin returns error', function () {
    $result = $this->tool->execute(['action' => 'commit']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('No active transaction');
});

test('rollback without begin returns error', function () {
    $result = $this->tool->execute(['action' => 'rollback']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('No active transaction');
});

test('savepoint and release', function () {
    $this->tool->execute(['action' => 'begin']);

    $result = $this->tool->execute(['action' => 'savepoint', 'name' => 'sp1']);
    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('sp1');

    $result = $this->tool->execute(['action' => 'release', 'name' => 'sp1']);
    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('released');

    $this->tool->execute(['action' => 'commit']);
});

test('rollback to savepoint', function () {
    $db = $this->manager->getConnection('test');

    $this->tool->execute(['action' => 'begin']);
    $db->exec('UPDATE counter SET value = 10 WHERE id = 1');

    $this->tool->execute(['action' => 'savepoint', 'name' => 'sp1']);
    $db->exec('UPDATE counter SET value = 20 WHERE id = 1');

    $this->tool->execute(['action' => 'rollback', 'name' => 'sp1']);

    // Value should be before savepoint
    $value = (int) $db->query('SELECT value FROM counter WHERE id = 1')->fetchColumn();
    expect($value)->toBe(10);

    $this->tool->execute(['action' => 'commit']);
});

test('savepoint without name returns error', function () {
    $result = $this->tool->execute(['action' => 'savepoint']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('name');
});

test('status reports no active transaction', function () {
    $result = $this->tool->execute(['action' => 'status']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('no active transaction');
});

test('status reports active transaction', function () {
    $this->tool->execute(['action' => 'begin']);
    $result = $this->tool->execute(['action' => 'status']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('active');
});
