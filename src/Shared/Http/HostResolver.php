<?php

declare(strict_types=1);

namespace App\Shared\Http;

/**
 * Turns a host name into the IP addresses SafeFetcher vets and then connects to. An interface so a test can
 * answer 127.0.0.1, 169.254.169.254, ::1 or a different address on every call (DNS rebinding).
 */
interface HostResolver
{
    /**
     * Every A and AAAA address of the host, in canonical text form; empty when DNS knows none.
     *
     * @return list<string>
     */
    public function resolve(string $host): array;
}
