<?php

declare(strict_types=1);

namespace PRSWS\CasaDelta\Lib;

/**
 * Joins the snapshot results to the current configuration and builds the status API body
 * (data-model.md sections 3, 5 and 6, contracts/status-api.md).
 */
final class StatusAssembler
{
    public const NEXT_CHECK_SECONDS = 60;

    private const STATES = ['up', 'down', 'cannot_resolve'];

    /**
     * @param list<array<string, mixed>> $hosts all configured hosts: uuid, enabled, name, address, group, sort_order
     * @param array<string, mixed>|null $snapshot decoded snapshot, or null when missing or unreadable
     * @param string|null $subsystemError set by the caller when running the check itself failed
     * @return array{summary: array<string, mixed>, groups: list<array{name: string, hosts: list<array<string, mixed>>}>}
     */
    public static function assemble(array $hosts, ?array $snapshot, ?string $subsystemError = null): array
    {
        $enabled = array_values(array_filter($hosts, static fn(array $h): bool => self::isEnabled($h['enabled'] ?? '0')));

        $error = null;
        $results = [];
        $lastRun = null;
        if (count($enabled) > 0) {
            $error = $subsystemError;
            $usable = $snapshot !== null && ($snapshot['schema'] ?? null) === SnapshotFreshness::SCHEMA;
            if ($usable) {
                $results = is_array($snapshot['results'] ?? null) ? $snapshot['results'] : [];
                $lastRun = SnapshotFreshness::parseTime($snapshot['finished_at'] ?? null);
                if (($snapshot['ok'] ?? false) !== true && $error === null) {
                    $error = 'Host check failed. Showing the last known results.';
                }
            } elseif ($error === null) {
                $error = $snapshot === null
                    ? 'Host check results are unavailable.'
                    : 'Host check results have an unsupported format.';
            }
        }
        $stale = $error !== null;

        $counts = ['up' => 0, 'down' => 0, 'cannot_resolve' => 0, 'pending' => 0];
        $groups = [];
        $groupIndex = [];
        foreach (HostOrdering::sort($enabled) as $host) {
            $entry = self::buildEntry($host, $results[(string)$host['uuid']] ?? null, $stale);
            $counts[$entry['state']]++;

            $key = HostOrdering::lower((string)$host['group']);
            if (!isset($groupIndex[$key])) {
                $groupIndex[$key] = count($groups);
                $groups[] = ['name' => (string)$host['group'], 'hosts' => []];
            }
            $groups[$groupIndex[$key]]['hosts'][] = $entry;
        }

        return [
            'summary' => [
                'total_hosts' => count($enabled),
                'up_hosts' => $counts['up'],
                'down_hosts' => $counts['down'],
                'unresolved_hosts' => $counts['cannot_resolve'],
                'pending_hosts' => $counts['pending'],
                'last_run' => $lastRun === null ? null : self::format($lastRun),
                'next_check_at' => $lastRun === null ? null : self::format($lastRun + self::NEXT_CHECK_SECONDS),
                'subsystem_status' => $error === null ? 'ok' : 'error',
                'subsystem_error' => $error,
            ],
            'groups' => $groups,
        ];
    }

    /**
     * @param array<string, mixed> $host
     * @return array<string, mixed>
     */
    private static function buildEntry(array $host, mixed $result, bool $stale): array
    {
        $state = 'pending';
        $latency = null;
        $lastChecked = null;
        $message = null;
        $isStale = false;

        if (is_array($result) && in_array($result['state'] ?? null, self::STATES, true)) {
            $state = $result['state'];
            $latency = $state === 'up' && is_numeric($result['latency_ms'] ?? null) ? (float)$result['latency_ms'] : null;
            $lastChecked = is_string($result['last_checked'] ?? null) ? $result['last_checked'] : null;
            $message = is_string($result['error_message'] ?? null) ? $result['error_message'] : null;
            $isStale = $stale;
        }

        $order = $host['sort_order'] ?? 0;

        return [
            'host_uuid' => (string)$host['uuid'],
            'name' => (string)($host['name'] ?? ''),
            'address' => (string)($host['address'] ?? ''),
            'group' => (string)($host['group'] ?? ''),
            'sort_order' => is_numeric($order) ? (int)$order : 0,
            'state' => $state,
            'latency_ms' => $latency,
            'last_checked' => $lastChecked,
            'error_message' => $message,
            'stale' => $isStale,
        ];
    }

    private static function isEnabled(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }

    private static function format(int $time): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $time);
    }
}
