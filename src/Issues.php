<?php
/**
 * Repository for issues (aka "tasks" — the cards on the kanban
 * board). An issue always belongs to a project and may optionally
 * target a milestone.
 */

declare(strict_types=1);

namespace ProjectView;

require_once __DIR__ . '/Database.php';

final class Issues
{
    public const STATUS_TODO        = 'todo';
    public const STATUS_IN_PROGRESS = 'in-progress';
    public const STATUS_DONE        = 'done';
    public const STATUS_DISCARDED   = 'discarded';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_TODO,
        self::STATUS_IN_PROGRESS,
        self::STATUS_DONE,
        self::STATUS_DISCARDED,
    ];

    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function create(
        int $projectId,
        ?int $milestoneId,
        string $title,
        string $status = self::STATUS_TODO,
        ?string $workStartedAt = null,
        ?string $workCompletedAt = null
    ): int {
        self::assertStatus($status);
        $now = gmdate('c');
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO issues (
                 project_id, milestone_id, title, status,
                 work_started_at, work_completed_at, created_at, updated_at
             ) VALUES (
                 :project_id, :milestone_id, :title, :status,
                 :work_started_at, :work_completed_at, :created_at, :updated_at
             )'
        );
        $stmt->execute([
            'project_id'        => $projectId,
            'milestone_id'      => $milestoneId,
            'title'             => $title,
            'status'            => $status,
            'work_started_at'   => $workStartedAt,
            'work_completed_at' => $workCompletedAt,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM issues WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forProject(int $projectId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM issues
             WHERE project_id = :project_id
             ORDER BY status, position, id'
        );
        $stmt->execute(['project_id' => $projectId]);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll();
        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function byStatus(string $status): array
    {
        self::assertStatus($status);
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM issues WHERE status = :status ORDER BY position, id'
        );
        $stmt->execute(['status' => $status]);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll();
        return $rows;
    }

    public function setStatus(int $id, string $status, int $position = 0): void
    {
        self::assertStatus($status);
        $stmt = $this->db->pdo()->prepare(
            'UPDATE issues
             SET status = :status, position = :position, updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'id'         => $id,
            'status'     => $status,
            'position'   => $position,
            'updated_at' => gmdate('c'),
        ]);
    }

    public function update(
        int $id,
        string $title,
        ?int $milestoneId,
        string $status,
        ?string $workStartedAt,
        ?string $workCompletedAt
    ): void {
        self::assertStatus($status);
        $stmt = $this->db->pdo()->prepare(
            'UPDATE issues SET
                title = :title,
                milestone_id = :milestone_id,
                status = :status,
                work_started_at = :work_started_at,
                work_completed_at = :work_completed_at,
                updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'id'                => $id,
            'title'             => $title,
            'milestone_id'      => $milestoneId,
            'status'            => $status,
            'work_started_at'   => $workStartedAt,
            'work_completed_at' => $workCompletedAt,
            'updated_at'        => gmdate('c'),
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM issues WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    private static function assertStatus(string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException("unknown issue status: {$status}");
        }
    }
}
