<?php

declare(strict_types=1);

namespace PRSWS\CasaDelta\Lib;

/**
 * Freshness and cooldown rules for the shared status snapshot (data-model.md section 4).
 *
 *   fresh    <=> ok and schema = 1 and (now - finished_at) < 55 s and fingerprint matches
 *   cooldown <=> (now - finished_at) < 5 s   (whether the last run succeeded or failed)
 */
final class SnapshotFreshness
{
    public const SCHEMA = 1;
    public const TTL_SECONDS = 55;
    public const COOLDOWN_SECONDS = 5;

    /**
     * @param array<string, mixed>|null $snapshot
     */
    public static function isFresh(?array $snapshot, int $now, string $currentFingerprint): bool
    {
        if ($snapshot === null) {
            return false;
        }
        if (($snapshot['ok'] ?? false) !== true || ($snapshot['schema'] ?? null) !== self::SCHEMA) {
            return false;
        }
        if (!is_string($snapshot['fingerprint'] ?? null) || !hash_equals($currentFingerprint, $snapshot['fingerprint'])) {
            return false;
        }
        $age = self::age($snapshot, $now);

        return $age !== null && $age >= 0 && $age < self::TTL_SECONDS;
    }

    /**
     * @param array<string, mixed>|null $snapshot
     */
    public static function isInCooldown(?array $snapshot, int $now): bool
    {
        $age = $snapshot === null ? null : self::age($snapshot, $now);

        return $age !== null && $age >= 0 && $age < self::COOLDOWN_SECONDS;
    }

    /**
     * Seconds until the cooldown ends (1..5), or 0 when no cooldown is active.
     *
     * @param array<string, mixed>|null $snapshot
     */
    public static function retryAfter(?array $snapshot, int $now): int
    {
        if (!self::isInCooldown($snapshot, $now)) {
            return 0;
        }
        $age = (int)self::age($snapshot, $now);

        return max(1, min(self::COOLDOWN_SECONDS, self::COOLDOWN_SECONDS - $age));
    }

    /**
     * SHA-256 (hex) over the sorted "uuid|address" pairs of the given (enabled) hosts.
     *
     * @param list<array<string, mixed>> $hosts each with uuid and address
     */
    public static function fingerprint(array $hosts): string
    {
        $pairs = [];
        foreach ($hosts as $host) {
            $pairs[] = ($host['uuid'] ?? '') . '|' . ($host['address'] ?? '');
        }
        sort($pairs, SORT_STRING);

        return hash('sha256', implode("\n", $pairs));
    }

    /**
     * Parses an ISO 8601 timestamp to a unix time, or null when invalid.
     */
    public static function parseTime(mixed $value): ?int
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $time = strtotime($value);

        return $time === false ? null : $time;
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private static function age(array $snapshot, int $now): ?int
    {
        $finished = self::parseTime($snapshot['finished_at'] ?? null);

        return $finished === null ? null : $now - $finished;
    }
}
