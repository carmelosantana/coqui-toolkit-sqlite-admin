<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\SqliteAdmin\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Toolkits\SqliteAdmin\Runtime\DatabaseManager;

final readonly class DisconnectTool
{
    public function __construct(
        private DatabaseManager $manager,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'sqlite_disconnect',
            description: 'Close a database connection. If the disconnected database was active, the next available connection becomes active.',
            parameters: [
                new StringParameter(
                    'alias',
                    'Alias of the database to disconnect',
                    required: true,
                ),
            ],
            callback: fn(array $args): ToolResult => $this->execute($args),
        );
    }

    private function execute(array $args): ToolResult
    {
        $alias = (string) ($args['alias'] ?? '');

        if ($alias === '') {
            return ToolResult::error('Parameter "alias" is required.');
        }

        try {
            $path = $this->manager->getPath($alias);
            $this->manager->disconnect($alias);

            $remaining = $this->manager->connectionCount();
            $activeInfo = '';
            if ($remaining > 0 && $this->manager->activeAlias() !== '') {
                $activeInfo = sprintf(' Active database is now **%s**.', $this->manager->activeAlias());
            }

            return ToolResult::success(sprintf(
                'Disconnected from **%s** (`%s`). %d connection%s remaining.%s',
                $alias,
                $path,
                $remaining,
                $remaining === 1 ? '' : 's',
                $activeInfo,
            ));
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage());
        }
    }
}
