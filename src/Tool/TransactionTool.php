<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\SqliteAdmin\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Toolkits\SqliteAdmin\Runtime\DatabaseManager;

final readonly class TransactionTool
{
    public function __construct(
        private DatabaseManager $manager,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'sqlite_transaction',
            description: 'Manage database transactions: BEGIN, COMMIT, ROLLBACK. Also supports named savepoints for nested transaction-like behavior. Ensure all write operations within a logical unit are wrapped in a transaction for atomicity.',
            parameters: [
                new EnumParameter(
                    'action',
                    'Transaction action',
                    values: ['begin', 'commit', 'rollback', 'savepoint', 'release', 'status'],
                    required: true,
                ),
                new StringParameter(
                    'name',
                    'Savepoint name (required for savepoint and release actions)',
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
        $name = (string) ($args['name'] ?? '');
        $database = (string) ($args['database'] ?? '');

        try {
            $alias = $this->manager->resolveAlias($database);
            $db = $this->manager->getConnection($alias);

            return match ($action) {
                'begin' => $this->begin($db, $alias),
                'commit' => $this->commit($db, $alias),
                'rollback' => $this->rollback($db, $alias, $name),
                'savepoint' => $this->savepoint($db, $name),
                'release' => $this->release($db, $name),
                'status' => $this->status($alias),
                default => ToolResult::error(sprintf('Unknown action "%s".', $action)),
            };
        } catch (\Throwable $e) {
            return ToolResult::error(sprintf('Transaction error: %s', $e->getMessage()));
        }
    }

    private function begin(\PDO $db, string $alias): ToolResult
    {
        if ($this->manager->hasTransaction($alias)) {
            return ToolResult::error(sprintf('Database **%s** already has an active transaction. COMMIT or ROLLBACK first, or use savepoints for nesting.', $alias));
        }

        $db->beginTransaction();
        $this->manager->beginTransaction($alias);

        return ToolResult::success(sprintf('Transaction started on **%s**. Use COMMIT to save or ROLLBACK to discard changes.', $alias));
    }

    private function commit(\PDO $db, string $alias): ToolResult
    {
        if (!$this->manager->hasTransaction($alias)) {
            return ToolResult::error(sprintf('No active transaction on **%s**.', $alias));
        }

        $db->commit();
        $this->manager->endTransaction($alias);

        return ToolResult::success(sprintf('Transaction committed on **%s**. All changes saved.', $alias));
    }

    private function rollback(\PDO $db, string $alias, string $savepointName): ToolResult
    {
        if ($savepointName !== '') {
            $db->exec(sprintf('ROLLBACK TO SAVEPOINT "%s"', str_replace('"', '""', $savepointName)));

            return ToolResult::success(sprintf('Rolled back to savepoint **%s** on **%s**.', $savepointName, $alias));
        }

        if (!$this->manager->hasTransaction($alias)) {
            return ToolResult::error(sprintf('No active transaction on **%s**.', $alias));
        }

        $db->rollBack();
        $this->manager->endTransaction($alias);

        return ToolResult::success(sprintf('Transaction rolled back on **%s**. All changes discarded.', $alias));
    }

    private function savepoint(\PDO $db, string $name): ToolResult
    {
        if ($name === '') {
            return ToolResult::error('Parameter "name" is required for savepoint action.');
        }

        $db->exec(sprintf('SAVEPOINT "%s"', str_replace('"', '""', $name)));

        return ToolResult::success(sprintf('Savepoint **%s** created. Use RELEASE to commit or ROLLBACK to undo to this point.', $name));
    }

    private function release(\PDO $db, string $name): ToolResult
    {
        if ($name === '') {
            return ToolResult::error('Parameter "name" is required for release action.');
        }

        $db->exec(sprintf('RELEASE SAVEPOINT "%s"', str_replace('"', '""', $name)));

        return ToolResult::success(sprintf('Savepoint **%s** released (committed).', $name));
    }

    private function status(string $alias): ToolResult
    {
        $hasTransaction = $this->manager->hasTransaction($alias);

        return ToolResult::success(sprintf(
            'Database **%s**: %s.',
            $alias,
            $hasTransaction ? 'transaction **active**' : 'no active transaction',
        ));
    }
}
