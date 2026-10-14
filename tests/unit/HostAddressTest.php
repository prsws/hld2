<?php

declare(strict_types=1);

namespace PRSWS\CasaDelta\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PRSWS\CasaDelta\Lib\HostAddress;
use PRSWS\CasaDelta\Lib\HostAddressException;

final class HostAddressTest extends TestCase
{
    /**
     * @return array<string, array{string, string, int|null, string}>
     */
    public static function acceptedProvider(): array
    {
        return [
            // contracts/settings-api.md "Validation matrix"
            'ipv4'                          => ['192.168.1.10', '192.168.1.10', null, 'ipv4'],
            'ipv4 with port'                => ['192.168.1.10:8006', '192.168.1.10', 8006, 'ipv4'],
            'bare ipv6'                     => ['fe80::1', 'fe80::1', null, 'ipv6'],
            'bracketed ipv6 with port'      => ['[2001:db8::1]:8006', '2001:db8::1', 8006, 'ipv6'],
            'single label hostname'         => ['pve', 'pve', null, 'hostname'],
            'hostname'                      => ['pve.lan', 'pve.lan', null, 'hostname'],
            'hostname with port'            => ['pve.lan:8006', 'pve.lan', 8006, 'hostname'],
            'ipv6 looks like port'          => ['2001:db8::1:8006', '2001:db8::1:8006', null, 'ipv6'],
            'bracketed ipv6 without port'   => ['[2001:db8::1]', '2001:db8::1', null, 'ipv6'],
            // data-model.md section 2 examples
            'dm ipv4'                       => ['192.168.1.1', '192.168.1.1', null, 'ipv4'],
            'dm fe80::1:8006 is ipv6'       => ['fe80::1:8006', 'fe80::1:8006', null, 'ipv6'],
            // ports and hostname edge cases
            'port 65535'                    => ['pve.lan:65535', 'pve.lan', 65535, 'hostname'],
            'port 1'                        => ['pve.lan:1', 'pve.lan', 1, 'hostname'],
            'trailing dot hostname'         => ['pve.lan.', 'pve.lan.', null, 'hostname'],
            'hostname with hyphen and digit' => ['nas-01.home.lan:5000', 'nas-01.home.lan', 5000, 'hostname'],
            'numeric tld hostname'          => ['host.123', 'host.123', null, 'hostname'],
        ];
    }

    #[DataProvider('acceptedProvider')]
    public function testAccepted(string $raw, string $host, ?int $port, string $kind): void
    {
        $address = HostAddress::parse($raw);

        $this->assertSame($raw, $address->raw);
        $this->assertSame($host, $address->host);
        $this->assertSame($port, $address->port);
        $this->assertSame($kind, $address->kind);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function rejectedProvider(): array
    {
        $ipv4 = 'Invalid IPv4 address.';
        $port = 'Port must be between 1 and 65535.';
        $host = 'Invalid hostname.';

        return [
            'three octets'              => ['192.168.1', $ipv4],
            'octet out of range'        => ['192.168.1.300', $ipv4],
            'all numeric labels'        => ['999.1.1.1', $ipv4],
            'port zero'                 => ['pve.lan:0', $port],
            'port 65536'                => ['pve.lan:65536', $port],
            'leading zero port'         => ['pve.lan:08006', $port],
            'non numeric port'          => ['pve.lan:http', $port],
            'empty port'                => ['pve.lan:', $port],
            'ipv4 port zero'            => ['192.168.1.10:0', $port],
            'bracketed ipv6 bad port'   => ['[2001:db8::1]:65536', $port],
            'bracketed ipv6 empty port' => ['[2001:db8::1]:', $port],
            'bracketed ipv4'            => ['[192.168.1.1]:80', 'Only IPv6 addresses may be bracketed.'],
            'bracketed hostname'        => ['[pve.lan]', 'Only IPv6 addresses may be bracketed.'],
            'leading hyphen'            => ['-pve.lan', $host],
            'underscore'                => ['pve_lan', $host],
            'empty label'               => ['a..b', $host],
            'trailing hyphen label'     => ['pve-.lan', $host],
            'label too long'            => [str_repeat('a', 64) . '.lan', $host],
            'hostname too long'         => [implode('.', array_fill(0, 64, 'abcd')), $host],
            'url'                       => ['http://pve.lan', 'Enter a host, not a URL.'],
            'url with path'             => ['pve.lan/status', 'Enter a host, not a URL.'],
            'zone identifier'           => ['fe80::1%igb0', 'IPv6 zone identifiers are not supported.'],
            'empty'                     => ['', 'Address is required.'],
            'whitespace only'           => ['   ', 'Address is required.'],
            'invalid bare ipv6'         => ['fe80::zz', 'Invalid IPv6 address.'],
            'invalid bracketed ipv6'    => ['[fe80::zz]:80', 'Invalid IPv6 address.'],
            'embedded space'            => ['pve .lan', $host],
        ];
    }

    #[DataProvider('rejectedProvider')]
    public function testRejected(string $raw, string $message): void
    {
        try {
            HostAddress::parse($raw);
            $this->fail("Expected '$raw' to be rejected");
        } catch (HostAddressException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    public function testTryParseReturnsNullOnError(): void
    {
        $this->assertNull(HostAddress::tryParse('192.168.1'));
        $this->assertInstanceOf(HostAddress::class, HostAddress::tryParse('192.168.1.1'));
    }
}
