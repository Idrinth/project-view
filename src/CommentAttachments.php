<?php
/**
 * Repository for media attachments on comments.
 *
 * Each attachment belongs to exactly one comment and references a
 * file stored under config/uploads/. The DB row tracks the original
 * (user-supplied) filename, the random on-disk filename, a mime type
 * and a broad `kind` bucket (image / video / audio) so the frontend
 * can pick between <img>, <video> and <audio> without re-parsing the
 * mime type on every render.
 *
 * The storage directory lives outside the web root on purpose:
 * uploads are served through the API (`comment-attachment` endpoint)
 * so the backend can set the correct Content-Type and content
 * disposition, and future features (access control, rate limiting,
 * scanning) can be layered on top without having to touch Apache.
 */

declare(strict_types=1);

namespace ProjectView;

require_once __DIR__ . '/Database.php';

final class CommentAttachments
{
    public const KIND_IMAGE = 'image';
    public const KIND_VIDEO = 'video';
    public const KIND_AUDIO = 'audio';

    /**
     * Whitelist of accepted mime types mapped to their broad kind.
     * Anything not in this list is rejected at upload time: it keeps
     * a clear boundary around what the frontend knows how to render,
     * and (in the case of SVG) avoids shipping script-capable formats
     * to unauthenticated viewers.
     *
     * @var array<string, string>
     */
    private const MIME_KINDS = [
        'image/png'      => self::KIND_IMAGE,
        'image/jpeg'     => self::KIND_IMAGE,
        'image/gif'      => self::KIND_IMAGE,
        'image/webp'     => self::KIND_IMAGE,
        'image/avif'     => self::KIND_IMAGE,
        'video/mp4'      => self::KIND_VIDEO,
        'video/webm'     => self::KIND_VIDEO,
        'video/ogg'      => self::KIND_VIDEO,
        'audio/mpeg'     => self::KIND_AUDIO,
        'audio/mp3'      => self::KIND_AUDIO,
        'audio/ogg'      => self::KIND_AUDIO,
        'audio/wav'      => self::KIND_AUDIO,
        'audio/x-wav'    => self::KIND_AUDIO,
        'audio/webm'     => self::KIND_AUDIO,
        'audio/mp4'      => self::KIND_AUDIO,
        'audio/aac'      => self::KIND_AUDIO,
        'audio/flac'     => self::KIND_AUDIO,
    ];

    private Database $db;
    private string $storageDir;

    public function __construct(Database $db, ?string $storageDir = null)
    {
        $this->db = $db;
        $this->storageDir = $storageDir ?? (__DIR__ . '/../config/uploads');
    }

    /**
     * Absolute path to the directory holding uploaded media. Created
     * on demand the first time an attachment is written.
     */
    public function storageDir(): string
    {
        return $this->storageDir;
    }

    /**
     * Map a mime type to the broad kind the frontend renders with.
     * Returns null for any type the API does not accept.
     */
    public static function kindForMime(string $mime): ?string
    {
        $mime = strtolower(trim($mime));
        return self::MIME_KINDS[$mime] ?? null;
    }

    /**
     * Persist an uploaded file as an attachment on `$commentId`. The
     * source file is moved into config/uploads/ under a random name
     * so that URLs cannot be guessed and users cannot overwrite each
     * other's files by uploading something with the same original
     * name. Returns the inserted row id.
     *
     * @throws \RuntimeException when the file cannot be stored.
     */
    public function create(
        int $commentId,
        string $sourcePath,
        string $originalName,
        string $mimeType,
        int $size
    ): int {
        $kind = self::kindForMime($mimeType);
        if ($kind === null) {
            throw new \InvalidArgumentException("unsupported media type: {$mimeType}");
        }

        $this->ensureStorageDir();

        $extension = self::safeExtension($originalName, $mimeType);
        $storedName = bin2hex(random_bytes(16)) . ($extension !== '' ? '.' . $extension : '');
        $target = $this->storageDir . '/' . $storedName;

        // Prefer move_uploaded_file() when the source is an actual
        // request upload (it enforces that the path came from $_FILES),
        // and fall back to a plain rename otherwise so tests can feed
        // arbitrary file paths through the same code path.
        $moved = false;
        if (is_uploaded_file($sourcePath)) {
            $moved = move_uploaded_file($sourcePath, $target);
        }
        if (!$moved) {
            $moved = @rename($sourcePath, $target);
        }
        if (!$moved) {
            throw new \RuntimeException('could not store uploaded file');
        }

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO comment_attachments
                 (comment_id, kind, mime_type, original_name, stored_name, size, created_at)
             VALUES (:comment_id, :kind, :mime_type, :original_name, :stored_name, :size, :created_at)'
        );
        $stmt->execute([
            'comment_id'    => $commentId,
            'kind'          => $kind,
            'mime_type'     => $mimeType,
            'original_name' => $originalName,
            'stored_name'   => $storedName,
            'size'          => $size,
            'created_at'    => gmdate('c'),
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * Attachments for a single comment, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function forComment(int $commentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM comment_attachments
              WHERE comment_id = :comment_id
           ORDER BY id ASC'
        );
        $stmt->execute(['comment_id' => $commentId]);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll();
        return $rows;
    }

    /**
     * Attachments for every comment on an issue, keyed by comment id.
     * Used by the detail view so rendering all comments only takes a
     * single round trip regardless of how many have attachments.
     *
     * @return array<int, list<array<string, mixed>>>
     */
    public function forIssue(int $issueId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ca.* FROM comment_attachments ca
               JOIN comments c ON c.id = ca.comment_id
              WHERE c.issue_id = :issue_id
           ORDER BY ca.comment_id ASC, ca.id ASC'
        );
        $stmt->execute(['issue_id' => $issueId]);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $commentId = (int) $row['comment_id'];
            if (!isset($out[$commentId])) {
                $out[$commentId] = [];
            }
            $out[$commentId][] = $row;
        }
        return $out;
    }

    /**
     * Look up a single attachment by id. Returns null if not found.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM comment_attachments WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Remove the attachment row and the backing file. Missing files
     * are tolerated (the DB row is still cleared) so a stray disk
     * cleanup doesn't leave orphaned metadata around.
     */
    public function delete(int $id): void
    {
        $row = $this->find($id);
        if ($row === null) {
            return;
        }
        $path = $this->storageDir . '/' . (string) $row['stored_name'];
        if (is_file($path)) {
            @unlink($path);
        }
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM comment_attachments WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    /**
     * Resolve the on-disk path for a stored attachment. Uses the
     * stored_name verbatim (which is machine-generated) so callers
     * cannot smuggle path-traversal segments through.
     */
    public function pathFor(array $row): string
    {
        $name = isset($row['stored_name']) ? (string) $row['stored_name'] : '';
        // Defensive: stored_name is machine-generated hex + extension,
        // but strip any directory separators just in case a row was
        // tampered with directly in the database.
        $name = str_replace(['/', '\\', "\0"], '', $name);
        return $this->storageDir . '/' . $name;
    }

    private function ensureStorageDir(): void
    {
        if (!is_dir($this->storageDir)
            && !mkdir($this->storageDir, 0755, true)
            && !is_dir($this->storageDir)) {
            throw new \RuntimeException('could not create upload directory: ' . $this->storageDir);
        }
    }

    /**
     * Derive a safe lowercase file extension for an uploaded file.
     * Preference order is (1) the extension on the original filename
     * if it matches a-z0-9 and is short, (2) a mime-type-derived
     * default. Falls back to the empty string rather than inventing
     * an extension we are not sure about.
     */
    private static function safeExtension(string $originalName, string $mimeType): string
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext !== '' && preg_match('/^[a-z0-9]{1,8}$/', $ext) === 1) {
            return $ext;
        }
        static $mimeExt = [
            'image/png'   => 'png',
            'image/jpeg'  => 'jpg',
            'image/gif'   => 'gif',
            'image/webp'  => 'webp',
            'image/avif'  => 'avif',
            'video/mp4'   => 'mp4',
            'video/webm'  => 'webm',
            'video/ogg'   => 'ogv',
            'audio/mpeg'  => 'mp3',
            'audio/mp3'   => 'mp3',
            'audio/ogg'   => 'ogg',
            'audio/wav'   => 'wav',
            'audio/x-wav' => 'wav',
            'audio/webm'  => 'weba',
            'audio/mp4'   => 'm4a',
            'audio/aac'   => 'aac',
            'audio/flac'  => 'flac',
        ];
        return $mimeExt[strtolower($mimeType)] ?? '';
    }
}
