<?php

declare(strict_types=1);

namespace PRSWS\CasaDelta\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PRSWS\CasaDelta\Lib\PingResultParser;

final class PingResultParserTest extends TestCase
{
    private const OK_OUTPUT = <<<TXT
PING pve.lan (192.168.1.10): 56 data bytes
64 bytes from 192.168.1.10: icmp_seq=0 ttl=64 time=0.412 ms
64 bytes from 192.168.1.10: icmp_seq=1 ttl=64 time=0.438 ms

--- pve.lan ping statistics ---
2 packets transmitted, 2 packets received, 0.0% packet loss
round-trip min/avg/max/stddev = 0.412/0.425/0.438/0.013 ms
TXT;

    public function testExitZeroIsUpWithAverageRtt(): void
    {
        $r = PingResultParser::parse(0, self::OK_OUTPUT, '');

        $this->assertSame('up', $r['state']);
        $this->assertSame(0.43, $r['latency_ms']);
        $this->assertNull($r['error_message']);
    }

    public function testLatencyIsRoundedToTwoDecimals(): void
    {
        $out = "round-trip min/avg/max/stddev = 1.000/12.34567/20.000/1.000 ms\n";
        $r = PingResultParser::parse(0, $out, '');

        $this->assertSame(12.35, $r['latency_ms']);
    }

    public function testOneOfTwoRepliesStillUp(): void
    {
        $out = "2 packets transmitted, 1 packets received, 50.0% packet loss\n"
            . "round-trip min/avg/max/stddev = 0.300/0.300/0.300/0.000 ms\n";
        $r = PingResultParser::parse(0, $out, '');

        $this->assertSame('up', $r['state']);
        $this->assertSame(0.3, $r['latency_ms']);
    }

    public function testExitZeroWithoutRttLineIsUpWithNullLatency(): void
    {
        $r = PingResultParser::parse(0, '', '');

        $this->assertSame('up', $r['state']);
        $this->assertNull($r['latency_ms']);
    }

    public function testExitTwoIsDown(): void
    {
        $out = "2 packets transmitted, 0 packets received, 100.0% packet loss\n";
        $r = PingResultParser::parse(2, $out, '');

        $this->assertSame('down', $r['state']);
        $this->assertNull($r['latency_ms']);
    }

    public function testExit68IsCannotResolve(): void
    {
        $r = PingResultParser::parse(68, '', 'ping: cannot resolve pve.lan: Host name lookup failure', 'pve.lan');

        $this->assertSame('cannot_resolve', $r['state']);
        $this->assertNull($r['latency_ms']);
        $this->assertSame('cannot resolve pve.lan', $r['error_message']);
    }

    public function testCannotResolveInStderrWithOtherExitCode(): void
    {
        $r = PingResultParser::parse(1, '', 'ping: cannot resolve nas.lan: Unknown host', 'nas.lan');

        $this->assertSame('cannot_resolve', $r['state']);
        $this->assertNull($r['latency_ms']);
    }

    public function testUnknownHostInStderrIsCannotResolve(): void
    {
        $r = PingResultParser::parse(1, '', 'ping: unknown host foo', 'foo');

        $this->assertSame('cannot_resolve', $r['state']);
    }

    public function testKilledAtDeadlineIsDownWithMessage(): void
    {
        $r = PingResultParser::parse(-1, '', '', null, true);

        $this->assertSame('down', $r['state']);
        $this->assertNull($r['latency_ms']);
        $this->assertSame('probe timed out', $r['error_message']);
    }

    public function testTimedOutWinsOverPartialOutput(): void
    {
        $r = PingResultParser::parse(0, self::OK_OUTPUT, '', null, true);

        $this->assertSame('down', $r['state']);
        $this->assertNull($r['latency_ms']);
    }

    public function testOtherExitCodeIsDownWithMessage(): void
    {
        $r = PingResultParser::parse(71, '', 'ping: sendto: Network is unreachable');

        $this->assertSame('down', $r['state']);
        $this->assertNull($r['latency_ms']);
        $this->assertNotEmpty($r['error_message']);
        $this->assertStringNotContainsString('unreachable', $r['error_message'], 'raw command output must not leak');
    }

    public function testLatencyOnlyWhenUp(): void
    {
        foreach ([[2, ''], [68, ''], [1, 'cannot resolve x'], [71, '']] as [$code, $err]) {
            $this->assertNull(PingResultParser::parse($code, self::OK_OUTPUT, $err)['latency_ms']);
        }
    }
}
