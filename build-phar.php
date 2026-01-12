#!/usr/bin/env php
<?php

/**
 * Build script for creating a PHAR archive of db_updater
 * 
 * Usage: php build-phar.php
 */

$pharFile = __DIR__ . '/db_updater.phar';

// Remove existing PHAR if it exists
if (file_exists($pharFile)) {
    unlink($pharFile);
}

// Create PHAR archive
$phar = new Phar($pharFile);

// Start buffering
$phar->startBuffering();

// Add all PHP source files
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(__DIR__ . '/src', RecursiveDirectoryIterator::SKIP_DOTS)
);
$files = [];
foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $files[$file->getPathname()] = $file->getPathname();
    }
}
$phar->buildFromIterator(new ArrayIterator($files), __DIR__);

// Add autoloader
$phar->addFile('autoload.php', 'autoload.php');

// Create stub that will be executed when PHAR is run
$stub = <<<'STUB'
#!/usr/bin/env php
<?php
Phar::mapPhar('db_updater.phar');

// Register autoloader for PHAR context
spl_autoload_register(function ($class) {
    $prefix = 'DbUpdater\\';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relative_class = substr($class, $len);
    $file = 'phar://db_updater.phar/src/' . str_replace('\\', '/', $relative_class) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});

// Parse command line arguments
$ddlFile = null;
$dryRun = false;
$jsonOutput = false;
$configFile = null;

foreach ($argv as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
    } elseif ($arg === '--json') {
        $jsonOutput = true;
    } elseif (strpos($arg, '--config=') === 0) {
        $configFile = substr($arg, 9);
    } elseif ($arg !== $argv[0] && $ddlFile === null) {
        $ddlFile = $arg;
    }
}

// Auto-detect config file if not specified
if ($configFile === null) {
    if (file_exists('config.json')) {
        $configFile = 'config.json';
    } elseif (file_exists('config.php')) {
        $configFile = 'config.php';
    } else {
        $configFile = 'config.php'; // Default fallback
    }
}

// Validate arguments
if ($ddlFile === null) {
    echo "Usage: php db_updater.phar <ddl_file> [--dry-run] [--json] [--config=<config_file>]\n";
    echo "\n";
    echo "Options:\n";
    echo "  --dry-run          Preview changes without applying them\n";
    echo "  --json             Output results in JSON format (for parsing execution results)\n";
    echo "  --config=<file>    Path to configuration file (default: config.php or config.json)\n";
    exit(1);
}

// Load configuration
if (!file_exists($configFile)) {
    echo "Error: Configuration file not found: {$configFile}\n";
    echo "Please copy config.example.php to config.php (or config.example.json to config.json) and update with your database credentials.\n";
    exit(1);
}

$config = null;
$extension = strtolower(pathinfo($configFile, PATHINFO_EXTENSION));

if ($extension === 'json') {
    // Load JSON configuration
    $jsonContent = file_get_contents($configFile);
    $config = json_decode($jsonContent, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "Error: Invalid JSON in configuration file: {$configFile}\n";
        echo "JSON error: " . json_last_error_msg() . "\n";
        exit(1);
    }
} else {
    // Load PHP configuration (default)
    $config = require $configFile;
}

if (!is_array($config)) {
    echo "Error: Configuration file must return an array (PHP) or be a valid JSON object.\n";
    exit(1);
}

use DbUpdater\DatabaseHandler;
use DbUpdater\Logger;
use DbUpdater\SchemaExtractor;
use DbUpdater\SchemaParser;
use DbUpdater\SchemaComparator;
use DbUpdater\SqlGenerator;
use DbUpdater\DdlGenerator;

class DbUpdater
{
    private $config;
    private $logger;
    private $db;
    private $dryRun;
    private $jsonOutput;

    public function __construct(array $config, bool $dryRun = false, bool $jsonOutput = false)
    {
        $this->config = $config;
        $this->dryRun = $dryRun;
        $this->jsonOutput = $jsonOutput;
        // Disable file logging in JSON mode
        $loggingConfig = $config['logging'] ?? [];
        if ($jsonOutput) {
            $loggingConfig['enabled'] = false;
        }
        $this->logger = new Logger($loggingConfig);
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
                if ($this->jsonOutput) {
                    echo json_encode([
                        'status' => 'success',
                        'success' => true,
                        'message' => 'Database schema matches desired state. No changes needed.',
                        'statements_executed' => 0
                    ], JSON_PRETTY_PRINT) . "\n";
                    return;
                }
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
                if ($this->jsonOutput) {
                    echo json_encode([
                        'status' => 'success',
                        'success' => true,
                        'message' => 'Database schema is already up to date. No changes needed.',
                        'statements_executed' => 0
                    ], JSON_PRETTY_PRINT) . "\n";
                    return;
                }
                $this->logger->info("Database schema is already up to date. No changes needed.");
                return;
            }

            // Display SQL statements
            if (!$this->jsonOutput) {
                $this->logger->info("Generated " . count($sqlStatements) . " SQL statement(s) to apply:");
                echo "\n" . str_repeat("=", 80) . "\n";
                echo "SQL STATEMENTS TO APPLY:\n";
                echo str_repeat("=", 80) . "\n\n";

                foreach ($sqlStatements as $index => $sql) {
                    echo ($index + 1) . ". " . $sql . "\n\n";
                }

                echo str_repeat("=", 80) . "\n\n";
            }

            // Apply changes if not in dry-run mode
            if ($this->dryRun) {
                if ($this->jsonOutput) {
                    echo json_encode([
                        'status' => 'dry_run',
                        'success' => null,
                        'message' => 'Dry-run mode: SQL statements would be executed',
                        'statements_count' => count($sqlStatements),
                        'sql_statements' => $sqlStatements
                    ], JSON_PRETTY_PRINT) . "\n";
                    return;
                }
                $this->logger->info("Dry-run mode: Skipping execution of SQL statements");
                return;
            }

            // Confirm before applying (skip in non-interactive mode or JSON output)
            if (!$this->jsonOutput && function_exists('posix_isatty') && posix_isatty(STDIN)) {
                echo "Do you want to apply these changes? (yes/no): ";
                $handle = fopen("php://stdin", "r");
                $line = trim(fgets($handle));
                fclose($handle);

                if (strtolower($line) !== 'yes' && strtolower($line) !== 'y') {
                    $this->logger->info("Operation cancelled by user");
                    return;
                }
            } elseif (!$this->jsonOutput) {
                $this->logger->warning("Non-interactive mode: Proceeding with changes automatically");
            }

            // Execute SQL statements
            if (!$this->jsonOutput) {
                $this->logger->info("Applying changes to database...");
            }
            
            $executedCount = 0;
            foreach ($sqlStatements as $sql) {
                try {
                    $this->db->execute($sql);
                    $executedCount++;
                } catch (\Exception $e) {
                    if ($this->jsonOutput) {
                        echo json_encode([
                            'status' => 'error',
                            'success' => false,
                            'message' => 'Failed to execute SQL statement',
                            'error' => $e->getMessage(),
                            'failed_statement' => $sql,
                            'statements_executed' => $executedCount,
                            'statements_total' => count($sqlStatements)
                        ], JSON_PRETTY_PRINT) . "\n";
                        exit(1);
                    }
                    $this->logger->error("Failed to execute SQL: " . $e->getMessage());
                    $this->logger->error("SQL was: " . $sql);
                    throw $e;
                }
            }

            if ($this->jsonOutput) {
                echo json_encode([
                    'status' => 'success',
                    'success' => true,
                    'message' => 'Database update completed successfully',
                    'statements_executed' => $executedCount
                ], JSON_PRETTY_PRINT) . "\n";
                return;
            }

            $this->logger->info("Database update completed successfully!");

        } catch (\Exception $e) {
            if ($this->jsonOutput) {
                echo json_encode([
                    'status' => 'error',
                    'success' => false,
                    'message' => 'An error occurred during schema comparison or execution',
                    'error' => $e->getMessage()
                ], JSON_PRETTY_PRINT) . "\n";
                exit(1);
            }
            $this->logger->error("Error: " . $e->getMessage());
            $this->logger->error("Stack trace: " . $e->getTraceAsString());
            exit(1);
        }
    }

}

// Run the updater
$updater = new DbUpdater($config, $dryRun, $jsonOutput);
$updater->run($ddlFile);

__HALT_COMPILER();
STUB;

$phar->setStub($stub);

// Stop buffering and write the PHAR
$phar->stopBuffering();

// Make PHAR executable
chmod($pharFile, 0755);

echo "PHAR archive created successfully: {$pharFile}\n";
echo "You can now use it as: php db_updater.phar <ddl_file> [options]\n";
echo "Or make it executable and run directly: ./db_updater.phar <ddl_file> [options]\n";

