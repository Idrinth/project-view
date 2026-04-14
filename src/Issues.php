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
    public const STATUS_WAITING     = 'waiting';
    public const STATUS_DONE        = 'done';
    public const STATUS_DISCARDED   = 'discarded';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_TODO,
        self::STATUS_IN_PROGRESS,
        self::STATUS_WAITING,
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
        ?string $workCompletedAt = null,
        ?string $description = null,
        ?int $assigneeId = null
    ): int {
        self::assertStatus($status);
        $now = gmdate('c');
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO issues (
                 project_id, milestone_id, assignee_id, title, description, status,
                 work_started_at, work_completed_at, created_at, updated_at
             ) VALUES (
                 :project_id, :milestone_id, :assignee_id, :title, :description, :status,
                 :work_started_at, :work_completed_at, :created_at, :updated_at
             )'
        );
        $stmt->execute([
            'project_id'        => $projectId,
            'milestone_id'      => $milestoneId,
            'assignee_id'       => $assigneeId,
            'title'             => $title,
            'description'       => $description,
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

    /**
     * Overwrite the work_started_at / work_completed_at columns on an
     * issue. Used by the status transition logic in the API to stamp
     * an issue when it leaves Todo (started) or enters Done/Discarded
     * (completed); passing null for either argument clears that field.
     */
    public function setWorkTimestamps(int $id, ?string $workStartedAt, ?string $workCompletedAt): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE issues SET
                work_started_at = :work_started_at,
                work_completed_at = :work_completed_at,
                updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'id'                => $id,
            'work_started_at'   => $workStartedAt,
            'work_completed_at' => $workCompletedAt,
            'updated_at'        => gmdate('c'),
        ]);
    }

    public function update(
        int $id,
        string $title,
        ?int $milestoneId,
        string $status,
        ?string $workStartedAt,
        ?string $workCompletedAt,
        ?string $description = null,
        ?int $assigneeId = null
    ): void {
        self::assertStatus($status);
        $stmt = $this->db->pdo()->prepare(
            'UPDATE issues SET
                title = :title,
                description = :description,
                milestone_id = :milestone_id,
                assignee_id = :assignee_id,
                status = :status,
                work_started_at = :work_started_at,
                work_completed_at = :work_completed_at,
                updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'id'                => $id,
            'title'             => $title,
            'description'       => $description,
            'milestone_id'      => $milestoneId,
            'assignee_id'       => $assigneeId,
            'status'            => $status,
            'work_started_at'   => $workStartedAt,
            'work_completed_at' => $workCompletedAt,
            'updated_at'        => gmdate('c'),
        ]);
    }

    /**
     * Overwrite the assignee column on an issue. Pass null to clear
     * the assignee. Used by the kanban board's "assign" affordance so
     * the per-card profile picture can be changed without rewriting
     * every other editable field.
     */
    public function setAssignee(int $id, ?int $assigneeId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE issues
             SET assignee_id = :assignee_id, updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'id'          => $id,
            'assignee_id' => $assigneeId,
            'updated_at'  => gmdate('c'),
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
