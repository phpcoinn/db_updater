<?php

namespace DbUpdater;

class DdlGenerator
{
    private $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Generate DDL statements from a schema array
     */
    public function generateDdl(array $schema): string
    {
        $ddl = "";
        $tables = $schema['tables'] ?? [];
        
        foreach ($tables as $tableName => $table) {
            $ddl .= $this->generateCreateTable($tableName, $table) . "\n";
        }

        return $ddl;
    }

    private function generateCreateTable(string $tableName, array $table): string
    {
        $sql = "CREATE TABLE `{$tableName}` (\n";

        $parts = [];

        // Add columns
        foreach ($table['columns'] ?? [] as $columnName => $column) {
            $parts[] = "  " . $this->generateColumnDefinition($column);
        }

        // Add primary key if exists
        foreach ($table['indexes'] ?? [] as $indexName => $index) {
            if ($index['unique'] && strtoupper($indexName) === 'PRIMARY') {
                $columns = implode('`, `', $index['columns']);
                $parts[] = "  PRIMARY KEY (`{$columns}`)";
            }
        }

        // Add other indexes
        foreach ($table['indexes'] ?? [] as $indexName => $index) {
            if (strtoupper($indexName) !== 'PRIMARY') {
                $columns = implode('`, `', $index['columns']);
                $indexType = $index['unique'] ? 'UNIQUE KEY' : 'KEY';
                $parts[] = "  {$indexType} `{$indexName}` (`{$columns}`)";
            }
        }

        // Add foreign keys
        foreach ($table['foreign_keys'] ?? [] as $fkName => $fk) {
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

        if (!empty($optionParts)) {
            $sql .= " " . implode(" ", $optionParts);
        }

        $sql .= ";";

        return $sql;
    }

    private function generateColumnDefinition(array $column): string
    {
        $def = "`{$column['name']}` {$column['type']}";

        if (!($column['nullable'] ?? true)) {
            $def .= " NOT NULL";
        }

        if (isset($column['default']) && $column['default'] !== null) {
            $default = $column['default'];
            // Quote string defaults
            if (is_string($default) && !preg_match('/^[\d\.]+$/', $default) && strtoupper($default) !== 'NULL') {
                $default = "'" . addslashes($default) . "'";
            }
            $def .= " DEFAULT {$default}";
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

    /**
     * Normalize DDL string for comparison (remove whitespace, comments, etc.)
     */
    public function normalizeDdl(string $ddl): string
    {
        // Remove comments
        $ddl = preg_replace('/--.*$/m', '', $ddl);
        $ddl = preg_replace('/\/\*[^*]*\*+(?:[^*\/][^*]*\*+)*\//s', '', $ddl);
        
        // Remove SET statements
        $ddl = preg_replace('/SET\s+[^;]+;/i', '', $ddl);
        
        // Remove DROP TABLE statements
        $ddl = preg_replace('/DROP\s+TABLE\s+IF\s+EXISTS\s+[^;]+;/i', '', $ddl);
        
        // Normalize whitespace
        $ddl = preg_replace('/\s+/', ' ', $ddl);
        $ddl = preg_replace('/\s*\(\s*/', '(', $ddl);
        $ddl = preg_replace('/\s*\)\s*/', ')', $ddl);
        $ddl = preg_replace('/\s*,\s*/', ',', $ddl);
        $ddl = preg_replace('/\s*;\s*/', ';', $ddl);
        
        // Convert to lowercase for case-insensitive comparison
        $ddl = strtolower($ddl);
        
        // Trim
        $ddl = trim($ddl);
        
        return $ddl;
    }
}

