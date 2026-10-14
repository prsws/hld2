<?php

declare(strict_types=1);

namespace PRSWS\CasaDelta\Lib;

/**
 * Value object for a configured host address: IPv4, IPv6 or hostname, optionally with a port
 * (research R-04, data-model.md section 2). Framework-free.
 */
final class HostAddress
{
    public const KIND_IPV4 = 'ipv4';
    public const KIND_IPV6 = 'ipv6';
    public const KIND_HOSTNAME = 'hostname';

    private const LABEL_PATTERN = '/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/';
    private const PORT_PATTERN = '/^[1-9][0-9]{0,4}$/';

    private function __construct(
        public readonly string $raw,
        public readonly string $host,
        public readonly ?int $port,
        public readonly string $kind,
    ) {
    }

    /**
     * @throws HostAddressException with the specific validation message
     */
    public static function parse(string $raw): self
    {
        if (trim($raw) === '') {
            throw new HostAddressException('Address is required.');
        }
        if (str_contains($raw, '/')) {
            throw new HostAddressException('Enter a host, not a URL.');
        }
        if (str_contains($raw, '%') && str_contains($raw, ':')) {
            throw new HostAddressException('IPv6 zone identifiers are not supported.');
        }
        if (preg_match('/\s/', $raw) === 1) {
            throw new HostAddressException('Invalid hostname.');
        }

        if ($raw[0] === '[') {
            return self::parseBracketed($raw);
        }

        $colons = substr_count($raw, ':');
        if ($colons >= 2) {
            if (filter_var($raw, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new HostAddressException('Invalid IPv6 address.');
            }
            return new self($raw, $raw, null, self::KIND_IPV6);
        }

        $port = null;
        $host = $raw;
        if ($colons === 1) {
            [$host, $portText] = explode(':', $raw, 2);
        }
        $kind = self::validateHost($host);
        if ($colons === 1) {
            $port = self::validatePort($portText);
        }

        return new self($raw, $host, $port, $kind);
    }

    /**
     * Returns null instead of throwing when the address is invalid.
     */
    public static function tryParse(string $raw): ?self
    {
        try {
            return self::parse($raw);
        } catch (HostAddressException) {
            return null;
        }
    }

    private static function parseBracketed(string $raw): self
    {
        $end = strpos($raw, ']');
        if ($end === false) {
            throw new HostAddressException('Invalid IPv6 address.');
        }
        $inner = substr($raw, 1, $end - 1);
        $rest = substr($raw, $end + 1);

        if (!str_contains($inner, ':')) {
            // [192.168.1.1], [pve.lan], []
            throw new HostAddressException('Only IPv6 addresses may be bracketed.');
        }
        if (filter_var($inner, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            throw new HostAddressException('Invalid IPv6 address.');
        }

        $port = null;
        if ($rest !== '') {
            if ($rest[0] !== ':') {
                throw new HostAddressException('Invalid IPv6 address.');
            }
            $port = self::validatePort(substr($rest, 1));
        }

        return new self($raw, $inner, $port, self::KIND_IPV6);
    }

    private static function validateHost(string $host): string
    {
        if ($host === '') {
            throw new HostAddressException('Invalid hostname.');
        }

        $name = rtrim($host, '.');
        $labels = explode('.', $name);

        $allNumeric = true;
        foreach ($labels as $label) {
            if ($label === '' || preg_match('/^[0-9]+$/', $label) !== 1) {
                $allNumeric = false;
                break;
            }
        }
        if ($allNumeric) {
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new HostAddressException('Invalid IPv4 address.');
            }
            return self::KIND_IPV4;
        }

        if ($name === '' || strlen($name) > 253 || substr($host, -2) === '..') {
            throw new HostAddressException('Invalid hostname.');
        }
        foreach ($labels as $label) {
            if (preg_match(self::LABEL_PATTERN, $label) !== 1) {
                throw new HostAddressException('Invalid hostname.');
            }
        }

        return self::KIND_HOSTNAME;
    }

    private static function validatePort(string $text): int
    {
        if (preg_match(self::PORT_PATTERN, $text) !== 1 || (int)$text > 65535) {
            throw new HostAddressException('Port must be between 1 and 65535.');
        }

        return (int)$text;
    }
}
