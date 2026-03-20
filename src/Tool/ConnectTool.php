<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\SqliteAdmin\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\BoolParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Toolkits\SqliteAdmin\Runtime\DatabaseManager;

final readonly class ConnectTool
{
    public function __construct(
        private DatabaseManager $manager,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'sqlite_connect',
            description: 'Open or create a SQLite database. Relative paths resolve under the workspace databases/ directory. Use ":memory:" for an in-memory database. The connected database becomes the active connection.',
            parameters: [
                new StringParameter(
                    'path',
                    'Path to the SQLite database file, or ":memory:" for in-memory. Relative paths resolve under workspace/databases/',
                    required: true,
                ),
                new StringParameter(
                    'alias',
                    'Short name for this connection (auto-generated from filename if omitted)',
                    required: false,
                ),
                new BoolParameter(
                    'create',
                    'Create the database if it does not exist (default: true)',
                    required: false,
                ),
            ],
            callback: fn(array $args): ToolResult => $this->execute($args),
        );
    }

    private function execute(array $args): ToolResult
    {
        $path = (string) ($args['path'] ?? '');
        $alias = (string) ($args['alias'] ?? '');
        $create = (bool) ($args['create'] ?? true);

        if ($path === '') {
            return ToolResult::error('Parameter "path" is required.');
        }

        try {
            $connectedAlias = $this->manager->connect($path, $alias, $create);
            $db = $this->manager->getConnection($connectedAlias);

            // Gather basic info
            $stmt = $db->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'");
            $tableCount = (int) $stmt->fetchColumn();

            $realPath = $this->manager->getPath($connectedAlias);
            $sizeInfo = '';
            if ($realPath !== ':memory:' && file_exists($realPath)) {
                $sizeInfo = sprintf(', size: %s', $this->formatBytes((int) filesize($realPath)));
            }

            return ToolResult::success(sprintf(
                'Connected to **%s** (`%s`) — %d table%s%s. This is now the active database.',
                $connectedAlias,
                $realPath,
                $tableCount,
                $tableCount === 1 ? '' : 's',
                $sizeInfo,
            ));
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage());
        }
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
