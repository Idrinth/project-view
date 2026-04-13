<?php
/**
 * Per-user edit access grants against projects.
 *
 * By default a signed-in user has edit access to nothing. Rows in the
 * `project_access` table add grants: a row `(user_id, project_id)`
 * gives that user edit rights on the referenced project *and every
 * descendant* (access inherits down the project tree, matching how
 * the UI groups children under their parents).
 *
 * The bootstrap admin account (user id 1) is treated as a super-user
 * and bypasses this table — canEdit() short-circuits to true for id 1
 * even when the `project_access` table is empty, so the initial
 * install never locks itself out.
 *
 * Grants are managed from the CLI with bin/users.php access …
 */

declare(strict_types=1);

namespace ProjectView;

require_once __DIR__ . '/Database.php';

final class ProjectAccess
{
    /**
     * User id that is treated as the administrator. Bypasses every
     * access check so fresh installs (which have no grants yet) and
     * recovery workflows always have one account with full rights.
     */
    public const ADMIN_USER_ID = 1;

    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Grant `$userId` edit access on `$projectId` and, transitively,
     * every descendant project. Idempotent: an existing grant is left
     * untouched and the row id is returned.
     */
    public function grant(int $userId, int $projectId): int
    {
        $existing = $this->find($userId, $projectId);
        if ($existing !== null) {
            return (int) $existing['id'];
        }
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO project_access (user_id, project_id, created_at)
             VALUES (:user_id, :project_id, :created_at)'
        );
        $stmt->execute([
            'user_id'    => $userId,
            'project_id' => $projectId,
            'created_at' => gmdate('c'),
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * Remove a direct grant. Revoking access at a sub-project does not
     * affect inherited access via an ancestor grant; to fully cut a
     * user off, every ancestor grant has to be revoked too.
     *
     * @return int number of rows removed (0 or 1).
     */
    public function revoke(int $userId, int $projectId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM project_access
              WHERE user_id = :user_id AND project_id = :project_id'
        );
        $stmt->execute([
            'user_id'    => $userId,
            'project_id' => $projectId,
        ]);
        return $stmt->rowCount();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $userId, int $projectId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM project_access
              WHERE user_id = :user_id AND project_id = :project_id'
        );
        $stmt->execute([
            'user_id'    => $userId,
            'project_id' => $projectId,
        ]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Every grant held by `$userId`, with project ids as the list
     * payload. Order is insertion (by id) so CLI listings stay stable.
     *
     * @return list<int>
     */
    public function projectIdsForUser(int $userId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT project_id FROM project_access
              WHERE user_id = :user_id
           ORDER BY id'
        );
        $stmt->execute(['user_id' => $userId]);
        $ids = [];
        foreach ($stmt->fetchAll() as $row) {
            $ids[] = (int) $row['project_id'];
        }
        return $ids;
    }

    /**
     * Decide whether `$userId` may edit content under `$projectId`.
     *
     * The rule: walk the project's parent chain up to the root and
     * return true as soon as any node (including `$projectId` itself)
     * has a grant for `$userId`. The admin account bypasses the walk
     * entirely. A cycle guard stops us looping forever if data ever
     * becomes corrupt.
     */
    public function canEdit(int $userId, int $projectId): bool
    {
        if ($userId === self::ADMIN_USER_ID) {
            return true;
        }
        if ($projectId <= 0) {
            return false;
        }
        $grants = $this->projectIdsForUser($userId);
        if ($grants === []) {
            return false;
        }
        $allowed = array_flip($grants);

        $stmt = $this->db->pdo()->prepare('SELECT parent_id FROM projects WHERE id = :id');
        $current = $projectId;
        $seen = [];
        while ($current > 0 && !isset($seen[$current])) {
            if (isset($allowed[$current])) {
                return true;
            }
            $seen[$current] = true;
            $stmt->execute(['id' => $current]);
            $row = $stmt->fetch();
            if ($row === false) {
                return false;
            }
            $current = $row['parent_id'] !== null ? (int) $row['parent_id'] : 0;
        }
        return false;
    }
}
