<?php

declare(strict_types=1);

namespace PRSWS\CasaDelta\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PRSWS\CasaDelta\Lib\SnapshotFreshness;

final class SnapshotFreshnessTest extends TestCase
{
    private const FP = 'abc123';

    private static function now(): int
    {
        return (int)strtotime('2026-10-14T09:16:00Z');
    }

    /**
     * @return array<string, mixed>
     */
    private static function snapshot(int $ageSeconds, array $override = []): array
    {
        return array_merge([
            'schema' => 1,
            'ok' => true,
            'finished_at' => gmdate('Y-m-d\TH:i:s\Z', self::now() - $ageSeconds),
            'fingerprint' => self::FP,
            'results' => [],
        ], $override);
    }

    public function testFreshBoundaries(): void
    {
        $this->assertTrue(SnapshotFreshness::isFresh(self::snapshot(0), self::now(), self::FP));
        $this->assertTrue(SnapshotFreshness::isFresh(self::snapshot(54), self::now(), self::FP));
        $this->assertFalse(SnapshotFreshness::isFresh(self::snapshot(55), self::now(), self::FP));
        $this->assertFalse(SnapshotFreshness::isFresh(self::snapshot(120), self::now(), self::FP));
    }

    public function testCooldownBoundaries(): void
    {
        $this->assertTrue(SnapshotFreshness::isInCooldown(self::snapshot(0), self::now()));
        $this->assertTrue(SnapshotFreshness::isInCooldown(self::snapshot(4), self::now()));
        $this->assertFalse(SnapshotFreshness::isInCooldown(self::snapshot(5), self::now()));
        $this->assertFalse(SnapshotFreshness::isInCooldown(self::snapshot(30), self::now()));
    }

    public function testCooldownAppliesAfterFailedRun(): void
    {
        $this->assertTrue(SnapshotFreshness::isInCooldown(self::snapshot(2, ['ok' => false]), self::now()));
    }

    public function testNotFreshWhenNotOk(): void
    {
        $this->assertFalse(SnapshotFreshness::isFresh(self::snapshot(1, ['ok' => false]), self::now(), self::FP));
    }

    public function testUnknownSchemaIsNotFresh(): void
    {
        $this->assertFalse(SnapshotFreshness::isFresh(self::snapshot(1, ['schema' => 2]), self::now(), self::FP));
        $this->assertFalse(SnapshotFreshness::isFresh(self::snapshot(1, ['schema' => '1']), self::now(), self::FP));
    }

    public function testDifferentFingerprintIsNotFresh(): void
    {
        $this->assertFalse(SnapshotFreshness::isFresh(self::snapshot(1), self::now(), 'other'));
    }

    public function testMissingOrMalformedSnapshotIsNotFresh(): void
    {
        $this->assertFalse(SnapshotFreshness::isFresh(null, self::now(), self::FP));
        $this->assertFalse(SnapshotFreshness::isFresh([], self::now(), self::FP));
        $this->assertFalse(SnapshotFreshness::isFresh(self::snapshot(1, ['finished_at' => 'garbage']), self::now(), self::FP));
        $this->assertFalse(SnapshotFreshness::isInCooldown(null, self::now()));
        $this->assertFalse(SnapshotFreshness::isInCooldown(self::snapshot(1, ['finished_at' => null]), self::now()));
    }

    public function testFutureFinishedAtIsNeitherFreshNorCooldown(): void
    {
        $future = self::snapshot(-30);
        $this->assertFalse(SnapshotFreshness::isFresh($future, self::now(), self::FP));
        $this->assertFalse(SnapshotFreshness::isInCooldown($future, self::now()));
    }

    public function testRetryAfterIsBetweenOneAndFive(): void
    {
        $this->assertSame(5, SnapshotFreshness::retryAfter(self::snapshot(0), self::now()));
        $this->assertSame(1, SnapshotFreshness::retryAfter(self::snapshot(4), self::now()));
        $this->assertSame(0, SnapshotFreshness::retryAfter(self::snapshot(5), self::now()));
        $this->assertSame(0, SnapshotFreshness::retryAfter(null, self::now()));
    }

    public function testFingerprintIsSha256HexOfSortedPairs(): void
    {
        $hosts = [
            ['uuid' => 'b', 'address' => 'pve.lan:8006'],
            ['uuid' => 'a', 'address' => '192.168.1.1'],
        ];

        $expected = hash('sha256', "a|192.168.1.1\nb|pve.lan:8006");
        $this->assertSame($expected, SnapshotFreshness::fingerprint($hosts));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', SnapshotFreshness::fingerprint($hosts));
    }

    public function testFingerprintIgnoresOrderAndOtherFields(): void
    {
        $a = [
            ['uuid' => 'a', 'address' => 'x', 'name' => 'One'],
            ['uuid' => 'b', 'address' => 'y', 'name' => 'Two'],
        ];
        $b = [
            ['uuid' => 'b', 'address' => 'y', 'name' => 'Renamed'],
            ['uuid' => 'a', 'address' => 'x', 'name' => 'One'],
        ];

        $this->assertSame(SnapshotFreshness::fingerprint($a), SnapshotFreshness::fingerprint($b));
    }

    public function testFingerprintChangesWithAddressOrMembership(): void
    {
        $base = [['uuid' => 'a', 'address' => 'x']];

        $this->assertNotSame(
            SnapshotFreshness::fingerprint($base),
            SnapshotFreshness::fingerprint([['uuid' => 'a', 'address' => 'y']])
        );
        $this->assertNotSame(
            SnapshotFreshness::fingerprint($base),
            SnapshotFreshness::fingerprint([])
        );
    }
}
