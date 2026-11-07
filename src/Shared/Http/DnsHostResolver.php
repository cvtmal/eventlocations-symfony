<?php

declare(strict_types=1);

namespace App\Shared\Http;

/**
 * The system resolver, asked for A and AAAA records only: no hosts file and no search domains (the name is made
 * absolute with a trailing dot), because what this class answers is what SafeFetcher vets, and the connection is
 * then pinned to that answer.
 */
final class DnsHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        $records = @dns_get_record($host.'.', \DNS_A | \DNS_AAAA);
        if (!\is_array($records)) {
            return [];
        }

        $addresses = [];
        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (\is_string($address) && false !== filter_var($address, \FILTER_VALIDATE_IP)) {
                $addresses[$address] = $address;
            }
        }

        return array_values($addresses);
    }
}
