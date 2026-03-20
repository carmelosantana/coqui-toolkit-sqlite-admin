<?php

declare(strict_types=1);

use CoquiBot\Toolkits\SqliteAdmin\SqliteAdminToolkit;

test('provides 12 tools', function () {
    $toolkit = new SqliteAdminToolkit();
    $tools = $toolkit->tools();

    expect($tools)->toHaveCount(12);
});

test('all tools have unique names', function () {
    $toolkit = new SqliteAdminToolkit();
    $names = array_map(fn($tool) => $tool->name(), $toolkit->tools());

    expect($names)->toEqual(array_unique($names));
});

test('expected tool names are present', function () {
    $toolkit = new SqliteAdminToolkit();
    $names = array_map(fn($tool) => $tool->name(), $toolkit->tools());

    expect($names)->toContain(
        'sqlite_connect',
        'sqlite_disconnect',
        'sqlite_databases',
        'sqlite_query',
        'sqlite_schema',
        'sqlite_schema_modify',
        'sqlite_import_export',
        'sqlite_backup_restore',
        'sqlite_optimize',
        'sqlite_vector',
        'sqlite_analyze',
        'sqlite_transaction',
    );
});

test('all tools have descriptions', function () {
    $toolkit = new SqliteAdminToolkit();

    foreach ($toolkit->tools() as $tool) {
        expect($tool->description())->not()->toBeEmpty(
            sprintf('Tool "%s" has no description', $tool->name()),
        );
    }
});

test('most tools have parameters', function () {
    $toolkit = new SqliteAdminToolkit();
    $noParamTools = ['sqlite_databases'];

    foreach ($toolkit->tools() as $tool) {
        if (in_array($tool->name(), $noParamTools, true)) {
            continue;
        }
        expect($tool->parameters())->not()->toBeEmpty(
            sprintf('Tool "%s" has no parameters', $tool->name()),
        );
    }
});

test('guidelines contain XML wrapper', function () {
    $toolkit = new SqliteAdminToolkit();
    $guidelines = $toolkit->guidelines();

    expect($guidelines)
        ->toContain('<sqlite_admin_guidelines>')
        ->toContain('</sqlite_admin_guidelines>');
});

test('guidelines cover key workflows', function () {
    $toolkit = new SqliteAdminToolkit();
    $guidelines = $toolkit->guidelines();

    expect($guidelines)
        ->toContain('Connection Workflow')
        ->toContain('Query Execution')
        ->toContain('Schema Operations')
        ->toContain('Transactions')
        ->toContain('Vector Store')
        ->toContain('Backup')
        ->toContain('Import/Export');
});

test('fromEnv factory creates valid toolkit', function () {
    $toolkit = SqliteAdminToolkit::fromEnv();

    expect($toolkit)->toBeInstanceOf(SqliteAdminToolkit::class);
    expect($toolkit->tools())->toHaveCount(12);
});
