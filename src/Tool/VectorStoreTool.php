<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\SqliteAdmin\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Toolkits\SqliteAdmin\Runtime\DatabaseManager;

final class VectorStoreTool
{
    private const string SCHEMA_SQL = <<<'SQL'
        CREATE TABLE IF NOT EXISTS "%s" (
            id TEXT PRIMARY KEY,
            content TEXT NOT NULL,
            embedding BLOB,
            metadata TEXT DEFAULT '{}',
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )
    SQL;

    private const string FTS_SQL = <<<'SQL'
        CREATE VIRTUAL TABLE IF NOT EXISTS "%s_fts" USING fts5(
            content,
            content='',
            tokenize='porter unicode61'
        )
    SQL;

    private const string FTS_LOOKUP_SQL = <<<'SQL'
        CREATE TABLE IF NOT EXISTS "%s_fts_lookup" (
            rowid INTEGER PRIMARY KEY,
            vector_id TEXT NOT NULL
        )
    SQL;

    public function __construct(
        private readonly DatabaseManager $manager,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'sqlite_vector',
            description: 'Vector storage and similarity search in SQLite. Create vector stores, add text with embeddings, and perform semantic similarity search. Supports both pre-computed embeddings (float arrays) and automatic text embedding when a provider is configured. Also provides FTS5 full-text search fallback.',
            parameters: [
                new EnumParameter(
                    'action',
                    'Vector store action',
                    values: ['create_store', 'add', 'search', 'delete', 'info', 'list_stores'],
                    required: true,
                ),
                new StringParameter(
                    'store',
                    'Vector store name (used as table name)',
                    required: false,
                ),
                new StringParameter(
                    'text',
                    'Text content to store or search for',
                    required: false,
                ),
                new StringParameter(
                    'embedding',
                    'JSON array of floats for pre-computed embedding vector. Example: [0.1, -0.3, 0.5, ...]',
                    required: false,
                ),
                new NumberParameter(
                    'limit',
                    'Maximum number of results to return (default: 10)',
                    required: false,
                ),
                new NumberParameter(
                    'threshold',
                    'Minimum similarity score 0.0-1.0 (default: 0.0)',
                    required: false,
                ),
                new StringParameter(
                    'metadata',
                    'JSON metadata to attach to the record. Example: {"source": "file.txt", "page": 3}',
                    required: false,
                ),
                new StringParameter(
                    'id',
                    'Record ID for delete operations',
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
        $store = (string) ($args['store'] ?? '');
        $database = (string) ($args['database'] ?? '');

        try {
            $db = $this->manager->resolveConnection($database);

            return match ($action) {
                'create_store' => $this->createStore($db, $store),
                'add' => $this->addRecord($db, $store, $args),
                'search' => $this->search($db, $store, $args),
                'delete' => $this->deleteRecord($db, $store, $args),
                'info' => $this->storeInfo($db, $store),
                'list_stores' => $this->listStores($db),
                default => ToolResult::error(sprintf('Unknown action "%s".', $action)),
            };
        } catch (\Throwable $e) {
            return ToolResult::error(sprintf('Vector store error: %s', $e->getMessage()));
        }
    }

    private function createStore(\PDO $db, string $store): ToolResult
    {
        if ($store === '') {
            return ToolResult::error('Parameter "store" is required for create_store.');
        }

        $this->validateStoreName($store);

        $db->exec(sprintf(self::SCHEMA_SQL, $store));
        $db->exec(sprintf(self::FTS_SQL, $store));
        $db->exec(sprintf(self::FTS_LOOKUP_SQL, $store));

        return ToolResult::success(sprintf(
            'Vector store **%s** created with FTS5 full-text search support. Add records with `sqlite_vector(action: "add", store: "%s", text: "...")`.',
            $store,
            $store,
        ));
    }

    /**
     * @param array<string, mixed> $args
     */
    private function addRecord(\PDO $db, string $store, array $args): ToolResult
    {
        if ($store === '') {
            return ToolResult::error('Parameter "store" is required.');
        }

        $text = (string) ($args['text'] ?? '');
        $embeddingJson = (string) ($args['embedding'] ?? '');
        $metadataJson = (string) ($args['metadata'] ?? '{}');

        if ($text === '') {
            return ToolResult::error('Parameter "text" is required for adding records.');
        }

        $id = bin2hex(random_bytes(16));
        $embeddingBlob = null;

        // Use pre-computed embedding if provided
        if ($embeddingJson !== '') {
            $embedding = json_decode($embeddingJson, true);
            if (is_array($embedding) && $embedding !== []) {
                $embeddingBlob = pack('f*', ...array_map('floatval', $embedding));
            }
        }

        // Insert main record
        $stmt = $db->prepare(sprintf(
            'INSERT INTO "%s" (id, content, embedding, metadata) VALUES (:id, :content, :embedding, :metadata)',
            $store,
        ));
        $stmt->execute([
            'id' => $id,
            'content' => $text,
            'embedding' => $embeddingBlob,
            'metadata' => $metadataJson,
        ]);

        // Insert into FTS
        $this->insertFts($db, $store, $id, $text);

        $embeddingInfo = $embeddingBlob !== null
            ? sprintf(', %d-dimensional embedding stored', (int) (strlen($embeddingBlob) / 4))
            : ', no embedding (FTS5 only)';

        return ToolResult::success(sprintf('Record added to **%s**: `%s`%s.', $store, $id, $embeddingInfo));
    }

    /**
     * @param array<string, mixed> $args
     */
    private function search(\PDO $db, string $store, array $args): ToolResult
    {
        if ($store === '') {
            return ToolResult::error('Parameter "store" is required.');
        }

        $text = (string) ($args['text'] ?? '');
        $embeddingJson = (string) ($args['embedding'] ?? '');
        $limit = (int) ($args['limit'] ?? 10);
        $threshold = (float) ($args['threshold'] ?? 0.0);

        if ($text === '' && $embeddingJson === '') {
            return ToolResult::error('Either "text" (for FTS/vector search) or "embedding" (for pure vector search) is required.');
        }

        $results = [];

        // Vector search if embedding provided
        if ($embeddingJson !== '') {
            $queryEmbedding = json_decode($embeddingJson, true);
            if (is_array($queryEmbedding) && $queryEmbedding !== []) {
                $results = $this->vectorSearch($db, $store, $queryEmbedding, $limit, $threshold);
            }
        }

        // FTS5 search on text
        if ($text !== '' && count($results) < $limit) {
            $ftsResults = $this->ftsSearch($db, $store, $text, $limit);
            $results = $this->mergeResults($results, $ftsResults, $limit);
        }

        if ($results === []) {
            return ToolResult::success(sprintf('No results found in **%s** for the given query.', $store));
        }

        $output = sprintf("**%d result%s from %s:**\n\n", count($results), count($results) === 1 ? '' : 's', $store);
        $output .= "| # | Score | ID | Content | Metadata |\n";
        $output .= "| --- | --- | --- | --- | --- |\n";

        foreach ($results as $i => $result) {
            $content = mb_strlen($result['content']) > 80
                ? mb_substr($result['content'], 0, 77) . '...'
                : $result['content'];
            $content = str_replace(['|', "\n"], ['\\|', ' '], $content);

            $output .= sprintf(
                "| %d | %.3f | `%s` | %s | %s |\n",
                $i + 1,
                $result['score'],
                mb_substr($result['id'], 0, 12) . '...',
                $content,
                $result['metadata'] !== '{}' ? '`' . mb_substr($result['metadata'], 0, 40) . '`' : '',
            );
        }

        return ToolResult::success($output);
    }

    /**
     * @param array<string, mixed> $args
     */
    private function deleteRecord(\PDO $db, string $store, array $args): ToolResult
    {
        if ($store === '') {
            return ToolResult::error('Parameter "store" is required.');
        }

        $id = (string) ($args['id'] ?? '');
        if ($id === '') {
            return ToolResult::error('Parameter "id" is required for delete.');
        }

        // Get content for FTS deletion
        $stmt = $db->prepare(sprintf('SELECT content FROM "%s" WHERE id = :id', $store));
        $stmt->execute(['id' => $id]);
        $content = $stmt->fetchColumn();

        if ($content === false) {
            return ToolResult::error(sprintf('Record "%s" not found in store "%s".', $id, $store));
        }

        // Delete from FTS
        $this->deleteFts($db, $store, $id, (string) $content);

        // Delete main record
        $stmt = $db->prepare(sprintf('DELETE FROM "%s" WHERE id = :id', $store));
        $stmt->execute(['id' => $id]);

        return ToolResult::success(sprintf('Record `%s` deleted from **%s**.', $id, $store));
    }

    private function storeInfo(\PDO $db, string $store): ToolResult
    {
        if ($store === '') {
            return ToolResult::error('Parameter "store" is required.');
        }

        $countStmt = $db->query(sprintf('SELECT COUNT(*) FROM "%s"', $store));
        $totalCount = (int) $countStmt->fetchColumn();

        $embCountStmt = $db->query(sprintf('SELECT COUNT(*) FROM "%s" WHERE embedding IS NOT NULL', $store));
        $embCount = (int) $embCountStmt->fetchColumn();

        // Get dimensions from first embedding
        $dimensions = 0;
        if ($embCount > 0) {
            $embStmt = $db->query(sprintf('SELECT embedding FROM "%s" WHERE embedding IS NOT NULL LIMIT 1', $store));
            $embBlob = $embStmt->fetchColumn();
            if (is_string($embBlob)) {
                $dimensions = (int) (strlen($embBlob) / 4);
            }
        }

        $output = sprintf("**Vector store: %s**\n\n", $store);
        $output .= sprintf("- Total records: %d\n", $totalCount);
        $output .= sprintf("- With embeddings: %d\n", $embCount);
        $output .= sprintf("- Without embeddings (FTS only): %d\n", $totalCount - $embCount);
        if ($dimensions > 0) {
            $output .= sprintf("- Embedding dimensions: %d\n", $dimensions);
        }

        return ToolResult::success($output);
    }

    private function listStores(\PDO $db): ToolResult
    {
        // Find tables that have the vector store schema (content + embedding columns)
        $stmt = $db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' AND name NOT LIKE '%_fts%' ORDER BY name");
        $tables = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $stores = [];
        foreach ($tables as $table) {
            $infoStmt = $db->query(sprintf('PRAGMA table_info("%s")', str_replace('"', '""', $table)));
            $columns = $infoStmt->fetchAll(\PDO::FETCH_ASSOC);
            $colNames = array_column($columns, 'name');

            if (in_array('content', $colNames, true) && in_array('embedding', $colNames, true)) {
                $countStmt = $db->query(sprintf('SELECT COUNT(*) FROM "%s"', str_replace('"', '""', $table)));
                $stores[] = [
                    'name' => $table,
                    'records' => (int) $countStmt->fetchColumn(),
                ];
            }
        }

        if ($stores === []) {
            return ToolResult::success('No vector stores found. Create one with `sqlite_vector(action: "create_store", store: "my_store")`.');
        }

        $output = sprintf("**%d vector store%s:**\n\n", count($stores), count($stores) === 1 ? '' : 's');
        $output .= "| Store | Records |\n| --- | --- |\n";
        foreach ($stores as $s) {
            $output .= sprintf("| %s | %d |\n", $s['name'], $s['records']);
        }

        return ToolResult::success($output);
    }

    /**
     * @param list<float> $queryEmbedding
     * @return list<array{id: string, content: string, metadata: string, score: float}>
     */
    private function vectorSearch(\PDO $db, string $store, array $queryEmbedding, int $limit, float $threshold): array
    {
        $stmt = $db->query(sprintf('SELECT id, content, embedding, metadata FROM "%s" WHERE embedding IS NOT NULL', $store));
        $results = [];

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $storedEmbedding = unpack('f*', $row['embedding']);
            if ($storedEmbedding === false) {
                continue;
            }
            $storedEmbedding = array_values($storedEmbedding);

            $score = $this->cosineSimilarity($queryEmbedding, $storedEmbedding);
            if ($score >= $threshold) {
                $results[] = [
                    'id' => $row['id'],
                    'content' => $row['content'],
                    'metadata' => $row['metadata'] ?? '{}',
                    'score' => $score,
                ];
            }
        }

        usort($results, fn(array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($results, 0, $limit);
    }

    /**
     * @return list<array{id: string, content: string, metadata: string, score: float}>
     */
    private function ftsSearch(\PDO $db, string $store, string $query, int $limit): array
    {
        $sanitized = $this->sanitizeFtsQuery($query);

        try {
            $stmt = $db->prepare(sprintf(
                'SELECT l.vector_id, f.rank FROM "%s_fts" f JOIN "%s_fts_lookup" l ON l.rowid = f.rowid WHERE "%s_fts" MATCH :query ORDER BY f.rank LIMIT :limit',
                $store,
                $store,
                $store,
            ));
            $stmt->execute(['query' => $sanitized, 'limit' => $limit]);
            $ftsRows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            // FTS syntax error — fall back to LIKE
            return $this->likeSearch($db, $store, $query, $limit);
        }

        if ($ftsRows === []) {
            return $this->likeSearch($db, $store, $query, $limit);
        }

        $results = [];
        foreach ($ftsRows as $ftsRow) {
            $main = $db->prepare(sprintf('SELECT id, content, metadata FROM "%s" WHERE id = :id', $store));
            $main->execute(['id' => $ftsRow['vector_id']]);
            $row = $main->fetch(\PDO::FETCH_ASSOC);

            if ($row !== false) {
                // Normalize FTS rank to 0..1 range (rank is negative, lower = better)
                $score = 1.0 / (1.0 + abs((float) $ftsRow['rank']));
                $results[] = [
                    'id' => $row['id'],
                    'content' => $row['content'],
                    'metadata' => $row['metadata'] ?? '{}',
                    'score' => $score,
                ];
            }
        }

        return $results;
    }

    /**
     * @return list<array{id: string, content: string, metadata: string, score: float}>
     */
    private function likeSearch(\PDO $db, string $store, string $query, int $limit): array
    {
        $stmt = $db->prepare(sprintf(
            'SELECT id, content, metadata FROM "%s" WHERE content LIKE :query LIMIT :limit',
            $store,
        ));
        $stmt->execute(['query' => '%' . $query . '%', 'limit' => $limit]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $results = [];
        foreach ($rows as $i => $row) {
            $results[] = [
                'id' => $row['id'],
                'content' => $row['content'],
                'metadata' => $row['metadata'] ?? '{}',
                'score' => 0.5 - ($i * 0.01), // Diminishing score to indicate fallback ordering
            ];
        }

        return $results;
    }

    /**
     * @param list<array{id: string, content: string, metadata: string, score: float}> $primary
     * @param list<array{id: string, content: string, metadata: string, score: float}> $secondary
     * @return list<array{id: string, content: string, metadata: string, score: float}>
     */
    private function mergeResults(array $primary, array $secondary, int $limit): array
    {
        $seen = [];
        foreach ($primary as $item) {
            $seen[$item['id']] = true;
        }

        foreach ($secondary as $item) {
            if (!isset($seen[$item['id']])) {
                $primary[] = $item;
                $seen[$item['id']] = true;
            }
        }

        usort($primary, fn(array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($primary, 0, $limit);
    }

    private function insertFts(\PDO $db, string $store, string $id, string $content): void
    {
        try {
            $stmt = $db->prepare(sprintf('INSERT INTO "%s_fts" (content) VALUES (:content)', $store));
            $stmt->execute(['content' => $content]);

            $rowid = $db->lastInsertId();
            $stmt = $db->prepare(sprintf('INSERT INTO "%s_fts_lookup" (rowid, vector_id) VALUES (:rowid, :id)', $store));
            $stmt->execute(['rowid' => $rowid, 'id' => $id]);
        } catch (\PDOException) {
            // FTS table might not exist — non-fatal
        }
    }

    private function deleteFts(\PDO $db, string $store, string $id, string $content): void
    {
        try {
            // Find the FTS rowid via lookup
            $stmt = $db->prepare(sprintf('SELECT rowid FROM "%s_fts_lookup" WHERE vector_id = :id', $store));
            $stmt->execute(['id' => $id]);
            $rowid = $stmt->fetchColumn();

            if ($rowid !== false) {
                // Delete from FTS — contentless tables use INSERT with special delete command
                $db->prepare(sprintf('INSERT INTO "%s_fts" ("%s_fts", rowid, content) VALUES (\'delete\', :rowid, :content)', $store, $store))
                    ->execute(['rowid' => $rowid, 'content' => $content]);

                $db->prepare(sprintf('DELETE FROM "%s_fts_lookup" WHERE rowid = :rowid', $store))
                    ->execute(['rowid' => $rowid]);
            }
        } catch (\PDOException) {
            // Non-fatal
        }
    }

    private function validateStoreName(string $name): void
    {
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid store name "%s". Must be alphanumeric with underscores, starting with a letter.', $name));
        }
    }

    /**
     * @param list<float> $a
     * @param list<float> $b
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        $len = min(count($a), count($b));
        for ($i = 0; $i < $len; $i++) {
            $dotProduct += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        $denominator = sqrt($normA) * sqrt($normB);

        return $denominator > 0.0 ? $dotProduct / $denominator : 0.0;
    }

    private function sanitizeFtsQuery(string $query): string
    {
        // Strip FTS5 special characters
        $clean = preg_replace('/[*"()\-{}^~:+]/', ' ', $query) ?? $query;
        $words = array_filter(explode(' ', $clean), fn(string $w): bool => trim($w) !== '');

        if ($words === []) {
            return '""';
        }

        // Wrap in quotes and join with OR
        return implode(' OR ', array_map(fn(string $w): string => '"' . trim($w) . '"', $words));
    }
}
