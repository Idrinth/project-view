<?php
/**
 * User management CLI for Project View.
 *
 * Creates, updates and deletes accounts in the `users` table so they
 * can sign in via the login endpoint. Passwords are hashed with
 * password_hash() before being stored and are never echoed back.
 *
 * Usage:
 *   php bin/users.php list
 *   php bin/users.php add     <username> [password]
 *   php bin/users.php passwd  <username> [password]
 *   php bin/users.php rename  <old-username> <new-username>
 *   php bin/users.php delete  <username>
 *
 * When the password is omitted on `add` or `passwd` it is read from
 * stdin without echoing. Provide the password as an argument only in
 * trusted, non-interactive contexts (it may otherwise leak via the
 * process list or shell history).
 */

declare(strict_types=1);

require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/Users.php';

use ProjectView\Database;
use ProjectView\Users;

/**
 * Print usage information to the given stream.
 *
 * @param resource $stream
 */
function users_print_usage($stream): void
{
    fwrite($stream, <<<USAGE
Usage:
  php bin/users.php list
  php bin/users.php add     <username> [password]
  php bin/users.php passwd  <username> [password]
  php bin/users.php rename  <old-username> <new-username>
  php bin/users.php delete  <username>

USAGE
    );
}

/**
 * Read a password from stdin without echoing it back to the terminal.
 * Falls back to a plain read if stty is unavailable (e.g. on Windows
 * or when stdin is not a tty), so automated pipelines still work.
 */
function users_read_password(string $prompt): string
{
    fwrite(STDERR, $prompt);

    $silenced = false;
    if (function_exists('shell_exec') && stream_isatty(STDIN)) {
        $stty = shell_exec('stty -g 2>/dev/null');
        if (is_string($stty) && $stty !== '') {
            shell_exec('stty -echo 2>/dev/null');
            $silenced = true;
        }
    }

    $password = fgets(STDIN);

    if ($silenced) {
        shell_exec('stty ' . escapeshellarg(trim((string) $stty)) . ' 2>/dev/null');
        fwrite(STDERR, "\n");
    }

    if ($password === false) {
        return '';
    }
    return rtrim($password, "\r\n");
}

/**
 * Resolve a password from either the provided argument or an
 * interactive prompt. On `add` and `passwd` the prompt confirms the
 * password to guard against typos.
 */
function users_resolve_password(?string $argument, bool $confirm): string
{
    if ($argument !== null) {
        if ($argument === '') {
            fwrite(STDERR, "error: password must not be empty\n");
            exit(1);
        }
        return $argument;
    }

    $password = users_read_password('Password: ');
    if ($password === '') {
        fwrite(STDERR, "error: password must not be empty\n");
        exit(1);
    }
    if ($confirm) {
        $again = users_read_password('Confirm password: ');
        if ($password !== $again) {
            fwrite(STDERR, "error: passwords do not match\n");
            exit(1);
        }
    }
    return $password;
}

$argv    = $_SERVER['argv'] ?? [];
$command = $argv[1] ?? '';

if ($command === '' || $command === 'help' || $command === '--help' || $command === '-h') {
    users_print_usage(STDOUT);
    exit($command === '' ? 1 : 0);
}

try {
    $users = new Users(new Database());
} catch (\Throwable $e) {
    fwrite(STDERR, 'failed to open database: ' . $e->getMessage() . "\n");
    fwrite(STDERR, "hint: run `php bin/migrate.php` first.\n");
    exit(1);
}

try {
    switch ($command) {
        case 'list':
            $rows = $users->all();
            if ($rows === []) {
                echo "(no users)\n";
                break;
            }
            printf("%-5s %-24s %s\n", 'ID', 'USERNAME', 'CREATED');
            foreach ($rows as $row) {
                printf(
                    "%-5s %-24s %s\n",
                    (string) ($row['id'] ?? ''),
                    (string) ($row['username'] ?? ''),
                    (string) ($row['created_at'] ?? '')
                );
            }
            break;

        case 'add':
            $username = $argv[2] ?? '';
            if ($username === '') {
                fwrite(STDERR, "usage: php bin/users.php add <username> [password]\n");
                exit(1);
            }
            if ($users->findByUsername($username) !== null) {
                fwrite(STDERR, "error: user '{$username}' already exists\n");
                exit(1);
            }
            $password = users_resolve_password($argv[3] ?? null, true);
            $id = $users->create($username, $password);
            echo "created user '{$username}' (id {$id})\n";
            break;

        case 'passwd':
            $username = $argv[2] ?? '';
            if ($username === '') {
                fwrite(STDERR, "usage: php bin/users.php passwd <username> [password]\n");
                exit(1);
            }
            $existing = $users->findByUsername($username);
            if ($existing === null) {
                fwrite(STDERR, "error: user '{$username}' does not exist\n");
                exit(1);
            }
            $password = users_resolve_password($argv[3] ?? null, true);
            $users->setPassword((int) $existing['id'], $password);
            echo "updated password for '{$username}'\n";
            break;

        case 'rename':
            $old = $argv[2] ?? '';
            $new = $argv[3] ?? '';
            if ($old === '' || $new === '') {
                fwrite(STDERR, "usage: php bin/users.php rename <old-username> <new-username>\n");
                exit(1);
            }
            $existing = $users->findByUsername($old);
            if ($existing === null) {
                fwrite(STDERR, "error: user '{$old}' does not exist\n");
                exit(1);
            }
            if ($old !== $new && $users->findByUsername($new) !== null) {
                fwrite(STDERR, "error: user '{$new}' already exists\n");
                exit(1);
            }
            $users->rename((int) $existing['id'], $new);
            echo "renamed '{$old}' to '{$new}'\n";
            break;

        case 'delete':
            $username = $argv[2] ?? '';
            if ($username === '') {
                fwrite(STDERR, "usage: php bin/users.php delete <username>\n");
                exit(1);
            }
            $removed = $users->deleteByUsername($username);
            if ($removed === 0) {
                fwrite(STDERR, "error: user '{$username}' does not exist\n");
                exit(1);
            }
            echo "deleted user '{$username}'\n";
            break;

        default:
            fwrite(STDERR, "unknown command: {$command}\n");
            users_print_usage(STDERR);
            exit(1);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");
    exit(1);
}
