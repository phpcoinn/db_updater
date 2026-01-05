<?php

namespace DbUpdater;

class Logger
{
    private $enabled;
    private $logFile;
    private $level;
    private $levels = ['DEBUG' => 0, 'INFO' => 1, 'WARNING' => 2, 'ERROR' => 3];

    public function __construct(array $config = [])
    {
        $this->enabled = $config['enabled'] ?? true;
        $this->logFile = $config['file'] ?? 'db_updater.log';
        $this->level = $this->levels[strtoupper($config['level'] ?? 'INFO')] ?? 1;
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('DEBUG', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('INFO', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('WARNING', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('ERROR', $message, $context);
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if (!$this->enabled) {
            return;
        }

        $levelValue = $this->levels[$level] ?? 1;
        if ($levelValue < $this->level) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' ' . json_encode($context) : '';
        $logMessage = "[{$timestamp}] [{$level}] {$message}{$contextStr}" . PHP_EOL;

        // Write to file
        file_put_contents($this->logFile, $logMessage, FILE_APPEND);

        // Also output to console
        echo $logMessage;
    }

    public function logSql(string $sql): void
    {
        $this->info("SQL: {$sql}");
    }
}

