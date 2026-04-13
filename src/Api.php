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
require_once __DIR__ . '/Milestones.php';
require_once __DIR__ . '/Issues.php';
require_once __DIR__ . '/TimeEntries.php';
require_once __DIR__ . '/TimeAggregates.php';

final class Api
{
    private Auth $auth;
    private Database $db;
    private Projects $projects;
    private Milestones $milestones;
    private Issues $issues;
    private TimeEntries $timeEntries;
    private TimeAggregates $timeAggregates;

    public function __construct(?Auth $auth = null, ?Database $db = null)
    {
        $this->auth = $auth ?? new Auth();
        $this->db = $db ?? new Database();
        $this->projects = new Projects($this->db);
        $this->milestones = new Milestones($this->db);
        $this->issues = new Issues($this->db);
        $this->timeEntries = new TimeEntries($this->db);
        $this->timeAggregates = new TimeAggregates($this->db);
    }

    /**
     * Dispatch a request to the matching endpoint method.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     * @throws \InvalidArgumentException When the endpoint is unknown.
     * @throws UnauthorizedException     When the caller is not signed in.
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
            case 'releases':
                return $this->releases();
            case 'time':
                return $this->time();
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
        return ['user' => ['name' => $user]];
    }

    /**
     * Build the kanban board payload. Issues are grouped by status
     * into the four fixed columns; within each column they are
     * ordered by `position` then id so drag-and-drop ordering is
     * preserved. Project name is surfaced as the card's "category"
     * (the UI's label for projects) and time totals come from the
     * `time_aggregates` cache.
     *
     * @return array<string, mixed>
     */
    private function kanban(): array
    {
        $columnSpec = [
            ['id' => Issues::STATUS_TODO,        'title' => 'Todo',        'discarded' => false],
            ['id' => Issues::STATUS_IN_PROGRESS, 'title' => 'In Progress', 'discarded' => false],
            ['id' => Issues::STATUS_DONE,        'title' => 'Done',        'discarded' => false],
            ['id' => Issues::STATUS_DISCARDED,   'title' => 'Discarded',   'discarded' => true],
        ];

        $stmt = $this->db->pdo()->query(
            'SELECT i.id, i.title, i.status, i.position,
                    i.work_started_at, i.work_completed_at,
                    p.name AS project_name,
                    m.name AS milestone_name
               FROM issues i
               JOIN projects p ON p.id = i.project_id
          LEFT JOIN milestones m ON m.id = i.milestone_id
           ORDER BY i.status, i.position, i.id'
        );
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt === false ? [] : $stmt->fetchAll();

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
            $columns[$status]['cards'][] = [
                'id'            => $id,
                'title'         => (string) $row['title'],
                'category'      => (string) $row['project_name'],
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

        $status    = isset($body['column']) && is_string($body['column']) ? $body['column'] : '';
        $title     = isset($body['title'])  && is_string($body['title'])  ? trim($body['title']) : '';
        $category  = isset($body['category'])  && is_string($body['category'])  ? trim($body['category'])  : '';
        $milestone = isset($body['milestone']) && is_string($body['milestone']) ? trim($body['milestone']) : '';

        if ($status === '' || $title === '') {
            throw new BadRequestException('column and title are required');
        }
        if (!in_array($status, Issues::STATUSES, true)) {
            throw new BadRequestException("unknown column: {$status}");
        }
        if ($category === '') {
            $category = 'Uncategorised';
        }

        $projectId = $this->resolveProjectIdByName($category);
        $milestoneId = null;
        if ($milestone !== '') {
            $milestoneId = $this->resolveMilestoneIdByName($projectId, $milestone);
        }

        $position = $this->nextPositionInStatus($status);
        $issueId = $this->issues->create(
            $projectId,
            $milestoneId,
            $title,
            $status
        );
        // Place the new card at the bottom of its column.
        $this->issues->setStatus($issueId, $status, $position);

        return [
            'ok'   => true,
            'card' => [
                'id'            => $issueId,
                'title'         => $title,
                'category'      => $category,
                'milestone'     => $milestone !== '' ? $milestone : null,
                'workStarted'   => null,
                'workCompleted' => null,
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

        $this->reorderWithin($to, $id, $index);
        return ['ok' => true];
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
     * Find the project row by name, creating it with a slugified
     * identifier if it does not yet exist. Slug collisions are
     * resolved by appending a numeric suffix.
     */
    private function resolveProjectIdByName(string $name): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM projects WHERE name = :name');
        $stmt->execute(['name' => $name]);
        $row = $stmt->fetch();
        if ($row !== false) {
            return (int) $row['id'];
        }

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
        return $this->projects->create($name, $slug);
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
     * @return array<string, mixed>
     */
    private function releases(): array
    {
        $stmt = $this->db->pdo()->query(
            'SELECT p.name AS project_name,
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

        $projects = [];
        foreach ($rows as $row) {
            $name = (string) $row['project_name'];
            if (!isset($projects[$name])) {
                $projects[$name] = [
                    'name'     => $name,
                    'releases' => [],
                ];
            }
            $projects[$name]['releases'][] = [
                'version' => (string) $row['milestone_name'],
                'date'    => (string) $row['released_at'],
                'notes'   => $row['notes'] !== null ? (string) $row['notes'] : '',
            ];
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

final class BadRequestException extends \RuntimeException
{
}
