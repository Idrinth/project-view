<?php
/**
 * Authentication configuration for Project View.
 *
 * Holds the secret used to sign session JWTs and the cookie settings
 * used to deliver them. This file lives outside of public/ and is
 * only loaded server-side by src/Auth.php.
 *
 * Accounts are stored in the database (the `users` table) and are
 * managed from the command line:
 *
 *     php bin/users.php add    <username>
 *     php bin/users.php passwd <username>
 *     php bin/users.php list
 *     php bin/users.php delete <username>
 *
 * Replace the JWT secret below before deploying.
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
];
