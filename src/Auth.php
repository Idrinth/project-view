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
require_once __DIR__ . '/Users.php';

final class Auth
{
    /** @var array<string, mixed> */
    private array $config;

    private Users $users;

    /**
     * @param array<string, mixed>|null $config Optional pre-loaded
     *   configuration. Primarily intended for tests; in normal use
     *   the constructor loads config/auth.php from disk.
     * @param Users|null $users Optional user repository. Primarily
     *   intended for tests; in normal use the constructor builds one
     *   from the default Database connection.
     */
    public function __construct(?array $config = null, ?Users $users = null)
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
    }

    public function cookieName(): string
    {
        return (string) $this->config['cookie_name'];
    }

    /**
     * Verify credentials and return a freshly signed JWT, or null on
     * failure. A uniform failure return value avoids leaking whether a
     * username exists.
     */
    public function attempt(string $username, string $password): ?string
    {
        $user = $this->users->findByUsername($username);
        $hash = is_array($user) && isset($user['password_hash']) && is_string($user['password_hash'])
            ? $user['password_hash']
            : '';
        if ($hash === '') {
            // Keep timing roughly constant by still running a verify.
            password_verify($password, '$2y$12$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvali');
            return null;
        }
        if (!password_verify($password, $hash)) {
            return null;
        }

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
        if ($this->users->findByUsername($sub) === null) {
            return null;
        }
        return $sub;
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
