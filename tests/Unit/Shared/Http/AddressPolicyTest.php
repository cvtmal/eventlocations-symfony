<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Http;

use App\Shared\Http\AddressPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AddressPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function blocked(): iterable
    {
        yield 'loopback' => ['127.0.0.1', 'loopback'];
        yield 'loopback range end' => ['127.255.255.254', 'loopback'];
        yield 'this network' => ['0.0.0.0', 'unspecified'];
        yield 'private 10' => ['10.1.2.3', 'private'];
        yield 'private 172.16' => ['172.16.0.1', 'private'];
        yield 'private 172.31' => ['172.31.255.255', 'private'];
        yield 'private 192.168' => ['192.168.0.10', 'private'];
        yield 'cgnat' => ['100.64.0.1', 'cgnat'];
        yield 'cgnat end' => ['100.127.255.255', 'cgnat'];
        yield 'aws, gcp and azure metadata' => ['169.254.169.254', 'metadata'];
        yield 'ecs task metadata' => ['169.254.170.2', 'link-local'];
        yield 'azure wire server' => ['168.63.129.16', 'metadata'];
        yield 'alibaba metadata' => ['100.100.100.200', 'metadata'];
        yield 'oracle metadata' => ['192.0.0.192', 'reserved'];
        yield 'documentation' => ['203.0.113.7', 'documentation'];
        yield 'benchmark' => ['198.19.0.1', 'benchmark'];
        yield 'multicast' => ['224.0.0.1', 'multicast'];
        yield 'reserved' => ['240.0.0.1', 'reserved'];
        yield 'broadcast' => ['255.255.255.255', 'reserved'];
        yield 'ipv6 loopback' => ['::1', 'loopback'];
        yield 'ipv6 unspecified' => ['::', 'unspecified'];
        yield 'ipv6 unique local' => ['fd12:3456:789a::1', 'unique-local'];
        yield 'aws ipv6 metadata' => ['fd00:ec2::254', 'unique-local'];
        yield 'ipv6 link-local' => ['fe80::1', 'link-local'];
        yield 'ipv6 multicast' => ['ff02::1', 'multicast'];
        yield 'ipv6 documentation' => ['2001:db8::1', 'documentation'];
        yield 'teredo' => ['2001:0:4136:e378:8000:63bf:3fff:fdd2', 'reserved'];
        yield '6to4 of a public address' => ['2002:5db8:d822::1', 'ipv6-transition'];
        yield 'nat64' => ['64:ff9b::7f00:1', 'ipv6-transition'];
        yield 'mapped loopback' => ['::ffff:127.0.0.1', 'loopback'];
        yield 'mapped loopback in hex' => ['::ffff:7f00:1', 'loopback'];
        yield 'mapped metadata' => ['::ffff:169.254.169.254', 'metadata'];
        yield 'mapped private' => ['::ffff:10.0.0.1', 'private'];
        yield 'mapped public is refused too' => ['::ffff:93.184.216.34', 'ipv4-mapped'];
        yield 'ipv4-compatible loopback' => ['::127.0.0.1', 'reserved'];
        yield 'not an address' => ['localhost', 'invalid'];
        yield 'octal spelling' => ['0177.0.0.1', 'invalid'];
        yield 'short spelling' => ['127.1', 'invalid'];
        yield 'with a zone' => ['fe80::1%eth0', 'invalid'];
    }

    #[DataProvider('blocked')]
    public function test_a_non_public_address_is_blocked_with_its_range(string $ip, string $range): void
    {
        self::assertSame($range, (new AddressPolicy())->blockedReason($ip));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function public(): iterable
    {
        yield 'example.com' => ['93.184.216.34'];
        yield 'dns' => ['8.8.8.8'];
        yield 'just below 10/8' => ['9.255.255.255'];
        yield 'just above 10/8' => ['11.0.0.0'];
        yield 'just below private 172' => ['172.15.255.255'];
        yield 'just above private 172' => ['172.32.0.0'];
        yield 'just below cgnat' => ['100.63.255.255'];
        yield 'just above cgnat' => ['100.128.0.0'];
        yield 'just above link-local' => ['169.255.0.0'];
        yield 'just below benchmark' => ['198.17.255.255'];
        yield 'documentation neighbour' => ['203.0.114.1'];
        yield 'ipv6 global' => ['2606:2800:220:1:248:1893:25c8:1946'];
        yield 'ipv6 google' => ['2a00:1450:4001:80b::200e'];
        yield 'ipv6 just above the protocol block' => ['2001:200::1'];
    }

    #[DataProvider('public')]
    public function test_a_public_address_passes(string $ip): void
    {
        self::assertNull((new AddressPolicy())->blockedReason($ip));
    }
}
