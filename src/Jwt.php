<?php
/**
 * Minimal HS256 JSON Web Token helper.
 *
 * Only the algorithm we actually use is supported; any other "alg"
 * value in an incoming token is rejected. There are no external
 * dependencies: encoding and verification are done with hash_hmac.
 */

declare(strict_types=1);

namespace ProjectView;

final class Jwt
{
    /**
     * Encode an array of claims as a signed JWT.
     *
     * @param array<string, mixed> $claims
     */
    public static function encode(array $claims, string $secret): string
    {
        $header  = ['alg' => 'HS256', 'typ' => 'JWT'];
        $segments = [
            self::base64UrlEncode(self::jsonEncode($header)),
            self::base64UrlEncode(self::jsonEncode($claims)),
        ];
        $signing   = implode('.', $segments);
        $signature = hash_hmac('sha256', $signing, $secret, true);
        $segments[] = self::base64UrlEncode($signature);

        return implode('.', $segments);
    }

    /**
     * Verify a JWT and return its claims, or null if it is invalid or
     * expired. "exp" and "nbf" are honoured when present.
     *
     * @return array<string, mixed>|null
     */
    public static function decode(string $token, string $secret): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$header64, $payload64, $signature64] = $parts;

        $header = json_decode(self::base64UrlDecode($header64) ?? '', true);
        if (!is_array($header) || ($header['alg'] ?? null) !== 'HS256') {
            return null;
        }

        $expected = hash_hmac('sha256', $header64 . '.' . $payload64, $secret, true);
        $actual   = self::base64UrlDecode($signature64);
        if ($actual === null || !hash_equals($expected, $actual)) {
            return null;
        }

        $payload = json_decode(self::base64UrlDecode($payload64) ?? '', true);
        if (!is_array($payload)) {
            return null;
        }

        $now = time();
        if (isset($payload['exp']) && is_numeric($payload['exp']) && $now >= (int) $payload['exp']) {
            return null;
        }
        if (isset($payload['nbf']) && is_numeric($payload['nbf']) && $now < (int) $payload['nbf']) {
            return null;
        }

        return $payload;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $padded  = str_pad($value, (int) (ceil(strlen($value) / 4) * 4), '=');
        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);
        return $decoded === false ? null : $decoded;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function jsonEncode(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
