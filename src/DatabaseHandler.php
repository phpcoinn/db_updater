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
        $host = $config['host'] ?? 'localhost';
        $port = $config['port'] ?? 3306;
        $dbname = $config['dbname'] ?? '';
        $username = $config['username'] ?? '';
        $password = $config['password'] ?? '';
        $charset = $config['charset'] ?? 'utf8mb4';

        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

        try {
            $this->pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $this->logger->info("Connected to database: {$dbname}");
        } catch (PDOException $e) {
            $this->logger->error("Database connection failed: " . $e->getMessage());
            throw $e;
        }
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

