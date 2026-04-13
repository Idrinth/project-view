<?php
/**
 * Repository for issue-to-issue dependency links.
 *
 * A single row `(issue_id, blocked_by_id)` records that `issue_id` is
 * blocked by `blocked_by_id`. The reverse direction (`blocked_by_id`
 * blocks `issue_id`) is implied - there is only one row per pair.
 *
 * Used by the kanban board's "Waiting for internal" column so a card
 * paused on another task can point at the blocker and show it in the
 * detail view.
 */

declare(strict_types=1);

namespace ProjectView;

require_once __DIR__ . '/Database.php';

final class IssueLinks
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Insert a new link. The caller is responsible for validating
     * that neither endpoint is zero, that they differ, and that the
     * link does not introduce a cycle (the API layer does all three).
     */
    public function add(int $issueId, int $blockedById): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO issue_links (issue_id, blocked_by_id, created_at)
             VALUES (:issue_id, :blocked_by_id, :created_at)'
        );
        $stmt->execute([
            'issue_id'      => $issueId,
            'blocked_by_id' => $blockedById,
            'created_at'    => gmdate('c'),
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM issue_links WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * Whether the pair already exists in either direction. Used by
     * the API layer to short-circuit duplicate inserts and to reject
     * the trivial cycle "A blocks B and B blocks A".
     */
    public function exists(int $issueId, int $blockedById): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM issue_links
              WHERE (issue_id = :a AND blocked_by_id = :b)
                 OR (issue_id = :b AND blocked_by_id = :a)
              LIMIT 1'
        );
        $stmt->execute(['a' => $issueId, 'b' => $blockedById]);
        return $stmt->fetch() !== false;
    }

    /**
     * Issues that block `$issueId`, joined with their metadata so the
     * detail view can render a clickable link without a second round
     * trip.
     *
     * @return list<array<string, mixed>>
     */
    public function blockedBy(int $issueId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT l.id AS link_id,
                    i.id, i.title, i.status, i.project_id
               FROM issue_links l
               JOIN issues i ON i.id = l.blocked_by_id
              WHERE l.issue_id = :issue_id
           ORDER BY i.id'
        );
        $stmt->execute(['issue_id' => $issueId]);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll();
        return $rows;
    }

    /**
     * Issues that `$issueId` blocks (the inverse direction).
     *
     * @return list<array<string, mixed>>
     */
    public function blocks(int $issueId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT l.id AS link_id,
                    i.id, i.title, i.status, i.project_id
               FROM issue_links l
               JOIN issues i ON i.id = l.issue_id
              WHERE l.blocked_by_id = :issue_id
           ORDER BY i.id'
        );
        $stmt->execute(['issue_id' => $issueId]);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll();
        return $rows;
    }

    /**
     * Walk the "is blocked by" graph starting from `$startId` and
     * return the set of issue ids that transitively block it. Used to
     * prevent cycles: if adding `A blocked by B` would make A reach
     * itself through the existing graph, the insert is refused.
     *
     * @return array<int, true>
     */
    public function ancestors(int $startId): array
    {
        $seen = [];
        $stack = [$startId];
        $stmt = $this->db->pdo()->prepare(
            'SELECT blocked_by_id FROM issue_links WHERE issue_id = :id'
        );
        while ($stack !== []) {
            $current = array_pop($stack);
            $stmt->execute(['id' => $current]);
            foreach ($stmt->fetchAll() as $row) {
                $id = (int) $row['blocked_by_id'];
                if (!isset($seen[$id])) {
                    $seen[$id] = true;
                    $stack[] = $id;
                }
            }
        }
        return $seen;
    }
}
