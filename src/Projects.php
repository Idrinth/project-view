<?php
/**
 * Repository for projects (referred to as "categories" in parts of
 * the UI) — the top-level things being built or managed. Milestones
 * and issues hang off a project.
 *
 * Rows form a tree: each project optionally points at a parent
 * project via `parent_id`. Roots have parent_id NULL. The frontend
 * uses this to group related projects under umbrella categories
 * such as "Mods > Skyrim > Idrinth Thalui" without losing the
 * ability to attach milestones/issues to the leaf.
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

    public function create(
        string $name,
        string $slug,
        ?int $parentId = null,
        ?string $description = null
    ): int {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO projects (parent_id, name, slug, description, created_at)
             VALUES (:parent_id, :name, :slug, :description, :created_at)'
        );
        $stmt->execute([
            'parent_id'   => $parentId,
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
     * Look up a project by its (parent_id, name) pair. NULL parent
     * matches root-level projects and is handled via `IS NULL` rather
     * than equality so the lookup works on every PDO driver.
     *
     * @return array<string, mixed>|null
     */
    public function findByParentAndName(?int $parentId, string $name): ?array
    {
        if ($parentId === null) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT * FROM projects WHERE parent_id IS NULL AND name = :name'
            );
            $stmt->execute(['name' => $name]);
        } else {
            $stmt = $this->db->pdo()->prepare(
                'SELECT * FROM projects WHERE parent_id = :parent_id AND name = :name'
            );
            $stmt->execute(['parent_id' => $parentId, 'name' => $name]);
        }
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Direct children of the given parent (or all roots when
     * `$parentId` is null).
     *
     * @return list<array<string, mixed>>
     */
    public function children(?int $parentId): array
    {
        if ($parentId === null) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT * FROM projects WHERE parent_id IS NULL ORDER BY name ASC'
            );
            $stmt->execute();
        } else {
            $stmt = $this->db->pdo()->prepare(
                'SELECT * FROM projects WHERE parent_id = :parent_id ORDER BY name ASC'
            );
            $stmt->execute(['parent_id' => $parentId]);
        }
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll();
        return $rows;
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

    /**
     * Breadcrumb of names from the root down to and including the
     * project with the given id. Returns an empty list when the id
     * does not exist. Walks parents in a loop with a small guard to
     * avoid looping forever if the data ever becomes cyclic.
     *
     * @return list<string>
     */
    public function pathFor(int $id): array
    {
        $names = [];
        $current = $id;
        $seen = [];
        $stmt = $this->db->pdo()->prepare('SELECT parent_id, name FROM projects WHERE id = :id');
        while ($current > 0 && !isset($seen[$current])) {
            $seen[$current] = true;
            $stmt->execute(['id' => $current]);
            $row = $stmt->fetch();
            if ($row === false) {
                return [];
            }
            array_unshift($names, (string) $row['name']);
            $current = $row['parent_id'] !== null ? (int) $row['parent_id'] : 0;
        }
        return $names;
    }

    /**
     * Breadcrumbs for every project, keyed by id. Computed with a
     * single pass over the table so read paths (kanban, releases)
     * can avoid N+1 lookups when rendering.
     *
     * @return array<int, list<string>>
     */
    public function allPaths(): array
    {
        $rows = $this->all();
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = [
                'name'   => (string) $row['name'],
                'parent' => $row['parent_id'] !== null ? (int) $row['parent_id'] : 0,
            ];
        }

        $paths = [];
        foreach ($byId as $id => $_) {
            $names = [];
            $current = $id;
            $seen = [];
            while ($current > 0 && isset($byId[$current]) && !isset($seen[$current])) {
                $seen[$current] = true;
                array_unshift($names, $byId[$current]['name']);
                $current = $byId[$current]['parent'];
            }
            $paths[$id] = $names;
        }
        return $paths;
    }

    public function update(
        int $id,
        string $name,
        string $slug,
        ?int $parentId,
        ?string $description
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE projects
             SET parent_id = :parent_id, name = :name, slug = :slug, description = :description
             WHERE id = :id'
        );
        $stmt->execute([
            'id'          => $id,
            'parent_id'   => $parentId,
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
