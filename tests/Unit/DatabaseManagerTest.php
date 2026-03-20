<?php

declare(strict_types=1);

use CoquiBot\Toolkits\SqliteAdmin\Runtime\DatabaseManager;
use CoquiBot\Toolkits\SqliteAdmin\Exception\SqliteAdminException;

test('connect to in-memory database', function () {
    $manager = new DatabaseManager();
    $alias = $manager->connect(':memory:');

    expect($alias)->toBe('memory');
    expect($manager->activeAlias())->toBe('memory');
});

test('connect with custom alias', function () {
    $manager = new DatabaseManager();
    $alias = $manager->connect(':memory:', 'testdb');

    expect($alias)->toBe('testdb');
});

test('disconnect removes connection', function () {
    $manager = new DatabaseManager();
    $manager->connect(':memory:', 'db1');

    $manager->disconnect('db1');

    expect($manager->list())->toBeEmpty();
});

test('disconnect active switches to another', function () {
    $manager = new DatabaseManager();
    $manager->connect(':memory:', 'db1');
    $manager->connect(':memory:', 'db2');

    expect($manager->activeAlias())->toBe('db2');

    $manager->disconnect('db2');

    expect($manager->activeAlias())->toBe('db1');
});

test('switch active database', function () {
    $manager = new DatabaseManager();
    $manager->connect(':memory:', 'db1');
    $manager->connect(':memory:', 'db2');

    $manager->switchTo('db1');

    expect($manager->activeAlias())->toBe('db1');
});

test('switch to unknown alias throws', function () {
    $manager = new DatabaseManager();
    $manager->connect(':memory:', 'db1');

    $manager->switchTo('nonexistent');
})->throws(SqliteAdminException::class);

test('max connections enforced', function () {
    $manager = new DatabaseManager();

    for ($i = 0; $i < 10; $i++) {
        $manager->connect(':memory:', 'db' . $i);
    }

    $manager->connect(':memory:', 'db10');
})->throws(SqliteAdminException::class, 'Maximum');

test('resolveConnection returns active PDO', function () {
    $manager = new DatabaseManager();
    $manager->connect(':memory:', 'test');

    $pdo = $manager->resolveConnection('');

    expect($pdo)->toBeInstanceOf(PDO::class);
});

test('resolveConnection with alias returns specified PDO', function () {
    $manager = new DatabaseManager();
    $manager->connect(':memory:', 'db1');
    $manager->connect(':memory:', 'db2');

    $pdo = $manager->resolveConnection('db1');

    expect($pdo)->toBeInstanceOf(PDO::class);
});

test('resolveConnection with no connections throws', function () {
    $manager = new DatabaseManager();

    $manager->resolveConnection('');
})->throws(SqliteAdminException::class);

test('transaction tracking', function () {
    $manager = new DatabaseManager();
    $manager->connect(':memory:', 'test');

    expect($manager->hasTransaction('test'))->toBeFalse();

    $manager->beginTransaction('test');
    expect($manager->hasTransaction('test'))->toBeTrue();

    $manager->endTransaction('test');
    expect($manager->hasTransaction('test'))->toBeFalse();
});

test('list returns connection info', function () {
    $manager = new DatabaseManager();
    $manager->connect(':memory:', 'test');

    $list = $manager->list();

    expect($list)->toHaveCount(1);
    expect($list[0]['alias'])->toBe('test');
    expect($list[0]['active'])->toBeTrue();
});

test('getPath returns database path', function () {
    $manager = new DatabaseManager();
    $manager->connect(':memory:', 'test');

    expect($manager->getPath('test'))->toBe(':memory:');
});

test('fromEnv creates instance', function () {
    $manager = DatabaseManager::fromEnv();

    expect($manager)->toBeInstanceOf(DatabaseManager::class);
});

test('connect to file-based database', function () {
    $tmpDir = sys_get_temp_dir() . '/sqlite-admin-test-' . uniqid();
    mkdir($tmpDir, 0755, true);
    $dbPath = $tmpDir . '/test.db';

    try {
        $manager = new DatabaseManager($tmpDir);
        $alias = $manager->connect($dbPath);

        expect($alias)->toBe('test');
        expect(file_exists($dbPath))->toBeTrue();

        $manager->disconnect($alias);
    } finally {
        @unlink($dbPath);
        @rmdir($tmpDir);
    }
});
