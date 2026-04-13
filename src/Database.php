<?php
/**
 * PDO-backed database gateway for Project View.
 *
 * Owns the PDO connection and the schema migration. Repositories in
 * this directory (Projects, Milestones, Issues, TimeEntries,
 * TimeAggregates) accept a Database instance and run prepared
 * statements through it.
 *
 * The schema is intentionally portable across SQLite (the default)
 * and other major PDO drivers: column types are kept generic, and
 * the only modern bit of SQL the repositories rely on is the
 * `INSERT ... ON CONFLICT DO UPDATE` upsert clause, which works on
 * SQLite 3.24+ and PostgreSQL.
 */

declare(strict_types=1);

namespace ProjectView;

final class Database
{
    private \PDO $pdo;

    /**
     * @param array<string, mixed>|null $config Optional pre-loaded
     *   configuration. Primarily intended for tests; in normal use
     *   the constructor loads config/database.php from disk.
     */
    public function __construct(?array $config = null)
    {
        if ($config === null) {
            $path = __DIR__ . '/../config/database.php';
            if (!is_file($path)) {
                throw new \RuntimeException('database config not found: ' . $path);
            }
            /** @var mixed $loaded */
            $loaded = require $path;
            if (!is_array($loaded)) {
                throw new \RuntimeException('database config must return an array');
            }
            $config = $loaded;
        }

        $config += [
            'dsn'      => '',
            'username' => null,
            'password' => null,
            'options'  => [],
        ];

        if (!is_string($config['dsn']) || $config['dsn'] === '') {
            throw new \RuntimeException('database config: dsn must be a non-empty string');
        }

        $options = is_array($config['options']) ? $config['options'] : [];
        $options += [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        $username = is_string($config['username']) ? $config['username'] : null;
        $password = is_string($config['password']) ? $config['password'] : null;

        $this->pdo = new \PDO($config['dsn'], $username, $password, $options);

        // Enforce foreign keys on SQLite (off by default for legacy
        // reasons). No-op on other drivers.
        if ($this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $this->pdo->exec('PRAGMA foreign_keys = ON');
        }
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    /**
     * Create every table and index in the schema if missing. Safe to
     * run more than once: every statement uses IF NOT EXISTS, so a
     * second invocation is a no-op.
     *
     * A small set of in-place upgrades (adding newly introduced
     * columns on older installs) runs after the CREATE statements so
     * existing databases pick up new features without losing data.
     * Replace this with a real migration tool once the schema needs
     * to change in a way these idempotent helpers cannot express.
     */
    public function migrate(): void
    {
        foreach (self::schema() as $sql) {
            $this->pdo->exec($sql);
        }
        $this->applyInPlaceMigrations();
    }

    /**
     * Idempotent upgrades for schemas that predate a column. Each
     * branch checks for the absence of the column and adds it with a
     * backwards-compatible default.
     */
    private function applyInPlaceMigrations(): void
    {
        if (!$this->hasColumn('projects', 'parent_id')) {
            // Adds the self-referencing grouping column for installs
            // that predate the project hierarchy. New installs get
            // the column from schema()'s CREATE TABLE already.
            $this->pdo->exec(
                'ALTER TABLE projects ADD COLUMN parent_id INTEGER NULL REFERENCES projects(id) ON DELETE CASCADE'
            );
        }
        // Ensure the companion index exists. Runs after the ALTER
        // above so the column is always present when the index is
        // built; IF NOT EXISTS keeps it a no-op on later runs.
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_projects_parent ON projects(parent_id)');

        // Pre-hierarchy installs declared `projects.name` as globally
        // UNIQUE, which now blocks siblings under different parents
        // from sharing a name (the kanban-add endpoint hits this as a
        // "UNIQUE constraint failed: projects.name" 500 when users
        // type a category like "Mods/Warhammer III/Idrinth Thalui"
        // while another "Idrinth Thalui" already lives under Skyrim).
        // The current schema uses UNIQUE(parent_id, name) instead, so
        // drop the legacy standalone constraint when we spot it.
        if ($this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite'
            && $this->hasLegacyProjectNameUnique()) {
            $this->rebuildProjectsTable();
        }

        if (!$this->hasColumn('issues', 'description')) {
            // Adds the free-form description column for installs that
            // predate it. Existing rows get NULL, which renders as
            // "no description" in the UI.
            $this->pdo->exec('ALTER TABLE issues ADD COLUMN description TEXT NULL');
        }

        if (!$this->hasColumn('time_entries', 'user_id')) {
            // Adds the per-entry author column for installs that predate
            // it. Existing rows are backfilled with user-id 1 (the
            // bootstrap account) via the column default, so historical
            // time entries stay visible and aggregations keep working.
            // The FK to users(id) is intentionally omitted on the
            // ALTER path: SQLite forbids adding a NOT NULL REFERENCES
            // column except with a NULL default. Fresh installs still
            // get the FK from the CREATE TABLE in schema().
            $this->pdo->exec(
                'ALTER TABLE time_entries ADD COLUMN user_id INTEGER NOT NULL DEFAULT 1'
            );
        }
        // Ensure the companion index exists. Runs after the ALTER above
        // so the column is always present when the index is built; IF
        // NOT EXISTS keeps it a no-op on fresh installs that already
        // built the index from schema().
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_time_entries_user ON time_entries(user_id)');

        // Add the self-service profile columns on older installs. Each
        // column is nullable so historical rows stay valid; new fields
        // show up empty until the user edits their profile.
        foreach (['display_name', 'website_url', 'about', 'avatar_mime', 'avatar_data'] as $column) {
            if (!$this->hasColumn('users', $column)) {
                $this->pdo->exec('ALTER TABLE users ADD COLUMN ' . $column . ' TEXT NULL');
            }
        }

        // Collapse the legacy split 'waiting-external' / 'waiting-internal'
        // statuses into a single 'waiting' status. Older installs have
        // rows tagged with the old values; the kanban only renders one
        // "Waiting" column now, and any card whose status is not in
        // Issues::STATUSES would silently disappear from the board.
        // Idempotent: the UPDATE is a no-op once every row is migrated.
        $this->pdo->exec(
            "UPDATE issues SET status = 'waiting'
              WHERE status IN ('waiting-external', 'waiting-internal')"
        );
    }

    /**
     * Detect the legacy single-column UNIQUE(name) constraint on the
     * projects table (inline `name TEXT NOT NULL UNIQUE` from the
     * pre-hierarchy schema). Only called on SQLite; other drivers
     * need their own migration path.
     */
    private function hasLegacyProjectNameUnique(): bool
    {
        $stmt = $this->pdo->query('PRAGMA index_list(projects)');
        if ($stmt === false) {
            return false;
        }
        /** @var list<array<string, mixed>> $indexes */
        $indexes = $stmt->fetchAll();
        foreach ($indexes as $idx) {
            if (!isset($idx['unique']) || (int) $idx['unique'] !== 1) {
                continue;
            }
            $name = isset($idx['name']) ? (string) $idx['name'] : '';
            if ($name === '') {
                continue;
            }
            $cols = $this->pdo->query(
                "PRAGMA index_info('" . str_replace("'", "''", $name) . "')"
            );
            if ($cols === false) {
                continue;
            }
            /** @var list<array<string, mixed>> $columns */
            $columns = $cols->fetchAll();
            if (count($columns) === 1
                && isset($columns[0]['name'])
                && (string) $columns[0]['name'] === 'name') {
                return true;
            }
        }
        return false;
    }

    /**
     * Rebuild the projects table to match the current schema,
     * preserving existing rows. SQLite cannot drop a column-level
     * UNIQUE constraint in place, so we copy into a fresh table with
     * the correct shape, drop the old one and rename. Foreign keys
     * are temporarily disabled so dropping the old table does not
     * cascade into child rows (milestones, issues, ...) whose FKs
     * are re-bound to the renamed table afterwards.
     */
    private function rebuildProjectsTable(): void
    {
        // PRAGMA foreign_keys is a no-op inside a transaction, so
        // toggle it before we start one.
        $this->pdo->exec('PRAGMA foreign_keys = OFF');
        try {
            $this->pdo->beginTransaction();
            try {
                // The parent-index is auto-dropped with the old table,
                // but it may also not exist yet on very old installs;
                // either way, we recreate it from scratch below.
                $this->pdo->exec('DROP INDEX IF EXISTS idx_projects_parent');
                $this->pdo->exec(
                    'CREATE TABLE projects_new (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        parent_id INTEGER NULL,
                        name TEXT NOT NULL,
                        slug TEXT NOT NULL UNIQUE,
                        description TEXT NULL,
                        created_at TEXT NOT NULL,
                        UNIQUE (parent_id, name),
                        FOREIGN KEY (parent_id) REFERENCES projects(id) ON DELETE CASCADE
                    )'
                );
                $this->pdo->exec(
                    'INSERT INTO projects_new (id, parent_id, name, slug, description, created_at)
                     SELECT id, parent_id, name, slug, description, created_at FROM projects'
                );
                $this->pdo->exec('DROP TABLE projects');
                $this->pdo->exec('ALTER TABLE projects_new RENAME TO projects');
                $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_projects_parent ON projects(parent_id)');
                $this->pdo->commit();
            } catch (\Throwable $e) {
                $this->pdo->rollBack();
                throw $e;
            }
        } finally {
            $this->pdo->exec('PRAGMA foreign_keys = ON');
        }
    }

    /**
     * Introspect whether `$table` has `$column`. Supports SQLite
     * (PRAGMA) and any driver that exposes `information_schema`.
     */
    private function hasColumn(string $table, string $column): bool
    {
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $this->pdo->query('PRAGMA table_info(' . $table . ')');
            if ($stmt === false) {
                return false;
            }
            foreach ($stmt->fetchAll() as $row) {
                if (isset($row['name']) && (string) $row['name'] === $column) {
                    return true;
                }
            }
            return false;
        }
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) AS c FROM information_schema.columns
              WHERE table_name = :t AND column_name = :c'
        );
        $stmt->execute(['t' => $table, 'c' => $column]);
        $row = $stmt->fetch();
        return $row !== false && (int) $row['c'] > 0;
    }

    /**
     * Schema as a list of CREATE statements, in dependency order.
     *
     * @return list<string>
     */
    public static function schema(): array
    {
        return [
            // users — accounts allowed to sign in. password_hash is
            // produced with PHP's password_hash() (bcrypt / argon2)
            // and verified with password_verify(). Managed from the
            // CLI with bin/users.php.
            // The optional profile columns (display_name, website_url,
            // about, avatar_mime, avatar_data) hold the self-service
            // profile a signed-in user can edit from profile.html. The
            // avatar image is stored inline as base64 text alongside
            // its MIME type so the build step (which wipes public/)
            // cannot strand uploaded files on disk.
            "CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                display_name TEXT NULL,
                website_url TEXT NULL,
                about TEXT NULL,
                avatar_mime TEXT NULL,
                avatar_data TEXT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )",

            // projects (aka "categories" in the UI) — the actual
            // things being built or managed. Rows form a tree: every
            // project optionally points at a `parent_id` so the UI
            // can group related projects ("Mods > Skyrim > Idrinth
            // Thalui"). Roots have parent_id NULL. Milestones and
            // issues may hang off any node; in practice they live on
            // the leaves but the schema does not enforce that.
            //
            // Name uniqueness is scoped to a parent so sibling
            // projects under different parents can share a name
            // ("Idrinth Thalui" under both Skyrim and Warhammer III).
            // Slugs stay globally unique because they are used as
            // URL identifiers.
            "CREATE TABLE IF NOT EXISTS projects (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                parent_id INTEGER NULL,
                name TEXT NOT NULL,
                slug TEXT NOT NULL UNIQUE,
                description TEXT NULL,
                created_at TEXT NOT NULL,
                UNIQUE (parent_id, name),
                FOREIGN KEY (parent_id) REFERENCES projects(id) ON DELETE CASCADE
            )",
            // The parent_id index is added by applyInPlaceMigrations()
            // after the column has been guaranteed to exist, so the
            // same code path works for both fresh installs (column
            // present from the CREATE TABLE above) and older installs
            // being upgraded (column just added by ALTER TABLE).

            // milestones (aka "releases") — named version markers
            // that belong to a project. released_at is null for
            // planned/unreleased milestones and is filled in once
            // the release ships.
            "CREATE TABLE IF NOT EXISTS milestones (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                released_at TEXT NULL,
                notes TEXT NULL,
                created_at TEXT NOT NULL,
                UNIQUE (project_id, name),
                FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
            )",
            "CREATE INDEX IF NOT EXISTS idx_milestones_project ON milestones(project_id)",
            "CREATE INDEX IF NOT EXISTS idx_milestones_released_at ON milestones(released_at)",

            // issues (aka "tasks" — the cards on the kanban board).
            // status is one of: todo / in-progress / waiting / done /
            // discarded. position lets the frontend persist drag-and-drop
            // order within a column.
            "CREATE TABLE IF NOT EXISTS issues (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id INTEGER NOT NULL,
                milestone_id INTEGER NULL,
                title TEXT NOT NULL,
                description TEXT NULL,
                status TEXT NOT NULL DEFAULT 'todo',
                position INTEGER NOT NULL DEFAULT 0,
                work_started_at TEXT NULL,
                work_completed_at TEXT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
                FOREIGN KEY (milestone_id) REFERENCES milestones(id) ON DELETE SET NULL
            )",
            "CREATE INDEX IF NOT EXISTS idx_issues_project ON issues(project_id)",
            "CREATE INDEX IF NOT EXISTS idx_issues_milestone ON issues(milestone_id)",
            "CREATE INDEX IF NOT EXISTS idx_issues_status ON issues(status)",

            // Raw time tracking entries. Each row records hours spent
            // on a single issue on a specific date, optionally tagged
            // with a work category (e.g. Development, Testing,
            // Research). These rows are the source of truth.
            //
            // user_id attributes the entry to the account that logged
            // it. Defaults to 1 so legacy installs that predate the
            // column get backfilled to the bootstrap user; the API
            // stamps it with the signed-in user on every new entry.
            "CREATE TABLE IF NOT EXISTS time_entries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                issue_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL DEFAULT 1,
                category TEXT NOT NULL DEFAULT '',
                spent_on TEXT NOT NULL,
                hours REAL NOT NULL,
                note TEXT NULL,
                created_at TEXT NOT NULL,
                FOREIGN KEY (issue_id) REFERENCES issues(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id)
            )",
            "CREATE INDEX IF NOT EXISTS idx_time_entries_issue ON time_entries(issue_id)",
            "CREATE INDEX IF NOT EXISTS idx_time_entries_spent_on ON time_entries(spent_on)",
            "CREATE INDEX IF NOT EXISTS idx_time_entries_category ON time_entries(category)",
            // The user_id index is added by applyInPlaceMigrations()
            // after the column has been guaranteed to exist, so the
            // same code path works for fresh installs (column present
            // from the CREATE TABLE above) and older installs being
            // upgraded (column just added by ALTER TABLE).

            // Aggregated time tracking cache. Rows here are derived
            // from time_entries and used by high-traffic read paths
            // (the index overview and the kanban board) which want
            // per-issue and per-project totals without scanning every
            // raw entry on each request.
            //
            // - scope        : 'issue' / 'project' / 'milestone'
            // - scope_id     : id within that scope
            // - period_length: 'total' / 'week' / 'month'
            // - period_start : ISO-8601 date for the bucket; the
            //                  empty string is used for 'total' so
            //                  the UNIQUE constraint stays simple.
            // - category     : a specific work category, or '*' for
            //                  the cross-category total.
            "CREATE TABLE IF NOT EXISTS time_aggregates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                scope TEXT NOT NULL,
                scope_id INTEGER NOT NULL,
                period_length TEXT NOT NULL,
                period_start TEXT NOT NULL,
                category TEXT NOT NULL DEFAULT '*',
                hours REAL NOT NULL,
                computed_at TEXT NOT NULL,
                UNIQUE (scope, scope_id, period_length, period_start, category)
            )",
            "CREATE INDEX IF NOT EXISTS idx_time_aggregates_scope ON time_aggregates(scope, scope_id)",
            "CREATE INDEX IF NOT EXISTS idx_time_aggregates_period ON time_aggregates(period_length, period_start)",

            // Comments on issues. Rendered on the task detail view
            // alongside the edit form and the time entries. `author`
            // stores the signed-in username at the time the comment
            // was posted so deleting a user row later does not erase
            // attribution.
            "CREATE TABLE IF NOT EXISTS comments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                issue_id INTEGER NOT NULL,
                author TEXT NOT NULL,
                body TEXT NOT NULL,
                created_at TEXT NOT NULL,
                FOREIGN KEY (issue_id) REFERENCES issues(id) ON DELETE CASCADE
            )",
            "CREATE INDEX IF NOT EXISTS idx_comments_issue ON comments(issue_id)",

            // Issue dependency links. Each row says `issue_id` is
            // blocked by `blocked_by_id`. The inverse direction
            // (`blocked_by_id` blocks `issue_id`) is implied by the
            // same row. Pairs are unique; self-links are rejected at
            // the API layer. Deleting either endpoint cascades so
            // links never outlive the issues they connect.
            "CREATE TABLE IF NOT EXISTS issue_links (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                issue_id INTEGER NOT NULL,
                blocked_by_id INTEGER NOT NULL,
                created_at TEXT NOT NULL,
                UNIQUE (issue_id, blocked_by_id),
                FOREIGN KEY (issue_id) REFERENCES issues(id) ON DELETE CASCADE,
                FOREIGN KEY (blocked_by_id) REFERENCES issues(id) ON DELETE CASCADE
            )",
            "CREATE INDEX IF NOT EXISTS idx_issue_links_issue ON issue_links(issue_id)",
            "CREATE INDEX IF NOT EXISTS idx_issue_links_blocker ON issue_links(blocked_by_id)",
        ];
    }
}
