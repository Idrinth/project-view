<?php
/**
 * Repository for raw time tracking entries.
 *
 * Each row records a number of hours spent on a single issue on a
 * specific date, optionally tagged with a work category (for
 * example "Development", "Testing" or "Research"). These rows are
 * the source of truth; TimeAggregates keeps derived totals alongside
 * them for quick reads.
 */

declare(strict_types=1);

namespace ProjectView;

require_once __DIR__ . '/Database.php';

final class TimeEntries
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function create(
        int $issueId,
        string $spentOn,
        float $hours,
        string $category = '',
        ?string $note = null,
        int $userId = 1
    ): int {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO time_entries
                (issue_id, user_id, category, spent_on, hours, note, created_at)
             VALUES
                (:issue_id, :user_id, :category, :spent_on, :hours, :note, :created_at)'
        );
        $stmt->execute([
            'issue_id'   => $issueId,
            'user_id'    => $userId,
            'category'   => $category,
            'spent_on'   => $spentOn,
            'hours'      => $hours,
            'note'       => $note,
            'created_at' => gmdate('c'),
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM time_entries WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forIssue(int $issueId): array
    {
        // LEFT JOIN so an entry whose author was deleted from the
        // users table still surfaces - the username comes back NULL
        // and the detail view falls back to displaying the bare id.
        // display_name is pulled in the same trip so the detail view
        // can prefer the author's chosen label without issuing a
        // per-entry profile lookup.
        $stmt = $this->db->pdo()->prepare(
            'SELECT te.*, u.username AS username, u.display_name AS display_name
             FROM time_entries te
             LEFT JOIN users u ON u.id = te.user_id
             WHERE te.issue_id = :issue_id
             ORDER BY te.spent_on DESC, te.id DESC'
        );
        $stmt->execute(['issue_id' => $issueId]);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll();
        return $rows;
    }

    /**
     * Entries in a closed date range, inclusive on both ends.
     *
     * @return list<array<string, mixed>>
     */
    public function between(string $from, string $to): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM time_entries
             WHERE spent_on >= :from AND spent_on <= :to
             ORDER BY spent_on ASC, id ASC'
        );
        $stmt->execute(['from' => $from, 'to' => $to]);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll();
        return $rows;
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM time_entries WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
