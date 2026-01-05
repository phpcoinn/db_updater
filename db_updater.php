#!/usr/bin/env php
<?php

require_once __DIR__ . '/autoload.php';

use DbUpdater\DatabaseHandler;
use DbUpdater\Logger;
use DbUpdater\SchemaExtractor;
use DbUpdater\SchemaParser;
use DbUpdater\SchemaComparator;
use DbUpdater\SqlGenerator;
use DbUpdater\DdlGenerator;

/**
 * Database Updater Tool
 * 
 * Compares current database schema with desired state from DDL file
 * and applies necessary changes to synchronize the database.
 * 
 * Usage:
 *   php db_updater.php <ddl_file> [--dry-run] [--config=<config_file>]
 * 
 * Options:
 *   --dry-run          Preview changes without applying them
 *   --config=<file>    Path to configuration file (default: config.php)
 */

class DbUpdater
{
    private $config;
    private $logger;
    private $db;
    private $dryRun;

    public function __construct(array $config, bool $dryRun = false)
    {
        $this->config = $config;
        $this->dryRun = $dryRun;
        $this->logger = new Logger($config['logging'] ?? []);
    }

    public function run(string $ddlFile): void
    {
        try {
            $this->logger->info("Starting database update process");
            
            if ($this->dryRun) {
                $this->logger->info("DRY-RUN MODE: No changes will be applied");
            }

            // Initialize database connection
            $this->db = new DatabaseHandler($this->config['database'], $this->logger);

            // Extract current schema from database
            $extractor = new SchemaExtractor($this->db, $this->logger);
            $currentSchema = $extractor->extractSchema();

            // Generate DDL from current database schema
            $ddlGenerator = new DdlGenerator($this->logger);
            $currentDdl = $ddlGenerator->generateDdl($currentSchema);
            $normalizedCurrentDdl = $ddlGenerator->normalizeDdl($currentDdl);

            // Read and normalize desired DDL file
            $desiredDdlContent = file_get_contents($ddlFile);
            $normalizedDesiredDdl = $ddlGenerator->normalizeDdl($desiredDdlContent);

            // Quick check: if normalized DDLs match, no changes needed
            if ($normalizedCurrentDdl === $normalizedDesiredDdl) {
                $this->logger->info("Database schema matches desired state. No changes needed.");
                return;
            }

            // DDLs differ, so parse and compare structures to find specific differences
            $this->logger->info("Schema differences detected. Analyzing changes...");
            
            // Parse desired schema from DDL file
            $parser = new SchemaParser($this->logger);
            $desiredSchema = $parser->parseDdlFile($ddlFile);

            // Compare schemas to find specific differences
            // Get ignore configuration
            $ignoreColumns = $this->config['ignore_columns'] ?? [];
            $ignoreTables = $this->config['ignore_tables'] ?? [];
            $ignoreViews = $this->config['ignore_views'] ?? [];
            $comparator = new SchemaComparator($this->logger, $ignoreColumns, $ignoreTables, $ignoreViews);
            $differences = $comparator->compare($currentSchema, $desiredSchema);

            // Generate SQL statements
            $sqlGenerator = new SqlGenerator($this->logger, $desiredSchema);
            $sqlStatements = $sqlGenerator->generateSql($differences);

            if (empty($sqlStatements)) {
                $this->logger->info("Database schema is already up to date. No changes needed.");
                return;
            }

            // Display SQL statements
            $this->logger->info("Generated " . count($sqlStatements) . " SQL statement(s) to apply:");
            echo "\n" . str_repeat("=", 80) . "\n";
            echo "SQL STATEMENTS TO APPLY:\n";
            echo str_repeat("=", 80) . "\n\n";

            foreach ($sqlStatements as $index => $sql) {
                echo ($index + 1) . ". " . $sql . "\n\n";
            }

            echo str_repeat("=", 80) . "\n\n";

            // Apply changes if not in dry-run mode
            if ($this->dryRun) {
                $this->logger->info("Dry-run mode: Skipping execution of SQL statements");
                return;
            }

            // Confirm before applying (skip in non-interactive mode)
            if (function_exists('posix_isatty') && posix_isatty(STDIN)) {
                echo "Do you want to apply these changes? (yes/no): ";
                $handle = fopen("php://stdin", "r");
                $line = trim(fgets($handle));
                fclose($handle);

                if (strtolower($line) !== 'yes' && strtolower($line) !== 'y') {
                    $this->logger->info("Operation cancelled by user");
                    return;
                }
            } else {
                $this->logger->warning("Non-interactive mode: Proceeding with changes automatically");
            }

            // Execute SQL statements
            $this->logger->info("Applying changes to database...");
            
            foreach ($sqlStatements as $sql) {
                try {
                    $this->db->execute($sql);
                } catch (\Exception $e) {
                    $this->logger->error("Failed to execute SQL: " . $e->getMessage());
                    $this->logger->error("SQL was: " . $sql);
                    throw $e;
                }
            }

            $this->logger->info("Database update completed successfully!");

        } catch (\Exception $e) {
            $this->logger->error("Error: " . $e->getMessage());
            $this->logger->error("Stack trace: " . $e->getTraceAsString());
            exit(1);
        }
    }
}

// Parse command line arguments
$ddlFile = null;
$dryRun = false;
$configFile = 'config.php';

foreach ($argv as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
    } elseif (strpos($arg, '--config=') === 0) {
        $configFile = substr($arg, 9);
    } elseif ($arg !== $argv[0] && $ddlFile === null) {
        $ddlFile = $arg;
    }
}

// Validate arguments
if ($ddlFile === null) {
    echo "Usage: php db_updater.php <ddl_file> [--dry-run] [--config=<config_file>]\n";
    echo "\n";
    echo "Options:\n";
    echo "  --dry-run          Preview changes without applying them\n";
    echo "  --config=<file>    Path to configuration file (default: config.php)\n";
    exit(1);
}

// Load configuration
if (!file_exists($configFile)) {
    echo "Error: Configuration file not found: {$configFile}\n";
    echo "Please copy config.example.php to config.php and update with your database credentials.\n";
    exit(1);
}

$config = require $configFile;

if (!is_array($config)) {
    echo "Error: Configuration file must return an array.\n";
    exit(1);
}

// Run the updater
$updater = new DbUpdater($config, $dryRun);
$updater->run($ddlFile);

