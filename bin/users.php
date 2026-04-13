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
 *   php bin/users.php access  list    <username>
 *   php bin/users.php access  grant   <username> <category-path>
 *   php bin/users.php access  revoke  <username> <category-path>
 *
 * When the password is omitted on `add` or `passwd` it is read from
 * stdin without echoing. Provide the password as an argument only in
 * trusted, non-interactive contexts (it may otherwise leak via the
 * process list or shell history).
 *
 * Category paths for `access` are "/" separated, e.g.
 * "Mods/Skyrim/Idrinth Thalui". A grant at a node implicitly covers
 * every descendant project. The admin account (user id 1) has full
 * access everywhere and bypasses these grants entirely.
 */

declare(strict_types=1);

require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/Users.php';
require __DIR__ . '/../src/Projects.php';
require __DIR__ . '/../src/ProjectAccess.php';

use ProjectView\Database;
use ProjectView\ProjectAccess;
use ProjectView\Projects;
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
  php bin/users.php access  list    <username>
  php bin/users.php access  grant   <username> <category-path>
  php bin/users.php access  revoke  <username> <category-path>

Category paths are "/" separated, e.g. "Mods/Skyrim/Idrinth Thalui".
A grant covers the named project and every descendant. The admin
account (user id 1) always has full access.

USAGE
    );
}

/**
 * Resolve a "/" separated category path to the matching leaf project
 * id. Returns null when any segment is missing — creation of
 * categories is the API's job, not the CLI's.
 */
function users_resolve_project_id(Projects $projects, string $path): ?int
{
    $segments = [];
    foreach (preg_split('#/#', $path) ?: [] as $piece) {
        $trimmed = trim((string) $piece);
        if ($trimmed !== '') {
            $segments[] = $trimmed;
        }
    }
    if ($segments === []) {
        return null;
    }
    $parentId = null;
    $leafId = 0;
    foreach ($segments as $name) {
        $row = $projects->findByParentAndName($parentId, $name);
        if ($row === null) {
            return null;
        }
        $leafId = (int) $row['id'];
        $parentId = $leafId;
    }
    return $leafId;
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
    $db = new Database();
    $users = new Users($db);
    $projects = new Projects($db);
    $projectAccess = new ProjectAccess($db);
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

        case 'access':
            $sub = $argv[2] ?? '';
            if ($sub === '' || $sub === 'help' || $sub === '--help' || $sub === '-h') {
                users_print_usage($sub === '' ? STDERR : STDOUT);
                exit($sub === '' ? 1 : 0);
            }
            $username = $argv[3] ?? '';
            if ($username === '') {
                fwrite(STDERR, "usage: php bin/users.php access {$sub} <username> …\n");
                exit(1);
            }
            $existing = $users->findByUsername($username);
            if ($existing === null) {
                fwrite(STDERR, "error: user '{$username}' does not exist\n");
                exit(1);
            }
            $userId = (int) $existing['id'];

            // Surface the admin bypass so operators understand why the
            // table may be empty yet the user can still edit.
            if ($userId === ProjectAccess::ADMIN_USER_ID) {
                echo "note: user '{$username}' (id {$userId}) is the admin account "
                   . "and always has full edit access; grants below are informational only.\n";
            }

            switch ($sub) {
                case 'list':
                    $ids = $projectAccess->projectIdsForUser($userId);
                    if ($ids === []) {
                        echo "(no grants)\n";
                        break;
                    }
                    $paths = $projects->allPaths();
                    printf("%-5s %s\n", 'PID', 'CATEGORY');
                    foreach ($ids as $pid) {
                        $path = $paths[$pid] ?? ['<missing project>'];
                        printf("%-5d %s\n", $pid, implode('/', $path));
                    }
                    break;

                case 'grant':
                    $path = $argv[4] ?? '';
                    if ($path === '') {
                        fwrite(STDERR, "usage: php bin/users.php access grant <username> <category-path>\n");
                        exit(1);
                    }
                    $projectId = users_resolve_project_id($projects, $path);
                    if ($projectId === null) {
                        fwrite(STDERR, "error: category '{$path}' does not exist\n");
                        exit(1);
                    }
                    $projectAccess->grant($userId, $projectId);
                    echo "granted '{$username}' edit access on '{$path}' (project id {$projectId})\n";
                    break;

                case 'revoke':
                    $path = $argv[4] ?? '';
                    if ($path === '') {
                        fwrite(STDERR, "usage: php bin/users.php access revoke <username> <category-path>\n");
                        exit(1);
                    }
                    $projectId = users_resolve_project_id($projects, $path);
                    if ($projectId === null) {
                        fwrite(STDERR, "error: category '{$path}' does not exist\n");
                        exit(1);
                    }
                    $removed = $projectAccess->revoke($userId, $projectId);
                    if ($removed === 0) {
                        fwrite(STDERR, "error: no direct grant on '{$path}' for '{$username}'\n");
                        exit(1);
                    }
                    echo "revoked '{$username}' edit access on '{$path}' (project id {$projectId})\n";
                    break;

                default:
                    fwrite(STDERR, "unknown access subcommand: {$sub}\n");
                    users_print_usage(STDERR);
                    exit(1);
            }
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
