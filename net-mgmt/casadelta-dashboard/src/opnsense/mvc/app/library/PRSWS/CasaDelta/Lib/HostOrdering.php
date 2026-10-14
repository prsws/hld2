<?php

declare(strict_types=1);

namespace PRSWS\CasaDelta\Lib;

/**
 * Display ordering of hosts (data-model.md section 6, research R-16).
 *
 * Sort key: (groupRank, groupRank = 3 ? lower(group) : "", sort_order, lower(name), uuid)
 * with network -> 0, nodes -> 1, apps -> 2, anything else -> 3 (case-insensitive).
 */
final class HostOrdering
{
    public const RANK_CUSTOM = 3;

    public static function groupRank(string $group): int
    {
        return match (self::lower($group)) {
            'network' => 0,
            'nodes' => 1,
            'apps' => 2,
            default => self::RANK_CUSTOM,
        };
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    public static function compare(array $a, array $b): int
    {
        $groupA = (string)($a['group'] ?? '');
        $groupB = (string)($b['group'] ?? '');
        $rankA = self::groupRank($groupA);
        $rankB = self::groupRank($groupB);

        return $rankA <=> $rankB
            ?: strcmp($rankA === self::RANK_CUSTOM ? self::lower($groupA) : '', $rankB === self::RANK_CUSTOM ? self::lower($groupB) : '')
            ?: (self::order($a) <=> self::order($b))
            ?: strcmp(self::lower((string)($a['name'] ?? '')), self::lower((string)($b['name'] ?? '')))
            ?: strcmp((string)($a['uuid'] ?? ''), (string)($b['uuid'] ?? ''));
    }

    /**
     * @param list<array<string, mixed>> $hosts each with uuid, name, group, sort_order
     * @return list<array<string, mixed>>
     */
    public static function sort(array $hosts): array
    {
        $sorted = array_values($hosts);
        usort($sorted, [self::class, 'compare']);

        return $sorted;
    }

    public static function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    /**
     * @param array<string, mixed> $host
     */
    private static function order(array $host): int
    {
        $value = $host['sort_order'] ?? 0;

        return is_numeric($value) ? (int)$value : 0;
    }
}
