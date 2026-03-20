<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\SqliteAdmin\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Toolkits\SqliteAdmin\Runtime\DatabaseManager;

final readonly class OptimizeTool
{
    public function __construct(
        private DatabaseManager $manager,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'sqlite_optimize',
            description: 'Database maintenance: VACUUM to reclaim space, ANALYZE to update statistics, integrity_check for corruption detection, WAL checkpoint, REINDEX, and PRAGMA tuning recommendations.',
            parameters: [
                new EnumParameter(
                    'action',
                    'Maintenance action',
                    values: ['vacuum', 'analyze', 'integrity_check', 'wal_checkpoint', 'optimize', 'reindex', 'pragma_report'],
                    required: true,
                ),
                new StringParameter(
                    'table',
                    'Target specific table for ANALYZE or REINDEX (optional — whole database if omitted)',
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
        $database = (string) ($args['database'] ?? '');

        try {
            $db = $this->manager->resolveConnection($database);

            return match ($action) {
                'vacuum' => $this->vacuum($db),
                'analyze' => $this->analyze($db, $table),
                'integrity_check' => $this->integrityCheck($db),
                'wal_checkpoint' => $this->walCheckpoint($db),
                'optimize' => $this->optimize($db),
                'reindex' => $this->reindex($db, $table),
                'pragma_report' => $this->pragmaReport($db),
                default => ToolResult::error(sprintf('Unknown action "%s".', $action)),
            };
        } catch (\Throwable $e) {
            return ToolResult::error(sprintf('Optimize error: %s', $e->getMessage()));
        }
    }

    private function vacuum(\PDO $db): ToolResult
    {
        $sizeBefore = $this->getDatabaseSize($db);
        $db->exec('VACUUM');
        $sizeAfter = $this->getDatabaseSize($db);
        $saved = $sizeBefore - $sizeAfter;

        return ToolResult::success(sprintf(
            "VACUUM completed.\n- Before: %s\n- After: %s\n- Reclaimed: %s",
            $this->formatBytes($sizeBefore),
            $this->formatBytes($sizeAfter),
            $saved > 0 ? $this->formatBytes($saved) : '0 B (no fragmentation)',
        ));
    }

    private function analyze(\PDO $db, string $table): ToolResult
    {
        if ($table !== '') {
            $db->exec(sprintf('ANALYZE "%s"', str_replace('"', '""', $table)));

            return ToolResult::success(sprintf('ANALYZE completed for table **%s**. Query planner statistics updated.', $table));
        }

        $db->exec('ANALYZE');

        return ToolResult::success('ANALYZE completed for all tables. Query planner statistics updated.');
    }

    private function integrityCheck(\PDO $db): ToolResult
    {
        $stmt = $db->query('PRAGMA integrity_check');
        $results = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        if ($results === ['ok']) {
            return ToolResult::success('Database integrity check: **PASSED** — no issues found.');
        }

        $output = "Database integrity check: **ISSUES FOUND**\n\n";
        foreach ($results as $issue) {
            $output .= sprintf("- %s\n", $issue);
        }

        return ToolResult::success($output);
    }

    private function walCheckpoint(\PDO $db): ToolResult
    {
        $journalMode = (string) $db->query('PRAGMA journal_mode')->fetchColumn();

        if (strtolower($journalMode) !== 'wal') {
            return ToolResult::error(sprintf('Database is not in WAL mode (current: %s). WAL checkpoint requires WAL journal mode.', $journalMode));
        }

        $stmt = $db->query('PRAGMA wal_checkpoint(TRUNCATE)');
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        return ToolResult::success(sprintf(
            "WAL checkpoint completed.\n- Busy: %s\n- Pages checkpointed: %d\n- Pages in WAL: %d",
            ((int) ($result['busy'] ?? 0)) === 1 ? 'yes (some pages skipped)' : 'no',
            (int) ($result['checkpointed'] ?? 0),
            (int) ($result['log'] ?? 0),
        ));
    }

    private function optimize(\PDO $db): ToolResult
    {
        // Run SQLite's built-in optimizer
        $db->exec('PRAGMA optimize');

        return ToolResult::success('PRAGMA optimize completed. SQLite has re-analyzed tables where the query planner statistics may be stale.');
    }

    private function reindex(\PDO $db, string $table): ToolResult
    {
        if ($table !== '') {
            $db->exec(sprintf('REINDEX "%s"', str_replace('"', '""', $table)));

            return ToolResult::success(sprintf('REINDEX completed for **%s**.', $table));
        }

        $db->exec('REINDEX');

        return ToolResult::success('REINDEX completed for all indexes.');
    }

    private function pragmaReport(\PDO $db): ToolResult
    {
        $pragmas = [
            'journal_mode' => 'Journal Mode',
            'page_size' => 'Page Size',
            'cache_size' => 'Cache Size',
            'foreign_keys' => 'Foreign Keys',
            'busy_timeout' => 'Busy Timeout (ms)',
            'wal_autocheckpoint' => 'WAL Auto-checkpoint',
            'synchronous' => 'Synchronous',
            'temp_store' => 'Temp Store',
            'mmap_size' => 'Memory-mapped I/O Size',
            'auto_vacuum' => 'Auto Vacuum',
        ];

        $output = "**PRAGMA Settings:**\n\n";
        $output .= "| Setting | Value | Description |\n";
        $output .= "| --- | --- | --- |\n";

        foreach ($pragmas as $pragma => $label) {
            $value = (string) $db->query(sprintf('PRAGMA %s', $pragma))->fetchColumn();
            $output .= sprintf("| %s | `%s` | %s |\n", $pragma, $value, $label);
        }

        // Add recommendations
        $recommendations = $this->getRecommendations($db);
        if ($recommendations !== []) {
            $output .= "\n**Recommendations:**\n\n";
            foreach ($recommendations as $rec) {
                $output .= sprintf("- %s\n", $rec);
            }
        }

        return ToolResult::success($output);
    }

    /**
     * @return list<string>
     */
    private function getRecommendations(\PDO $db): array
    {
        $recs = [];

        $journalMode = strtolower((string) $db->query('PRAGMA journal_mode')->fetchColumn());
        if ($journalMode !== 'wal') {
            $recs[] = '**Enable WAL mode** (`PRAGMA journal_mode=WAL`) for better concurrent read performance.';
        }

        $foreignKeys = (int) $db->query('PRAGMA foreign_keys')->fetchColumn();
        if ($foreignKeys === 0) {
            $recs[] = '**Enable foreign keys** (`PRAGMA foreign_keys=ON`) for referential integrity enforcement.';
        }

        $busyTimeout = (int) $db->query('PRAGMA busy_timeout')->fetchColumn();
        if ($busyTimeout === 0) {
            $recs[] = '**Set busy_timeout** (`PRAGMA busy_timeout=5000`) to avoid SQLITE_BUSY errors under concurrency.';
        }

        $mmapSize = (int) $db->query('PRAGMA mmap_size')->fetchColumn();
        if ($mmapSize === 0) {
            $dbSize = $this->getDatabaseSize($db);
            if ($dbSize > 1_048_576) {
                $recs[] = sprintf('**Enable memory-mapped I/O** (`PRAGMA mmap_size=%d`) for better read performance on this %s database.', min($dbSize, 268_435_456), $this->formatBytes($dbSize));
            }
        }

        return $recs;
    }

    private function getDatabaseSize(\PDO $db): int
    {
        $pageCount = (int) $db->query('PRAGMA page_count')->fetchColumn();
        $pageSize = (int) $db->query('PRAGMA page_size')->fetchColumn();

        return $pageCount * $pageSize;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1_048_576) {
            return sprintf('%.1f KB', $bytes / 1024);
        }

        return sprintf('%.1f MB', $bytes / 1_048_576);
    }
}
