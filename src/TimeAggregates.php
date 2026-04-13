<?php
/**
 * Repository for aggregated time tracking totals.
 *
 * Rows here are derived from time_entries and used by read paths
 * that would otherwise have to scan the raw table on every request:
 * the index overview page and the per-card "time spent" on the
 * kanban board.
 *
 * Each row aggregates hours for a single (scope, scope_id) pair
 * over a time bucket, broken down by work category. The sentinel
 * category "*" stores the cross-category total for that bucket;
 * period_length 'total' pairs with an empty period_start to store
 * an all-time figure.
 */

declare(strict_types=1);

namespace ProjectView;

require_once __DIR__ . '/Database.php';

final class TimeAggregates
{
    public const SCOPE_ISSUE     = 'issue';
    public const SCOPE_PROJECT   = 'project';
    public const SCOPE_MILESTONE = 'milestone';

    public const PERIOD_TOTAL = 'total';
    public const PERIOD_WEEK  = 'week';
    public const PERIOD_MONTH = 'month';

    public const CATEGORY_ALL = '*';

    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Insert or update a single aggregate row. A row with the same
     * (scope, scope_id, period_length, period_start, category) is
     * replaced in place.
     *
     * Uses `ON CONFLICT DO UPDATE`, which is supported on SQLite
     * 3.24+ and PostgreSQL. If the DSN is pointed at MySQL, swap
     * this statement for `ON DUPLICATE KEY UPDATE`.
     */
    public function put(
        string $scope,
        int $scopeId,
        string $periodLength,
        string $periodStart,
        string $category,
        float $hours
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO time_aggregates
                 (scope, scope_id, period_length, period_start, category, hours, computed_at)
             VALUES
                 (:scope, :scope_id, :period_length, :period_start, :category, :hours, :computed_at)
             ON CONFLICT (scope, scope_id, period_length, period_start, category)
             DO UPDATE SET hours = excluded.hours, computed_at = excluded.computed_at'
        );
        $stmt->execute([
            'scope'         => $scope,
            'scope_id'      => $scopeId,
            'period_length' => $periodLength,
            'period_start'  => $periodStart,
            'category'      => $category,
            'hours'         => $hours,
            'computed_at'   => gmdate('c'),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(
        string $scope,
        int $scopeId,
        string $periodLength,
        string $periodStart,
        string $category = self::CATEGORY_ALL
    ): ?array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM time_aggregates
             WHERE scope = :scope AND scope_id = :scope_id
               AND period_length = :period_length AND period_start = :period_start
               AND category = :category'
        );
        $stmt->execute([
            'scope'         => $scope,
            'scope_id'      => $scopeId,
            'period_length' => $periodLength,
            'period_start'  => $periodStart,
            'category'      => $category,
        ]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Every aggregate row for a given (scope, scope_id).
     *
     * @return list<array<string, mixed>>
     */
    public function forScope(string $scope, int $scopeId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM time_aggregates
             WHERE scope = :scope AND scope_id = :scope_id
             ORDER BY period_length, period_start DESC, category'
        );
        $stmt->execute(['scope' => $scope, 'scope_id' => $scopeId]);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll();
        return $rows;
    }

    /**
     * Drop every aggregate for a given scope. Call before a full
     * recompute to avoid leaving stale categories behind.
     */
    public function clearScope(string $scope, int $scopeId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM time_aggregates WHERE scope = :scope AND scope_id = :scope_id'
        );
        $stmt->execute(['scope' => $scope, 'scope_id' => $scopeId]);
    }

    /**
     * Recompute all-time totals for a single issue from the raw
     * time_entries table: one cross-category total plus one row per
     * category present in the entries.
     */
    public function refreshIssue(int $issueId): void
    {
        $pdo = $this->db->pdo();
        $this->clearScope(self::SCOPE_ISSUE, $issueId);

        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(hours), 0) AS total
             FROM time_entries WHERE issue_id = :id'
        );
        $stmt->execute(['id' => $issueId]);
        $totalRow = $stmt->fetch();
        $total = (float) ($totalRow['total'] ?? 0.0);
        $this->put(
            self::SCOPE_ISSUE,
            $issueId,
            self::PERIOD_TOTAL,
            '',
            self::CATEGORY_ALL,
            $total
        );

        $stmt = $pdo->prepare(
            'SELECT category, COALESCE(SUM(hours), 0) AS total
             FROM time_entries WHERE issue_id = :id GROUP BY category'
        );
        $stmt->execute(['id' => $issueId]);
        foreach ($stmt->fetchAll() as $row) {
            $this->put(
                self::SCOPE_ISSUE,
                $issueId,
                self::PERIOD_TOTAL,
                '',
                (string) $row['category'],
                (float) $row['total']
            );
        }
    }

    /**
     * Recompute all-time totals for a project: sums every time entry
     * against every issue that belongs to it.
     */
    public function refreshProject(int $projectId): void
    {
        $pdo = $this->db->pdo();
        $this->clearScope(self::SCOPE_PROJECT, $projectId);

        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(te.hours), 0) AS total
             FROM time_entries te
             JOIN issues i ON i.id = te.issue_id
             WHERE i.project_id = :project_id'
        );
        $stmt->execute(['project_id' => $projectId]);
        $totalRow = $stmt->fetch();
        $total = (float) ($totalRow['total'] ?? 0.0);
        $this->put(
            self::SCOPE_PROJECT,
            $projectId,
            self::PERIOD_TOTAL,
            '',
            self::CATEGORY_ALL,
            $total
        );

        $stmt = $pdo->prepare(
            'SELECT te.category, COALESCE(SUM(te.hours), 0) AS total
             FROM time_entries te
             JOIN issues i ON i.id = te.issue_id
             WHERE i.project_id = :project_id
             GROUP BY te.category'
        );
        $stmt->execute(['project_id' => $projectId]);
        foreach ($stmt->fetchAll() as $row) {
            $this->put(
                self::SCOPE_PROJECT,
                $projectId,
                self::PERIOD_TOTAL,
                '',
                (string) $row['category'],
                (float) $row['total']
            );
        }
    }

    /**
     * Recompute all-time totals for a milestone: sums every time
     * entry against every issue that targets the milestone.
     */
    public function refreshMilestone(int $milestoneId): void
    {
        $pdo = $this->db->pdo();
        $this->clearScope(self::SCOPE_MILESTONE, $milestoneId);

        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(te.hours), 0) AS total
             FROM time_entries te
             JOIN issues i ON i.id = te.issue_id
             WHERE i.milestone_id = :milestone_id'
        );
        $stmt->execute(['milestone_id' => $milestoneId]);
        $totalRow = $stmt->fetch();
        $total = (float) ($totalRow['total'] ?? 0.0);
        $this->put(
            self::SCOPE_MILESTONE,
            $milestoneId,
            self::PERIOD_TOTAL,
            '',
            self::CATEGORY_ALL,
            $total
        );

        $stmt = $pdo->prepare(
            'SELECT te.category, COALESCE(SUM(te.hours), 0) AS total
             FROM time_entries te
             JOIN issues i ON i.id = te.issue_id
             WHERE i.milestone_id = :milestone_id
             GROUP BY te.category'
        );
        $stmt->execute(['milestone_id' => $milestoneId]);
        foreach ($stmt->fetchAll() as $row) {
            $this->put(
                self::SCOPE_MILESTONE,
                $milestoneId,
                self::PERIOD_TOTAL,
                '',
                (string) $row['category'],
                (float) $row['total']
            );
        }
    }
}
