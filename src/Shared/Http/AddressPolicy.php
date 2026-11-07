<?php

declare(strict_types=1);

namespace App\Shared\Http;

/**
 * Which IP addresses SafeFetcher may connect to: global unicast addresses of the public internet and nothing else.
 *
 * IPv4 is a deny list of every special-purpose range (RFC 6890) plus the cloud metadata addresses. IPv6 is an allow
 * list: only 2000::/3 passes, minus the ranges inside it that are not the public internet (protocol assignments
 * including Teredo and ORCHID, documentation, 6to4). An IPv4-mapped address (::ffff:a.b.c.d) is judged by the IPv4
 * rules, so `::ffff:127.0.0.1` is loopback and not "some IPv6 address", and a mapped public address is refused
 * too, because no DNS answer has a reason to look like that. NAT64 (64:ff9b::/96) is refused as a whole: its
 * embedded address would be reached through a gateway that this check cannot see.
 */
final class AddressPolicy
{
    /**
     * More specific entries come first, so a metadata address gets its own label before its range's.
     *
     * @var list<array{string, string}>
     */
    private const IPV4 = [
        ['169.254.169.254/32', 'metadata'],
        ['168.63.129.16/32', 'metadata'],
        ['100.100.100.200/32', 'metadata'],
        ['0.0.0.0/8', 'unspecified'],
        ['10.0.0.0/8', 'private'],
        ['100.64.0.0/10', 'cgnat'],
        ['127.0.0.0/8', 'loopback'],
        ['169.254.0.0/16', 'link-local'],
        ['172.16.0.0/12', 'private'],
        ['192.0.0.0/24', 'reserved'],
        ['192.0.2.0/24', 'documentation'],
        ['192.88.99.0/24', 'reserved'],
        ['192.168.0.0/16', 'private'],
        ['198.18.0.0/15', 'benchmark'],
        ['198.51.100.0/24', 'documentation'],
        ['203.0.113.0/24', 'documentation'],
        ['224.0.0.0/4', 'multicast'],
        ['240.0.0.0/4', 'reserved'],
    ];

    /**
     * Inside 2000::/3 and not public.
     *
     * @var list<array{string, string}>
     */
    private const IPV6_EXCEPTIONS = [
        ['2001::/23', 'reserved'],
        ['2001:db8::/32', 'documentation'],
        ['2002::/16', 'ipv6-transition'],
        ['3fff::/20', 'documentation'],
    ];

    /**
     * Outside 2000::/3, named for the report; everything else outside is "reserved".
     *
     * @var list<array{string, string}>
     */
    private const IPV6_LABELS = [
        ['::1/128', 'loopback'],
        ['::/128', 'unspecified'],
        ['64:ff9b::/96', 'ipv6-transition'],
        ['64:ff9b:1::/48', 'ipv6-transition'],
        ['fc00::/7', 'unique-local'],
        ['fe80::/10', 'link-local'],
        ['fec0::/10', 'private'],
        ['ff00::/8', 'multicast'],
    ];

    /**
     * The label of the range the address is in, or null when it is on the public internet. Anything that is not an
     * IP address in canonical form is "invalid".
     */
    public function blockedReason(string $ip): ?string
    {
        $packed = @inet_pton($ip);
        if (false === $packed) {
            return 'invalid';
        }

        return 4 === \strlen($packed) ? self::ipv4Reason($packed) : self::ipv6Reason($packed);
    }

    private static function ipv4Reason(string $packed): ?string
    {
        foreach (self::IPV4 as [$cidr, $label]) {
            if (self::contains($cidr, $packed)) {
                return $label;
            }
        }

        return null;
    }

    private static function ipv6Reason(string $packed): ?string
    {
        // ::ffff:a.b.c.d is IPv4 in IPv6 clothes: the IPv4 rules name the range, and a public one is refused too,
        // because no DNS answer has a reason to look like this.
        if (self::contains('::ffff:0:0/96', $packed)) {
            return self::ipv4Reason(substr($packed, 12)) ?? 'ipv4-mapped';
        }

        if (self::contains('2000::/3', $packed)) {
            foreach (self::IPV6_EXCEPTIONS as [$cidr, $label]) {
                if (self::contains($cidr, $packed)) {
                    return $label;
                }
            }

            return null;
        }

        foreach (self::IPV6_LABELS as [$cidr, $label]) {
            if (self::contains($cidr, $packed)) {
                return $label;
            }
        }

        return 'reserved';
    }

    /**
     * Whether the packed address (4 or 16 bytes) is inside the CIDR block.
     */
    private static function contains(string $cidr, string $packed): bool
    {
        [$network, $bits] = explode('/', $cidr);
        $networkPacked = inet_pton($network);
        if (false === $networkPacked || \strlen($networkPacked) !== \strlen($packed)) {
            return false;
        }

        $bits = (int) $bits;
        $whole = intdiv($bits, 8);
        if (substr($packed, 0, $whole) !== substr($networkPacked, 0, $whole)) {
            return false;
        }

        $rest = $bits % 8;
        if (0 === $rest) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (\ord($packed[$whole]) & $mask) === (\ord($networkPacked[$whole]) & $mask);
    }
}
