<?php
/**
 * Database configuration for Project View.
 *
 * Connection settings for the PDO-backed datastore that the API
 * reads from and writes to. This file lives outside of public/ and
 * is only loaded server-side by src/Database.php.
 *
 * The default DSN is SQLite, because the project deliberately ships
 * with no third-party PHP dependencies and the SQLite PDO driver is
 * part of the standard PHP build. Swap the DSN (and credentials) for
 * a MySQL or PostgreSQL one when a single file no longer fits.
 *
 * The SQLite database file is created on first migration; keep it
 * out of version control (see .gitignore).
 */

declare(strict_types=1);

$root = dirname(__DIR__);

return [
    // PDO DSN. The default writes to config/data.sqlite next to this
    // file; replace it with e.g. 'mysql:host=localhost;dbname=pv'
    // when moving off SQLite.
    'dsn' => 'sqlite:' . $root . '/config/data.sqlite',

    // Credentials. SQLite does not use them; keep null for it.
    'username' => null,
    'password' => null,

    // Extra PDO options. Merged on top of the safe defaults set in
    // src/Database.php (exception error mode, associative fetches,
    // real prepared statements).
    'options' => [],
];
