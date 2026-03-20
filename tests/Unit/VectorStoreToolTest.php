<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CoquiBot\Toolkits\SqliteAdmin\Runtime\DatabaseManager;
use CoquiBot\Toolkits\SqliteAdmin\Tool\VectorStoreTool;

beforeEach(function () {
    $this->manager = new DatabaseManager();
    $this->manager->connect(':memory:', 'test');
    $this->tool = (new VectorStoreTool($this->manager))->build();
});

test('create_store creates vector store tables', function () {
    $result = $this->tool->execute(['action' => 'create_store', 'store' => 'documents']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('documents');

    // Verify tables created
    $db = $this->manager->getConnection('test');
    $stmt = $db->query("SELECT name FROM sqlite_master WHERE type IN ('table', 'table') ORDER BY name");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    expect($tables)->toContain('documents');
});

test('add record to store', function () {
    $this->tool->execute(['action' => 'create_store', 'store' => 'docs']);

    $result = $this->tool->execute([
        'action' => 'add',
        'store' => 'docs',
        'text' => 'The quick brown fox jumps over the lazy dog.',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('Record added');
});

test('add record with embedding', function () {
    $this->tool->execute(['action' => 'create_store', 'store' => 'docs']);

    $embedding = json_encode([0.1, 0.2, 0.3, 0.4, 0.5]);
    $result = $this->tool->execute([
        'action' => 'add',
        'store' => 'docs',
        'text' => 'Test content with embedding',
        'embedding' => $embedding,
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('5-dimensional');
});

test('add record with metadata', function () {
    $this->tool->execute(['action' => 'create_store', 'store' => 'docs']);

    $result = $this->tool->execute([
        'action' => 'add',
        'store' => 'docs',
        'text' => 'Test content with metadata',
        'metadata' => '{"source": "test.txt", "page": 1}',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('Record added');
});

test('search by text via FTS', function () {
    $this->tool->execute(['action' => 'create_store', 'store' => 'docs']);
    $this->tool->execute(['action' => 'add', 'store' => 'docs', 'text' => 'Machine learning is a subset of artificial intelligence.']);
    $this->tool->execute(['action' => 'add', 'store' => 'docs', 'text' => 'Deep learning uses neural networks with many layers.']);
    $this->tool->execute(['action' => 'add', 'store' => 'docs', 'text' => 'PHP is a server-side programming language.']);

    $result = $this->tool->execute([
        'action' => 'search',
        'store' => 'docs',
        'text' => 'machine learning',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('result');
    // FTS search should find at least one record about machine learning
    expect($result->content)->not()->toContain('No results');
});

test('search by embedding with cosine similarity', function () {
    $this->tool->execute(['action' => 'create_store', 'store' => 'docs']);

    // Add records with embeddings
    $this->tool->execute([
        'action' => 'add',
        'store' => 'docs',
        'text' => 'Record A',
        'embedding' => json_encode([1.0, 0.0, 0.0]),
    ]);
    $this->tool->execute([
        'action' => 'add',
        'store' => 'docs',
        'text' => 'Record B',
        'embedding' => json_encode([0.0, 1.0, 0.0]),
    ]);

    // Search with embedding similar to Record A
    $result = $this->tool->execute([
        'action' => 'search',
        'store' => 'docs',
        'embedding' => json_encode([0.9, 0.1, 0.0]),
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    // Record A should score higher (more similar)
    expect($result->content)->toContain('Record A');
});

test('delete record', function () {
    $this->tool->execute(['action' => 'create_store', 'store' => 'docs']);
    $addResult = $this->tool->execute([
        'action' => 'add',
        'store' => 'docs',
        'text' => 'To be deleted',
    ]);

    // Extract ID from output
    preg_match('/`([a-f0-9]{32})`/', $addResult->content, $matches);
    $id = $matches[1] ?? '';

    expect($id)->not()->toBeEmpty();

    $result = $this->tool->execute([
        'action' => 'delete',
        'store' => 'docs',
        'id' => $id,
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('deleted');
});

test('store info shows statistics', function () {
    $this->tool->execute(['action' => 'create_store', 'store' => 'docs']);
    $this->tool->execute(['action' => 'add', 'store' => 'docs', 'text' => 'First record']);
    $this->tool->execute([
        'action' => 'add',
        'store' => 'docs',
        'text' => 'Second record',
        'embedding' => json_encode([0.1, 0.2, 0.3]),
    ]);

    $result = $this->tool->execute(['action' => 'info', 'store' => 'docs']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('Total records: 2');
    expect($result->content)->toContain('With embeddings: 1');
});

test('list_stores shows all vector stores', function () {
    $this->tool->execute(['action' => 'create_store', 'store' => 'docs']);
    $this->tool->execute(['action' => 'create_store', 'store' => 'articles']);

    $result = $this->tool->execute(['action' => 'list_stores']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('docs');
    expect($result->content)->toContain('articles');
});

test('search with no results', function () {
    $this->tool->execute(['action' => 'create_store', 'store' => 'docs']);

    $result = $this->tool->execute([
        'action' => 'search',
        'store' => 'docs',
        'text' => 'nonexistent content',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('No results');
});

test('invalid store name rejected', function () {
    $result = $this->tool->execute([
        'action' => 'create_store',
        'store' => 'invalid-name!',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Error);
});

test('missing store parameter returns error', function () {
    $result = $this->tool->execute([
        'action' => 'add',
        'text' => 'some text',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('store');
});
