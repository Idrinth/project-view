<?php
/**
 * Repository for milestones (referred to as "releases" on the
 * releases page). A milestone belongs to a project and has a name
 * (typically a version string) plus an optional release date and
 * release notes.
 */

declare(strict_types=1);

namespace ProjectView;

require_once __DIR__ . '/Database.php';

final class Milestones
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function create(
        int $projectId,
        string $name,
        ?string $releasedAt = null,
        ?string $notes = null
    ): int {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO milestones (project_id, name, released_at, notes, created_at)
             VALUES (:project_id, :name, :released_at, :notes, :created_at)'
        );
        $stmt->execute([
            'project_id'  => $projectId,
            'name'        => $name,
            'released_at' => $releasedAt,
            'notes'       => $notes,
            'created_at'  => gmdate('c'),
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM milestones WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Milestones for a project, latest release first. Unreleased
     * milestones (released_at IS NULL) come after released ones.
     *
     * The ORDER BY uses `released_at IS NULL` rather than NULLS LAST
     * so the query runs identically on SQLite, MySQL and Postgres.
     *
     * @return list<array<string, mixed>>
     */
    public function forProject(int $projectId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM milestones
             WHERE project_id = :project_id
             ORDER BY (released_at IS NULL) ASC, released_at DESC, name DESC'
        );
        $stmt->execute(['project_id' => $projectId]);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll();
        return $rows;
    }

    public function update(int $id, string $name, ?string $releasedAt, ?string $notes): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE milestones
             SET name = :name, released_at = :released_at, notes = :notes
             WHERE id = :id'
        );
        $stmt->execute([
            'id'          => $id,
            'name'        => $name,
            'released_at' => $releasedAt,
            'notes'       => $notes,
        ]);
    }

    public function markReleased(int $id, string $releasedAt): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE milestones SET released_at = :released_at WHERE id = :id'
        );
        $stmt->execute(['id' => $id, 'released_at' => $releasedAt]);
    }

    public function clearRelease(int $id): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE milestones SET released_at = NULL WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    /**
     * A milestone is considered "released" once every issue attached
     * to it has been resolved (status=done or status=discarded) AND at
     * least one of those issues is actually done — a milestone whose
     * cards were all discarded shipped no finished content, so it is
     * not a release.
     *
     * Called from the API whenever an issue update might flip the
     * milestone across that threshold. Issues with no milestone
     * (milestone_id IS NULL) are filtered out by the caller, since
     * there is nothing to release.
     *
     * The check is bidirectional: if a card is moved back out of
     * done/discarded (or a done card is reassigned away, or the only
     * remaining resolved cards are discarded), an already-stamped
     * release date is cleared so the releases page no longer lists a
     * release that has nothing finished behind it.
     *
     * A milestone with zero issues is treated as not released.
     */
    public function refreshReleaseStatus(int $milestoneId): void
    {
        $milestone = $this->find($milestoneId);
        if ($milestone === null) {
            return;
        }

        $stmt = $this->db->pdo()->prepare(
            "SELECT
                 COUNT(*) AS total,
                 SUM(CASE WHEN status IN ('done', 'discarded') THEN 1 ELSE 0 END) AS finished,
                 SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) AS done
               FROM issues
              WHERE milestone_id = :milestone_id"
        );
        $stmt->execute(['milestone_id' => $milestoneId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return;
        }
        $total = (int) $row['total'];
        $finished = (int) $row['finished'];
        $done = (int) $row['done'];

        $shouldBeReleased = $total > 0 && $finished === $total && $done > 0;
        $isReleased = $milestone['released_at'] !== null;

        if ($shouldBeReleased && !$isReleased) {
            $this->markReleased($milestoneId, gmdate('Y-m-d'));
        } elseif (!$shouldBeReleased && $isReleased) {
            $this->clearRelease($milestoneId);
        }
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM milestones WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
