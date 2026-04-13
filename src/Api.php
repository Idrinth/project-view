<?php
/**
 * Project View API.
 *
 * Routes requests by endpoint name and returns payloads as PHP arrays
 * read from the PDO-backed datastore. The front controller
 * (public/index.php) is responsible for JSON encoding and HTTP
 * response handling.
 */

declare(strict_types=1);

namespace ProjectView;

require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Projects.php';
require_once __DIR__ . '/ProjectAccess.php';
require_once __DIR__ . '/Milestones.php';
require_once __DIR__ . '/Issues.php';
require_once __DIR__ . '/IssueLinks.php';
require_once __DIR__ . '/TimeEntries.php';
require_once __DIR__ . '/TimeAggregates.php';
require_once __DIR__ . '/Comments.php';
require_once __DIR__ . '/Users.php';

final class Api
{
    private Auth $auth;
    private Database $db;
    private Projects $projects;
    private ProjectAccess $projectAccess;
    private Milestones $milestones;
    private Issues $issues;
    private IssueLinks $issueLinks;
    private TimeEntries $timeEntries;
    private TimeAggregates $timeAggregates;
    private Comments $comments;
    private Users $users;

    public function __construct(?Auth $auth = null, ?Database $db = null)
    {
        $this->auth = $auth ?? new Auth();
        $this->db = $db ?? new Database();
        $this->projects = new Projects($this->db);
        $this->projectAccess = new ProjectAccess($this->db);
        $this->milestones = new Milestones($this->db);
        $this->issues = new Issues($this->db);
        $this->issueLinks = new IssueLinks($this->db);
        $this->timeEntries = new TimeEntries($this->db);
        $this->timeAggregates = new TimeAggregates($this->db);
        $this->comments = new Comments($this->db);
        $this->users = new Users($this->db);
    }

    /**
     * Dispatch a request to the matching endpoint method.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     * @throws \InvalidArgumentException When the endpoint is unknown.
     * @throws UnauthorizedException     When the caller is not signed in.
     * @throws ForbiddenException        When the caller lacks edit access.
     * @throws BadRequestException       When the request payload is invalid.
     */
    public function handle(string $endpoint, string $method = 'GET', array $body = []): array
    {
        switch ($endpoint) {
            case 'login':
                return $this->login($method, $body);
            case 'logout':
                return $this->logout($method);
            case 'me':
                return $this->me();
            case 'kanban':
                return $this->kanban();
            case 'kanban-add':
                return $this->kanbanAdd($method, $body);
            case 'kanban-move':
                return $this->kanbanMove($method, $body);
            case 'issue':
                return $this->issue($method, $body);
            case 'issue-update':
                return $this->issueUpdate($method, $body);
            case 'issue-time-add':
                return $this->issueTimeAdd($method, $body);
            case 'issue-comment-add':
                return $this->issueCommentAdd($method, $body);
            case 'issue-link-add':
                return $this->issueLinkAdd($method, $body);
            case 'issue-link-remove':
                return $this->issueLinkRemove($method, $body);
            case 'releases':
                return $this->releases();
            case 'time':
                return $this->time();
            case 'profile':
                return $this->profile($method, $body);
            case 'profile-picture':
                return $this->profilePicture($method, $body);
            default:
                throw new \InvalidArgumentException("unknown endpoint: {$endpoint}");
        }
    }

    /**
     * Ensure the current request is authenticated and return the username.
     *
     * @throws UnauthorizedException when no valid session is present.
     */
    private function requireUser(): string
    {
        $user = $this->auth->currentUser();
        if ($user === null) {
            throw new UnauthorizedException('not signed in');
        }
        return $user;
    }

    /**
     * Ensure the signed-in user may edit content under `$projectId`.
     * The admin account (user id 1) always passes. Everyone else needs
     * a matching row in `project_access`, either on the project itself
     * or on one of its ancestors.
     *
     * @throws UnauthorizedException when no session is present.
     * @throws ForbiddenException    when the session lacks edit access.
     */
    private function requireEditAccess(int $projectId): void
    {
        $userId = $this->auth->currentUserId();
        if ($userId === null) {
            throw new UnauthorizedException('not signed in');
        }
        if (!$this->projectAccess->canEdit($userId, $projectId)) {
            throw new ForbiddenException('no edit access to this project');
        }
    }

    /**
     * Variant of requireEditAccess() for write paths that accept a
     * category path string and auto-create missing intermediate nodes
     * (kanban-add, issue-update with a new category). The access check
     * runs against the deepest *existing* node so we never create
     * orphan projects for callers that would then be rejected anyway.
     * Creating a brand-new root category (no ancestor exists yet) is
     * restricted to the admin account.
     *
     * @throws UnauthorizedException when no session is present.
     * @throws ForbiddenException    when the session lacks edit access.
     */
    private function requireEditAccessForPath(string $path): void
    {
        $userId = $this->auth->currentUserId();
        if ($userId === null) {
            throw new UnauthorizedException('not signed in');
        }
        if ($userId === ProjectAccess::ADMIN_USER_ID) {
            return;
        }

        $segments = [];
        foreach (preg_split('#/#', $path) ?: [] as $piece) {
            $trimmed = trim((string) $piece);
            if ($trimmed !== '') {
                $segments[] = $trimmed;
            }
        }
        if ($segments === []) {
            $segments = ['Uncategorised'];
        }

        $parentId = null;
        $deepestExisting = 0;
        foreach ($segments as $name) {
            $existing = $this->projects->findByParentAndName($parentId, $name);
            if ($existing === null) {
                break;
            }
            $deepestExisting = (int) $existing['id'];
            $parentId = $deepestExisting;
        }

        if ($deepestExisting === 0) {
            // No part of the requested path exists yet; creating a new
            // root-level category is an admin-only operation.
            throw new ForbiddenException('no edit access to create new root categories');
        }
        if (!$this->projectAccess->canEdit($userId, $deepestExisting)) {
            throw new ForbiddenException('no edit access to this project');
        }
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function login(string $method, array $body): array
    {
        if ($method !== 'POST') {
            throw new BadRequestException('login requires POST');
        }
        $username = isset($body['username']) && is_string($body['username']) ? $body['username'] : '';
        $password = isset($body['password']) && is_string($body['password']) ? $body['password'] : '';
        if ($username === '' || $password === '') {
            throw new BadRequestException('username and password are required');
        }

        $token = $this->auth->attempt($username, $password);
        if ($token === null) {
            throw new UnauthorizedException('invalid credentials');
        }
        $this->auth->sendCookie($token);

        return ['user' => ['name' => $username]];
    }

    /**
     * @return array<string, mixed>
     */
    private function logout(string $method): array
    {
        if ($method !== 'POST') {
            throw new BadRequestException('logout requires POST');
        }
        $this->auth->clearCookie();
        return ['ok' => true];
    }

    /**
     * @return array<string, mixed>
     */
    private function me(): array
    {
        $user = $this->auth->currentUser();
        if ($user === null) {
            throw new UnauthorizedException('not signed in');
        }
        // Surface the display name (when set) so the nav can address
        // the user by how they prefer to be named without also having
        // to pull the full profile — including the avatar — on every
        // page load.
        $row = $this->users->findByUsername($user);
        $displayName = '';
        if (is_array($row) && isset($row['display_name']) && is_string($row['display_name'])) {
            $displayName = trim($row['display_name']);
        }
        return [
            'user' => [
                'name'        => $user,
                'displayName' => $displayName,
            ],
        ];
    }

    /**
     * Return (GET) or update (POST) the current user's self-service
     * profile: display name, website URL, short about blurb and an
     * optional avatar. The avatar is surfaced as a data URL so the
     * page can render it without a second round trip.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function profile(string $method, array $body): array
    {
        $username = $this->requireUser();
        $row = $this->users->findByUsername($username);
        if (!is_array($row) || !isset($row['id'])) {
            // The cookie decoded, but the account was deleted in the
            // meantime. Treat that as unauthenticated.
            throw new UnauthorizedException('not signed in');
        }
        $id = (int) $row['id'];

        if ($method === 'GET') {
            return ['profile' => self::profilePayload($row)];
        }
        if ($method !== 'POST') {
            throw new BadRequestException('profile requires GET or POST');
        }

        $displayName = self::sanitizeProfileText($body['displayName'] ?? '', 80);
        $about       = self::sanitizeProfileText($body['about'] ?? '', 500);
        $websiteUrl  = self::sanitizeWebsiteUrl($body['websiteUrl'] ?? '');

        $this->users->updateProfile($id, $displayName, $websiteUrl, $about);

        $fresh = $this->users->findByUsername($username) ?? $row;
        return ['profile' => self::profilePayload($fresh)];
    }

    /**
     * Set (POST) or clear (DELETE) the current user's avatar. The
     * image is posted as a base64 data URL (or `{mime, data}` pair)
     * and stored inline in the users table so the build step cannot
     * strand uploaded files in `public/`.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function profilePicture(string $method, array $body): array
    {
        $username = $this->requireUser();
        $row = $this->users->findByUsername($username);
        if (!is_array($row) || !isset($row['id'])) {
            throw new UnauthorizedException('not signed in');
        }
        $id = (int) $row['id'];

        if ($method === 'DELETE' || ($method === 'POST' && ($body['clear'] ?? false) === true)) {
            $this->users->clearAvatar($id);
            $fresh = $this->users->findByUsername($username) ?? $row;
            return ['profile' => self::profilePayload($fresh)];
        }
        if ($method !== 'POST') {
            throw new BadRequestException('profile-picture requires POST or DELETE');
        }

        [$mime, $base64] = self::parseAvatarUpload($body);
        $this->users->setAvatar($id, $mime, $base64);

        $fresh = $this->users->findByUsername($username) ?? $row;
        return ['profile' => self::profilePayload($fresh)];
    }

    /**
     * Shape a raw users row as the JSON profile payload. Keeps the
     * sensitive bits (password hash) out of the response and folds the
     * avatar into a single `avatar` data URL so the frontend can drop
     * it straight into an <img>.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function profilePayload(array $row): array
    {
        $avatar = null;
        $mime = isset($row['avatar_mime']) && is_string($row['avatar_mime']) ? $row['avatar_mime'] : '';
        $data = isset($row['avatar_data']) && is_string($row['avatar_data']) ? $row['avatar_data'] : '';
        if ($mime !== '' && $data !== '') {
            $avatar = 'data:' . $mime . ';base64,' . $data;
        }
        return [
            'username'    => isset($row['username']) ? (string) $row['username'] : '',
            'displayName' => isset($row['display_name']) && is_string($row['display_name'])
                ? $row['display_name']
                : '',
            'websiteUrl'  => isset($row['website_url']) && is_string($row['website_url'])
                ? $row['website_url']
                : '',
            'about'       => isset($row['about']) && is_string($row['about']) ? $row['about'] : '',
            'avatar'      => $avatar,
        ];
    }

    /**
     * Trim and length-cap a free-form profile text field. Control
     * characters other than tab, newline and carriage return are
     * dropped so the stored value is safe to render as-is.
     */
    private static function sanitizeProfileText(mixed $value, int $maxLength): string
    {
        if (!is_string($value)) {
            return '';
        }
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
        if (function_exists('mb_substr')) {
            $value = mb_substr($value, 0, $maxLength);
        } elseif (strlen($value) > $maxLength) {
            $value = substr($value, 0, $maxLength);
        }
        return $value;
    }

    /**
     * Validate an optional http(s) website URL. Empty values are
     * allowed (the field is optional); anything else must parse as a
     * URL with an http or https scheme so we never hand the UI a
     * `javascript:` link it would dutifully click through.
     */
    private static function sanitizeWebsiteUrl(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (strlen($value) > 255) {
            throw new BadRequestException('website URL is too long');
        }
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            throw new BadRequestException('website URL is not a valid URL');
        }
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new BadRequestException('website URL must be http or https');
        }
        return $value;
    }

    /**
     * Extract the (mime, base64) tuple from an avatar upload. Accepts
     * either a `data:<mime>;base64,<bytes>` URL in `image` or a
     * `{mime, data}` pair. Rejects payloads outside the small
     * allowlist of web image formats or over the per-user size cap.
     *
     * @param array<string, mixed> $body
     * @return array{0: string, 1: string}
     */
    private static function parseAvatarUpload(array $body): array
    {
        $mime = '';
        $base64 = '';

        if (isset($body['image']) && is_string($body['image']) && $body['image'] !== '') {
            $image = $body['image'];
            if (preg_match('#^data:([a-zA-Z0-9.+-/]+);base64,([A-Za-z0-9+/=\s]+)$#', $image, $m) === 1) {
                $mime = strtolower($m[1]);
                $base64 = preg_replace('/\s+/', '', $m[2]) ?? '';
            } else {
                throw new BadRequestException('image must be a base64 data URL');
            }
        } else {
            if (isset($body['mime']) && is_string($body['mime'])) {
                $mime = strtolower(trim($body['mime']));
            }
            if (isset($body['data']) && is_string($body['data'])) {
                $base64 = preg_replace('/\s+/', '', $body['data']) ?? '';
            }
        }

        if ($mime === '' || $base64 === '') {
            throw new BadRequestException('avatar image is required');
        }

        $allowed = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
        if (!in_array($mime, $allowed, true)) {
            throw new BadRequestException(
                'avatar must be one of: ' . implode(', ', $allowed)
            );
        }

        // Cap the decoded size at ~256 KiB. base64 inflates by ~4/3,
        // so the encoded column stays comfortably under 400 KiB.
        $decoded = base64_decode($base64, true);
        if ($decoded === false) {
            throw new BadRequestException('avatar image is not valid base64');
        }
        if (strlen($decoded) > 262144) {
            throw new BadRequestException('avatar image exceeds the 256 KB limit');
        }

        // Re-encode the decoded bytes so the stored value is free of
        // whitespace or newline quirks from the client.
        return [$mime, base64_encode($decoded)];
    }

    /**
     * Separator used in category path strings exchanged with the
     * frontend (e.g. "Mods / Skyrim / Idrinth Thalui").
     */
    private const CATEGORY_PATH_SEPARATOR = ' / ';

    /**
     * Build the kanban board payload. Issues are grouped by status
     * into the five fixed columns; within each column they are
     * ordered by `position` then id so drag-and-drop ordering is
     * preserved. Each card carries the full breadcrumb of its
     * project ("categoryPath") so the UI can group cards under
     * umbrella categories. `category` is the collapsed-to-one-line
     * path for backwards compatibility. Time totals come from the
     * `time_aggregates` cache.
     *
     * @return array<string, mixed>
     */
    private function kanban(): array
    {
        $columnSpec = [
            ['id' => Issues::STATUS_TODO,        'title' => 'Todo',        'discarded' => false],
            ['id' => Issues::STATUS_IN_PROGRESS, 'title' => 'In Progress', 'discarded' => false],
            ['id' => Issues::STATUS_WAITING,     'title' => 'Waiting',     'discarded' => false],
            ['id' => Issues::STATUS_DONE,        'title' => 'Done',        'discarded' => false],
            ['id' => Issues::STATUS_DISCARDED,   'title' => 'Discarded',   'discarded' => true],
        ];

        $stmt = $this->db->pdo()->query(
            'SELECT i.id, i.title, i.description, i.status, i.position,
                    i.work_started_at, i.work_completed_at,
                    p.id AS project_id,
                    m.name AS milestone_name
               FROM issues i
               JOIN projects p ON p.id = i.project_id
          LEFT JOIN milestones m ON m.id = i.milestone_id
           ORDER BY i.status, i.position, i.id'
        );
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt === false ? [] : $stmt->fetchAll();

        // Pre-compute breadcrumbs for every project so the card loop
        // below does not issue a parent-chain query per row.
        $paths = $this->projects->allPaths();

        // Pull the cross-category totals for every issue in one query
        // so the card list doesn't issue N+1 lookups. Missing rows are
        // treated as zero hours.
        $aggStmt = $this->db->pdo()->prepare(
            "SELECT scope_id, hours FROM time_aggregates
              WHERE scope = :scope
                AND period_length = :period
                AND period_start = ''
                AND category = :category"
        );
        $aggStmt->execute([
            'scope'    => TimeAggregates::SCOPE_ISSUE,
            'period'   => TimeAggregates::PERIOD_TOTAL,
            'category' => TimeAggregates::CATEGORY_ALL,
        ]);
        $timeSpent = [];
        foreach ($aggStmt->fetchAll() as $row) {
            $timeSpent[(int) $row['scope_id']] = (float) $row['hours'];
        }

        $columns = [];
        foreach ($columnSpec as $spec) {
            $columns[$spec['id']] = [
                'id'        => $spec['id'],
                'title'     => $spec['title'],
                'discarded' => $spec['discarded'],
                'cards'     => [],
            ];
        }

        foreach ($rows as $row) {
            $status = (string) $row['status'];
            if (!isset($columns[$status])) {
                // Unknown status - skip rather than surface a broken column.
                continue;
            }
            $id = (int) $row['id'];
            $projectId = (int) $row['project_id'];
            $path = $paths[$projectId] ?? [];
            $columns[$status]['cards'][] = [
                'id'            => $id,
                'title'         => (string) $row['title'],
                'description'   => $row['description'] !== null ? (string) $row['description'] : '',
                'category'      => implode(self::CATEGORY_PATH_SEPARATOR, $path),
                'categoryPath'  => $path,
                'milestone'     => $row['milestone_name'] !== null ? (string) $row['milestone_name'] : null,
                'workStarted'   => $row['work_started_at'] !== null ? (string) $row['work_started_at'] : null,
                'workCompleted' => $row['work_completed_at'] !== null ? (string) $row['work_completed_at'] : null,
                'timeSpent'     => $timeSpent[$id] ?? 0.0,
            ];
        }

        return ['columns' => array_values($columns)];
    }

    /**
     * Persist a new card added from the kanban UI. The "column"
     * field maps to an issue status; "category" is the project name
     * (creating the project on demand keeps the write path simple
     * while there is no dedicated project admin UI). The optional
     * milestone is resolved or created within the chosen project.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function kanbanAdd(string $method, array $body): array
    {
        if ($method !== 'POST') {
            throw new BadRequestException('kanban-add requires POST');
        }
        $this->requireUser();

        $status      = isset($body['column']) && is_string($body['column']) ? $body['column'] : '';
        $title       = isset($body['title'])  && is_string($body['title'])  ? trim($body['title']) : '';
        $description = isset($body['description']) && is_string($body['description']) ? trim($body['description']) : '';
        $category    = isset($body['category'])  && is_string($body['category'])  ? trim($body['category'])  : '';
        $milestone   = isset($body['milestone']) && is_string($body['milestone']) ? trim($body['milestone']) : '';

        if ($status === '' || $title === '') {
            throw new BadRequestException('column and title are required');
        }
        if (!in_array($status, Issues::STATUSES, true)) {
            throw new BadRequestException("unknown column: {$status}");
        }
        if ($category === '') {
            $category = 'Uncategorised';
        }

        // Enforce edit access before resolving the path so we never
        // auto-create project nodes for callers that will be rejected.
        $this->requireEditAccessForPath($category);

        [$projectId, $path] = $this->resolveProjectIdByPath($category);
        $milestoneId = null;
        if ($milestone !== '') {
            $milestoneId = $this->resolveMilestoneIdByName($projectId, $milestone);
        }

        $position = $this->nextPositionInStatus($status);
        // If the card is being created directly in a later column
        // (e.g. "In Progress" or "Done") stamp the transition points
        // at creation so the timeline reflects reality.
        [$workStarted, $workCompleted] = self::timestampsForStatus($status, null, null);
        $issueId = $this->issues->create(
            $projectId,
            $milestoneId,
            $title,
            $status,
            $workStarted,
            $workCompleted,
            $description !== '' ? $description : null
        );
        // Place the new card at the bottom of its column.
        $this->issues->setStatus($issueId, $status, $position);

        return [
            'ok'   => true,
            'card' => [
                'id'            => $issueId,
                'title'         => $title,
                'description'   => $description,
                'category'      => implode(self::CATEGORY_PATH_SEPARATOR, $path),
                'categoryPath'  => $path,
                'milestone'     => $milestone !== '' ? $milestone : null,
                'workStarted'   => $workStarted,
                'workCompleted' => $workCompleted,
                'timeSpent'     => 0.0,
            ],
        ];
    }

    /**
     * Move a kanban card to a different column and/or index within
     * the target column. The "to" field is the destination status.
     * Positions of sibling cards in the target column are renumbered
     * from zero so future inserts don't drift.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function kanbanMove(string $method, array $body): array
    {
        if ($method !== 'POST') {
            throw new BadRequestException('kanban-move requires POST');
        }
        $this->requireUser();

        $id = isset($body['id']) && (is_int($body['id']) || (is_string($body['id']) && ctype_digit($body['id'])))
            ? (int) $body['id']
            : 0;
        $to    = isset($body['to']) && is_string($body['to']) ? $body['to'] : '';
        $index = isset($body['index']) && (is_int($body['index']) || (is_string($body['index']) && ctype_digit((string) $body['index'])))
            ? (int) $body['index']
            : 0;

        if ($id <= 0 || $to === '') {
            throw new BadRequestException('id and to are required');
        }
        if (!in_array($to, Issues::STATUSES, true)) {
            throw new BadRequestException("unknown column: {$to}");
        }

        $issue = $this->issues->find($id);
        if ($issue === null) {
            throw new BadRequestException('unknown issue');
        }
        $this->requireEditAccess((int) $issue['project_id']);

        $this->reorderWithin($to, $id, $index);

        // Stamp the transition points when a card leaves Todo or
        // enters Done/Discarded. Existing values are preserved so a
        // card that is moved back and forth keeps the original start
        // date rather than having it rewritten on every hop.
        $currentStarted = isset($issue['work_started_at']) && $issue['work_started_at'] !== null
            ? (string) $issue['work_started_at']
            : null;
        $currentCompleted = isset($issue['work_completed_at']) && $issue['work_completed_at'] !== null
            ? (string) $issue['work_completed_at']
            : null;
        [$workStarted, $workCompleted] = self::timestampsForStatus($to, $currentStarted, $currentCompleted);
        if ($workStarted !== $currentStarted || $workCompleted !== $currentCompleted) {
            $this->issues->setWorkTimestamps($id, $workStarted, $workCompleted);
        }

        // Auto-fill the milestone's release date the moment this move
        // leaves every card on the milestone in done/discarded. Cards
        // with no milestone (milestone_id IS NULL) are filtered out.
        if ($issue['milestone_id'] !== null) {
            $this->milestones->refreshReleaseStatus((int) $issue['milestone_id']);
        }

        return [
            'ok'   => true,
            'card' => [
                'id'            => $id,
                'workStarted'   => $workStarted,
                'workCompleted' => $workCompleted,
            ],
        ];
    }

    /**
     * Decide what work_started_at / work_completed_at should be for an
     * issue after it transitions to `$status`. A card that has already
     * been started or completed keeps its original timestamp - we only
     * fill blanks, never rewrite or clear them, so that moving a card
     * backwards on the board doesn't destroy history.
     *
     * @return array{0: ?string, 1: ?string} [workStartedAt, workCompletedAt]
     */
    private static function timestampsForStatus(
        string $status,
        ?string $currentStarted,
        ?string $currentCompleted
    ): array {
        $today = gmdate('Y-m-d');
        $started = $currentStarted;
        $completed = $currentCompleted;
        if ($status !== Issues::STATUS_TODO && $started === null) {
            $started = $today;
        }
        if (
            ($status === Issues::STATUS_DONE || $status === Issues::STATUS_DISCARDED)
            && $completed === null
        ) {
            $completed = $today;
        }
        return [$started, $completed];
    }

    /**
     * Return the full detail payload for a single issue: the card
     * itself (as rendered on the kanban board), plus its raw time
     * entries and comments. Used by the task detail modal.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function issue(string $method, array $body): array
    {
        if ($method !== 'POST') {
            throw new BadRequestException('issue requires POST');
        }
        $id = $this->readId($body);
        $issue = $this->issues->find($id);
        if ($issue === null) {
            throw new BadRequestException('unknown issue');
        }

        $projectId = (int) $issue['project_id'];
        $path = $this->projects->pathFor($projectId);

        $milestoneName = null;
        if ($issue['milestone_id'] !== null) {
            $milestone = $this->milestones->find((int) $issue['milestone_id']);
            if ($milestone !== null) {
                $milestoneName = (string) $milestone['name'];
            }
        }

        $agg = $this->timeAggregates->get(
            TimeAggregates::SCOPE_ISSUE,
            $id,
            TimeAggregates::PERIOD_TOTAL,
            '',
            TimeAggregates::CATEGORY_ALL
        );
        $timeSpent = $agg !== null ? (float) $agg['hours'] : 0.0;

        $entries = [];
        foreach ($this->timeEntries->forIssue($id) as $row) {
            $entries[] = [
                'id'       => (int) $row['id'],
                'spentOn'  => (string) $row['spent_on'],
                'hours'    => (float) $row['hours'],
                'category' => (string) $row['category'],
                'note'     => $row['note'] !== null ? (string) $row['note'] : '',
                'userId'   => (int) $row['user_id'],
                // username comes from the LEFT JOIN in
                // TimeEntries::forIssue and is NULL if the author was
                // since deleted; fall back to the empty string so the
                // frontend can decide how to render the gap.
                'userName' => isset($row['username']) && $row['username'] !== null
                    ? (string) $row['username']
                    : '',
            ];
        }

        $comments = [];
        foreach ($this->comments->forIssue($id) as $row) {
            $comments[] = [
                'id'        => (int) $row['id'],
                'author'    => (string) $row['author'],
                'body'      => (string) $row['body'],
                'createdAt' => (string) $row['created_at'],
            ];
        }

        // Pre-resolve project breadcrumbs once for both link
        // directions so a heavily linked card does not repeat the
        // pathFor() lookup per row.
        $allPaths = $this->projects->allPaths();
        $blockedBy = $this->mapLinkedIssues($this->issueLinks->blockedBy($id), $allPaths);
        $blocks    = $this->mapLinkedIssues($this->issueLinks->blocks($id), $allPaths);

        return [
            'issue' => [
                'id'            => $id,
                'title'         => (string) $issue['title'],
                'description'   => $issue['description'] !== null ? (string) $issue['description'] : '',
                'status'        => (string) $issue['status'],
                'category'      => implode(self::CATEGORY_PATH_SEPARATOR, $path),
                'categoryPath'  => $path,
                'milestone'     => $milestoneName,
                'workStarted'   => $issue['work_started_at'] !== null ? (string) $issue['work_started_at'] : null,
                'workCompleted' => $issue['work_completed_at'] !== null ? (string) $issue['work_completed_at'] : null,
                'timeSpent'     => $timeSpent,
            ],
            'timeEntries' => $entries,
            'comments'    => $comments,
            'blockedBy'   => $blockedBy,
            'blocks'      => $blocks,
        ];
    }

    /**
     * Format a list of issue rows returned by IssueLinks for the
     * detail view. Each entry carries the link id so the frontend can
     * delete it without a second round trip.
     *
     * @param list<array<string, mixed>>          $rows
     * @param array<int, list<string>>            $paths
     * @return list<array<string, mixed>>
     */
    private function mapLinkedIssues(array $rows, array $paths): array
    {
        $out = [];
        foreach ($rows as $row) {
            $projectId = (int) $row['project_id'];
            $path = $paths[$projectId] ?? [];
            $out[] = [
                'linkId'       => (int) $row['link_id'],
                'id'           => (int) $row['id'],
                'title'        => (string) $row['title'],
                'status'       => (string) $row['status'],
                'category'     => implode(self::CATEGORY_PATH_SEPARATOR, $path),
                'categoryPath' => $path,
            ];
        }
        return $out;
    }

    /**
     * Apply an edit to an existing issue: title, description,
     * category (project path, creating missing nodes), milestone,
     * status and work dates. Keeps the card's column in sync when the
     * status changes by appending it to the bottom of the new column.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function issueUpdate(string $method, array $body): array
    {
        if ($method !== 'POST') {
            throw new BadRequestException('issue-update requires POST');
        }
        $this->requireUser();

        $id = $this->readId($body);
        $existing = $this->issues->find($id);
        if ($existing === null) {
            throw new BadRequestException('unknown issue');
        }
        // Must be able to edit the card where it currently lives
        // before making any change (title, status, category, …).
        $this->requireEditAccess((int) $existing['project_id']);

        $title       = isset($body['title']) && is_string($body['title']) ? trim($body['title']) : '';
        $description = isset($body['description']) && is_string($body['description']) ? trim($body['description']) : '';
        $category    = isset($body['category']) && is_string($body['category']) ? trim($body['category']) : '';
        $milestone   = isset($body['milestone']) && is_string($body['milestone']) ? trim($body['milestone']) : '';
        $status      = isset($body['status']) && is_string($body['status']) ? $body['status'] : (string) $existing['status'];
        $workStarted = isset($body['workStarted']) && is_string($body['workStarted']) ? trim($body['workStarted']) : '';
        $workDone    = isset($body['workCompleted']) && is_string($body['workCompleted']) ? trim($body['workCompleted']) : '';

        if ($title === '') {
            throw new BadRequestException('title is required');
        }
        if (!in_array($status, Issues::STATUSES, true)) {
            throw new BadRequestException("unknown status: {$status}");
        }
        if ($category === '') {
            $category = 'Uncategorised';
        }

        // Re-home into a different project requires edit access on the
        // destination too; check before resolveProjectIdByPath() so a
        // rejection does not leave freshly auto-created orphans behind.
        $this->requireEditAccessForPath($category);

        [$projectId, $path] = $this->resolveProjectIdByPath($category);
        $milestoneId = null;
        if ($milestone !== '') {
            $milestoneId = $this->resolveMilestoneIdByName($projectId, $milestone);
        }

        // Update the main fields first, then re-home the project_id
        // separately because Issues::update() does not touch that
        // column (category edits are a detail-view-only affordance).
        $this->issues->update(
            $id,
            $title,
            $milestoneId,
            $status,
            $workStarted !== '' ? $workStarted : null,
            $workDone !== '' ? $workDone : null,
            $description !== '' ? $description : null
        );

        if ((int) $existing['project_id'] !== $projectId) {
            $stmt = $this->db->pdo()->prepare(
                'UPDATE issues SET project_id = :project_id, updated_at = :updated_at WHERE id = :id'
            );
            $stmt->execute([
                'project_id' => $projectId,
                'updated_at' => gmdate('c'),
                'id'         => $id,
            ]);
        }

        // If status changed, append the card to the bottom of the new
        // column so the kanban ordering stays sensible.
        if ((string) $existing['status'] !== $status) {
            $position = $this->nextPositionInStatus($status);
            $this->issues->setStatus($id, $status, $position);
        }

        // Auto-fill the release date on any milestone this edit may
        // have tipped over the "all cards done/discarded" threshold.
        // When the milestone was reassigned we re-check both sides:
        // the previous milestone may now be complete because this
        // card was the only blocker, and the new one may be complete
        // because the moved card arrived already done/discarded.
        // Unassigned milestones (NULL) are filtered out — there is
        // nothing to release.
        $previousMilestoneId = $existing['milestone_id'] !== null
            ? (int) $existing['milestone_id']
            : null;
        if ($previousMilestoneId !== null && $previousMilestoneId !== $milestoneId) {
            $this->milestones->refreshReleaseStatus($previousMilestoneId);
        }
        if ($milestoneId !== null) {
            $this->milestones->refreshReleaseStatus($milestoneId);
        }

        return [
            'ok'    => true,
            'issue' => [
                'id'            => $id,
                'title'         => $title,
                'description'   => $description,
                'status'        => $status,
                'category'      => implode(self::CATEGORY_PATH_SEPARATOR, $path),
                'categoryPath'  => $path,
                'milestone'     => $milestone !== '' ? $milestone : null,
                'workStarted'   => $workStarted !== '' ? $workStarted : null,
                'workCompleted' => $workDone !== '' ? $workDone : null,
            ],
        ];
    }

    /**
     * Record a time entry against an issue. Refreshes the aggregate
     * cache so the kanban board's per-card "time spent" stays in sync.
     *
     * The entry is attributed to the signed-in user; callers cannot
     * forge a different author. Legacy rows that predate the column
     * are backfilled to user-id 1 by the schema migration.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function issueTimeAdd(string $method, array $body): array
    {
        if ($method !== 'POST') {
            throw new BadRequestException('issue-time-add requires POST');
        }
        $this->requireUser();
        // requireUser() guarantees a session, so currentUserId() is
        // never null here; fall back to 1 only as a defensive default
        // matching the schema's column default.
        $userId   = $this->auth->currentUserId() ?? 1;
        $userName = $this->auth->currentUser() ?? '';

        $id = $this->readId($body);
        $issue = $this->issues->find($id);
        if ($issue === null) {
            throw new BadRequestException('unknown issue');
        }
        $this->requireEditAccess((int) $issue['project_id']);

        $spentOn  = isset($body['spentOn'])  && is_string($body['spentOn'])  ? trim($body['spentOn'])  : '';
        $category = isset($body['category']) && is_string($body['category']) ? trim($body['category']) : '';
        $note     = isset($body['note'])     && is_string($body['note'])     ? trim($body['note'])     : '';
        $hours    = isset($body['hours']) ? (float) $body['hours'] : 0.0;

        if ($spentOn === '') {
            $spentOn = gmdate('Y-m-d');
        }
        if ($hours <= 0) {
            throw new BadRequestException('hours must be greater than zero');
        }

        $entryId = $this->timeEntries->create(
            $id,
            $spentOn,
            $hours,
            $category,
            $note !== '' ? $note : null,
            $userId
        );

        $this->timeAggregates->refreshIssue($id);
        $this->timeAggregates->refreshProject((int) $issue['project_id']);
        if ($issue['milestone_id'] !== null) {
            $this->timeAggregates->refreshMilestone((int) $issue['milestone_id']);
        }

        $agg = $this->timeAggregates->get(
            TimeAggregates::SCOPE_ISSUE,
            $id,
            TimeAggregates::PERIOD_TOTAL,
            '',
            TimeAggregates::CATEGORY_ALL
        );

        return [
            'ok'    => true,
            'entry' => [
                'id'       => $entryId,
                'spentOn'  => $spentOn,
                'hours'    => $hours,
                'category' => $category,
                'note'     => $note,
                'userId'   => $userId,
                'userName' => $userName,
            ],
            'timeSpent' => $agg !== null ? (float) $agg['hours'] : 0.0,
        ];
    }

    /**
     * Append a comment to an issue. Attribution comes from the
     * signed-in user - callers cannot forge the author.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function issueCommentAdd(string $method, array $body): array
    {
        if ($method !== 'POST') {
            throw new BadRequestException('issue-comment-add requires POST');
        }
        $author = $this->requireUser();

        $id = $this->readId($body);
        $issue = $this->issues->find($id);
        if ($issue === null) {
            throw new BadRequestException('unknown issue');
        }
        $this->requireEditAccess((int) $issue['project_id']);

        $text = isset($body['body']) && is_string($body['body']) ? trim($body['body']) : '';
        if ($text === '') {
            throw new BadRequestException('body is required');
        }

        $commentId = $this->comments->create($id, $author, $text);

        return [
            'ok'      => true,
            'comment' => [
                'id'        => $commentId,
                'author'    => $author,
                'body'      => $text,
                'createdAt' => gmdate('c'),
            ],
        ];
    }

    /**
     * Record that issue `id` is blocked by issue `blockedBy`. The
     * inverse direction (`blockedBy` blocks `id`) is implied by the
     * same row. Rejects self-links, duplicates (in either direction)
     * and additions that would close a cycle in the dependency
     * graph.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function issueLinkAdd(string $method, array $body): array
    {
        if ($method !== 'POST') {
            throw new BadRequestException('issue-link-add requires POST');
        }
        $this->requireUser();

        $id = $this->readId($body);
        $blockedById = isset($body['blockedBy']) && (is_int($body['blockedBy']) || (is_string($body['blockedBy']) && ctype_digit($body['blockedBy'])))
            ? (int) $body['blockedBy']
            : 0;
        if ($blockedById <= 0) {
            throw new BadRequestException('blockedBy is required');
        }
        if ($id === $blockedById) {
            throw new BadRequestException('an issue cannot block itself');
        }
        $issue = $this->issues->find($id);
        if ($issue === null) {
            throw new BadRequestException('unknown issue');
        }
        $this->requireEditAccess((int) $issue['project_id']);
        if ($this->issues->find($blockedById) === null) {
            throw new BadRequestException('unknown blocker');
        }
        if ($this->issueLinks->exists($id, $blockedById)) {
            throw new BadRequestException('these issues are already linked');
        }
        // Cycle check: if the proposed blocker is already (transitively)
        // blocked by `id`, adding `id blocked by blockedById` would
        // close a loop. We walk the "is blocked by" graph from the
        // blocker and refuse if we hit `id`.
        $reachable = $this->issueLinks->ancestors($blockedById);
        if (isset($reachable[$id])) {
            throw new BadRequestException('that link would create a cycle');
        }

        $linkId = $this->issueLinks->add($id, $blockedById);
        $blocker = $this->issues->find($blockedById);
        $projectId = (int) $blocker['project_id'];
        $path = $this->projects->pathFor($projectId);

        return [
            'ok'   => true,
            'link' => [
                'linkId'       => $linkId,
                'id'           => $blockedById,
                'title'        => (string) $blocker['title'],
                'status'       => (string) $blocker['status'],
                'category'     => implode(self::CATEGORY_PATH_SEPARATOR, $path),
                'categoryPath' => $path,
            ],
        ];
    }

    /**
     * Remove a single dependency link by its id. Either endpoint of
     * the link can trigger removal; the row is the same regardless
     * of which side the user opened the detail view for.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function issueLinkRemove(string $method, array $body): array
    {
        if ($method !== 'POST') {
            throw new BadRequestException('issue-link-remove requires POST');
        }
        $this->requireUser();

        $linkId = isset($body['linkId']) && (is_int($body['linkId']) || (is_string($body['linkId']) && ctype_digit($body['linkId'])))
            ? (int) $body['linkId']
            : 0;
        if ($linkId <= 0) {
            throw new BadRequestException('linkId is required');
        }

        // Gate the delete on edit access for the issue that owns the
        // "blocked by" side of the link. Unknown links fall through
        // silently so repeated deletes stay idempotent.
        $stmt = $this->db->pdo()->prepare(
            'SELECT i.project_id FROM issue_links l
               JOIN issues i ON i.id = l.issue_id
              WHERE l.id = :id'
        );
        $stmt->execute(['id' => $linkId]);
        $row = $stmt->fetch();
        if ($row !== false) {
            $this->requireEditAccess((int) $row['project_id']);
        }

        $this->issueLinks->delete($linkId);
        return ['ok' => true];
    }

    /**
     * Extract a positive integer `id` from a request body, accepting
     * either the native int or a digit-only string.
     *
     * @param array<string, mixed> $body
     */
    private function readId(array $body): int
    {
        $raw = $body['id'] ?? null;
        if (is_int($raw) && $raw > 0) {
            return $raw;
        }
        if (is_string($raw) && ctype_digit($raw) && $raw !== '0') {
            return (int) $raw;
        }
        throw new BadRequestException('id is required');
    }

    /**
     * Insert the moved issue into the target column at `$index` and
     * rewrite every sibling's `position` so the ordering is compact
     * and 0-based. Runs inside a transaction so a partial update can
     * never leave the column in a half-renumbered state.
     */
    private function reorderWithin(string $status, int $movedId, int $index): void
    {
        $pdo = $this->db->pdo();

        $stmt = $pdo->prepare(
            'SELECT id FROM issues
              WHERE status = :status AND id <> :moved
           ORDER BY position, id'
        );
        $stmt->execute(['status' => $status, 'moved' => $movedId]);
        $others = array_map(static fn($row) => (int) $row['id'], $stmt->fetchAll());

        if ($index < 0) {
            $index = 0;
        }
        if ($index > count($others)) {
            $index = count($others);
        }
        array_splice($others, $index, 0, [$movedId]);

        $pdo->beginTransaction();
        try {
            $update = $pdo->prepare(
                'UPDATE issues
                    SET status = :status, position = :position, updated_at = :updated_at
                  WHERE id = :id'
            );
            $now = gmdate('c');
            foreach ($others as $pos => $issueId) {
                $update->execute([
                    'status'     => $status,
                    'position'   => $pos,
                    'updated_at' => $now,
                    'id'         => $issueId,
                ]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Resolve a category path like "Mods / Skyrim / Idrinth Thalui"
     * to a leaf project id, creating any missing intermediate nodes
     * along the way. Accepts either the " / " separator used by the
     * UI or a plain "/" so typing on mobile is less finicky. Empty
     * path segments are dropped; a wholly empty path falls back to
     * a single "Uncategorised" root.
     *
     * @return array{0: int, 1: list<string>} id of the leaf project
     *   and its full breadcrumb (including the leaf itself).
     */
    private function resolveProjectIdByPath(string $path): array
    {
        $segments = [];
        foreach (preg_split('#/#', $path) ?: [] as $piece) {
            $trimmed = trim((string) $piece);
            if ($trimmed !== '') {
                $segments[] = $trimmed;
            }
        }
        if ($segments === []) {
            $segments = ['Uncategorised'];
        }

        $parentId = null;
        $leafId = 0;
        foreach ($segments as $name) {
            $existing = $this->projects->findByParentAndName($parentId, $name);
            if ($existing !== null) {
                $leafId = (int) $existing['id'];
            } else {
                $leafId = $this->projects->create($name, $this->uniqueSlug($name), $parentId);
            }
            $parentId = $leafId;
        }
        return [$leafId, $segments];
    }

    /**
     * Produce a globally unique slug based on `$name`. The base slug
     * is slugified from the name; collisions are resolved by
     * appending a numeric suffix.
     */
    private function uniqueSlug(string $name): string
    {
        $base = self::slugify($name);
        if ($base === '') {
            $base = 'project';
        }
        $slug = $base;
        $suffix = 2;
        while ($this->projects->findBySlug($slug) !== null) {
            $slug = $base . '-' . $suffix;
            $suffix++;
        }
        return $slug;
    }

    /**
     * Find or create a milestone by (project, name).
     */
    private function resolveMilestoneIdByName(int $projectId, string $name): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM milestones WHERE project_id = :project_id AND name = :name'
        );
        $stmt->execute(['project_id' => $projectId, 'name' => $name]);
        $row = $stmt->fetch();
        if ($row !== false) {
            return (int) $row['id'];
        }
        return $this->milestones->create($projectId, $name);
    }

    /**
     * Next position to use when appending a card to a column.
     */
    private function nextPositionInStatus(string $status): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(MAX(position), -1) + 1 AS next
               FROM issues WHERE status = :status'
        );
        $stmt->execute(['status' => $status]);
        $row = $stmt->fetch();
        return $row === false ? 0 : (int) $row['next'];
    }

    private static function slugify(string $value): string
    {
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value, '-');
    }

    /**
     * Build the releases page payload: every project with at least
     * one released milestone, each with its releases in descending
     * release-date order. Unreleased milestones are intentionally
     * omitted from this view.
     *
     * Every project carries its full `path` breadcrumb so the
     * frontend can group projects under their umbrella categories
     * ("Mods", "Open Source", ...). Each release carries the
     * cross-category total hours logged against the issues tied to
     * it (from the `time_aggregates` cache); the project carries the
     * sum of those totals across all its releases.
     *
     * @return array<string, mixed>
     */
    private function releases(): array
    {
        $stmt = $this->db->pdo()->query(
            'SELECT p.id AS project_id,
                    p.name AS project_name,
                    m.id AS milestone_id,
                    m.name AS milestone_name,
                    m.released_at,
                    m.notes
               FROM projects p
               JOIN milestones m ON m.project_id = p.id
              WHERE m.released_at IS NOT NULL
           ORDER BY p.name ASC, m.released_at DESC, m.name DESC'
        );
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt === false ? [] : $stmt->fetchAll();

        // Pull the issues tied to each released milestone in one query
        // so the per-release task list can be rendered without firing
        // an N+1 lookup per release.
        $issueStmt = $this->db->pdo()->query(
            'SELECT i.id, i.title, i.status, i.milestone_id
               FROM issues i
               JOIN milestones m ON m.id = i.milestone_id
              WHERE m.released_at IS NOT NULL
           ORDER BY i.milestone_id, i.position, i.id'
        );
        /** @var list<array<string, mixed>> $issueRows */
        $issueRows = $issueStmt === false ? [] : $issueStmt->fetchAll();
        $issuesByMilestone = [];
        foreach ($issueRows as $issueRow) {
            $milestoneId = (int) $issueRow['milestone_id'];
            $issuesByMilestone[$milestoneId][] = [
                'id'     => (int) $issueRow['id'],
                'title'  => (string) $issueRow['title'],
                'status' => (string) $issueRow['status'],
            ];
        }

        // Pull the cross-category total hours for every milestone in a
        // single query and key them by milestone id. Milestones with no
        // logged time simply won't be in this map and are treated as 0.
        $hoursStmt = $this->db->pdo()->prepare(
            "SELECT scope_id, hours FROM time_aggregates
              WHERE scope = :scope
                AND period_length = :period
                AND period_start = ''
                AND category = :category"
        );
        $hoursStmt->execute([
            'scope'    => TimeAggregates::SCOPE_MILESTONE,
            'period'   => TimeAggregates::PERIOD_TOTAL,
            'category' => TimeAggregates::CATEGORY_ALL,
        ]);
        $hoursByMilestone = [];
        foreach ($hoursStmt->fetchAll() as $hoursRow) {
            $hoursByMilestone[(int) $hoursRow['scope_id']] = (float) $hoursRow['hours'];
        }

        $paths = $this->projects->allPaths();

        $projects = [];
        foreach ($rows as $row) {
            $projectId = (int) $row['project_id'];
            $milestoneId = (int) $row['milestone_id'];
            $path = $paths[$projectId] ?? [(string) $row['project_name']];
            $key = implode(self::CATEGORY_PATH_SEPARATOR, $path);
            if (!isset($projects[$key])) {
                $projects[$key] = [
                    'name'     => (string) $row['project_name'],
                    'path'     => $path,
                    'group'    => $path[0] ?? (string) $row['project_name'],
                    'releases' => [],
                ];
            }
            $projects[$key]['releases'][] = [
                'version'    => (string) $row['milestone_name'],
                'date'       => (string) $row['released_at'],
                'notes'      => $row['notes'] !== null ? (string) $row['notes'] : '',
                'issues'     => $issuesByMilestone[$milestoneId] ?? [],
                'totalHours' => $hoursByMilestone[$milestoneId] ?? 0.0,
            ];
        }

        // Per-project totals: sum the milestone totals so the UI can
        // surface "total time spent across every release" without the
        // frontend having to re-add the per-release figures itself.
        foreach ($projects as $key => $project) {
            $total = 0.0;
            foreach ($project['releases'] as $release) {
                $total += (float) $release['totalHours'];
            }
            $projects[$key]['totalHours'] = $total;
        }

        return ['projects' => array_values($projects)];
    }

    /**
     * Build the time tracking payload: the set of distinct work
     * categories recorded in `time_entries`, plus a week-by-week
     * breakdown (latest week first) where each row holds the hours
     * logged against one issue for that week, aligned with the
     * category list.
     *
     * Weeks are ISO calendar weeks (Monday through Sunday). Only
     * weeks that have at least one entry are returned.
     *
     * @return array<string, mixed>
     */
    private function time(): array
    {
        $pdo = $this->db->pdo();

        $catStmt = $pdo->query(
            "SELECT DISTINCT category FROM time_entries
              WHERE category <> ''
           ORDER BY category ASC"
        );
        /** @var list<array<string, mixed>> $catRows */
        $catRows = $catStmt === false ? [] : $catStmt->fetchAll();
        $categories = array_map(static fn($row) => (string) $row['category'], $catRows);
        $categoryIndex = array_flip($categories);

        $stmt = $pdo->query(
            'SELECT te.issue_id, te.category, te.spent_on, te.hours, i.title
               FROM time_entries te
               JOIN issues i ON i.id = te.issue_id
           ORDER BY te.spent_on DESC, te.id DESC'
        );
        /** @var list<array<string, mixed>> $entries */
        $entries = $stmt === false ? [] : $stmt->fetchAll();

        // Bucket entries by ISO week (keyed by the Monday start date),
        // and within each week by issue.
        $weeks = [];
        foreach ($entries as $entry) {
            $spentOn = (string) $entry['spent_on'];
            [$weekStart, $weekEnd] = self::weekRange($spentOn);
            if ($weekStart === null || $weekEnd === null) {
                continue;
            }

            if (!isset($weeks[$weekStart])) {
                $weeks[$weekStart] = [
                    'start'  => $weekStart,
                    'end'    => $weekEnd,
                    'issues' => [],
                ];
            }

            $issueId = (int) $entry['issue_id'];
            if (!isset($weeks[$weekStart]['issues'][$issueId])) {
                $weeks[$weekStart]['issues'][$issueId] = [
                    'id'    => $issueId,
                    'label' => '#' . $issueId . ' ' . (string) $entry['title'],
                    'hours' => array_fill(0, count($categories), 0.0),
                ];
            }

            $cat = (string) $entry['category'];
            if ($cat === '' || !isset($categoryIndex[$cat])) {
                continue;
            }
            $weeks[$weekStart]['issues'][$issueId]['hours'][$categoryIndex[$cat]] += (float) $entry['hours'];
        }

        // Sort weeks by start date desc; flatten the per-issue map
        // back to a list.
        krsort($weeks);
        $weeksOut = [];
        foreach ($weeks as $week) {
            $week['issues'] = array_values($week['issues']);
            $weeksOut[] = $week;
        }

        return [
            'categories' => $categories,
            'weeks'      => $weeksOut,
        ];
    }

    /**
     * Monday-to-Sunday ISO week range that contains `$date`.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function weekRange(string $date): array
    {
        try {
            $dt = new \DateTimeImmutable($date);
        } catch (\Exception $e) {
            return [null, null];
        }
        // ISO-8601 weekday: 1 (Mon) through 7 (Sun).
        $weekday = (int) $dt->format('N');
        $monday = $dt->modify('-' . ($weekday - 1) . ' days');
        $sunday = $monday->modify('+6 days');
        return [$monday->format('Y-m-d'), $sunday->format('Y-m-d')];
    }
}

final class UnauthorizedException extends \RuntimeException
{
}

final class ForbiddenException extends \RuntimeException
{
}

final class BadRequestException extends \RuntimeException
{
}
