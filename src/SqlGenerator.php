<?php

namespace DbUpdater;

class SqlGenerator
{
    private $logger;
    private $desiredSchema;

    public function __construct(Logger $logger, array $desiredSchema)
    {
        $this->logger = $logger;
        $this->desiredSchema = $desiredSchema;
    }

    public function generateSql(array $differences): array
    {
        $sqlStatements = [];

        // Generate CREATE TABLE statements
        foreach ($differences['tables_to_create'] ?? [] as $tableName) {
            $sqlStatements[] = $this->generateCreateTable($tableName);
        }

        // Generate ALTER TABLE statements
        foreach ($differences['tables_to_alter'] ?? [] as $tableName => $tableDiff) {
            $alterStatements = $this->generateAlterTable($tableName, $tableDiff);
            $sqlStatements = array_merge($sqlStatements, $alterStatements);
        }

        return $sqlStatements;
    }

    private function generateCreateTable(string $tableName): string
    {
        $table = $this->desiredSchema['tables'][$tableName];
        $sql = "CREATE TABLE `{$tableName}` (\n";

        $parts = [];

        // Add columns
        foreach ($table['columns'] as $columnName => $column) {
            $parts[] = "  " . $this->generateColumnDefinition($column);
        }

        // Add primary key if exists
        foreach ($table['indexes'] as $indexName => $index) {
            if ($index['unique'] && strtoupper($indexName) === 'PRIMARY') {
                $columns = implode('`, `', $index['columns']);
                $parts[] = "  PRIMARY KEY (`{$columns}`)";
            }
        }

        // Add other indexes
        foreach ($table['indexes'] as $indexName => $index) {
            if (strtoupper($indexName) !== 'PRIMARY') {
                $columns = implode('`, `', $index['columns']);
                $indexType = $index['unique'] ? 'UNIQUE KEY' : 'KEY';
                $parts[] = "  {$indexType} `{$indexName}` (`{$columns}`)";
            }
        }

        // Add foreign keys
        foreach ($table['foreign_keys'] as $fkName => $fk) {
            $columns = implode('`, `', $fk['columns']);
            $refColumns = implode('`, `', $fk['referenced_columns']);
            $parts[] = "  CONSTRAINT `{$fkName}` FOREIGN KEY (`{$columns}`) REFERENCES `{$fk['referenced_table']}` (`{$refColumns}`) ON UPDATE {$fk['on_update']} ON DELETE {$fk['on_delete']}";
        }

        $sql .= implode(",\n", $parts);
        $sql .= "\n)";

        // Add table options
        $options = $table['table_options'] ?? [];
        $optionParts = [];
        
        if (!empty($options['engine'])) {
            $optionParts[] = "ENGINE={$options['engine']}";
        }
        
        if (!empty($options['collation'])) {
            $optionParts[] = "COLLATE={$options['collation']}";
        }
        
        if (!empty($options['comment'])) {
            $optionParts[] = "COMMENT='" . addslashes($options['comment']) . "'";
        }
        
        if (!empty($options['auto_increment'])) {
            $optionParts[] = "AUTO_INCREMENT={$options['auto_increment']}";
        }

        if (!empty($optionParts)) {
            $sql .= " " . implode(" ", $optionParts);
        }

        $sql .= ";";

        return $sql;
    }

    private function generateAlterTable(string $tableName, array $diff): array
    {
        $statements = [];

        // Drop foreign keys first (if needed)
        foreach ($diff['foreign_keys_to_drop'] ?? [] as $fkName) {
            $statements[] = "ALTER TABLE `{$tableName}` DROP FOREIGN KEY `{$fkName}`;";
        }

        // Drop indexes
        foreach ($diff['indexes_to_drop'] ?? [] as $indexName) {
            if (strtoupper($indexName) === 'PRIMARY') {
                $statements[] = "ALTER TABLE `{$tableName}` DROP PRIMARY KEY;";
            } else {
                $statements[] = "ALTER TABLE `{$tableName}` DROP INDEX `{$indexName}`;";
            }
        }

        // Add columns
        foreach ($diff['columns_to_add'] ?? [] as $columnName => $column) {
            $columnDef = $this->generateColumnDefinition($column);
            $statements[] = "ALTER TABLE `{$tableName}` ADD COLUMN {$columnDef};";
        }

        // Modify columns
        foreach ($diff['columns_to_modify'] ?? [] as $columnName => $columnData) {
            $columnDef = $this->generateColumnDefinition($columnData['desired']);
            $statements[] = "ALTER TABLE `{$tableName}` MODIFY COLUMN {$columnDef};";
        }

        // Drop columns (if enabled)
        foreach ($diff['columns_to_drop'] ?? [] as $columnName) {
            $statements[] = "ALTER TABLE `{$tableName}` DROP COLUMN `{$columnName}`;";
        }

        // Add indexes
        foreach ($diff['indexes_to_add'] ?? [] as $indexName => $index) {
            $columns = implode('`, `', $index['columns']);
            
            if ($index['unique'] && strtoupper($indexName) === 'PRIMARY') {
                $statements[] = "ALTER TABLE `{$tableName}` ADD PRIMARY KEY (`{$columns}`);";
            } elseif ($index['unique']) {
                $statements[] = "ALTER TABLE `{$tableName}` ADD UNIQUE KEY `{$indexName}` (`{$columns}`);";
            } else {
                $statements[] = "ALTER TABLE `{$tableName}` ADD KEY `{$indexName}` (`{$columns}`);";
            }
        }

        // Add foreign keys
        foreach ($diff['foreign_keys_to_add'] ?? [] as $fkName => $fk) {
            $columns = implode('`, `', $fk['columns']);
            $refColumns = implode('`, `', $fk['referenced_columns']);
            $statements[] = "ALTER TABLE `{$tableName}` ADD CONSTRAINT `{$fkName}` FOREIGN KEY (`{$columns}`) REFERENCES `{$fk['referenced_table']}` (`{$refColumns}`) ON UPDATE {$fk['on_update']} ON DELETE {$fk['on_delete']};";
        }

        // Modify table options
        if (!empty($diff['table_options'])) {
            $options = $diff['table_options'];
            $optionParts = [];
            
            if (isset($options['engine'])) {
                $optionParts[] = "ENGINE={$options['engine']}";
            }
            
            if (isset($options['collation'])) {
                $optionParts[] = "COLLATE={$options['collation']}";
            }
            
            if (!empty($optionParts)) {
                $statements[] = "ALTER TABLE `{$tableName}` " . implode(" ", $optionParts) . ";";
            }
        }

        return $statements;
    }

    private function generateColumnDefinition(array $column): string
    {
        $def = "`{$column['name']}` {$column['type']}";

        if (!($column['nullable'] ?? true)) {
            $def .= " NOT NULL";
        }

        // Handle DEFAULT clause
        // Use array_key_exists instead of isset because isset(null) returns false
        if (array_key_exists('default', $column)) {
            if ($column['default'] === null) {
                // Explicitly set DEFAULT NULL for nullable columns to match schema file
                if ($column['nullable'] ?? true) {
                    $def .= " DEFAULT NULL";
                }
            } else {
                $default = $column['default'];
                // Determine if this is a string type column
                $isStringType = preg_match('/^(varchar|char|text|tinytext|mediumtext|longtext|enum|set)/i', $column['type']);
                
                // Quote string defaults - always quote for string types, or if it contains non-numeric characters
                if ($isStringType || (is_string($default) && !preg_match('/^[\d\.]+$/', $default) && strtoupper($default) !== 'NULL')) {
                    $default = "'" . addslashes($default) . "'";
                }
                $def .= " DEFAULT {$default}";
            }
        }

        if (!empty($column['extra'])) {
            $def .= " " . strtoupper($column['extra']);
        }

        if (!empty($column['charset'])) {
            $def .= " CHARACTER SET {$column['charset']}";
        }

        if (!empty($column['collation'])) {
            $def .= " COLLATE {$column['collation']}";
        }

        if (!empty($column['comment'])) {
            $def .= " COMMENT '" . addslashes($column['comment']) . "'";
        }

        return $def;
    }
}

