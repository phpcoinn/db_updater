# Database Updater Tool

A PHP tool that synchronizes MySQL database schema by comparing the current state with a desired state defined in a DDL file, then automatically applying necessary changes.

## Features

- Parses DDL files containing CREATE TABLE statements
- Extracts current database schema from MySQL INFORMATION_SCHEMA
- Compares current vs desired schemas
- Detects differences in:
  - Tables (missing tables)
  - Columns (missing, modified)
  - Indexes (missing, changed)
  - Foreign keys (missing, changed)
  - Table options (engine, collation, etc.)
- Generates appropriate ALTER TABLE and CREATE TABLE statements
- Dry-run mode to preview changes without applying
- Detailed logging of all operations

## Installation

1. Copy `config.example.php` to `config.php` and update with your database credentials:

```bash
cp config.example.php config.php
```

2. Edit `config.php` with your database connection details.

## Usage

### Basic Usage

```bash
php db_updater.php schema.sql
```

### Dry-Run Mode

Preview changes without applying them:

```bash
php db_updater.php schema.sql --dry-run
```

### Custom Configuration File

```bash
php db_updater.php schema.sql --config=my_config.php
```

## Generating Schema from Existing Database

You can generate a `schema.sql` file from your existing database using MySQL's `mysqldump` command or the included PHP utility.

### Using MySQL mysqldump (Recommended)

The easiest way is to use MySQL's native `mysqldump` command:

```bash
# Basic usage - schema only (no data)
mysqldump -u username -p --no-data database_name > schema.sql

# With host and port
mysqldump -h localhost -P 3306 -u username -p --no-data database_name > schema.sql

# Schema only, single transaction, add drop statements
mysqldump -u username -p --no-data --single-transaction --add-drop-table database_name > schema.sql

# Schema only, skip comments and auto-increment values
mysqldump -u username -p --no-data --skip-comments --skip-add-drop-table database_name > schema.sql
```

**Common mysqldump options:**
- `--no-data` or `-d` - Export schema only, no data
- `--single-transaction` - Ensures consistent snapshot (for InnoDB)
- `--add-drop-table` - Add DROP TABLE statements before CREATE
- `--skip-add-drop-table` - Don't add DROP TABLE statements
- `--skip-comments` - Skip comments in output
- `--routines` - Include stored procedures and functions
- `--triggers` - Include triggers
- `--events` - Include events

**Example:**
```bash
mysqldump -u myuser -p --no-data --single-transaction my_database > schema.sql
```

**Example workflow:**

1. Generate schema from your current database:
   ```bash
   mysqldump -u username -p --no-data database_name > schema.sql
   ```

2. Review and edit `schema.sql` if needed (add new tables, modify columns, etc.)

3. Use `db_updater.php` to apply changes:
   ```bash
   php db_updater.php schema.sql --dry-run  # Preview changes
   php db_updater.php schema.sql             # Apply changes
   ```

## DDL File Format

The DDL file should contain standard MySQL CREATE TABLE statements:

```sql
CREATE TABLE `users` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `posts` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `content` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `fk_posts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## How It Works

1. **Parse DDL File**: Reads and parses the DDL file to extract desired schema structure
2. **Extract Current Schema**: Queries MySQL INFORMATION_SCHEMA to get current database structure
3. **Compare Schemas**: Identifies differences between current and desired states
4. **Generate SQL**: Creates ALTER TABLE and CREATE TABLE statements for identified differences
5. **Apply Changes**: Executes SQL statements (unless in dry-run mode)

## Safety Features

- **Dry-run mode**: Preview all changes before applying
- **Confirmation prompt**: Asks for confirmation before applying changes
- **Detailed logging**: All operations are logged to `db_updater.log`
- **Error handling**: Stops execution on errors and logs detailed error information

## Limitations

- Currently does not drop tables or columns (for safety)
- Does not handle data migrations
- Requires MySQL/MariaDB database
- DDL parser may not handle all MySQL-specific syntax variations

## Configuration

### Configuration File Structure

The configuration file (`config.php`) supports the following options:

```php
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
    'ignore_columns' => [
        // Format: 'table_name.column_name' (specific table/column)
        'transactions.legacy_column',
        'users.old_field',
        
        // Or just 'column_name' (ignores in all tables)
        'version_specific_column',
    ],
];
```

### Ignoring Columns

When supporting multiple database versions, you may have columns that exist in one version but not another. Use the `ignore_columns` configuration option to exclude specific columns from schema comparison:

**Table-specific columns:**
```php
'ignore_columns' => [
    'transactions.old_column',  // Ignores 'old_column' only in 'transactions' table
    'users.legacy_field',       // Ignores 'legacy_field' only in 'users' table
],
```

**Global columns (all tables):**
```php
'ignore_columns' => [
    'version_column',  // Ignores 'version_column' in ALL tables
],
```

**Mixed usage:**
```php
'ignore_columns' => [
    'transactions.specific_column',  // Table-specific
    'global_column',                  // All tables
],
```

Ignored columns will:
- Not be added if missing
- Not be modified if different
- Not be dropped if extra

### Ignoring Tables

When supporting multiple database versions, you may have tables that exist in one version but not another. Use the `ignore_tables` configuration option to exclude entire tables from schema comparison:

```php
'ignore_tables' => [
    'legacy_table',      // Ignores 'legacy_table' completely
    'old_version_table', // Ignores 'old_version_table' completely
    'temporary_table',   // Ignores 'temporary_table' completely
],
```

Ignored tables will:
- Not be created if missing
- Not be compared/modified if existing
- Not be dropped if extra

## Logging

All operations are logged to `db_updater.log` (configurable in `config.php`). Log levels:
- DEBUG: Detailed debugging information
- INFO: General information about operations
- WARNING: Warning messages
- ERROR: Error messages

## Requirements

- PHP 7.4 or higher
- PDO extension
- PDO MySQL extension
- MySQL/MariaDB database

