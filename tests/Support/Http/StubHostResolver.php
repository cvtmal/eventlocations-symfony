<?php

declare(strict_types=1);

namespace App\Tests\Support\Http;

use App\Shared\Http\HostResolver;

/**
 * DNS for the SafeFetcher tests. A host answers its list; a host given several lists answers the first one the first
 * time it is asked, the second one the second time, and the last one from then on, which is what a rebinding attacker's
 * name server does. A host nobody registered answers the default, which is no address.
 */
final class StubHostResolver implements HostResolver
{
    /** A real public address, so a stubbed host passes the check unless a test says otherwise. */
    public const string PUBLIC_V4 = '93.184.216.34';
    public const string PUBLIC_V6 = '2606:2800:220:1:248:1893:25c8:1946';

    /** @var array<string, list<list<string>>> */
    private array $answers = [];

    /** @var array<string, int> */
    private array $calls = [];

    /** @var list<string> */
    private array $default = [];

    private int $total = 0;

    /**
     * What every host nobody registered answers.
     */
    public function byDefault(string ...$addresses): self
    {
        $this->default = array_values($addresses);

        return $this;
    }

    /**
     * @param list<string> ...$answers one list per consecutive question
     */
    public function host(string $host, array ...$answers): self
    {
        $this->answers[$host] = array_values($answers);

        return $this;
    }

    public function resolve(string $host): array
    {
        ++$this->total;
        $this->calls[$host] = ($this->calls[$host] ?? 0) + 1;
        $answers = $this->answers[$host] ?? [$this->default];

        return $answers[min($this->calls[$host], \count($answers)) - 1];
    }

    public function calls(string $host): int
    {
        return $this->calls[$host] ?? 0;
    }

    /** Every question asked, whichever host. */
    public function totalCalls(): int
    {
        return $this->total;
    }
}
