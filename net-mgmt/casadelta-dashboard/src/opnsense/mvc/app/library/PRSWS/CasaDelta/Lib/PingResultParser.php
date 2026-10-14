<?php

declare(strict_types=1);

namespace PRSWS\CasaDelta\Lib;

/**
 * Maps a finished ping process to a host state (data-model.md section 3, research R-10).
 * Works on values passed in: it never runs a process itself.
 */
final class PingResultParser
{
    public const STATE_UP = 'up';
    public const STATE_DOWN = 'down';
    public const STATE_CANNOT_RESOLVE = 'cannot_resolve';

    private const EX_NOHOST = 68;

    /**
     * @return array{state: string, latency_ms: ?float, error_message: ?string}
     */
    public static function parse(
        int $exitCode,
        string $stdout,
        string $stderr,
        ?string $host = null,
        bool $timedOut = false
    ): array {
        if ($timedOut) {
            return self::result(self::STATE_DOWN, null, 'probe timed out');
        }
        if ($exitCode === 0) {
            return self::result(self::STATE_UP, self::averageRtt($stdout), null);
        }
        if ($exitCode === self::EX_NOHOST || self::looksUnresolved($stderr . "\n" . $stdout)) {
            return self::result(
                self::STATE_CANNOT_RESOLVE,
                null,
                $host !== null && $host !== '' ? 'cannot resolve ' . $host : 'cannot resolve host'
            );
        }
        if ($exitCode === 2) {
            return self::result(self::STATE_DOWN, null, null);
        }

        return self::result(self::STATE_DOWN, null, 'probe failed');
    }

    /**
     * Average RTT in ms (2 decimals) from the "round-trip min/avg/max/stddev" line, or null.
     */
    public static function averageRtt(string $output): ?float
    {
        if (preg_match(
            '/(?:round-trip|rtt)\s+min\/avg\/max\/(?:std-?dev|mdev)\s*=\s*[\d.]+\/([\d.]+)\//i',
            $output,
            $m
        ) !== 1) {
            return null;
        }

        return round((float)$m[1], 2);
    }

    private static function looksUnresolved(string $text): bool
    {
        return preg_match('/cannot resolve|unknown host|name or service not known|host name lookup failure/i', $text) === 1;
    }

    /**
     * @return array{state: string, latency_ms: ?float, error_message: ?string}
     */
    private static function result(string $state, ?float $latency, ?string $message): array
    {
        return [
            'state' => $state,
            'latency_ms' => $state === self::STATE_UP ? $latency : null,
            'error_message' => $message,
        ];
    }
}
