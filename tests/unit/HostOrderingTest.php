<?php

declare(strict_types=1);

namespace PRSWS\CasaDelta\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PRSWS\CasaDelta\Lib\HostOrdering;

final class HostOrderingTest extends TestCase
{
    private static function host(string $uuid, string $name, string $group, int|string $order = 0): array
    {
        return ['uuid' => $uuid, 'name' => $name, 'group' => $group, 'sort_order' => $order];
    }

    /**
     * @param list<array<string, mixed>> $hosts
     * @return list<string>
     */
    private static function uuids(array $hosts): array
    {
        return array_map(static fn(array $h): string => $h['uuid'], HostOrdering::sort($hosts));
    }

    public function testGroupRank(): void
    {
        $this->assertSame(0, HostOrdering::groupRank('Network'));
        $this->assertSame(0, HostOrdering::groupRank('NETWORK'));
        $this->assertSame(1, HostOrdering::groupRank('nodes'));
        $this->assertSame(2, HostOrdering::groupRank('Apps'));
        $this->assertSame(3, HostOrdering::groupRank('Storage'));
        $this->assertSame(3, HostOrdering::groupRank(''));
    }

    public function testFixedGroupsComeBeforeCustomGroups(): void
    {
        $hosts = [
            self::host('c', 'Zed', 'Zebra'),
            self::host('d', 'Aaa', 'Apps'),
            self::host('b', 'Bbb', 'Nodes'),
            self::host('a', 'Ccc', 'network'),
            self::host('e', 'Ddd', 'Alpha'),
        ];

        $this->assertSame(['a', 'b', 'd', 'e', 'c'], self::uuids($hosts));
    }

    public function testCustomGroupsAreSortedCaseInsensitively(): void
    {
        $hosts = [
            self::host('1', 'x', 'beta'),
            self::host('2', 'x', 'Alpha'),
            self::host('3', 'x', 'Gamma'),
        ];

        $this->assertSame(['2', '1', '3'], self::uuids($hosts));
    }

    public function testSortOrderThenNameWithinGroup(): void
    {
        $hosts = [
            self::host('1', 'Charlie', 'Nodes', 5),
            self::host('2', 'bravo', 'Nodes', 5),
            self::host('3', 'Alpha', 'Nodes', 10),
            self::host('4', 'Zulu', 'Nodes', 0),
        ];

        $this->assertSame(['4', '2', '1', '3'], self::uuids($hosts));
    }

    public function testDefaultSortOrderZeroTiesAreSortedByName(): void
    {
        $hosts = [
            self::host('1', 'beta', 'Apps', ''),
            self::host('2', 'Alpha', 'Apps', 0),
            self::host('3', 'gamma', 'Apps', '0'),
        ];

        $this->assertSame(['2', '1', '3'], self::uuids($hosts));
    }

    public function testNegativeSortOrdersComeFirst(): void
    {
        $hosts = [
            self::host('1', 'A', 'Network', 0),
            self::host('2', 'B', 'Network', -5),
            self::host('3', 'C', 'Network', -9999),
            self::host('4', 'D', 'Network', 9999),
        ];

        $this->assertSame(['3', '2', '1', '4'], self::uuids($hosts));
    }

    public function testUuidIsFinalTieBreaker(): void
    {
        $hosts = [
            self::host('b-uuid', 'Same', 'Network', 1),
            self::host('a-uuid', 'same', 'Network', 1),
        ];

        $this->assertSame(['a-uuid', 'b-uuid'], self::uuids($hosts));
    }

    public function testSortDoesNotDependOnInputOrder(): void
    {
        $hosts = [
            self::host('1', 'A', 'Nodes', 2),
            self::host('2', 'B', 'Network', 0),
            self::host('3', 'C', 'Custom', -1),
            self::host('4', 'D', 'Apps', 0),
        ];

        $expected = self::uuids($hosts);
        $this->assertSame($expected, self::uuids(array_reverse($hosts)));
        $this->assertSame(['2', '1', '4', '3'], $expected);
    }
}
