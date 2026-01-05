<?php

namespace DbUpdater;

class SchemaExtractor
{
    private $db;
    private $logger;
    private $databaseName;

    public function __construct(DatabaseHandler $db, Logger $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
        $this->databaseName = $db->getDatabaseName();
    }

    public function extractSchema(): array
    {
        $this->logger->info("Extracting current database schema");
        
        $schema = [
            'tables' => [],
        ];

        $tables = $this->getTables();
        
        foreach ($tables as $tableName) {
            $schema['tables'][$tableName] = [
                'columns' => $this->getColumns($tableName),
                'indexes' => $this->getIndexes($tableName),
                'foreign_keys' => $this->getForeignKeys($tableName),
                'table_options' => $this->getTableOptions($tableName),
            ];
        }

        $this->logger->info("Extracted schema for " . count($tables) . " tables");
        return $schema;
    }

    private function getTables(): array
    {
        $sql = "SELECT TABLE_NAME 
                FROM INFORMATION_SCHEMA.TABLES 
                WHERE TABLE_SCHEMA = :dbname 
                AND TABLE_TYPE = 'BASE TABLE'
                ORDER BY TABLE_NAME";
        
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['dbname' => $this->databaseName]);
        
        return array_column($stmt->fetchAll(), 'TABLE_NAME');
    }

    private function getColumns(string $tableName): array
    {
        $sql = "SELECT 
                    COLUMN_NAME,
                    COLUMN_TYPE,
                    DATA_TYPE,
                    IS_NULLABLE,
                    COLUMN_DEFAULT,
                    EXTRA,
                    COLUMN_KEY,
                    COLUMN_COMMENT,
                    CHARACTER_SET_NAME,
                    COLLATION_NAME
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = :dbname 
                AND TABLE_NAME = :table
                ORDER BY ORDINAL_POSITION";
        
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'dbname' => $this->databaseName,
            'table' => $tableName,
        ]);
        
        $columns = [];
        foreach ($stmt->fetchAll() as $row) {
            $columns[$row['COLUMN_NAME']] = [
                'name' => $row['COLUMN_NAME'],
                'type' => $row['COLUMN_TYPE'],
                'data_type' => $row['DATA_TYPE'],
                'nullable' => $row['IS_NULLABLE'] === 'YES',
                'default' => $row['COLUMN_DEFAULT'],
                'extra' => $row['EXTRA'],
                'key' => $row['COLUMN_KEY'],
                'comment' => $row['COLUMN_COMMENT'],
                'charset' => $row['CHARACTER_SET_NAME'],
                'collation' => $row['COLLATION_NAME'],
            ];
        }
        
        return $columns;
    }

    private function getIndexes(string $tableName): array
    {
        $sql = "SELECT 
                    INDEX_NAME,
                    COLUMN_NAME,
                    SEQ_IN_INDEX,
                    NON_UNIQUE,
                    INDEX_TYPE,
                    INDEX_COMMENT
                FROM INFORMATION_SCHEMA.STATISTICS
                WHERE TABLE_SCHEMA = :dbname 
                AND TABLE_NAME = :table
                ORDER BY INDEX_NAME, SEQ_IN_INDEX";
        
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'dbname' => $this->databaseName,
            'table' => $tableName,
        ]);
        
        $indexes = [];
        foreach ($stmt->fetchAll() as $row) {
            $indexName = $row['INDEX_NAME'];
            
            if (!isset($indexes[$indexName])) {
                $indexes[$indexName] = [
                    'name' => $indexName,
                    'columns' => [],
                    'unique' => $row['NON_UNIQUE'] == 0,
                    'type' => $row['INDEX_TYPE'],
                    'comment' => $row['INDEX_COMMENT'],
                ];
            }
            
            $indexes[$indexName]['columns'][] = $row['COLUMN_NAME'];
        }
        
        return $indexes;
    }

    private function getForeignKeys(string $tableName): array
    {
        $sql = "SELECT 
                    k.CONSTRAINT_NAME,
                    k.COLUMN_NAME,
                    k.REFERENCED_TABLE_NAME,
                    k.REFERENCED_COLUMN_NAME,
                    r.UPDATE_RULE,
                    r.DELETE_RULE
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE k
                JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS r 
                    ON k.CONSTRAINT_NAME = r.CONSTRAINT_NAME 
                    AND k.TABLE_SCHEMA = r.CONSTRAINT_SCHEMA
                WHERE k.TABLE_SCHEMA = :dbname 
                AND k.TABLE_NAME = :table
                AND k.REFERENCED_TABLE_NAME IS NOT NULL
                ORDER BY k.CONSTRAINT_NAME, k.ORDINAL_POSITION";
        
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'dbname' => $this->databaseName,
            'table' => $tableName,
        ]);
        
        $foreignKeys = [];
        foreach ($stmt->fetchAll() as $row) {
            $constraintName = $row['CONSTRAINT_NAME'];
            
            if (!isset($foreignKeys[$constraintName])) {
                $foreignKeys[$constraintName] = [
                    'name' => $constraintName,
                    'columns' => [],
                    'referenced_table' => $row['REFERENCED_TABLE_NAME'],
                    'referenced_columns' => [],
                    'on_update' => $row['UPDATE_RULE'],
                    'on_delete' => $row['DELETE_RULE'],
                ];
            }
            
            $foreignKeys[$constraintName]['columns'][] = $row['COLUMN_NAME'];
            $foreignKeys[$constraintName]['referenced_columns'][] = $row['REFERENCED_COLUMN_NAME'];
        }
        
        return $foreignKeys;
    }

    private function getTableOptions(string $tableName): array
    {
        $sql = "SELECT 
                    ENGINE,
                    TABLE_COLLATION,
                    TABLE_COMMENT,
                    AUTO_INCREMENT
                FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_SCHEMA = :dbname 
                AND TABLE_NAME = :table";
        
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'dbname' => $this->databaseName,
            'table' => $tableName,
        ]);
        
        $row = $stmt->fetch();
        if (!$row) {
            return [];
        }
        
        return [
            'engine' => $row['ENGINE'],
            'collation' => $row['TABLE_COLLATION'],
            'comment' => $row['TABLE_COMMENT'],
            'auto_increment' => $row['AUTO_INCREMENT'],
        ];
    }
}

