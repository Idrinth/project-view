<?php
/**
 * Repository for projects (referred to as "categories" in parts of
 * the UI) — the top-level things being built or managed. Milestones
 * and issues hang off a project.
 */

declare(strict_types=1);

namespace ProjectView;

require_once __DIR__ . '/Database.php';

final class Projects
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function create(string $name, string $slug, ?string $description = null): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO projects (name, slug, description, created_at)
             VALUES (:name, :slug, :description, :created_at)'
        );
        $stmt->execute([
            'name'        => $name,
            'slug'        => $slug,
            'description' => $description,
            'created_at'  => gmdate('c'),
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM projects WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM projects WHERE slug = :slug');
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $stmt = $this->db->pdo()->query('SELECT * FROM projects ORDER BY name ASC');
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt === false ? [] : $stmt->fetchAll();
        return $rows;
    }

    public function update(int $id, string $name, string $slug, ?string $description): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE projects
             SET name = :name, slug = :slug, description = :description
             WHERE id = :id'
        );
        $stmt->execute([
            'id'          => $id,
            'name'        => $name,
            'slug'        => $slug,
            'description' => $description,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM projects WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
