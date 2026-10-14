<?php

declare(strict_types=1);

namespace PRSWS\CasaDelta\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PRSWS\CasaDelta\Lib\StatusAssembler;

final class StatusAssemblerTest extends TestCase
{
    private const FINISHED = '2026-10-14T09:15:02Z';

    private static function host(
        string $uuid,
        string $name,
        string $group = 'Network',
        string|int $order = 0,
        string $enabled = '1',
        string $address = '192.168.1.1'
    ): array {
        return [
            'uuid' => $uuid,
            'enabled' => $enabled,
            'name' => $name,
            'address' => $address,
            'group' => $group,
            'sort_order' => $order,
            'description' => '',
        ];
    }

    private static function probe(string $state, ?float $latency = null, ?string $message = null): array
    {
        return [
            'state' => $state,
            'latency_ms' => $latency,
            'last_checked' => '2026-10-14T09:15:01Z',
            'error_message' => $message,
        ];
    }

    private static function snapshot(array $results, array $override = []): array
    {
        return array_merge([
            'schema' => 1,
            'ok' => true,
            'error' => null,
            'started_at' => '2026-10-14T09:15:00Z',
            'finished_at' => self::FINISHED,
            'duration_ms' => 1840,
            'fingerprint' => 'x',
            'results' => $results,
        ], $override);
    }

    /**
     * @param array<string, mixed> $status
     * @return list<string>
     */
    private static function flatUuids(array $status): array
    {
        $out = [];
        foreach ($status['groups'] as $group) {
            foreach ($group['hosts'] as $h) {
                $out[] = $h['host_uuid'];
            }
        }
        return $out;
    }

    public function testJoinsSnapshotResultsToConfigByUuid(): void
    {
        $hosts = [
            self::host('u1', 'Core Switch', 'Network', 0, '1', '192.168.1.2'),
            self::host('u2', 'Proxmox', 'Nodes', 10, '1', 'pve.lan:8006'),
        ];
        $snap = self::snapshot([
            'u1' => self::probe('up', 0.42),
            'u2' => self::probe('cannot_resolve', null, 'cannot resolve pve.lan'),
        ]);

        $status = StatusAssembler::assemble($hosts, $snap);

        $this->assertSame('Network', $status['groups'][0]['name']);
        $first = $status['groups'][0]['hosts'][0];
        $this->assertSame('u1', $first['host_uuid']);
        $this->assertSame('Core Switch', $first['name']);
        $this->assertSame('192.168.1.2', $first['address']);
        $this->assertSame('Network', $first['group']);
        $this->assertSame(0, $first['sort_order']);
        $this->assertSame('up', $first['state']);
        $this->assertSame(0.42, $first['latency_ms']);
        $this->assertSame('2026-10-14T09:15:01Z', $first['last_checked']);
        $this->assertNull($first['error_message']);
        $this->assertFalse($first['stale']);

        $second = $status['groups'][1]['hosts'][0];
        $this->assertSame('pve.lan:8006', $second['address']);
        $this->assertSame(10, $second['sort_order']);
        $this->assertSame('cannot_resolve', $second['state']);
        $this->assertNull($second['latency_ms']);
        $this->assertSame('cannot resolve pve.lan', $second['error_message']);
    }

    public function testMetadataComesFromCurrentConfigNotSnapshot(): void
    {
        $snap = self::snapshot(['u1' => self::probe('up', 1.0) + ['name' => 'Old name', 'group' => 'Old']]);

        $status = StatusAssembler::assemble([self::host('u1', 'New name', 'Apps')], $snap);

        $this->assertSame('Apps', $status['groups'][0]['name']);
        $this->assertSame('New name', $status['groups'][0]['hosts'][0]['name']);
    }

    public function testEnabledHostMissingFromSnapshotIsPending(): void
    {
        $status = StatusAssembler::assemble(
            [self::host('u1', 'A'), self::host('u2', 'B')],
            self::snapshot(['u1' => self::probe('up', 0.5)])
        );

        $pending = $status['groups'][0]['hosts'][1];
        $this->assertSame('u2', $pending['host_uuid']);
        $this->assertSame('pending', $pending['state']);
        $this->assertNull($pending['latency_ms']);
        $this->assertNull($pending['last_checked']);
        $this->assertSame(1, $status['summary']['pending_hosts']);
        $this->assertSame(1, $status['summary']['up_hosts']);
        $this->assertSame(0, $status['summary']['down_hosts']);
        $this->assertSame(2, $status['summary']['total_hosts']);
    }

    public function testDisabledHostsAreExcludedAndNotCounted(): void
    {
        $status = StatusAssembler::assemble(
            [self::host('u1', 'A'), self::host('u2', 'B', 'Network', 0, '0')],
            self::snapshot(['u1' => self::probe('up', 0.5), 'u2' => self::probe('down')])
        );

        $this->assertSame(['u1'], self::flatUuids($status));
        $this->assertSame(1, $status['summary']['total_hosts']);
        $this->assertSame(0, $status['summary']['down_hosts']);
    }

    public function testSummaryCounts(): void
    {
        $hosts = [
            self::host('u1', 'A'),
            self::host('u2', 'B'),
            self::host('u3', 'C'),
            self::host('u4', 'D'),
            self::host('u5', 'E'),
        ];
        $snap = self::snapshot([
            'u1' => self::probe('up', 0.1),
            'u2' => self::probe('up', 0.2),
            'u3' => self::probe('down'),
            'u4' => self::probe('cannot_resolve'),
        ]);

        $s = StatusAssembler::assemble($hosts, $snap)['summary'];

        $this->assertSame(5, $s['total_hosts']);
        $this->assertSame(2, $s['up_hosts']);
        $this->assertSame(1, $s['down_hosts']);
        $this->assertSame(1, $s['unresolved_hosts']);
        $this->assertSame(1, $s['pending_hosts']);
        $this->assertSame('ok', $s['subsystem_status']);
        $this->assertNull($s['subsystem_error']);
    }

    public function testNextCheckIsSixtySecondsAfterFinish(): void
    {
        $s = StatusAssembler::assemble([self::host('u1', 'A')], self::snapshot(['u1' => self::probe('up', 1.0)]))['summary'];

        $this->assertSame(self::FINISHED, $s['last_run']);
        $this->assertSame('2026-10-14T09:16:02Z', $s['next_check_at']);
    }

    public function testNotOkSnapshotIsSubsystemErrorWithStaleResultsAndNoDownHosts(): void
    {
        $snap = self::snapshot(
            ['u1' => self::probe('up', 0.5), 'u2' => self::probe('cannot_resolve')],
            ['ok' => false, 'error' => 'secret /etc/path failure']
        );

        $status = StatusAssembler::assemble([self::host('u1', 'A'), self::host('u2', 'B')], $snap);

        $s = $status['summary'];
        $this->assertSame('error', $s['subsystem_status']);
        $this->assertNotEmpty($s['subsystem_error']);
        $this->assertStringNotContainsString('/etc', $s['subsystem_error']);
        $this->assertSame(0, $s['down_hosts']);
        foreach ($status['groups'][0]['hosts'] as $h) {
            $this->assertTrue($h['stale']);
            $this->assertNotSame('down', $h['state']);
        }
        $this->assertSame('up', $status['groups'][0]['hosts'][0]['state']);
        $this->assertSame('2026-10-14T09:15:01Z', $status['groups'][0]['hosts'][0]['last_checked']);
    }

    public function testUnreadableSnapshotIsSubsystemErrorAndHostsStayPending(): void
    {
        $status = StatusAssembler::assemble([self::host('u1', 'A')], null);

        $this->assertSame('error', $status['summary']['subsystem_status']);
        $this->assertNotEmpty($status['summary']['subsystem_error']);
        $this->assertNull($status['summary']['last_run']);
        $this->assertNull($status['summary']['next_check_at']);
        $this->assertSame('pending', $status['groups'][0]['hosts'][0]['state']);
        $this->assertSame(0, $status['summary']['down_hosts']);
    }

    public function testUnknownSchemaIsSubsystemError(): void
    {
        $status = StatusAssembler::assemble(
            [self::host('u1', 'A')],
            self::snapshot(['u1' => self::probe('up', 1.0)], ['schema' => 2])
        );

        $this->assertSame('error', $status['summary']['subsystem_status']);
        $this->assertSame('pending', $status['groups'][0]['hosts'][0]['state'], 'unknown schema is rejected, results ignored');
        $this->assertNull($status['summary']['last_run']);
    }

    public function testExplicitSubsystemErrorMarksValidSnapshotResultsStale(): void
    {
        $status = StatusAssembler::assemble(
            [self::host('u1', 'A')],
            self::snapshot(['u1' => self::probe('up', 1.0)]),
            'Host check could not be executed (backend timeout).'
        );

        $this->assertSame('error', $status['summary']['subsystem_status']);
        $this->assertSame('Host check could not be executed (backend timeout).', $status['summary']['subsystem_error']);
        $this->assertTrue($status['groups'][0]['hosts'][0]['stale']);
        $this->assertSame('up', $status['groups'][0]['hosts'][0]['state']);
    }

    public function testEmptyConfigGivesEmptyOkStatus(): void
    {
        foreach ([null, self::snapshot([])] as $snap) {
            $status = StatusAssembler::assemble([], $snap);

            $this->assertSame(0, $status['summary']['total_hosts']);
            $this->assertSame([], $status['groups']);
            $this->assertSame('ok', $status['summary']['subsystem_status']);
            $this->assertNull($status['summary']['subsystem_error']);
        }
    }

    public function testOnlyDisabledHostsGivesEmptyStatus(): void
    {
        $status = StatusAssembler::assemble([self::host('u1', 'A', 'Network', 0, '0')], null);

        $this->assertSame(0, $status['summary']['total_hosts']);
        $this->assertSame([], $status['groups']);
        $this->assertSame('ok', $status['summary']['subsystem_status']);
    }

    public function testGroupsAndHostsComeOutInHostOrderingOrder(): void
    {
        $hosts = [
            self::host('u1', 'Zed', 'Custom', 0),
            self::host('u2', 'B', 'Apps', 5),
            self::host('u3', 'A', 'Apps', 5),
            self::host('u4', 'C', 'Nodes', 0),
            self::host('u5', 'D', 'Network', 3),
            self::host('u6', 'E', 'Network', -1),
        ];
        $snap = self::snapshot(array_fill_keys(['u1', 'u2', 'u3', 'u4', 'u5', 'u6'], self::probe('up', 1.0)));

        $status = StatusAssembler::assemble($hosts, $snap);

        $this->assertSame(['Network', 'Nodes', 'Apps', 'Custom'], array_column($status['groups'], 'name'));
        $this->assertSame(['u6', 'u5', 'u4', 'u3', 'u2', 'u1'], self::flatUuids($status));
    }

    public function testLatencyIsNullUnlessUp(): void
    {
        $snap = self::snapshot([
            'u1' => self::probe('down', 9.9),
            'u2' => self::probe('up', 1.5),
        ]);

        $status = StatusAssembler::assemble([self::host('u1', 'A'), self::host('u2', 'B')], $snap);

        $this->assertNull($status['groups'][0]['hosts'][0]['latency_ms']);
        $this->assertSame(1.5, $status['groups'][0]['hosts'][1]['latency_ms']);
    }

    public function testUnknownResultStateIsTreatedAsPending(): void
    {
        $status = StatusAssembler::assemble(
            [self::host('u1', 'A')],
            self::snapshot(['u1' => self::probe('weird')])
        );

        $this->assertSame('pending', $status['groups'][0]['hosts'][0]['state']);
        $this->assertSame(1, $status['summary']['pending_hosts']);
    }

    public function testEmptySortOrderIsReportedAsZero(): void
    {
        $status = StatusAssembler::assemble([self::host('u1', 'A', 'Network', '')], null);

        $this->assertSame(0, $status['groups'][0]['hosts'][0]['sort_order']);
    }
}
