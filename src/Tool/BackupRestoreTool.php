<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\SqliteAdmin\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Toolkits\SqliteAdmin\Runtime\DatabaseManager;

final readonly class BackupRestoreTool
{
    public function __construct(
        private DatabaseManager $manager,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'sqlite_backup_restore',
            description: 'Backup a database to a file, restore from a backup, or clone a database. Restore operations require user confirmation.',
            parameters: [
                new EnumParameter(
                    'action',
                    'Backup/restore action',
                    values: ['backup', 'restore', 'clone'],
                    required: true,
                ),
                new StringParameter(
                    'destination',
                    'File path for the backup/clone output, or the backup file to restore from',
                    required: true,
                ),
                new StringParameter(
                    'database',
                    'Database alias to backup/restore (defaults to active)',
                    required: false,
                ),
            ],
            callback: fn(array $args): ToolResult => $this->execute($args),
        );
    }

    private function execute(array $args): ToolResult
    {
        $action = (string) ($args['action'] ?? '');
        $destination = (string) ($args['destination'] ?? '');
        $database = (string) ($args['database'] ?? '');

        if ($destination === '') {
            return ToolResult::error('Parameter "destination" is required.');
        }

        $resolvedDest = $this->resolveFilePath($destination);

        try {
            return match ($action) {
                'backup' => $this->backup($database, $resolvedDest),
                'restore' => $this->restore($database, $resolvedDest),
                'clone' => $this->cloneDb($database, $resolvedDest),
                default => ToolResult::error(sprintf('Unknown action "%s".', $action)),
            };
        } catch (\Throwable $e) {
            return ToolResult::error(sprintf('Backup/restore error: %s', $e->getMessage()));
        }
    }

    private function backup(string $database, string $destination): ToolResult
    {
        $alias = $this->manager->resolveAlias($database);
        $db = $this->manager->getConnection($alias);

        $dir = dirname($destination);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        // Use VACUUM INTO for atomic backup
        $db->exec(sprintf("VACUUM INTO %s", $db->quote($destination)));

        $size = file_exists($destination) ? filesize($destination) : 0;

        return ToolResult::success(sprintf(
            'Database **%s** backed up to `%s` (%s).',
            $alias,
            $destination,
            $this->formatBytes((int) $size),
        ));
    }

    private function restore(string $database, string $source): ToolResult
    {
        if (!file_exists($source)) {
            return ToolResult::error(sprintf('Backup file not found: %s', $source));
        }

        // Validate it's a valid SQLite database
        $header = file_get_contents($source, false, null, 0, 16);
        if ($header === false || !str_starts_with($header, 'SQLite format 3')) {
            return ToolResult::error('File does not appear to be a valid SQLite database.');
        }

        $alias = $this->manager->resolveAlias($database);
        $targetPath = $this->manager->getPath($alias);

        if ($targetPath === ':memory:') {
            return ToolResult::error('Cannot restore to an in-memory database. Connect to a file-based database first.');
        }

        // Disconnect, copy file, reconnect
        $this->manager->disconnect($alias);
        copy($source, $targetPath);
        $this->manager->connect($targetPath, $alias);

        $size = filesize($targetPath);

        return ToolResult::success(sprintf(
            'Database **%s** restored from `%s` (%s).',
            $alias,
            $source,
            $this->formatBytes((int) $size),
        ));
    }

    private function cloneDb(string $database, string $destination): ToolResult
    {
        $alias = $this->manager->resolveAlias($database);
        $sourcePath = $this->manager->getPath($alias);

        if ($sourcePath === ':memory:') {
            // For in-memory databases, use VACUUM INTO
            $db = $this->manager->getConnection($alias);
            $dir = dirname($destination);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $db->exec(sprintf("VACUUM INTO %s", $db->quote($destination)));
        } else {
            // File copy for file-based databases
            $dir = dirname($destination);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            copy($sourcePath, $destination);
        }

        $size = file_exists($destination) ? filesize($destination) : 0;

        return ToolResult::success(sprintf(
            'Database **%s** cloned to `%s` (%s). Use `sqlite_connect` to open the clone.',
            $alias,
            $destination,
            $this->formatBytes((int) $size),
        ));
    }

    private function resolveFilePath(string $path): string
    {
        if (str_starts_with($path, '~/')) {
            $home = getenv('HOME');
            if (is_string($home) && $home !== '') {
                return $home . substr($path, 1);
            }
        }

        if (!str_starts_with($path, '/') && $this->manager->storagePath() !== '') {
            return rtrim($this->manager->storagePath(), '/') . '/backups/' . $path;
        }

        return $path;
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
