<?php
/**
 * Authentication configuration for Project View.
 *
 * Lists the accounts that are allowed to sign in and holds the secret
 * used to sign session JWTs. This file lives outside of public/ and is
 * only loaded server-side by src/Auth.php.
 *
 * Password hashes must be produced with PHP's password_hash() (bcrypt
 * or argon2). Generate one with:
 *
 *     php -r "echo password_hash('your-password', PASSWORD_BCRYPT), \"\n\";"
 *
 * The example entry below accepts the password "admin" - replace it
 * (and the JWT secret) before deploying.
 */

declare(strict_types=1);

return [
    // Secret used to sign JWTs. Must be long, random and kept private.
    // Rotate the secret to invalidate every outstanding session.
    'jwt_secret' => 'CHANGE-ME-to-a-long-random-string-before-going-to-production',

    // Lifetime of an issued session token, in seconds.
    'jwt_ttl' => 60 * 60 * 8,

    // Name of the cookie that carries the JWT.
    'cookie_name' => 'pv_auth',

    // Set to true when serving over HTTPS so the cookie is only sent
    // over a secure connection. Keep false for local HTTP development.
    'cookie_secure' => false,

    // username => password hash. Only accounts listed here can sign in.
    'users' => [
        'admin' => '$2y$12$rOmP8mcsdsGfSlrHve0.eu0bvl8wpvsdVknn.9fWc0XDEn8.8eupy',
    ],
];
