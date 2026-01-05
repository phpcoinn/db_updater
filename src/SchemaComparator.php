<?php

namespace DbUpdater;

class SchemaComparator
{
    private $logger;
    private $ignoreColumns;
    private $ignoreTables;

    public function __construct(Logger $logger, array $ignoreColumns = [], array $ignoreTables = [])
    {
        $this->logger = $logger;
        $this->ignoreColumns = $ignoreColumns;
        $this->ignoreTables = $ignoreTables;
    }
    
    /**
     * Check if a table should be ignored during comparison
     */
    private function shouldIgnoreTable(string $tableName): bool
    {
        return in_array($tableName, $this->ignoreTables, true);
    }
    
    /**
     * Check if a column should be ignored during comparison
     */
    private function shouldIgnoreColumn(string $tableName, string $columnName): bool
    {
        // Check for table.column format
        $fullName = "{$tableName}.{$columnName}";
        if (in_array($fullName, $this->ignoreColumns)) {
            return true;
        }
        
        // Check for column name only (applies to all tables)
        if (in_array($columnName, $this->ignoreColumns)) {
            return true;
        }
        
        return false;
    }

    public function compare(array $currentSchema, array $desiredSchema): array
    {
        $this->logger->info("Comparing current schema with desired schema");
        
        $differences = [
            'tables_to_create' => [],
            'tables_to_alter' => [],
            'tables_to_drop' => [],
        ];

        $currentTables = array_keys($currentSchema['tables'] ?? []);
        $desiredTables = array_keys($desiredSchema['tables'] ?? []);

        // Find tables to create
        foreach ($desiredTables as $tableName) {
            // Skip ignored tables
            if ($this->shouldIgnoreTable($tableName)) {
                $this->logger->debug("Ignoring table {$tableName} during comparison");
                continue;
            }
            
            if (!in_array($tableName, $currentTables)) {
                $differences['tables_to_create'][] = $tableName;
            }
        }

        // Find tables to drop (optional - might want to skip this)
        // foreach ($currentTables as $tableName) {
        //     if (!in_array($tableName, $desiredTables)) {
        //         $differences['tables_to_drop'][] = $tableName;
        //     }
        // }

        // Compare existing tables
        foreach ($desiredTables as $tableName) {
            // Skip ignored tables
            if ($this->shouldIgnoreTable($tableName)) {
                $this->logger->debug("Ignoring table {$tableName} during comparison");
                continue;
            }
            
            if (in_array($tableName, $currentTables)) {
                $tableDiff = $this->compareTable(
                    $currentSchema['tables'][$tableName],
                    $desiredSchema['tables'][$tableName],
                    $tableName
                );
                
                if (!empty($tableDiff)) {
                    $differences['tables_to_alter'][$tableName] = $tableDiff;
                }
            }
        }

        $this->logger->info("Found " . count($differences['tables_to_create']) . " tables to create");
        $this->logger->info("Found " . count($differences['tables_to_alter']) . " tables to alter");
        
        return $differences;
    }

    private function compareTable(array $current, array $desired, string $tableName): array
    {
        $diff = [
            'columns_to_add' => [],
            'columns_to_modify' => [],
            'columns_to_drop' => [],
            'indexes_to_add' => [],
            'indexes_to_drop' => [],
            'foreign_keys_to_add' => [],
            'foreign_keys_to_drop' => [],
            'table_options' => [],
        ];

        // Compare columns
        $currentColumns = $current['columns'] ?? [];
        $desiredColumns = $desired['columns'] ?? [];

        foreach ($desiredColumns as $columnName => $desiredColumn) {
            // Skip ignored columns
            if ($this->shouldIgnoreColumn($tableName, $columnName)) {
                $this->logger->debug("Ignoring column {$tableName}.{$columnName} during comparison");
                continue;
            }
            
            if (!isset($currentColumns[$columnName])) {
                $diff['columns_to_add'][$columnName] = $desiredColumn;
            } else {
                $columnDiff = $this->compareColumn($currentColumns[$columnName], $desiredColumn);
                if (!empty($columnDiff)) {
                    $diff['columns_to_modify'][$columnName] = [
                        'current' => $currentColumns[$columnName],
                        'desired' => $desiredColumn,
                        'changes' => $columnDiff,
                    ];
                }
            }
        }

        // Find columns to drop (optional - might want to skip this for safety)
        // Note: This is commented out by default to prevent accidental data loss
        // Uncomment if you want the tool to detect and optionally drop extra columns
        foreach ($currentColumns as $columnName => $currentColumn) {
            // Skip ignored columns
            if ($this->shouldIgnoreColumn($tableName, $columnName)) {
                continue;
            }
            
            if (!isset($desiredColumns[$columnName])) {
                $diff['columns_to_drop'][] = $columnName;
            }
        }

        // Compare indexes
        $currentIndexes = $current['indexes'] ?? [];
        $desiredIndexes = $desired['indexes'] ?? [];

        foreach ($desiredIndexes as $indexName => $desiredIndex) {
            if (!isset($currentIndexes[$indexName])) {
                $diff['indexes_to_add'][$indexName] = $desiredIndex;
            } else {
                $indexDiff = $this->compareIndex($currentIndexes[$indexName], $desiredIndex);
                if (!empty($indexDiff)) {
                    // If index changed, drop and recreate
                    $diff['indexes_to_drop'][] = $indexName;
                    $diff['indexes_to_add'][$indexName] = $desiredIndex;
                }
            }
        }

        // Find indexes to drop (optional)
        // foreach ($currentIndexes as $indexName => $currentIndex) {
        //     if (!isset($desiredIndexes[$indexName])) {
        //         $diff['indexes_to_drop'][] = $indexName;
        //     }
        // }

        // Compare foreign keys
        $currentForeignKeys = $current['foreign_keys'] ?? [];
        $desiredForeignKeys = $desired['foreign_keys'] ?? [];

        foreach ($desiredForeignKeys as $fkName => $desiredFk) {
            if (!isset($currentForeignKeys[$fkName])) {
                $diff['foreign_keys_to_add'][$fkName] = $desiredFk;
            } else {
                $fkDiff = $this->compareForeignKey($currentForeignKeys[$fkName], $desiredFk);
                if (!empty($fkDiff)) {
                    // If FK changed, drop and recreate
                    $diff['foreign_keys_to_drop'][] = $fkName;
                    $diff['foreign_keys_to_add'][$fkName] = $desiredFk;
                }
            }
        }

        // Compare table options
        $currentOptions = $current['table_options'] ?? [];
        $desiredOptions = $desired['table_options'] ?? [];
        
        $optionsDiff = $this->compareTableOptions($currentOptions, $desiredOptions);
        if (!empty($optionsDiff)) {
            $diff['table_options'] = $optionsDiff;
        }

        // Remove empty arrays
        return array_filter($diff, function($value) {
            return !empty($value);
        });
    }

    private function compareColumn(array $current, array $desired): array
    {
        $changes = [];

        // Normalize types for comparison
        $currentType = $this->normalizeType($current['type'] ?? '');
        $desiredType = $this->normalizeType($desired['type'] ?? '');

        if ($currentType !== $desiredType) {
            $changes['type'] = true;
        }

        if (($current['nullable'] ?? true) !== ($desired['nullable'] ?? true)) {
            $changes['nullable'] = true;
        }

        $currentDefault = $current['default'] ?? null;
        $desiredDefault = $desired['default'] ?? null;
        
        // Normalize default values
        if ($this->normalizeDefault($currentDefault) !== $this->normalizeDefault($desiredDefault)) {
            $changes['default'] = true;
        }

        if (($current['extra'] ?? '') !== ($desired['extra'] ?? '')) {
            $changes['extra'] = true;
        }

        return $changes;
    }

    private function compareIndex(array $current, array $desired): array
    {
        $changes = [];

        $currentColumns = $current['columns'] ?? [];
        $desiredColumns = $desired['columns'] ?? [];

        if ($currentColumns !== $desiredColumns) {
            $changes['columns'] = true;
        }

        if (($current['unique'] ?? false) !== ($desired['unique'] ?? false)) {
            $changes['unique'] = true;
        }

        return $changes;
    }

    private function compareForeignKey(array $current, array $desired): array
    {
        $changes = [];

        $currentColumns = $current['columns'] ?? [];
        $desiredColumns = $desired['columns'] ?? [];

        if ($currentColumns !== $desiredColumns) {
            $changes['columns'] = true;
        }

        if (($current['referenced_table'] ?? '') !== ($desired['referenced_table'] ?? '')) {
            $changes['referenced_table'] = true;
        }

        $currentRefColumns = $current['referenced_columns'] ?? [];
        $desiredRefColumns = $desired['referenced_columns'] ?? [];

        if ($currentRefColumns !== $desiredRefColumns) {
            $changes['referenced_columns'] = true;
        }

        if (($current['on_update'] ?? 'RESTRICT') !== ($desired['on_update'] ?? 'RESTRICT')) {
            $changes['on_update'] = true;
        }

        if (($current['on_delete'] ?? 'RESTRICT') !== ($desired['on_delete'] ?? 'RESTRICT')) {
            $changes['on_delete'] = true;
        }

        return $changes;
    }

    private function compareTableOptions(array $current, array $desired): array
    {
        $changes = [];

        if (($current['engine'] ?? '') !== ($desired['engine'] ?? '')) {
            $changes['engine'] = $desired['engine'];
        }

        if (($current['collation'] ?? '') !== ($desired['collation'] ?? '')) {
            $changes['collation'] = $desired['collation'];
        }

        return $changes;
    }

    private function normalizeType(string $type): string
    {
        // Normalize type for comparison (remove case differences, normalize spacing)
        $type = strtoupper(trim($type));
        // Remove extra spaces
        $type = preg_replace('/\s+/', ' ', $type);
        return $type;
    }

    private function normalizeDefault($default)
    {
        if ($default === null || $default === 'NULL') {
            return null;
        }
        
        $default = (string)$default;
        
        // Handle empty string defaults - MySQL stores them as '' (with quotes) in INFORMATION_SCHEMA
        // but they should be normalized to empty string
        if ($default === "''" || $default === '\'\'' || $default === '') {
            return '';
        }
        
        // Remove surrounding quotes if present (for string defaults)
        if ((substr($default, 0, 1) === "'" && substr($default, -1) === "'") ||
            (substr($default, 0, 1) === '"' && substr($default, -1) === '"')) {
            $default = substr($default, 1, -1);
            // Unescape quotes
            $default = str_replace("\\'", "'", $default);
            $default = str_replace('\\"', '"', $default);
        }
        
        return $default;
    }
}

