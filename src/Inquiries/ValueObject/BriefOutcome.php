<?php

declare(strict_types=1);

namespace App\Inquiries\ValueObject;

/**
 * What the guest learns after a brief: the venues whose mail went out, and those still waiting for staff (G7).
 */
final readonly class BriefOutcome
{
    /**
     * @param list<string> $reached     venue names
     * @param list<string> $undelivered venue names
     */
    public function __construct(
        public array $reached,
        public array $undelivered,
    ) {
    }

    public function reachedAny(): bool
    {
        return [] !== $this->reached;
    }
}
