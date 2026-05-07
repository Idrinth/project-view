<?php
/**
 * Authentication service for Project View.
 *
 * Reads the JWT secret and cookie settings from config/auth.php,
 * issues signed tokens on successful login and verifies incoming
 * cookies so that the API can identify the caller.
 *
 * Accounts live in the database (the `users` table) and are managed
 * from the CLI with bin/users.php; this class never touches the user
 * list in config. Passwords are compared against stored hashes with
 * password_verify(), so only accounts present in the database can
 * sign in.
 */

declare(strict_types=1);

namespace ProjectView;

require_once __DIR__ . '/Jwt.php';
require_once __DIR__ . '/LoginAttempts.php';
require_once __DIR__ . '/Users.php';

final class Auth
{
    /** @var array<string, mixed> */
    private array $config;

    private Users $users;

    private ?LoginAttempts $loginAttempts;

    /**
     * Guard so the cookie gets re-issued at most once per request even
     * if currentUser()/currentUserId() is consulted several times.
     */
    private bool $cookieRefreshed = false;

    /**
     * @param array<string, mixed>|null $config Optional pre-loaded
     *   configuration. Primarily intended for tests; in normal use
     *   the constructor loads config/auth.php from disk.
     * @param Users|null $users Optional user repository. Primarily
     *   intended for tests; in normal use the constructor builds one
     *   from the default Database connection.
     * @param LoginAttempts|null $loginAttempts Optional throttle
     *   bookkeeper. Pass null in tests that don't care about the
     *   slowdown; production wiring builds one from the default
     *   Database connection.
     */
    public function __construct(?array $config = null, ?Users $users = null, ?LoginAttempts $loginAttempts = null)
    {
        if ($config === null) {
            $path = __DIR__ . '/../config/auth.php';
            if (!is_file($path)) {
                throw new \RuntimeException('auth config not found: ' . $path);
            }
            /** @var mixed $loaded */
            $loaded = require $path;
            if (!is_array($loaded)) {
                throw new \RuntimeException('auth config must return an array');
            }
            $config = $loaded;
        }

        $this->config = $config + [
            'jwt_secret'    => '',
            'jwt_ttl'       => 3600,
            'cookie_name'   => 'pv_auth',
            'cookie_secure' => false,
        ];

        if (!is_string($this->config['jwt_secret']) || $this->config['jwt_secret'] === '') {
            throw new \RuntimeException('auth config: jwt_secret must be a non-empty string');
        }

        $this->users = $users ?? new Users(new Database());
        $this->loginAttempts = $loginAttempts;
    }

    /**
     * Lazily build the LoginAttempts repository so installs that
     * pre-date the table only touch it once a login actually happens
     * (and migrate.php has had a chance to run).
     */
    private function loginAttempts(): LoginAttempts
    {
        if ($this->loginAttempts === null) {
            $this->loginAttempts = new LoginAttempts(new Database());
        }
        return $this->loginAttempts;
    }

    public function cookieName(): string
    {
        return (string) $this->config['cookie_name'];
    }

    /**
     * Verify credentials and return a freshly signed JWT, or null on
     * failure. A uniform failure return value avoids leaking whether a
     * username exists.
     *
     * Failed attempts are throttled with a progressive delay tracked
     * per (username, ip) in src/LoginAttempts.php: the delay is paid
     * up-front so even a guess against a non-existent username feels
     * the same as a wrong password against a real one. A successful
     * login clears the counter for the calling identity.
     *
     * @param string|null $ip Optional client IP. Defaults to
     *   $_SERVER['REMOTE_ADDR']; tests can pass a fixed value.
     */
    public function attempt(string $username, string $password, ?string $ip = null): ?string
    {
        $clientIp = $ip ?? (isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])
            ? $_SERVER['REMOTE_ADDR']
            : '');
        $throttle = $this->loginAttempts();
        $throttle->applyDelay($username, $clientIp);

        $user = $this->users->findByUsername($username);
        $hash = is_array($user) && isset($user['password_hash']) && is_string($user['password_hash'])
            ? $user['password_hash']
            : '';
        if ($hash === '') {
            // Keep timing roughly constant by still running a verify.
            password_verify($password, '$2y$12$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvali');
            $throttle->recordFailure($username, $clientIp);
            return null;
        }
        if (!password_verify($password, $hash)) {
            $throttle->recordFailure($username, $clientIp);
            return null;
        }

        $throttle->reset($username, $clientIp);

        $now = time();
        return Jwt::encode(
            [
                'sub' => $username,
                'iat' => $now,
                'nbf' => $now,
                'exp' => $now + (int) $this->config['jwt_ttl'],
            ],
            (string) $this->config['jwt_secret']
        );
    }

    /**
     * Return the authenticated username for the current request, or
     * null when no valid cookie is present.
     */
    public function currentUser(): ?string
    {
        $row = $this->currentUserRow();
        if ($row === null) {
            return null;
        }
        $username = $row['username'] ?? null;
        return is_string($username) && $username !== '' ? $username : null;
    }

    /**
     * Return the numeric id of the authenticated user, or null when no
     * valid session is present. Useful for callers that need to stamp
     * a foreign key (e.g. time entry attribution) rather than display
     * a username.
     */
    public function currentUserId(): ?int
    {
        $row = $this->currentUserRow();
        if ($row === null || !isset($row['id'])) {
            return null;
        }
        return (int) $row['id'];
    }

    /**
     * Resolve the cookie to the matching row in the users table. Both
     * currentUser() and currentUserId() build on this so the JWT is
     * verified once per request and the user lookup never disagrees
     * with itself.
     *
     * @return array<string, mixed>|null
     */
    private function currentUserRow(): ?array
    {
        $name = $this->cookieName();
        if (!isset($_COOKIE[$name]) || !is_string($_COOKIE[$name])) {
            return null;
        }
        $claims = Jwt::decode($_COOKIE[$name], (string) $this->config['jwt_secret']);
        if ($claims === null) {
            return null;
        }
        $sub = $claims['sub'] ?? null;
        if (!is_string($sub) || $sub === '') {
            return null;
        }
        // Guard against users that were removed from the database
        // after their token was issued.
        $row = $this->users->findByUsername($sub);
        if ($row === null) {
            return null;
        }

        // Slide the session forward for active users: once the token is
        // past the halfway mark of its lifetime, re-issue it with a full
        // TTL so a tab that keeps hitting the API never expires mid-use.
        $this->maybeRefreshCookie($sub, $claims);

        return $row;
    }

    /**
     * Re-issue the session cookie with a fresh TTL when the current
     * token is past half its lifetime. This keeps active sessions alive
     * without forcing users to sign back in every few hours.
     *
     * @param array<string, mixed> $claims
     */
    private function maybeRefreshCookie(string $subject, array $claims): void
    {
        if ($this->cookieRefreshed) {
            return;
        }
        // Headers may already have been flushed by a streaming endpoint
        // (e.g. comment-attachment). Setting a cookie after that would
        // raise a warning and do nothing useful.
        if (headers_sent()) {
            return;
        }

        $ttl = (int) $this->config['jwt_ttl'];
        if ($ttl <= 0) {
            return;
        }
        $exp = isset($claims['exp']) && is_numeric($claims['exp']) ? (int) $claims['exp'] : 0;
        if ($exp <= 0) {
            // Tokens without an exp claim don't expire, so there's
            // nothing to slide.
            return;
        }

        $now = time();
        if ($exp - $now > intdiv($ttl, 2)) {
            // Still in the first half of the token's lifetime - no need
            // to spend cycles (or a Set-Cookie header) refreshing yet.
            return;
        }

        $token = Jwt::encode(
            [
                'sub' => $subject,
                'iat' => $now,
                'nbf' => $now,
                'exp' => $now + $ttl,
            ],
            (string) $this->config['jwt_secret']
        );
        $this->sendCookie($token);
        // Update the in-process cookie so any later currentUser() calls
        // on the same request see the refreshed value rather than the
        // soon-to-expire one.
        $_COOKIE[$this->cookieName()] = $token;
        $this->cookieRefreshed = true;
    }

    /**
     * Persist a freshly issued token as an HttpOnly cookie on the
     * current response.
     */
    public function sendCookie(string $token): void
    {
        setcookie($this->cookieName(), $token, [
            'expires'  => time() + (int) $this->config['jwt_ttl'],
            'path'     => '/',
            'secure'   => (bool) $this->config['cookie_secure'],
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * Clear the auth cookie so the client is logged out.
     */
    public function clearCookie(): void
    {
        setcookie($this->cookieName(), '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => (bool) $this->config['cookie_secure'],
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
