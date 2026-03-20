<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\SqliteAdmin\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Toolkits\SqliteAdmin\Runtime\DatabaseManager;

final readonly class ListDatabasesTool
{
    public function __construct(
        private DatabaseManager $manager,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'sqlite_databases',
            description: 'List all open SQLite database connections with alias, file path, table count, size, and which is active.',
            parameters: [],
            callback: fn(array $args): ToolResult => $this->execute(),
        );
    }

    private function execute(): ToolResult
    {
        try {
            $databases = $this->manager->list();

            if ($databases === []) {
                return ToolResult::success('No database connections open. Use `sqlite_connect` to open a database.');
            }

            $output = sprintf("**%d database%s connected:**\n\n", count($databases), count($databases) === 1 ? '' : 's');
            $output .= "| Alias | Path | Tables | Size | Active | Transaction |\n";
            $output .= "| --- | --- | --- | --- | --- | --- |\n";

            foreach ($databases as $db) {
                $size = $db['size_bytes'] > 0 ? $this->formatBytes($db['size_bytes']) : 'n/a';
                $active = $db['active'] ? '**→ yes**' : 'no';
                $tx = $db['in_transaction'] ? 'yes' : 'no';

                $output .= sprintf(
                    "| %s | `%s` | %d | %s | %s | %s |\n",
                    $db['alias'],
                    $db['path'],
                    $db['tables'],
                    $size,
                    $active,
                    $tx,
                );
            }

            return ToolResult::success($output);
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
