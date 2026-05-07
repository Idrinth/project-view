<?php
/**
 * Failed-login bookkeeping for the progressive slowdown applied by
 * src/Auth.php.
 *
 * Each row counts consecutive failed logins for a given
 * (username, ip) pair. A successful login deletes the row, so the
 * counter only reflects unbroken runs of bad guesses; failures aged
 * past the inactivity window are treated as if no row existed, which
 * lets a legitimate user who eventually walks away still come back
 * the next day without waiting through the cap.
 *
 * Throttling on (username, ip) rather than just username means an
 * attacker hammering one account from one host cannot lock the
 * legitimate owner out from elsewhere; conversely, a botnet trying a
 * single password across many IPs still pays the per-IP delay.
 */

declare(strict_types=1);

namespace ProjectView;

require_once __DIR__ . '/Database.php';

final class LoginAttempts
{
    /**
     * Failures older than this are ignored when computing the delay.
     * Keeps a stale counter from punishing a user who comes back
     * hours later with the right password.
     */
    public const RESET_AFTER_SECONDS = 86400;

    /**
     * Base delay for the first failure, in microseconds.
     * Each additional failure doubles the delay up to MAX_DELAY_USEC.
     */
    public const BASE_DELAY_USEC = 100_000;

    /**
     * Hard ceiling so a long-running guess streak cannot stall a
     * worker for more than a few seconds per request.
     */
    public const MAX_DELAY_USEC = 5_000_000;

    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Compute the delay (in microseconds) that should be applied
     * before responding to a login attempt for the given identity.
     * Returns 0 when no recent failures are on file.
     */
    public function delayUsec(string $username, string $ip): int
    {
        $failures = $this->currentFailures($username, $ip);
        if ($failures <= 0) {
            return 0;
        }
        return self::computeDelayUsec($failures);
    }

    /**
     * Sleep for the appropriate delay. Split out from delayUsec()
     * so callers can also log or test the value without blocking.
     */
    public function applyDelay(string $username, string $ip): void
    {
        $usec = $this->delayUsec($username, $ip);
        if ($usec > 0) {
            usleep($usec);
        }
    }

    /**
     * Record a failed attempt, incrementing the counter or starting
     * a fresh row when the previous one (if any) has aged out.
     *
     * Implemented as a single atomic upsert so two concurrent
     * failures against the same (username, ip) can't lose an
     * increment or trip the UNIQUE constraint. The CASE in the
     * conflict branch preserves the "stale reset" behavior: when
     * the existing row's last failure is older than the reset
     * window, the counter goes back to 1 instead of climbing
     * forever.
     *
     * Stale rows for unrelated identities are GC'd here too so the
     * table stays bounded under a sustained guessing attack from
     * many distinct (username, ip) tuples.
     */
    public function recordFailure(string $username, string $ip): void
    {
        $now    = gmdate('c');
        $cutoff = gmdate('c', time() - self::RESET_AFTER_SECONDS);

        $cleanup = $this->db->pdo()->prepare(
            'DELETE FROM login_attempts WHERE last_failure_at < :cutoff'
        );
        $cleanup->execute(['cutoff' => $cutoff]);

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO login_attempts (username, ip, failures, last_failure_at)
             VALUES (:username, :ip, 1, :now)
             ON CONFLICT (username, ip) DO UPDATE
                SET failures = CASE
                        WHEN login_attempts.last_failure_at < :stale_cutoff THEN 1
                        ELSE login_attempts.failures + 1
                    END,
                    last_failure_at = :now_update'
        );
        $stmt->execute([
            'username'     => $username,
            'ip'           => $ip,
            'now'          => $now,
            'now_update'   => $now,
            'stale_cutoff' => $cutoff,
        ]);
    }

    /**
     * Drop the counter for an identity that just signed in
     * successfully so subsequent legitimate logins are fast again.
     */
    public function reset(string $username, string $ip): void
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM login_attempts WHERE username = :username AND ip = :ip'
        );
        $stmt->execute([
            'username' => $username,
            'ip'       => $ip,
        ]);
    }

    private function currentFailures(string $username, string $ip): int
    {
        $row = $this->fetchRow($username, $ip);
        if ($row === null || $this->isStale($row)) {
            return 0;
        }
        return isset($row['failures']) ? (int) $row['failures'] : 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchRow(string $username, string $ip): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT failures, last_failure_at FROM login_attempts
              WHERE username = :username AND ip = :ip'
        );
        $stmt->execute([
            'username' => $username,
            'ip'       => $ip,
        ]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function isStale(array $row): bool
    {
        $last = isset($row['last_failure_at']) && is_string($row['last_failure_at'])
            ? strtotime($row['last_failure_at'])
            : false;
        if ($last === false) {
            return true;
        }
        return (time() - $last) > self::RESET_AFTER_SECONDS;
    }

    /**
     * Exponential backoff: base * 2^(failures-1), capped. Public so
     * callers and tests can reason about the curve without poking at
     * the database.
     */
    public static function computeDelayUsec(int $failures): int
    {
        if ($failures <= 0) {
            return 0;
        }
        // Cap the exponent so the shift cannot overflow on long
        // guess streaks; the result is clamped immediately after.
        $exponent = $failures - 1;
        if ($exponent > 20) {
            $exponent = 20;
        }
        $delay = self::BASE_DELAY_USEC * (1 << $exponent);
        if ($delay > self::MAX_DELAY_USEC) {
            return self::MAX_DELAY_USEC;
        }
        return $delay;
    }
}
