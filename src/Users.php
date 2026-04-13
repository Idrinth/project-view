<?php
/**
 * Repository for users — accounts allowed to sign in to Project View.
 *
 * Password hashes are stored verbatim and are produced with PHP's
 * password_hash() (bcrypt / argon2); the repository does not know
 * which algorithm was used. Verification happens in src/Auth.php via
 * password_verify(). Manage the account list from the CLI with
 * bin/users.php.
 */

declare(strict_types=1);

namespace ProjectView;

require_once __DIR__ . '/Database.php';

final class Users
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Create a user from a plain-text password. Hashing happens here
     * so callers never have to know which algorithm is in use.
     */
    public function create(string $username, string $password): int
    {
        $hash = self::hashPassword($password);
        $now  = gmdate('c');
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO users (username, password_hash, created_at, updated_at)
             VALUES (:username, :password_hash, :created_at, :updated_at)'
        );
        $stmt->execute([
            'username'      => $username,
            'password_hash' => $hash,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByUsername(string $username): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM users WHERE username = :username'
        );
        $stmt->execute(['username' => $username]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $stmt = $this->db->pdo()->query(
            'SELECT id, username, created_at, updated_at FROM users ORDER BY username ASC'
        );
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt === false ? [] : $stmt->fetchAll();
        return $rows;
    }

    /**
     * Rename a user. The password is left untouched.
     */
    public function rename(int $id, string $username): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE users
             SET username = :username, updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'id'         => $id,
            'username'   => $username,
            'updated_at' => gmdate('c'),
        ]);
    }

    /**
     * Replace a user's password with a fresh hash of the given
     * plain-text password.
     */
    public function setPassword(int $id, string $password): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE users
             SET password_hash = :password_hash, updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'id'            => $id,
            'password_hash' => self::hashPassword($password),
            'updated_at'    => gmdate('c'),
        ]);
    }

    /**
     * Replace the free-form profile fields (display name, website URL,
     * about blurb). Each argument is the trimmed value the API
     * received; pass the empty string to clear a field. The avatar
     * lives on setAvatar() / clearAvatar() because it is binary and
     * has its own validation.
     */
    public function updateProfile(
        int $id,
        string $displayName,
        string $websiteUrl,
        string $about
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE users
             SET display_name = :display_name,
                 website_url  = :website_url,
                 about        = :about,
                 updated_at   = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'id'           => $id,
            'display_name' => $displayName === '' ? null : $displayName,
            'website_url'  => $websiteUrl  === '' ? null : $websiteUrl,
            'about'        => $about       === '' ? null : $about,
            'updated_at'   => gmdate('c'),
        ]);
    }

    /**
     * Store the avatar as base64-encoded bytes alongside its MIME
     * type. Bytes live in the database rather than on disk so the
     * build step (which wipes public/) cannot strand uploads.
     */
    public function setAvatar(int $id, string $mime, string $base64): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE users
             SET avatar_mime = :mime,
                 avatar_data = :data,
                 updated_at  = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'id'         => $id,
            'mime'       => $mime,
            'data'       => $base64,
            'updated_at' => gmdate('c'),
        ]);
    }

    public function clearAvatar(int $id): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE users
             SET avatar_mime = NULL,
                 avatar_data = NULL,
                 updated_at  = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'id'         => $id,
            'updated_at' => gmdate('c'),
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function deleteByUsername(string $username): int
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM users WHERE username = :username'
        );
        $stmt->execute(['username' => $username]);
        return $stmt->rowCount();
    }

    private static function hashPassword(string $password): string
    {
        if ($password === '') {
            throw new \InvalidArgumentException('password must not be empty');
        }
        $hash = password_hash($password, PASSWORD_BCRYPT);
        if (!is_string($hash) || $hash === '') {
            throw new \RuntimeException('password_hash() failed');
        }
        return $hash;
    }
}
