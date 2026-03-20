<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\SqliteAdmin\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Toolkits\SqliteAdmin\Runtime\DatabaseManager;

final readonly class ImportExportTool
{
    private const int BATCH_SIZE = 500;
    private const int MAX_OUTPUT_BYTES = 65_536;

    public function __construct(
        private DatabaseManager $manager,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'sqlite_import_export',
            description: 'Import data from CSV/JSON files into tables, export tables to CSV/JSON, or dump the full database schema and data as SQL.',
            parameters: [
                new EnumParameter(
                    'action',
                    'Import/export action',
                    values: ['import_csv', 'import_json', 'export_csv', 'export_json', 'dump_sql'],
                    required: true,
                ),
                new StringParameter(
                    'table',
                    'Table name for import or single-table export',
                    required: false,
                ),
                new StringParameter(
                    'file_path',
                    'Path to the file for import/export (relative paths resolve in workspace)',
                    required: true,
                ),
                new StringParameter(
                    'options',
                    'JSON options: {"delimiter": ",", "header": true, "if_exists": "append|replace|fail"} for CSV import. {"pretty": true} for JSON export.',
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
        $filePath = (string) ($args['file_path'] ?? '');
        $optionsJson = (string) ($args['options'] ?? '');
        $database = (string) ($args['database'] ?? '');

        if ($filePath === '') {
            return ToolResult::error('Parameter "file_path" is required.');
        }

        $options = [];
        if ($optionsJson !== '') {
            $decoded = json_decode($optionsJson, true);
            if (is_array($decoded)) {
                $options = $decoded;
            }
        }

        $resolvedPath = $this->resolveFilePath($filePath);

        try {
            $db = $this->manager->resolveConnection($database);

            return match ($action) {
                'import_csv' => $this->importCsv($db, $table, $resolvedPath, $options),
                'import_json' => $this->importJson($db, $table, $resolvedPath, $options),
                'export_csv' => $this->exportCsv($db, $table, $resolvedPath, $options),
                'export_json' => $this->exportJson($db, $table, $resolvedPath, $options),
                'dump_sql' => $this->dumpSql($db, $resolvedPath),
                default => ToolResult::error(sprintf('Unknown action "%s".', $action)),
            };
        } catch (\Throwable $e) {
            return ToolResult::error(sprintf('%s error: %s', $action, $e->getMessage()));
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function importCsv(\PDO $db, string $table, string $filePath, array $options): ToolResult
    {
        if ($table === '') {
            return ToolResult::error('Parameter "table" is required for CSV import.');
        }

        if (!file_exists($filePath)) {
            return ToolResult::error(sprintf('File not found: %s', $filePath));
        }

        $delimiter = (string) ($options['delimiter'] ?? ',');
        $hasHeader = (bool) ($options['header'] ?? true);
        $ifExists = (string) ($options['if_exists'] ?? 'append');

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            return ToolResult::error(sprintf('Cannot open file: %s', $filePath));
        }

        try {
            $headers = null;
            if ($hasHeader) {
                $headers = fgetcsv($handle, 0, $delimiter);
                if ($headers === false || $headers === [null]) {
                    return ToolResult::error('CSV file is empty or has no valid header row.');
                }
                // Clean BOM from first header
                $headers[0] = ltrim($headers[0], "\xEF\xBB\xBF");
            }

            // Handle if_exists policy
            if ($ifExists === 'replace') {
                $db->exec(sprintf('DELETE FROM "%s"', str_replace('"', '""', $table)));
            }

            // Auto-create table from headers if it doesn't exist
            if ($headers !== null) {
                $quotedCols = array_map(fn(string $h): string => '"' . str_replace('"', '""', trim($h)) . '"', $headers);
                $colDefs = implode(' TEXT, ', $quotedCols) . ' TEXT';
                $db->exec(sprintf('CREATE TABLE IF NOT EXISTS "%s" (%s)', str_replace('"', '""', $table), $colDefs));
            }

            $db->beginTransaction();
            $rowCount = 0;

            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                if ($row === [null]) {
                    continue;
                }

                if ($headers === null) {
                    // First data row — use positional columns
                    $headers = array_map(fn(int $i): string => 'col_' . ($i + 1), array_keys($row));
                    $quotedCols = array_map(fn(string $h): string => '"' . str_replace('"', '""', $h) . '"', $headers);
                    $colDefs = implode(' TEXT, ', $quotedCols) . ' TEXT';
                    $db->exec(sprintf('CREATE TABLE IF NOT EXISTS "%s" (%s)', str_replace('"', '""', $table), $colDefs));
                }

                $placeholders = implode(', ', array_fill(0, count($row), '?'));
                $quotedCols = array_map(fn(string $h): string => '"' . str_replace('"', '""', $h) . '"', $headers);
                $sql = sprintf('INSERT INTO "%s" (%s) VALUES (%s)', str_replace('"', '""', $table), implode(', ', $quotedCols), $placeholders);

                $db->prepare($sql)->execute($row);
                $rowCount++;

                if ($rowCount % self::BATCH_SIZE === 0) {
                    $db->commit();
                    $db->beginTransaction();
                }
            }

            $db->commit();

            return ToolResult::success(sprintf('Imported **%d rows** into table **%s** from `%s`.', $rowCount, $table, basename($filePath)));
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function importJson(\PDO $db, string $table, string $filePath, array $options): ToolResult
    {
        if ($table === '') {
            return ToolResult::error('Parameter "table" is required for JSON import.');
        }

        if (!file_exists($filePath)) {
            return ToolResult::error(sprintf('File not found: %s', $filePath));
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return ToolResult::error(sprintf('Cannot read file: %s', $filePath));
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return ToolResult::error('JSON file must contain an array of objects.');
        }

        // Handle both flat arrays and nested (e.g., {"data": [...]})
        if (isset($data[0]) && is_array($data[0])) {
            $rows = $data;
        } else {
            // Try to find the first array value
            $rows = null;
            foreach ($data as $value) {
                if (is_array($value) && isset($value[0]) && is_array($value[0])) {
                    $rows = $value;
                    break;
                }
            }
            if ($rows === null) {
                $rows = [$data]; // Single object
            }
        }

        $ifExists = (string) ($options['if_exists'] ?? 'append');
        if ($ifExists === 'replace') {
            $db->exec(sprintf('DELETE FROM "%s"', str_replace('"', '""', $table)));
        }

        // Auto-create table from first row keys
        $columns = array_keys($rows[0]);
        $quotedCols = array_map(fn(string $c): string => '"' . str_replace('"', '""', $c) . '"', $columns);
        $colDefs = implode(' TEXT, ', $quotedCols) . ' TEXT';
        $db->exec(sprintf('CREATE TABLE IF NOT EXISTS "%s" (%s)', str_replace('"', '""', $table), $colDefs));

        $db->beginTransaction();
        $rowCount = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $values = array_map(fn(string $col): mixed => $row[$col] ?? null, $columns);
            // Convert nested arrays/objects to JSON strings
            $values = array_map(fn(mixed $v): mixed => is_array($v) ? json_encode($v) : $v, $values);

            $placeholders = implode(', ', array_fill(0, count($values), '?'));
            $sql = sprintf('INSERT INTO "%s" (%s) VALUES (%s)', str_replace('"', '""', $table), implode(', ', $quotedCols), $placeholders);
            $db->prepare($sql)->execute(array_values($values));
            $rowCount++;

            if ($rowCount % self::BATCH_SIZE === 0) {
                $db->commit();
                $db->beginTransaction();
            }
        }

        $db->commit();

        return ToolResult::success(sprintf('Imported **%d rows** into table **%s** from `%s`.', $rowCount, $table, basename($filePath)));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function exportCsv(\PDO $db, string $table, string $filePath, array $options): ToolResult
    {
        if ($table === '') {
            return ToolResult::error('Parameter "table" is required for CSV export.');
        }

        $delimiter = (string) ($options['delimiter'] ?? ',');

        $stmt = $db->query(sprintf('SELECT * FROM "%s"', str_replace('"', '""', $table)));
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $handle = fopen($filePath, 'w');
        if ($handle === false) {
            return ToolResult::error(sprintf('Cannot write to file: %s', $filePath));
        }

        try {
            if ($rows !== []) {
                fputcsv($handle, array_keys($rows[0]), $delimiter);
                foreach ($rows as $row) {
                    fputcsv($handle, $row, $delimiter);
                }
            }

            return ToolResult::success(sprintf('Exported **%d rows** from **%s** to `%s`.', count($rows), $table, $filePath));
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function exportJson(\PDO $db, string $table, string $filePath, array $options): ToolResult
    {
        if ($table === '') {
            return ToolResult::error('Parameter "table" is required for JSON export.');
        }

        $pretty = (bool) ($options['pretty'] ?? true);

        $stmt = $db->query(sprintf('SELECT * FROM "%s"', str_replace('"', '""', $table)));
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $json = json_encode($rows, $flags);
        file_put_contents($filePath, $json);

        return ToolResult::success(sprintf('Exported **%d rows** from **%s** to `%s`.', count($rows), $table, $filePath));
    }

    private function dumpSql(\PDO $db, string $filePath): ToolResult
    {
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $handle = fopen($filePath, 'w');
        if ($handle === false) {
            return ToolResult::error(sprintf('Cannot write to file: %s', $filePath));
        }

        try {
            fwrite($handle, "-- SQLite dump generated by Coqui SQLite Admin\n");
            fwrite($handle, sprintf("-- Date: %s\n\n", date('c')));
            fwrite($handle, "BEGIN TRANSACTION;\n\n");

            // Dump schema
            $stmt = $db->query("SELECT type, name, sql FROM sqlite_master WHERE sql IS NOT NULL ORDER BY CASE type WHEN 'table' THEN 1 WHEN 'index' THEN 2 WHEN 'trigger' THEN 3 WHEN 'view' THEN 4 END");
            $objects = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $tableCount = 0;
            $totalRows = 0;

            foreach ($objects as $obj) {
                fwrite($handle, sprintf("%s;\n\n", $obj['sql']));

                // Dump data for tables
                if ($obj['type'] === 'table') {
                    $tableCount++;
                    $tableName = $obj['name'];
                    $dataStmt = $db->query(sprintf('SELECT * FROM "%s"', str_replace('"', '""', $tableName)));
                    $rows = $dataStmt->fetchAll(\PDO::FETCH_ASSOC);

                    foreach ($rows as $row) {
                        $cols = array_map(fn(string $c): string => '"' . str_replace('"', '""', $c) . '"', array_keys($row));
                        $vals = array_map(function (mixed $v) use ($db): string {
                            if ($v === null) {
                                return 'NULL';
                            }
                            if (is_int($v) || is_float($v)) {
                                return (string) $v;
                            }

                            return $db->quote((string) $v);
                        }, array_values($row));

                        fwrite($handle, sprintf(
                            "INSERT INTO \"%s\" (%s) VALUES (%s);\n",
                            str_replace('"', '""', $tableName),
                            implode(', ', $cols),
                            implode(', ', $vals),
                        ));
                        $totalRows++;
                    }

                    if ($rows !== []) {
                        fwrite($handle, "\n");
                    }
                }
            }

            fwrite($handle, "COMMIT;\n");

            $size = ftell($handle);

            return ToolResult::success(sprintf(
                'Database dumped to `%s` — %d table%s, %d row%s, %s.',
                $filePath,
                $tableCount,
                $tableCount === 1 ? '' : 's',
                $totalRows,
                $totalRows === 1 ? '' : 's',
                $this->formatBytes($size !== false ? $size : 0),
            ));
        } finally {
            fclose($handle);
        }
    }

    private function resolveFilePath(string $path): string
    {
        // Expand ~ to home directory
        if (str_starts_with($path, '~/')) {
            $home = getenv('HOME');
            if (is_string($home) && $home !== '') {
                return $home . substr($path, 1);
            }
        }

        // If relative and we have a storage path, resolve against it
        if (!str_starts_with($path, '/') && $this->manager->storagePath() !== '') {
            return rtrim($this->manager->storagePath(), '/') . '/' . $path;
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
