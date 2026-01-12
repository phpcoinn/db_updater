<?php

namespace DbUpdater;

use PDO;
use PDOException;

class DatabaseHandler
{
    private $pdo;
    private $logger;

    public function __construct(array $config, Logger $logger)
    {
        $this->logger = $logger;
        $this->connect($config);
    }

    private function connect(array $config): void
    {
        $username = $config['username'] ?? '';
        $password = $config['password'] ?? '';
        
        // Check if DSN is provided directly
        if (isset($config['dsn']) && !empty($config['dsn'])) {
            $dsn = $config['dsn'];
            // Extract database name from DSN for logging if possible
            $dbname = $this->extractDbnameFromDsn($dsn) ?? ($config['dbname'] ?? '');
        } else {
            // Construct DSN from individual parameters
            $host = $config['host'] ?? 'localhost';
            $port = $config['port'] ?? 3306;
            $dbname = $config['dbname'] ?? '';
            $charset = $config['charset'] ?? 'utf8mb4';
            
            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";
        }

        try {
            $this->pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $this->logger->info("Connected to database: " . ($dbname ?: 'unknown'));
        } catch (PDOException $e) {
            $this->logger->error("Database connection failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Extract database name from DSN string
     */
    private function extractDbnameFromDsn(string $dsn): ?string
    {
        // Parse DSN: mysql:host=...;dbname=...;charset=...
        if (preg_match('/dbname=([^;]+)/i', $dsn, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    public function query(string $sql): array
    {
        try {
            $this->logger->debug("Executing query: {$sql}");
            $stmt = $this->pdo->query($sql);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            $this->logger->error("Query failed: {$sql}", ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function execute(string $sql): bool
    {
        try {
            $this->logger->logSql($sql);
            $result = $this->pdo->exec($sql);
            $this->logger->info("Query executed successfully, affected rows: {$result}");
            return true;
        } catch (PDOException $e) {
            $this->logger->error("Execution failed: {$sql}", ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function getDatabaseName(): string
    {
        return $this->pdo->query("SELECT DATABASE()")->fetchColumn();
    }

}

