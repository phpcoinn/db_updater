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
    echo "Usage: php db_updater.phar <ddl_file> [--dry-run] [--config=<config_file>]\n";
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

// Run the updater
$updater = new DbUpdater($config, $dryRun);
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

