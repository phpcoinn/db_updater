<?php

namespace DbUpdater;

class SchemaParser
{
    private $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    public function parseDdlFile(string $ddlFile): array
    {
        if (!file_exists($ddlFile)) {
            throw new \RuntimeException("DDL file not found: {$ddlFile}");
        }

        $this->logger->info("Parsing DDL file: {$ddlFile}");
        $content = file_get_contents($ddlFile);
        
        return $this->parseDdl($content);
    }

    public function parseDdl(string $ddl): array
    {
        $schema = [
            'tables' => [],
        ];

        // Remove comments
        // Remove single-line comments (-- comments)
        $ddl = preg_replace('/--.*$/m', '', $ddl);
        // Remove multi-line comments including MySQL conditional comments (/*! ... */)
        // This pattern handles /* */ and /*! ... */ style comments
        $ddl = preg_replace('/\/\*[^*]*\*+(?:[^*\/][^*]*\*+)*\//s', '', $ddl);
        // Remove SET statements (they're not needed for schema comparison)
        // But be careful not to match "CHARACTER SET" or "SET" in column definitions
        // Only match SET statements that are standalone (at start of line or after semicolon)
        $ddl = preg_replace('/(?:^|;)\s*SET\s+[^;]+;/im', '', $ddl);
        // Remove DROP TABLE statements (they're not needed for schema comparison)
        $ddl = preg_replace('/DROP\s+TABLE\s+IF\s+EXISTS\s+[^;]+;/i', '', $ddl);

        // Extract CREATE TABLE statements
        // Manually extract table bodies to handle nested parentheses correctly
        $tables = [];
        $offset = 0;
        while (($pos = stripos($ddl, 'CREATE TABLE', $offset)) !== false) {
            // Find table name
            if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?\s*\(/is', substr($ddl, $pos), $nameMatch, PREG_OFFSET_CAPTURE)) {
                $tableName = trim($nameMatch[1][0]);
                // Find the opening parenthesis - it's at the end of the matched string
                $bodyStart = $pos + $nameMatch[0][1] + strlen($nameMatch[0][0]) - 1; // Position of opening (
                
                // Verify we're at the opening parenthesis
                if ($bodyStart >= strlen($ddl) || $ddl[$bodyStart] !== '(') {
                    // Fallback: search for opening parenthesis after table name
                    $bodyStart = strpos($ddl, '(', $pos);
                    if ($bodyStart === false) {
                        $offset = $pos + 1;
                        continue;
                    }
                }
                
                // Find matching closing parenthesis by tracking depth
                // Start at depth=1 because we're already inside the opening parenthesis
                $depth = 1;
                $bodyEnd = $bodyStart;
                $inQuotes = false;
                $quoteChar = '';
                for ($i = $bodyStart + 1; $i < strlen($ddl); $i++) {
                    $char = $ddl[$i];
                    $prev = $i > 0 ? $ddl[$i - 1] : '';
                    
                    // Handle quoted strings
                    if (!$inQuotes && ($char === '"' || $char === "'")) {
                        $inQuotes = true;
                        $quoteChar = $char;
                    } elseif ($inQuotes && $char === $quoteChar && $prev !== '\\') {
                        $inQuotes = false;
                    }
                    
                    if (!$inQuotes) {
                        if ($char === '(') {
                            $depth++;
                        } elseif ($char === ')') {
                            $depth--;
                            if ($depth === 0) {
                                $bodyEnd = $i;
                                break;
                            }
                        }
                    }
                }
                
                // Extract table body and options
                $tableBody = substr($ddl, $bodyStart + 1, $bodyEnd - $bodyStart - 1);
                $afterBody = substr($ddl, $bodyEnd + 1);
                if (preg_match('/\s*([^;]*?);/s', $afterBody, $optionsMatch)) {
                    $tableOptions = trim($optionsMatch[1]);
                    // Debug: log table body for smart_contracts
                    if ($tableName === 'smart_contracts') {
                        if (strpos($tableBody, 'metadata') === false) {
                            $this->logger->warning("smart_contracts table body does not contain 'metadata' column. Body length: " . strlen($tableBody));
                            $this->logger->warning("Last 200 chars of body: " . substr($tableBody, -200));
                        } else {
                            $this->logger->debug("smart_contracts table body contains 'metadata'. Body length: " . strlen($tableBody));
                        }
                    }
                    $tables[] = [
                        'name' => $tableName,
                        'body' => $tableBody,
                        'options' => $tableOptions,
                    ];
                    $offset = $bodyEnd + strlen($optionsMatch[0]);
                } else {
                    $offset = $pos + 1;
                }
            } else {
                $offset = $pos + 1;
            }
        }
        
        // Process extracted tables
        foreach ($tables as $table) {
            $tableName = $table['name'];
            $tableBody = trim($table['body']);
            $tableOptions = trim($table['options']);
        
            $schema['tables'][$tableName] = [
                'columns' => $this->parseColumns($tableBody),
                'indexes' => $this->parseIndexes($tableBody),
                'foreign_keys' => $this->parseForeignKeys($tableBody),
                'table_options' => $this->parseTableOptions($tableOptions),
            ];
        }

        $this->logger->info("Parsed " . count($schema['tables']) . " tables from DDL");
        return $schema;
    }

    private function parseColumns(string $tableBody): array
    {
        $columns = [];
        
        // Extract column definitions (everything before first constraint)
        // This regex matches column definitions that end with comma or end of string
        $pattern = '/`?(\w+)`?\s+([^,]+?)(?=\s*,\s*(?:PRIMARY|KEY|UNIQUE|FOREIGN|CONSTRAINT|CHECK|INDEX|`?\w+`?\s+[^,]+)|$)/i';
        
        // Better approach: split by commas and parse each part
        $parts = $this->splitTableBody($tableBody);
        
        foreach ($parts as $part) {
            $part = trim($part);
            
            // Skip if it's a constraint/key definition
            if (preg_match('/^\s*(PRIMARY\s+KEY|KEY|INDEX|UNIQUE|FOREIGN\s+KEY|CONSTRAINT|CHECK)\s+/i', $part)) {
                continue;
            }
            
            // Parse column definition
            if (preg_match('/^`?(\w+)`?\s+(.+)$/i', $part, $matches)) {
                $columnName = $matches[1];
                $columnDef = trim($matches[2]);
                
                // Debug: check if definition contains parentheses
                if (strpos($columnDef, '(') !== false && strpos($columnDef, ')') !== false) {
                    $this->logger->debug("Parsing column {$columnName} with definition: {$columnDef}");
                }
                
                $columns[$columnName] = $this->parseColumnDefinition($columnName, $columnDef);
            }
        }
        
        return $columns;
    }

    private function parseColumnDefinition(string $name, string $definition): array
    {
        $column = [
            'name' => $name,
            'type' => '',
            'nullable' => true,
            'default' => null,
            'extra' => '',
            'comment' => '',
            'charset' => null,
            'collation' => null,
        ];

        // Extract type - match data type with optional parameters like varchar(128), int(11), decimal(20,8)
        // Handle types like: varchar(128), int(11), decimal(20,8), text, timestamp, etc.
        // The type can have parentheses with parameters, e.g., varchar(128), decimal(20,8)
        $definition = trim($definition);
        
        // Match type with optional parentheses: varchar(128), int(11), decimal(20,8), etc.
        // Try to match with parentheses first (more specific), then fallback to without
        // Pattern: match "type(params)" where params can contain commas for types like decimal(20,8)
        // Match type with parentheses first (more specific)
        // Debug: log definition for problematic columns
        $originalDef = $definition;
        
        if (preg_match('/^([A-Za-z]+)\s*\(\s*([^)]+)\s*\)/i', $definition, $typeMatch)) {
            // Type with parameters - reconstruct as "type(params)"
            $column['type'] = strtolower(trim($typeMatch[1])) . '(' . trim($typeMatch[2]) . ')';
        } elseif (preg_match('/^([A-Za-z]+)/i', $definition, $typeMatch)) {
            $column['type'] = strtolower(trim($typeMatch[1])); // Type without parameters
            // If definition has parentheses but regex didn't match, log it
            if (strpos($originalDef, '(') !== false && strpos($originalDef, ')') !== false) {
                $this->logger->warning("Type extraction may have failed for: {$originalDef}");
            }
        }
        
        // Debug: log if type extraction failed
        if (empty($column['type'])) {
            $this->logger->warning("Failed to extract type from definition: {$definition}");
        }

        // Check for NOT NULL
        if (preg_match('/\bNOT\s+NULL\b/i', $definition)) {
            $column['nullable'] = false;
        }

        // Extract DEFAULT
        // Match DEFAULT followed by: NULL, quoted strings, numbers, or unquoted identifiers
        if (preg_match('/\bDEFAULT\s+(NULL|(?:[\'"]).*?(?:[\'"])|[\w\-\.]+|\d+)/i', $definition, $defaultMatch)) {
            $default = trim($defaultMatch[1], '"\'');
            // Convert NULL (case-insensitive) to null
            if (strtoupper($default) === 'NULL') {
                $column['default'] = null;
            } else {
                $column['default'] = $default;
            }
        }

        // Extract AUTO_INCREMENT
        if (preg_match('/\bAUTO_INCREMENT\b/i', $definition)) {
            $column['extra'] = 'auto_increment';
        }

        // Extract COMMENT
        if (preg_match("/\bCOMMENT\s+['\"](.*?)['\"]/i", $definition, $commentMatch)) {
            $column['comment'] = $commentMatch[1];
        }

        // Extract CHARACTER SET
        if (preg_match("/\bCHARACTER\s+SET\s+(\w+)/i", $definition, $charsetMatch)) {
            $column['charset'] = $charsetMatch[1];
        }

        // Extract COLLATE
        if (preg_match("/\bCOLLATE\s+(\w+)/i", $definition, $collateMatch)) {
            $column['collation'] = $collateMatch[1];
        }

        return $column;
    }

    private function parseIndexes(string $tableBody): array
    {
        $indexes = [];
        $parts = $this->splitTableBody($tableBody);

        foreach ($parts as $part) {
            $part = trim($part);
            
            // PRIMARY KEY
            if (preg_match('/^\s*PRIMARY\s+KEY\s*\((.+?)\)/i', $part, $matches)) {
                $columns = $this->parseColumnList($matches[1]);
                $indexes['PRIMARY'] = [
                    'name' => 'PRIMARY',
                    'columns' => $columns,
                    'unique' => true,
                    'type' => 'BTREE',
                    'comment' => '',
                ];
            }
            // UNIQUE KEY/INDEX
            elseif (preg_match('/^\s*UNIQUE\s+(?:KEY|INDEX)\s+(?:`?(\w+)`?\s+)?\((.+?)\)/i', $part, $matches)) {
                $name = $matches[1] ?? '';
                $columns = $this->parseColumnList($matches[2]);
                if (empty($name) && count($columns) === 1) {
                    $name = $columns[0];
                }
                $indexes[$name] = [
                    'name' => $name,
                    'columns' => $columns,
                    'unique' => true,
                    'type' => 'BTREE',
                    'comment' => '',
                ];
            }
            // KEY/INDEX
            elseif (preg_match('/^\s*(?:KEY|INDEX)\s+(?:`?(\w+)`?\s+)?\((.+?)\)/i', $part, $matches)) {
                $name = $matches[1] ?? '';
                $columns = $this->parseColumnList($matches[2]);
                if (empty($name) && count($columns) === 1) {
                    $name = $columns[0];
                }
                $indexes[$name] = [
                    'name' => $name,
                    'columns' => $columns,
                    'unique' => false,
                    'type' => 'BTREE',
                    'comment' => '',
                ];
            }
        }

        return $indexes;
    }

    private function parseForeignKeys(string $tableBody): array
    {
        $foreignKeys = [];
        $parts = $this->splitTableBody($tableBody);

        foreach ($parts as $part) {
            $part = trim($part);
            
            // Match FOREIGN KEY with optional CONSTRAINT name, and both ON UPDATE and ON DELETE clauses
            // Pattern matches: CONSTRAINT name? FOREIGN KEY (columns) REFERENCES table (columns) [ON DELETE action] [ON UPDATE action]
            if (preg_match('/^\s*(?:CONSTRAINT\s+`?(\w+)`?\s+)?FOREIGN\s+KEY\s*\((.+?)\)\s+REFERENCES\s+`?(\w+)`?\s*\((.+?)\)/i', $part, $matches)) {
                $name = $matches[1] ?? '';
                $columns = $this->parseColumnList($matches[2]);
                $referencedTable = $matches[3];
                $referencedColumns = $this->parseColumnList($matches[4]);
                
                if (empty($name)) {
                    $name = 'fk_' . implode('_', $columns) . '_' . $referencedTable;
                }

                $foreignKeys[$name] = [
                    'name' => $name,
                    'columns' => $columns,
                    'referenced_table' => $referencedTable,
                    'referenced_columns' => $referencedColumns,
                    'on_update' => 'RESTRICT', // Default
                    'on_delete' => 'RESTRICT', // Default
                ];

                // Parse ON DELETE clause
                if (preg_match('/\bON\s+DELETE\s+(RESTRICT|CASCADE|SET\s+NULL|NO\s+ACTION)\b/i', $part, $deleteMatch)) {
                    $foreignKeys[$name]['on_delete'] = strtoupper($deleteMatch[1]);
                }

                // Parse ON UPDATE clause
                if (preg_match('/\bON\s+UPDATE\s+(RESTRICT|CASCADE|SET\s+NULL|NO\s+ACTION)\b/i', $part, $updateMatch)) {
                    $foreignKeys[$name]['on_update'] = strtoupper($updateMatch[1]);
                }
            }
        }

        return $foreignKeys;
    }

    private function parseTableOptions(string $options): array
    {
        $tableOptions = [
            'engine' => 'InnoDB',
            'collation' => null,
            'comment' => '',
            'auto_increment' => null,
        ];

        // Extract ENGINE
        if (preg_match('/\bENGINE\s*=\s*(\w+)/i', $options, $matches)) {
            $tableOptions['engine'] = $matches[1];
        }

        // Extract DEFAULT CHARSET/CHARACTER SET
        if (preg_match('/\b(?:DEFAULT\s+)?(?:CHARSET|CHARACTER\s+SET)\s*=\s*(\w+)/i', $options, $matches)) {
            // Charset is stored but we'll use collation primarily
        }

        // Extract COLLATE
        if (preg_match('/\bCOLLATE\s*=\s*(\w+)/i', $options, $matches)) {
            $tableOptions['collation'] = $matches[1];
        }

        // Extract COMMENT
        if (preg_match("/\bCOMMENT\s*=\s*['\"](.*?)['\"]/i", $options, $matches)) {
            $tableOptions['comment'] = $matches[1];
        }

        // Extract AUTO_INCREMENT
        if (preg_match('/\bAUTO_INCREMENT\s*=\s*(\d+)/i', $options, $matches)) {
            $tableOptions['auto_increment'] = (int)$matches[1];
        }

        return $tableOptions;
    }

    private function splitTableBody(string $body): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $inQuotes = false;
        $quoteChar = '';

        for ($i = 0; $i < strlen($body); $i++) {
            $char = $body[$i];
            $prev = $i > 0 ? $body[$i - 1] : '';

            if (!$inQuotes && ($char === '"' || $char === "'")) {
                $inQuotes = true;
                $quoteChar = $char;
            } elseif ($inQuotes && $char === $quoteChar && $prev !== '\\') {
                $inQuotes = false;
            }

            if (!$inQuotes) {
                if ($char === '(') {
                    $depth++;
                } elseif ($char === ')') {
                    $depth--;
                } elseif ($char === ',' && $depth === 0) {
                    $parts[] = trim($current);
                    $current = '';
                    continue;
                }
            }

            $current .= $char;
        }

        if (!empty(trim($current))) {
            $parts[] = trim($current);
        }

        return $parts;
    }

    private function parseColumnList(string $list): array
    {
        $columns = [];
        $parts = preg_split('/\s*,\s*/', trim($list));
        
        foreach ($parts as $part) {
            $part = trim($part, '` ');
            if (!empty($part)) {
                $columns[] = $part;
            }
        }
        
        return $columns;
    }
}

