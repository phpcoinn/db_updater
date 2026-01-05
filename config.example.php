<?php
/**
 * Database Updater Configuration Example
 * 
 * Copy this file to config.php and update with your database credentials
 */

return [
    'database' => [
        'host' => 'localhost',
        'port' => 3306,
        'dbname' => 'your_database_name',
        'username' => 'your_username',
        'password' => 'your_password',
        'charset' => 'utf8mb4',
    ],
    
    'logging' => [
        'enabled' => true,
        'file' => 'db_updater.log',
        'level' => 'INFO', // DEBUG, INFO, WARNING, ERROR
    ],
    
    // Columns to ignore during schema comparison
    // Useful when supporting multiple database versions
    // Format: 'table_name.column_name' or just 'column_name' to ignore in all tables
    'ignore_columns' => [
        // Examples:
        // 'transactions.old_column',
        // 'users.legacy_field',
        // 'version_specific_column', // ignores this column in all tables
    ],
    
    // Tables to ignore during schema comparison
    // Useful when supporting multiple database versions
    // Format: array of table names to completely skip
    'ignore_tables' => [
        // Examples:
        // 'legacy_table',
        // 'old_version_table',
        // 'temporary_table',
    ],
];

