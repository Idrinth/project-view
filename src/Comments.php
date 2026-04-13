<?php
/**
 * Repository for comments on issues.
 *
 * Each comment belongs to a single issue and carries the username of
 * the signed-in user that posted it. Comments are read back in
 * chronological order for the task detail view.
 */

declare(strict_types=1);

namespace ProjectView;

require_once __DIR__ . '/Database.php';

final class Comments
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function create(int $issueId, string $author, string $body): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO comments (issue_id, author, body, created_at)
             VALUES (:issue_id, :author, :body, :created_at)'
        );
        $stmt->execute([
            'issue_id'   => $issueId,
            'author'     => $author,
            'body'       => $body,
            'created_at' => gmdate('c'),
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * Comments for a single issue, oldest first, so the detail view
     * renders them in the order they were posted.
     *
     * @return list<array<string, mixed>>
     */
    public function forIssue(int $issueId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM comments
             WHERE issue_id = :issue_id
             ORDER BY created_at ASC, id ASC'
        );
        $stmt->execute(['issue_id' => $issueId]);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll();
        return $rows;
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM comments WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
