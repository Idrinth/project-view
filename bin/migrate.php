<?php
/**
 * Database migration runner for Project View.
 *
 * Creates every table and index defined in src/Database.php that
 * doesn't already exist. Safe to re-run: existing tables and data
 * are left untouched.
 *
 * Usage:
 *   php bin/migrate.php
 */

declare(strict_types=1);

require __DIR__ . '/../src/Database.php';

try {
    $db = new \ProjectView\Database();
    $db->migrate();
    echo "Database schema is up to date.\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "migration failed: " . $e->getMessage() . "\n");
    exit(1);
}
